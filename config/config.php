<?php
/**
 * config/config.php — konstanta aplikasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

date_default_timezone_set('Asia/Jakarta');

define('APP_NAMA', 'Warehouse AVA');
define('APP_VERSI', '1.0.0');

// Jumlah baris per halaman (mengikuti PAGE_SIZE prototipe).
define('PAGE_SIZE', 50);

// Ambang penyangga status "menipis": stok_akhir <= stok_minimal * 1.3
define('AMBANG_RENDAH', 1.3);

// Pilihan dropdown — harus sama persis dengan yang ada di assets/js/app.js.
//
// Daftar ini diambil dari kategori yang BENAR-BENAR dipakai di berkas
// "KARTU STOK AGUSTUS 2026". Daftar lama prototipe (FISIO, FOX, AVO, AYRES,
// AC, LAINNYA) hanya cocok 3 dari 10: SAIFENU, FASHION, JERSEY, ACC, GYM,
// AOLIKES, dan TRAINING tidak ada di sana, sementara FOX tidak pernah
// dipakai sebagai kategori (produk FOX masuk FASHION/JERSEY).
define('KATEGORI_OPTIONS', [
    'ACC',       //   51 item
    'AOLIKES',   //   18
    'AVO',       //  130
    'AYRES',     //  687
    'FASHION',   //  176
    'FISIO',     //   33
    'GYM',       //   23
    'JERSEY',    //   89
    'SAIFENU',   //  181
    'TRAINING',  //   15
    'LAINNYA',   // penampung
]);
define('KET_MASUK',  ['Barang Baru', 'Restock', 'Retur Masuk', 'Lainnya']);
define('KET_KELUAR', ['Pesanan MP', 'Retur', 'Rusak / Reject', 'Lainnya']);

// Status retur. Hanya "Lengkap" yang mengembalikan barang ke stok; sisanya
// belum bisa diproses, jadi stoknya belum boleh ikut bertambah.
define('STATUS_RETUR', ['Lengkap', 'Sistem Belum Selesai']);
define('STATUS_RETUR_MASUK', 'Lengkap');

// Keterangan yang dipakai baris barang_masuk hasil retur. Harus salah satu
// dari KET_MASUK supaya lolos validasi yang sama dengan input manual.
define('KET_RETUR_MASUK', 'Retur Masuk');

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
