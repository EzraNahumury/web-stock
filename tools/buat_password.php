<?php
/**
 * buat_password.php — hasilkan hash password untuk akun aplikasi.
 *
 * Dipakai sebelum aplikasi dipakai sungguhan, terutama sebelum deploy:
 * skema mengirim akun awal "admin" dengan password "admin123", dan itu
 * tidak boleh dibiarkan hidup di server yang bisa diakses publik.
 *
 * Jalankan:
 *   php tools\buat_password.php "PasswordBaruAnda"
 *
 * Lalu jalankan SQL yang dicetaknya di phpMyAdmin (lokal maupun Hostinger).
 * Password tidak pernah ikut tersimpan di berkas mana pun — hanya hashnya.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = $argv[1] ?? '';

if ($password === '') {
    fwrite(STDERR, "Pakai: php tools/buat_password.php \"PasswordBaru\"\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password minimal 8 karakter.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Gagal membuat hash.\n");
    exit(1);
}

// Kutip tunggal di-escape supaya SQL yang dicetak aman disalin apa adanya.
$hashSql = str_replace("'", "''", $hash);

echo "Hash    : $hash\n";
echo "Terbukti: " . (password_verify($password, $hash) ? 'cocok' : 'GAGAL') . "\n\n";
echo "-- Ganti password akun admin:\n";
echo "UPDATE users SET password_hash = '$hashSql' WHERE username = 'admin';\n\n";
echo "-- Atau tambah pengguna baru (ganti username & nama):\n";
echo "INSERT INTO users (username, password_hash, nama_lengkap, role)\n";
echo "VALUES ('operator1', '$hashSql', 'Nama Lengkap', 'operator');\n";
