<?php
/**
 * buat_migrasi_reset.php — tulis migrasi pendataan ulang dari isi master
 * yang sedang berjalan.
 *
 * Dipakai setelah tools/reset_data.php dijalankan dan hasilnya sudah
 * diverifikasi tools/verifikasi_master.php. Isi master_barang yang sudah
 * terbukti benar itulah yang dituangkan menjadi berkas migrasi, bukan
 * berkas XLSX-nya dibaca ulang — supaya yang sampai ke server persis apa
 * yang sudah diperiksa, bukan hasil pembacaan kedua yang bisa berbeda.
 *
 * MIGRASI INI MENGHAPUS DATA
 * Berbeda dengan migrasi lain yang hanya mengubah struktur. Karena itu
 * berkasnya diberi penjaga "@lewati-jika-jumlah": bila master_barang sudah
 * berisi sejumlah baris yang dituju, migrasinya dilewati. Tabel `migrasi`
 * sudah mencegah pengulangan, tapi migrasi yang menghapus data tidak boleh
 * bergantung pada satu baris catatan — database yang dipulihkan dari
 * cadangan bisa kehilangan catatan itu sementara datanya sudah masuk.
 *
 * Jalankan:
 *   php tools\buat_migrasi_reset.php 012 "OKTOBER 2026"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Alat ini hanya untuk dijalankan dari terminal.');
}

require_once __DIR__ . '/../includes/db.php';

const TABEL_DIKOSONGKAN = [
    'opname_item',
    'opname_sesi',
    'pertukaran_barang',
    'retur',
    'barang_masuk',
    'barang_keluar',
    'import_batch',
    'master_barang',
];

$nomor   = $argv[1] ?? '';
$periode = $argv[2] ?? '';

if (!preg_match('/^\d{3}$/', $nomor) || $periode === '') {
    fwrite(STDERR, "Pemakaian: php tools\\buat_migrasi_reset.php <nomor 3 digit> \"PERIODE\"\n");
    exit(1);
}

$baris = dbAll(
    'SELECT sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif
       FROM master_barang
      WHERE deleted_at IS NULL
      ORDER BY nama, id'
);
if (!$baris) {
    fwrite(STDERR, "GAGAL: master_barang kosong. Jalankan tools/reset_data.php dulu.\n");
    exit(1);
}

// Kategori yang benar-benar dipakai katalog ini. Didaftarkan dengan
// INSERT IGNORE supaya dropdown di server tidak kehilangan nilai yang sudah
// dipakai data, tanpa menimpa kategori yang sudah ada di sana.
$kategori = [];
foreach ($baris as $b) {
    if ($b['kategori'] !== '') {
        $kategori[$b['kategori']] = true;
    }
}
$kategori = array_keys($kategori);
sort($kategori);

$totalUnit = 0;
$sementara = 0;
foreach ($baris as $b) {
    $totalUnit += (int)$b['stok_awal'];
    if ((int)$b['barcode_asli'] === 0) {
        $sementara++;
    }
}

$slug   = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $periode));
$slug   = trim($slug, '_');
$nama   = $nomor . '_reset_' . $slug . '.sql';
$keluar = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $nama;

$q = static function (string $s): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
};

$out  = "-- ============================================================================\n";
$out .= "-- $nama — DIGENERATE OTOMATIS, jangan diedit manual.\n";
$out .= "--\n";
$out .= "-- Pendataan ulang katalog barang, periode $periode.\n";
$out .= "--\n";
$out .= "-- MIGRASI INI MENGHAPUS DATA, bukan hanya mengubah struktur. Seluruh\n";
$out .= "-- transaksi dan katalog barang dibuang, lalu katalog diisi ulang.\n";
$out .= "--\n";
$out .= "-- Dikosongkan  : " . implode(', ', TABEL_DIKOSONGKAN) . "\n";
$out .= "-- Tidak disentuh: users, kategori, keterangan, activity_log, migrasi\n";
$out .= "--\n";
$out .= "-- Riwayat tidak punya tabelnya sendiri — dihitung dari barang_masuk dan\n";
$out .= "-- barang_keluar, jadi ikut kosong begitu keduanya dikosongkan.\n";
$out .= "--\n";
$out .= '-- Dibuat       : ' . date('Y-m-d H:i:s') . "\n";
$out .= '-- Jumlah baris : ' . count($baris) . "\n";
$out .= '-- Total unit   : ' . number_format($totalUnit) . "\n";
$out .= '-- Barcode SEMENTARA : ' . $sementara . "\n";
$out .= "--\n";
$out .= "-- Regenerasi: php tools\\buat_migrasi_reset.php $nomor \"$periode\"\n";
$out .= "-- ============================================================================\n\n";
$out .= "SET NAMES utf8mb4;\n\n";

$out .= "-- Penjaga: bila katalognya sudah sejumlah baris yang dituju, pendataan\n";
$out .= "-- ulang ini sudah terjadi dan berkasnya dilewati. Perlu karena migrasi\n";
$out .= "-- ini menghapus data — database yang dipulihkan dari cadangan bisa\n";
$out .= "-- kehilangan catatan di tabel `migrasi` sementara datanya sudah masuk.\n";
$out .= '-- @lewati-jika-jumlah: master_barang = ' . count($baris) . "\n\n";

$out .= "-- --- Kosongkan ------------------------------------------------------------\n";
foreach (TABEL_DIKOSONGKAN as $t) {
    $out .= "DELETE FROM `$t`;\n";
}
$out .= "\n";
foreach (TABEL_DIKOSONGKAN as $t) {
    $out .= "ALTER TABLE `$t` AUTO_INCREMENT = 1;\n";
}
$out .= "\n";

$out .= "-- --- Kategori yang dipakai katalog ini -----------------------------------\n";
foreach ($kategori as $k) {
    $out .= 'INSERT IGNORE INTO kategori (nama, keterangan, urutan) VALUES ('
        . $q($k) . ", 'Dipakai katalog $periode', 900);\n";
}
$out .= "\n";

$out .= "-- --- Katalog baru --------------------------------------------------------\n";
$out .= "-- Dipecah per 200 baris supaya tidak ada satu perintah raksasa: penerap\n";
$out .= "-- migrasi menjalankannya satu per satu, dan batas paket MySQL di server\n";
$out .= "-- bersama tidak selalu longgar.\n";

$kolom = '(sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif)';
$potong = [];
foreach ($baris as $b) {
    $potong[] = '  (' . $q((string)$b['sku'])
        . ', ' . $q((string)$b['barcode'])
        . ', ' . $q((string)$b['nama'])
        . ', ' . (int)$b['stok_awal']
        . ', ' . (int)$b['stok_minimal']
        . ', ' . $q((string)$b['kategori'])
        . ', ' . (int)$b['barcode_asli']
        . ', ' . (int)$b['aktif'] . ')';
}

foreach (array_chunk($potong, 200) as $kelompok) {
    $out .= "\nINSERT INTO master_barang $kolom VALUES\n" . implode(",\n", $kelompok) . ";\n";
}

file_put_contents($keluar, $out);

echo "Ditulis           : $keluar\n";
echo 'Baris katalog     : ' . count($baris) . "\n";
echo 'Total unit        : ' . number_format($totalUnit) . "\n";
echo 'Barcode SEMENTARA : ' . $sementara . "\n";
echo 'Kategori          : ' . count($kategori) . ' (' . implode(', ', $kategori) . ")\n";
echo 'Perintah INSERT   : ' . (int)ceil(count($potong) / 200) . "\n";
echo 'Ukuran            : ' . number_format(strlen($out) / 1024, 1) . " KB\n";
