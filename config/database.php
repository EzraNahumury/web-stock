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
 * Dicari berurutan; yang pertama ketemu dipakai, sisa berkas ini dilewati:
 *
 *   1. SATU TINGKAT DI ATAS public_html  -> gudang-config.php
 *      Paling aman untuk deploy lewat Git. Folder public_html ditimpa
 *      setiap kali deploy berjalan; berkas di atasnya tidak tersentuh,
 *      dan tidak bisa diakses lewat web sama sekali.
 *
 *   2. config/database.local.php
 *      Untuk unggah manual lewat FTP/File Manager. Sudah masuk .gitignore.
 *
 * Keduanya di luar repositori, jadi kredensial produksi tidak pernah ikut
 * ter-commit dan `git pull` tidak pernah menimpanya.
 */
$sumberKredensial = [
    dirname(__DIR__, 2) . '/gudang-config.php',   // di atas public_html
    __DIR__ . '/database.local.php',
];
foreach ($sumberKredensial as $berkas) {
    if (is_file($berkas)) {
        require $berkas;
        return;
    }
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
    // Ditandai "belum-diatur", bukan "produksi": nilai di atas cuma
    // placeholder. Tanpa penanda ini, aplikasi mencoba menyambung memakai
    // "GANTI_NAMA_DATABASE" lalu gagal dengan pesan yang tidak menjelaskan
    // apa pun — persis kasus yang membuat berkas kredensial tak terunggah
    // sulit dibedakan dari password yang salah.
    define('APP_ENV', 'belum-diatur');
}
