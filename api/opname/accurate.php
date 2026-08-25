<?php
/**
 * POST api/opname/accurate.php — isi kolom Stok accurate dari PDF Accurate.
 *
 * Body: { id, rows:[{nama, qty}], gudang?, pratinjau? }
 *
 * PDF-nya dibaca di sisi klien (assets/js/accurate-parser.js) dan yang
 * dikirim ke sini hanya pasangan nama + kuantitas. Berkasnya sendiri tidak
 * pernah diunggah: isinya memuat harga pokok per barang, dan menyimpannya
 * di server tanpa alasan hanya menambah tempat data itu bisa bocor.
 *
 * PENCOCOKAN LEWAT NAMA
 * Laporan Accurate tidak memuat barcode maupun SKU, jadi satu-satunya
 * pegangan adalah nama barang. Dicocokkan setelah dinormalkan: huruf besar,
 * spasi rangkap dirapatkan. Nama yang tidak ketemu, atau yang cocok ke lebih
 * dari satu baris, dilaporkan balik dan TIDAK diisikan — menebak barang mana
 * yang dimaksud lebih berbahaya daripada membiarkan kolomnya kosong.
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

/* --- Peta nama -> baris opname ------------------------------------------ */
// Nama yang muncul lebih dari sekali ditandai ganda; keduanya dilewati.
$peta = [];
foreach (dbAll('SELECT id, nama FROM opname_item WHERE sesi_id = ?', [$id]) as $r) {
    $k = $normal((string)$r['nama']);
    if (isset($peta[$k])) {
        $peta[$k] = 'ganda';
        continue;
    }
    $peta[$k] = (int)$r['id'];
}

$isi        = [];      // id baris => qty
$takKetemu  = [];
$ganda      = [];
$rusak      = 0;

foreach ($rows as $r) {
    if (!is_array($r)) {
        $rusak++;
        continue;
    }
    $nama = mb_substr(trim((string)($r['nama'] ?? '')), 0, 255);
    $qty  = $r['qty'] ?? null;

    if ($nama === '' || !is_numeric($qty)) {
        $rusak++;
        continue;
    }
    $qty = (int)round((float)$qty);
    if ($qty < 0) {
        $rusak++;
        continue;
    }

    $k = $normal($nama);
    if (!isset($peta[$k])) {
        if (count($takKetemu) < 50) {
            $takKetemu[] = $nama;
        }
        continue;
    }
    if ($peta[$k] === 'ganda') {
        if (count($ganda) < 50) {
            $ganda[] = $nama;
        }
        continue;
    }
    // Nama yang sama muncul dua kali di PDF: yang terakhir yang dipakai,
    // sama seperti membuka berkasnya dan membaca dari atas ke bawah.
    $isi[$peta[$k]] = $qty;
}

$ringkas = [
    'dibaca'     => count($rows),
    'cocok'      => count($isi),
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
