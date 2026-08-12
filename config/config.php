<?php
/**
 * config/config.php — konstanta aplikasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

date_default_timezone_set('Asia/Jakarta');

define('APP_NAMA', 'Stok Fingertape & Perlengkapan');
define('APP_VERSI', '1.0.0');

// Jumlah baris per halaman (mengikuti PAGE_SIZE prototipe).
define('PAGE_SIZE', 50);

// Ambang penyangga status "menipis": stok_akhir <= stok_minimal * 1.3
define('AMBANG_RENDAH', 1.3);

// Pilihan dropdown — harus sama persis dengan yang ada di assets/js/app.js.
define('KATEGORI_OPTIONS', ['FISIO', 'FOX', 'AVO', 'AYRES', 'AC', 'LAINNYA']);
define('KET_MASUK',  ['Barang Baru', 'Restock', 'Retur Masuk', 'Lainnya']);
define('KET_KELUAR', ['Pesanan MP', 'Retur', 'Rusak / Reject', 'Lainnya']);

// Boleh mencatat barang keluar melebihi stok tersedia? (audit D3)
// false = tolak; true = izinkan tapi tetap beri peringatan di respons.
define('IZINKAN_STOK_MINUS', false);

// Umur sesi tidak aktif sebelum logout otomatis (detik).
define('SESI_TIMEOUT', 8 * 3600);

// Tampilkan galat sesuai lingkungan.
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
