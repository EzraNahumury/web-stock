<?php
/**
 * config/database.php — kredensial database, deteksi lingkungan otomatis.
 *
 * Satu berkas untuk lokal (XAMPP) dan produksi (Hostinger). Tidak perlu
 * mengubah isinya saat deploy — deteksi berdasarkan host permintaan.
 *
 * PENTING: berkas ini berisi rahasia. Pastikan .htaccess memblokir akses
 * langsung ke folder config/, dan jangan pernah meng-commit kredensial
 * produksi yang sebenarnya ke repositori publik.
 */

declare(strict_types=1);

/*
 * Kredensial nyata TIDAK ditaruh di berkas ini.
 *
 * Bila ada config/database.local.php, berkas itulah yang dipakai dan sisa
 * berkas ini dilewati. Berkas .local sudah masuk .gitignore, jadi:
 *   - kredensial produksi tidak pernah ikut ter-commit ke repositori publik
 *   - `git pull` di server tidak menimpa kredensial yang sudah diisi
 *
 * Di server cukup unggah config/database.local.php sekali, lalu berkas ini
 * boleh diperbarui bebas lewat git tanpa menyentuh rahasia apa pun.
 */
if (is_file(__DIR__ . '/database.local.php')) {
    require __DIR__ . '/database.local.php';
    return;
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? 'cli');

$isLokal = $host === 'cli'
    || $host === 'localhost'
    || $host === '127.0.0.1'
    || strpos($host, 'localhost:') === 0
    || strpos($host, '127.0.0.1:') === 0
    || strpos($host, '192.168.') === 0;

if ($isLokal) {
    // ---- XAMPP (pengembangan) ----
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'web_stock');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('APP_DEBUG', true);
    define('APP_ENV', 'lokal');
} else {
    // ---- Hostinger (produksi) ----
    // Isi dari hPanel -> Databases -> Management. Nama berawalan uXXXXXX_.
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'GANTI_NAMA_DATABASE');
    define('DB_USER', 'GANTI_USER_DATABASE');
    define('DB_PASS', 'GANTI_PASSWORD_DATABASE');
    define('APP_DEBUG', false);
    define('APP_ENV', 'produksi');
}
