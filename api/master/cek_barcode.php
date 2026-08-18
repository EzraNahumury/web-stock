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

$daftar    = $in['barcodes'] ?? [];
$daftarSku = $in['skus'] ?? [];
if (!is_array($daftar) || !is_array($daftarSku)) {
    jsonError('Format barcodes/skus tidak valid.');
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

if (count($bersih) > 5000) {
    jsonError('Terlalu banyak barcode dalam satu permintaan.');
}

$ditemukan = [];
if ($bersih) {
    $tanda = implode(',', array_fill(0, count($bersih), '?'));
    $rows  = dbAll(
        "SELECT id, barcode, nama, sku FROM master_barang
          WHERE deleted_at IS NULL AND barcode IN ($tanda)",
        $bersih
    );
    // Nama dan SKU ikut dikirim supaya tabel review bisa mengisinya otomatis
    // begitu admin mengganti barcode sebuah baris.
    foreach ($rows as $r) {
        $ditemukan[$r['barcode']] = [
            'id'   => (int)$r['id'],
            'nama' => $r['nama'],
            'sku'  => $r['sku'],
        ];
    }
}

/* --- Pencarian lewat SKU --------------------------------------------------
 * Admin juga boleh menukar produk dengan mengetik SKU, bukan hanya barcode.
 * SKU TIDAK dijamin unik di master (data sumber punya beberapa yang kembar),
 * jadi kecocokan ganda ditandai agar antarmuka bisa memperingatkan alih-alih
 * diam-diam memilih salah satu.
 * ------------------------------------------------------------------------ */
$bersihSku = [];
foreach ($daftarSku as $v) {
    if (!is_scalar($v)) {
        continue;
    }
    $v = mb_substr(trim((string)$v), 0, 50);
    if ($v !== '') {
        $bersihSku[$v] = true;
    }
}
$bersihSku = array_keys($bersihSku);

$ditemukanSku = [];
if ($bersihSku) {
    if (count($bersihSku) > 5000) {
        jsonError('Terlalu banyak SKU dalam satu permintaan.');
    }
    $tandaS = implode(',', array_fill(0, count($bersihSku), '?'));
    $rowsS  = dbAll(
        "SELECT id, barcode, nama, sku FROM master_barang
          WHERE deleted_at IS NULL AND sku <> '' AND sku IN ($tandaS)
          ORDER BY id",
        $bersihSku
    );
    foreach ($rowsS as $r) {
        if (isset($ditemukanSku[$r['sku']])) {
            $ditemukanSku[$r['sku']]['ganda'] = true;
            continue;   // kemunculan pertama yang dipakai
        }
        $ditemukanSku[$r['sku']] = [
            'id'      => (int)$r['id'],
            'barcode' => $r['barcode'],
            'nama'    => $r['nama'],
            'sku'     => $r['sku'],
            'ganda'   => false,
        ];
    }
}

jsonOk([
    'ditemukan'     => $ditemukan ?: (object)[],
    'ditemukan_sku' => $ditemukanSku ?: (object)[],
]);
