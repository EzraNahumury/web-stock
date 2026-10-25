<?php
/**
 * POST api/opname/massal.php — isi petugas / penyesuaian untuk banyak baris.
 *
 * Body: { id, q?, kategori?, hanya?, petugas?, penyesuaian?, pratinjau? }
 *
 * Satu sesi opname bisa memuat ribuan barang, dan biasanya dihitung oleh
 * orang yang sama. Mengetik namanya per baris tidak masuk akal, jadi nilai
 * itu bisa diisikan sekaligus untuk seluruh baris yang SEDANG TERSARING —
 * penyaringnya sama persis dengan yang dipakai layar, dibangun di
 * includes/opname.php.
 *
 * `pratinjau` hanya menghitung, tidak mengubah apa pun. Antarmuka memakainya
 * untuk menyebut angkanya di dialog konfirmasi sebelum menimpa apa pun.
 *
 * PENYESUAIAN MASSAL: BISA, TAPI TIDAK DIAM-DIAM
 * Endpoint ini dulu menolak penyesuaian massal, karena setiap baris menulis
 * koreksi stok sendiri dan ribuan koreksi dalam satu klik terasa terlalu
 * besar. Gudang memintanya tetap ada — menyetel ratusan baris satu per satu
 * pada hari opname memakan waktu yang tidak mereka punya — jadi sekarang
 * bisa, dengan tiga pagar:
 *
 *   1. `pratinjau` menyebut lebih dulu berapa baris yang AKAN disesuaikan dan
 *      berapa yang dilewati beserta sebabnya, supaya angkanya terlihat
 *      sebelum ada yang berubah.
 *   2. Baris tanpa stok hitung, atau tanpa kaitan ke master, DILEWATI — bukan
 *      membuat seluruh permintaan gagal. Keduanya memang tidak bisa
 *      disesuaikan, dan menggagalkan semuanya karena satu baris begitu hanya
 *      membuat tombolnya tidak pernah bisa dipakai.
 *   3. Dibatasi MAKS_PENYESUAIAN_MASSAL baris sekali jalan. Bukan aturan
 *      gudang, melainkan pagar transaksi: setiap baris menulis atau membatalkan
 *      satu baris barang masuk/keluar.
 *
 * Tiap baris tetap lewat sinkronPenyesuaianStok() — jalur yang sama persis
 * dengan menyetelnya satu per satu — bukan UPDATE massal yang hanya mengubah
 * kolom penyesuaian tanpa menggeser stoknya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/opname.php';

/* Pagar transaksi, bukan aturan gudang: satu sesi opname bisa memuat ribuan
 * barang, dan tiap penyesuaian menulis satu baris barang masuk/keluar. */
const MAKS_PENYESUAIAN_MASSAL = 1000;

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('ID sesi tidak valid.');
}

$sesi = dbOne('SELECT * FROM opname_sesi WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($sesi === null) {
    jsonError('Sesi opname tidak ditemukan.', 404);
}
if ($sesi['status'] === 'selesai') {
    jsonError('Sesi ini sudah ditutup. Buka kembali statusnya bila memang perlu diubah.', 422);
}

$f     = filterOpnameItem($in, $id);
$total = (int)dbValue("SELECT COUNT(*) FROM opname_item i " . $f['where'], $f['params']);

/* Penyesuaian dihitung tersendiri: yang bisa disesuaikan hanya baris yang
 * sudah punya stok hitung DAN terhubung ke master. Angka inilah yang disebut
 * di dialog konfirmasi, bukan jumlah seluruh baris tersaring — menyebut 55
 * padahal yang berubah 12 membuat orang menyetujui hal yang berbeda dari yang
 * terjadi. */
$mintaPenyesuaian = array_key_exists('penyesuaian', $in);
$bisaSesuai = 0;
$lewatHitung = 0;
$lewatMaster = 0;
if ($mintaPenyesuaian) {
    $hit = dbOne(
        "SELECT
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.master_id IS NOT NULL
                              THEN 1 ELSE 0 END), 0) AS bisa,
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NULL THEN 1 ELSE 0 END), 0) AS tanpa_hitung,
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.master_id IS NULL
                              THEN 1 ELSE 0 END), 0) AS tanpa_master
           FROM opname_item i " . $f['where'],
        $f['params']
    );
    $bisaSesuai  = (int)$hit['bisa'];
    $lewatHitung = (int)$hit['tanpa_hitung'];
    $lewatMaster = (int)$hit['tanpa_master'];
}

if (!empty($in['pratinjau'])) {
    jsonOk([
        'pratinjau'    => true,
        'jumlah'       => $total,
        'bisa_sesuai'  => $bisaSesuai,
        'lewat_hitung' => $lewatHitung,
        'lewat_master' => $lewatMaster,
        'maks'         => MAKS_PENYESUAIAN_MASSAL,
    ]);
}

/* --- Kolom mana yang diisi ------------------------------------------------
 * Field yang tidak dikirim tidak disentuh, supaya mengisi petugas tidak
 * diam-diam ikut menimpa keputusan penyesuaian, dan sebaliknya.
 * ---------------------------------------------------------------------- */
$set    = [];
$isiSet = [];

if (array_key_exists('petugas', $in)) {
    $set[]    = 'i.petugas = ?';
    $isiSet[] = ambilStr($in, 'petugas', 100);
}
if (array_key_exists('dicek', $in)) {
    // Centang "sudah dihitung". Tidak menyentuh stok sama sekali — ia hanya
    // menandai bahwa baris itu sudah didatangi petugas.
    $set[]    = 'i.dicek = ?';
    $isiSet[] = !empty($in['dicek']) ? 1 : 0;
}

if (!$set && !$mintaPenyesuaian) {
    jsonError('Tidak ada yang diisi.');
}
if ($total === 0) {
    jsonOk(['jumlah' => 0, 'pesan' => 'Tidak ada baris yang cocok dengan penyaring ini.']);
}

/* --- Kolom biasa ---------------------------------------------------------
 * Satu UPDATE untuk seluruh baris tersaring; tidak ada yang menggeser stok,
 * jadi tidak perlu diperiksa baris per baris. */
$diubah = 0;
if ($set) {
    $diubah = dbTransaksi(static function (PDO $pdo) use ($set, $isiSet, $f) {
        $st = $pdo->prepare('UPDATE opname_item i SET ' . implode(', ', $set) . ' ' . $f['where']);
        $st->execute(array_merge($isiSet, $f['params']));
        return $st->rowCount();
    });
}

/* --- Penyesuaian ---------------------------------------------------------
 * Baris per baris lewat sinkronPenyesuaianStok(), karena masing-masing
 * menulis atau membatalkan baris barang masuk/keluarnya sendiri. */
$disesuaikan = 0;
$dilewati    = 0;
$unitNaik    = 0;
$unitTurun   = 0;

if ($mintaPenyesuaian) {
    $pilihan   = pilihanValid(ambilStr($in, 'penyesuaian', 30), PENYESUAIAN_OPNAME);
    $sesuaikan = ($pilihan === PENYESUAIAN_DISESUAIKAN);

    if ($sesuaikan && $bisaSesuai > MAKS_PENYESUAIAN_MASSAL) {
        jsonError(
            'Terlalu banyak sekaligus: ' . number_format($bisaSesuai, 0, ',', '.')
                . ' baris akan menulis koreksi stok, batasnya '
                . number_format(MAKS_PENYESUAIAN_MASSAL, 0, ',', '.') . '. '
                . 'Persempit penyaringnya — misalnya per kategori — lalu ulangi.',
            422
        );
    }

    $hasil = dbTransaksi(static function (PDO $pdo) use ($f, $pilihan, $sesuaikan) {
        $st = $pdo->prepare('SELECT * FROM opname_item i ' . $f['where'] . ' ORDER BY i.id');
        $st->execute($f['params']);

        $stSimpan = $pdo->prepare(
            'UPDATE opname_item
                SET penyesuaian = ?, adj_jenis = ?, adj_id = ?, adj_qty = ?
              WHERE id = ?'
        );

        $n = 0;
        $lewat = 0;
        $naik = 0;
        $turun = 0;

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $item) {
            /* Baris yang tidak bisa disesuaikan dilewati, bukan menggagalkan
             * semuanya: pada sesi berisi ratusan barang selalu ada yang belum
             * dihitung, dan menggagalkan karena itu membuat tombolnya tidak
             * pernah bisa dipakai. Jumlahnya dilaporkan balik. */
            if ($sesuaikan && ($item['stok_hitung'] === null || $item['master_id'] === null)) {
                $lewat++;
                continue;
            }

            $adj = sinkronPenyesuaianStok($pdo, $item, $sesuaikan);
            $stSimpan->execute([
                $pilihan, $adj['jenis'], $adj['id'], $adj['qty'], (int)$item['id'],
            ]);
            if ($adj['jenis'] === 'masuk') {
                $naik += (int)$adj['qty'];
            } elseif ($adj['jenis'] === 'keluar') {
                $turun += (int)$adj['qty'];
            }
            $n++;
        }
        return ['n' => $n, 'lewat' => $lewat, 'naik' => $naik, 'turun' => $turun];
    });

    $disesuaikan = $hasil['n'];
    $dilewati    = $hasil['lewat'];
    $unitNaik    = $hasil['naik'];
    $unitTurun   = $hasil['turun'];

    catatAktivitas('update', 'opname', $id, [
        'nama'     => $sesi['nama'],
        'aksi'     => 'penyesuaian massal',
        'pilihan'  => $pilihan,
        'kategori' => $f['kategori'] !== '' ? $f['kategori'] : 'semua',
        'baris'    => $disesuaikan,
        'stok_naik'  => $unitNaik,
        'stok_turun' => $unitTurun,
    ]);
}

if ($set) {
    catatAktivitas('update', 'opname', $id, [
        'nama'     => $sesi['nama'],
        'aksi'     => 'isi massal',
        'kategori' => $f['kategori'] !== '' ? $f['kategori'] : 'semua',
        'baris'    => $total,
    ]);
}

$pesan = [];
if ($set) {
    $pesan[] = number_format($total, 0, ',', '.') . ' baris diperbarui';
}
if ($mintaPenyesuaian) {
    $pesan[] = number_format($disesuaikan, 0, ',', '.') . ' baris disetel penyesuaiannya';
    if ($unitNaik > 0 || $unitTurun > 0) {
        $pesan[] = 'stok +' . number_format($unitNaik, 0, ',', '.')
            . ' / -' . number_format($unitTurun, 0, ',', '.') . ' pcs';
    }
    if ($dilewati > 0) {
        $pesan[] = number_format($dilewati, 0, ',', '.')
            . ' dilewati karena belum dihitung atau tidak terhubung ke master';
    }
}

jsonOk([
    'jumlah'      => $diubah,
    'cocok'       => $total,
    'disesuaikan' => $disesuaikan,
    'dilewati'    => $dilewati,
    'stok_naik'   => $unitNaik,
    'stok_turun'  => $unitTurun,
    'pesan'       => ucfirst(implode(', ', $pesan)) . '.',
]);
