<?php
/**
 * POST api/keterangan/save.php — tambah atau ubah satu pilihan keterangan.
 *
 * Body: { id?, jenis, nama, catatan?, urutan?, aktif? }
 *
 * MENGGANTI NAMA IKUT MEMPERBARUI TRANSAKSI
 * Keterangan disimpan sebagai teks di barang_masuk / barang_keluar / retur,
 * bukan sebagai relasi. Kalau namanya diubah tanpa memperbarui transaksinya,
 * catatan lama akan memuat nilai yang tidak lagi ada di daftar pilihan dan
 * tidak bisa disaring lagi. Karena itu keduanya diubah dalam satu transaksi.
 *
 * Baris terkunci tidak boleh diganti namanya: nilainya dipakai sistem
 * (retur menulis "Retur Masuk"), jadi mengubahnya akan memutus sambungan itu.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
// Dipakai saat centang "menambah stok" diubah: retur yang sudah tercatat
// disusulkan ke barang masuk lewat jalur yang sama dengan pencatatan biasa.
require_once __DIR__ . '/../../includes/retur.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();

$in = jsonInput();
wajibCsrf($in);

$arah    = arahKeterangan();
$id      = ambilInt($in, 'id', 0);
$jenis   = pilihanValid(ambilStr($in, 'jenis', 10), array_keys($arah));
$nama    = ambilStr($in, 'nama', 50);
$catatan = ambilStr($in, 'catatan', 120);
$urutan  = ambilInt($in, 'urutan', 0);
$aktif   = array_key_exists('aktif', $in) ? (!empty($in['aktif']) ? 1 : 0) : 1;

// Hanya berarti untuk keterangan retur: apakah retur dengan keterangan ini
// mengembalikan barangnya ke stok. Arah stok barang masuk dan barang keluar
// sudah ditentukan oleh tabelnya sendiri.
$tambahStok = $jenis === 'retur' && !empty($in['tambah_stok']) ? 1 : 0;

if ($nama === '') {
    jsonError('Nama keterangan wajib diisi.');
}
if ($urutan < 0) {
    jsonError('Urutan tidak boleh negatif.');
}

$tabel = $arah[$jenis]['tabel'];
$kolom = $arah[$jenis]['kolom'];

// Unik per arah: "Retur" boleh ada di keluar sekaligus di masuk.
$bentrok = dbOne(
    'SELECT id FROM keterangan WHERE jenis = ? AND nama = ? AND id <> ? AND deleted_at IS NULL LIMIT 1',
    [$jenis, $nama, $id]
);
if ($bentrok !== null) {
    jsonError('Keterangan "' . $nama . '" sudah ada di daftar ini.');
}

/* --- Ubah ---------------------------------------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM keterangan WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Keterangan tidak ditemukan.', 404);
    }
    if ($lama['jenis'] !== $jenis) {
        jsonError('Arah keterangan tidak bisa dipindah. Buat baru di daftar yang satunya.');
    }

    $gantiNama = ($lama['nama'] !== $nama);

    if ((int)$lama['terkunci'] === 1) {
        if ($gantiNama) {
            jsonError(
                'Keterangan "' . $lama['nama'] . '" dipakai sistem, jadi namanya tidak bisa diubah.',
                409
            );
        }
        if ($aktif === 0) {
            jsonError(
                'Keterangan "' . $lama['nama'] . '" dipakai sistem, jadi tidak bisa dinonaktifkan.',
                409
            );
        }
    }

    /* Mengubah "menambah stok" menyusul retur yang sudah terlanjur.
     *
     * Tanpa ini, mencentangnya hanya berlaku untuk retur yang dicatat
     * SESUDAHNYA — sementara yang sudah tercatat tetap tidak menambah stok,
     * diam-diam, persis masalah yang centang ini dibuat untuk menyelesaikan.
     * Jadi seluruh retur dengan keterangan ini disamakan ulang, satu per
     * satu, lewat jalur yang sama dengan pencatatan biasa. */
    $stokBerubah = $jenis === 'retur' && (int)$lama['tambah_stok'] !== $tambahStok;

    $hasil = dbTransaksi(static function (PDO $pdo) use (
        $id, $jenis, $nama, $catatan, $urutan, $aktif, $lama, $gantiNama,
        $tabel, $kolom, $tambahStok, $stokBerubah
    ) {
        $n = 0;
        if ($gantiNama) {
            $st = $pdo->prepare("UPDATE $tabel SET `$kolom` = ? WHERE `$kolom` = ?");
            $st->execute([$nama, $lama['nama']]);
            $n = $st->rowCount();
        }
        $st = $pdo->prepare(
            'UPDATE keterangan SET jenis = ?, nama = ?, catatan = ?, urutan = ?,
                                   aktif = ?, tambah_stok = ?
              WHERE id = ?'
        );
        $st->execute([$jenis, $nama, $catatan, $urutan, $aktif, $tambahStok, $id]);

        $disusul = 0;
        if ($stokBerubah) {
            $retur = $pdo->prepare(
                'SELECT id, tanggal, master_id, barcode, nama, jumlah, status, masuk_id
                   FROM retur WHERE status = ? AND deleted_at IS NULL'
            );
            $retur->execute([$nama]);
            $stTaut = $pdo->prepare('UPDATE retur SET masuk_id = ? WHERE id = ?');

            foreach ($retur->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $masukId = $r['masuk_id'] === null ? null : (int)$r['masuk_id'];
                // Daftar "menambah stok" sudah berubah di baris di atas, tapi
                // keteranganTambahStok() memakai cache dalam permintaan ini;
                // jadi keputusannya diberikan langsung, bukan dibaca ulang.
                $baru = sinkronMasukRetur($pdo, $r, $masukId, $tambahStok === 1);
                if ($baru !== $masukId) {
                    $stTaut->execute([$baru, $r['id']]);
                }
                $disusul++;
            }
        }

        return ['ikut' => $n, 'disusul' => $disusul];
    });
    $ikut = $hasil['ikut'];

    catatAktivitas('update', 'keterangan', $id, [
        'jenis'   => $jenis,
        'nama'    => $nama,
        'sebelum' => $lama['nama'],
        'ikut'    => $ikut,
    ]);

    $pesan = 'Keterangan tersimpan.';
    if ($ikut > 0) {
        $pesan .= ' ' . number_format($ikut, 0, ',', '.') . ' catatan ikut diperbarui.';
    }
    if ($hasil['disusul'] > 0) {
        $pesan .= ' ' . number_format($hasil['disusul'], 0, ',', '.') . ' retur '
            . ($tambahStok === 1
                ? 'disusulkan ke barang masuk.'
                : 'dikeluarkan lagi dari barang masuk.');
    }

    jsonOk([
        'id'      => $id,
        'ikut'    => $ikut,
        'disusul' => $hasil['disusul'],
        'pesan'   => $pesan,
    ]);
}

/* --- Tambah -------------------------------------------------------------- */
dbExec(
    'INSERT INTO keterangan (jenis, nama, catatan, urutan, aktif, tambah_stok)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$jenis, $nama, $catatan, $urutan, $aktif, $tambahStok]
);
$baruId = dbLastId();

catatAktivitas('create', 'keterangan', $baruId, ['jenis' => $jenis, 'nama' => $nama]);

jsonOk(['id' => $baruId, 'pesan' => 'Keterangan "' . $nama . '" ditambahkan.']);
