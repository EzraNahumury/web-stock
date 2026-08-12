<?php
/**
 * ekspor_master.php — tulis ulang sql/002_seed_master.sql dari isi
 * master_barang yang sedang berjalan.
 *
 * Dipakai sebelum deploy. Berkas seed harus mencerminkan keadaan nyata:
 * seed hasil konversi MASTER_SEED yang pertama berisi nol semua dan tanpa
 * kategori, jadi mengimpornya ke server akan menghapus hasil impor KARTU
 * STOK tanpa disadari.
 *
 * Jalankan: C:\xampp\php\php.exe tools\ekspor_master.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$keluar = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '002_seed_master.sql';

$baris = dbAll(
    'SELECT sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif
       FROM master_barang
      WHERE deleted_at IS NULL
      ORDER BY nama, id'
);

if (!$baris) {
    fwrite(STDERR, "GAGAL: master_barang kosong. Impor 001_schema.sql dulu.\n");
    exit(1);
}

$q = static function (string $s): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
};

$totalUnit = 0;
$adaAmbang = 0;
foreach ($baris as $b) {
    $totalUnit += (int)$b['stok_awal'];
    if ((int)$b['stok_minimal'] > 0) {
        $adaAmbang++;
    }
}

$out  = "-- ============================================================================\n";
$out .= "-- 002_seed_master.sql — DIGENERATE OTOMATIS, jangan diedit manual.\n";
$out .= "--\n";
$out .= "-- Isi master_barang siap pakai: hasil konversi MASTER_SEED prototipe yang\n";
$out .= "-- sudah diperbarui dengan data nyata dari KARTU STOK AGUSTUS 2026.\n";
$out .= "--\n";
$out .= '-- Dibuat        : ' . date('Y-m-d H:i:s') . "\n";
$out .= '-- Jumlah baris  : ' . count($baris) . "\n";
$out .= '-- Total unit    : ' . number_format($totalUnit) . "\n";
$out .= '-- Punya ambang  : ' . $adaAmbang . "\n";
$out .= "--\n";
$out .= "-- Regenerasi: php tools\\ekspor_master.php\n";
$out .= "-- ============================================================================\n\n";
$out .= "SET NAMES utf8mb4;\n\n";
$out .= "-- Kosongkan dulu supaya impor ulang tidak menabrak UNIQUE barcode.\n";
$out .= "DELETE FROM master_barang;\n";
$out .= "ALTER TABLE master_barang AUTO_INCREMENT = 1;\n\n";

$kolom = '(sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif)';
$chunk = 200;

for ($i = 0; $i < count($baris); $i += $chunk) {
    $potong = array_slice($baris, $i, $chunk);
    $out .= "INSERT INTO master_barang $kolom VALUES\n";
    $nilai = [];
    foreach ($potong as $b) {
        $nilai[] = sprintf(
            '(%s,%s,%s,%d,%d,%s,%d,%d)',
            $q((string)$b['sku']),
            $q((string)$b['barcode']),
            $q((string)$b['nama']),
            (int)$b['stok_awal'],
            (int)$b['stok_minimal'],
            $q((string)$b['kategori']),
            (int)$b['barcode_asli'],
            (int)$b['aktif']
        );
    }
    $out .= implode(",\n", $nilai) . ";\n\n";
}

file_put_contents($keluar, $out);

echo "Ditulis      : $keluar\n";
echo 'Baris        : ' . count($baris) . "\n";
echo 'Total unit   : ' . number_format($totalUnit) . "\n";
echo 'Punya ambang : ' . $adaAmbang . "\n";
echo 'Ukuran       : ' . number_format(strlen($out) / 1024, 1) . " KB\n";
