<?php
/**
 * POST api/opname/accurate.php — isi kolom Stok accurate dari PDF Accurate.
 *
 * Body: { id, rows:[{kode?, nama, qty}], gudang?, pratinjau? }
 *
 * PDF-nya dibaca di sisi klien (assets/js/accurate-parser.js) dan yang
 * dikirim ke sini hanya pasangan nama + kuantitas. Berkasnya sendiri tidak
 * pernah diunggah: isinya memuat harga pokok per barang, dan menyimpannya
 * di server tanpa alasan hanya menambah tempat data itu bisa bocor.
 *
 * PENCOCOKAN: KODE DULU, BARU NAMA
 * Laporan Accurate yang dicetak ke PDF tidak memuat kode barang, jadi dulu
 * satu-satunya pegangan adalah nama — dan nama itu pegangan yang rapuh: beda
 * satu spasi atau satu kata saja sudah tidak ketemu.
 *
 * Ekspor Accurate ke CSV/Excel memuat kolom kode barang. Bila ada, kode itu
 * dicocokkan lebih dulu ke SKU atau barcode: cocok persis, tidak bisa salah
 * orang. Nama tetap dipakai sebagai cadangan untuk baris yang kodenya kosong
 * atau tidak dikenal, dan untuk impor dari PDF yang memang tidak punya kode.
 *
 * Nama dicocokkan setelah dinormalkan: huruf besar, spasi rangkap dirapatkan.
 * Yang tidak ketemu, atau yang cocok ke lebih dari satu baris, dilaporkan
 * balik dan TIDAK diisikan — menebak barang mana yang dimaksud lebih berbahaya
 * daripada membiarkan kolomnya kosong.
 *
 * `pratinjau` menghitung tanpa mengubah apa pun, dipakai antarmuka untuk
 * menunjukkan hasilnya sebelum apa pun ditimpa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

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

$rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
if (!$rows) {
    jsonError('Tidak ada baris terbaca dari PDF.');
}
if (count($rows) > 20000) {
    jsonError('Terlalu banyak baris dalam satu berkas.');
}

/** Samakan bentuk nama supaya beda spasi dan huruf besar-kecil tidak menggagalkan. */
$normal = static function (string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return mb_strtoupper((string)$s);
};

/* --- Peta pencocokan -> baris opname ------------------------------------
 *
 * Dua peta: kode (SKU dan barcode) dan nama. Yang muncul lebih dari sekali
 * ditandai 'ganda' dan dilewati di kedua peta. */
$peta     = [];
$petaKode = [];
foreach (dbAll('SELECT id, nama, sku, barcode FROM opname_item WHERE sesi_id = ?', [$id]) as $r) {
    $idBaris = (int)$r['id'];

    $k = $normal((string)$r['nama']);
    if (isset($peta[$k])) {
        $peta[$k] = 'ganda';
    } else {
        $peta[$k] = $idBaris;
    }

    /* SKU dan barcode masuk ke peta yang sama: ekspor Accurate menyebut
     * kolomnya "No. Barang", dan gudang mengisinya kadang dengan SKU kadang
     * dengan barcode. Memaksa memilih salah satu hanya membuat setengah
     * barisnya tidak ketemu tanpa sebab yang kelihatan. */
    foreach ([(string)$r['sku'], (string)$r['barcode']] as $kode) {
        $kk = $normal($kode);
        if ($kk === '') {
            continue;
        }
        if (isset($petaKode[$kk]) && $petaKode[$kk] !== $idBaris) {
            $petaKode[$kk] = 'ganda';
        } elseif (!isset($petaKode[$kk])) {
            $petaKode[$kk] = $idBaris;
        }
    }
}

$isi        = [];      // id baris => qty
$takKetemu  = [];
$ganda      = [];
$rusak      = 0;
$lewatKode  = 0;       // berapa yang cocok lewat kode, bukan nama

foreach ($rows as $r) {
    if (!is_array($r)) {
        $rusak++;
        continue;
    }
    $nama = mb_substr(trim((string)($r['nama'] ?? '')), 0, 255);
    $kode = mb_substr(trim((string)($r['kode'] ?? '')), 0, 100);
    $qty  = $r['qty'] ?? null;

    if (($nama === '' && $kode === '') || !is_numeric($qty)) {
        $rusak++;
        continue;
    }
    $qty = (int)round((float)$qty);
    if ($qty < 0) {
        $rusak++;
        continue;
    }

    /* Kode lebih dulu: cocok persis, tidak bisa salah orang. Kode yang tidak
     * dikenal TIDAK langsung dianggap gagal — berkasnya bisa memuat barang di
     * luar kategori sesi ini, dan namanya masih mungkin cocok. */
    $baris = null;
    $kk = $normal($kode);
    if ($kk !== '' && isset($petaKode[$kk])) {
        if ($petaKode[$kk] === 'ganda') {
            if (count($ganda) < 50) {
                $ganda[] = $kode . ($nama !== '' ? ' (' . $nama . ')' : '');
            }
            continue;
        }
        $baris = $petaKode[$kk];
        $lewatKode++;
    }

    if ($baris === null) {
        $k = $normal($nama);
        if ($nama === '' || !isset($peta[$k])) {
            if (count($takKetemu) < 50) {
                $takKetemu[] = $nama !== '' ? $nama : $kode;
            }
            continue;
        }
        if ($peta[$k] === 'ganda') {
            if (count($ganda) < 50) {
                $ganda[] = $nama;
            }
            continue;
        }
        $baris = $peta[$k];
    }

    // Barang yang sama muncul dua kali di berkasnya: yang terakhir yang
    // dipakai, sama seperti membacanya dari atas ke bawah.
    $isi[$baris] = $qty;
}

$ringkas = [
    'dibaca'     => count($rows),
    'cocok'      => count($isi),
    // Dipakai layar untuk menyebut seberapa banyak yang cocok lewat kode —
    // itu ukuran seberapa bisa dipercaya hasil impornya.
    'lewat_kode' => $lewatKode,
    'tak_ketemu' => $takKetemu,
    'ganda'      => $ganda,
    'rusak'      => $rusak,
    'gudang'     => mb_substr(trim((string)($in['gudang'] ?? '')), 0, 100),
];

if (!empty($in['pratinjau'])) {
    jsonOk(['pratinjau' => true] + $ringkas);
}

if (!$isi) {
    jsonOk(['diisi' => 0, 'pesan' => 'Tidak ada nama barang yang cocok dengan isi laporan ini.'] + $ringkas);
}

$diisi = dbTransaksi(static function (PDO $pdo) use ($isi) {
    $st = $pdo->prepare('UPDATE opname_item SET stok_accurate = ? WHERE id = ?');
    $n = 0;
    foreach ($isi as $baris => $qty) {
        $st->execute([$qty, $baris]);
        $n++;
    }
    return $n;
});

catatAktivitas('update', 'opname', $id, [
    'aksi'   => 'impor stok accurate',
    'nama'   => $sesi['nama'],
    'baris'  => $diisi,
    'gudang' => $ringkas['gudang'],
]);

jsonOk([
    'diisi' => $diisi,
    'pesan' => number_format($diisi, 0, ',', '.') . ' baris terisi stok accurate-nya.',
] + $ringkas);
