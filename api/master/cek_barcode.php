<?php
/**
 * POST api/master/cek_barcode.php — cek sekumpulan barcode sekaligus.
 *
 * Dipakai tabel review impor PDF untuk menandai tiap baris: cocok master,
 * tak dikenal, atau barcode kosong. Dikirim sekali untuk semua baris, bukan
 * satu permintaan per baris — satu picking list bisa memuat ratusan baris.
 *
 * Body: { barcodes: ["12132519", ...] }
 * Respons: { ok, ditemukan: { "12132519": "NAMA BARANG", ... } }
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

$daftar = $in['barcodes'] ?? [];
if (!is_array($daftar)) {
    jsonError('Format barcodes tidak valid.');
}

// Bersihkan, buang duplikat dan yang kosong.
$bersih = [];
foreach ($daftar as $b) {
    if (!is_scalar($b)) {
        continue;
    }
    $b = mb_substr(trim((string)$b), 0, 50);
    if ($b !== '') {
        $bersih[$b] = true;
    }
}
$bersih = array_keys($bersih);

if (!$bersih) {
    jsonOk(['ditemukan' => (object)[]]);
}
if (count($bersih) > 5000) {
    jsonError('Terlalu banyak barcode dalam satu permintaan.');
}

$tanda = implode(',', array_fill(0, count($bersih), '?'));
$rows  = dbAll(
    "SELECT barcode, nama FROM master_barang
      WHERE deleted_at IS NULL AND barcode IN ($tanda)",
    $bersih
);

$ditemukan = [];
foreach ($rows as $r) {
    $ditemukan[$r['barcode']] = $r['nama'];
}

jsonOk(['ditemukan' => $ditemukan ?: (object)[]]);
