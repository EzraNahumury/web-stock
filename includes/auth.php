<?php
/**
 * includes/auth.php — sesi, login, dan proteksi CSRF.
 *
 * Memperbaiki audit B2/S1: prototipe tidak punya autentikasi sama sekali.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

/**
 * Mulai sesi dengan cookie yang aman.
 */
function mulaiSesi(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,       // tidak bisa dibaca JavaScript
        'secure'   => $https,     // hanya lewat HTTPS di produksi
        'samesite' => 'Lax',      // redam CSRF lintas situs
    ]);
    session_name('GUDANGSESS');
    session_start();

    // Logout otomatis setelah tidak aktif.
    if (isset($_SESSION['terakhir_aktif']) && (time() - $_SESSION['terakhir_aktif']) > SESI_TIMEOUT) {
        logout();
    }
    $_SESSION['terakhir_aktif'] = time();
}

function sudahLogin(): bool
{
    mulaiSesi();
    return !empty($_SESSION['user_id']);
}

function userSaatIni(): ?array
{
    if (!sudahLogin()) {
        return null;
    }
    require_once __DIR__ . '/izin.php';
    $db = userDb();

    // Peran dan hak akses diambil dari database, bukan dari isi sesi:
    // penurunan peran atau pencabutan akses harus berlaku pada permintaan
    // berikutnya, bukan menunggu orangnya logout. Nama dipakai dari sesi
    // sebagai cadangan bila barisnya sudah tidak ada.
    return [
        'id'           => (int)$_SESSION['user_id'],
        'username'     => (string)($db['username'] ?? ($_SESSION['username'] ?? '')),
        'nama_lengkap' => (string)($db['nama_lengkap'] ?? ($_SESSION['nama_lengkap'] ?? '')),
        'role'         => (string)($db['role'] ?? ($_SESSION['role'] ?? 'operator')),
        'akses'        => aksesSaya(),
        'boleh_tulis'  => bolehTulis(),
    ];
}

function userId(): ?int
{
    $u = userSaatIni();
    return $u ? $u['id'] : null;
}

function adalahAdmin(): bool
{
    $u = userSaatIni();
    return $u !== null && $u['role'] === 'admin';
}

/**
 * Untuk endpoint API: balas 401 JSON bila sesi kosong.
 */
function wajibLoginApi(): void
{
    if (!sudahLogin()) {
        require_once __DIR__ . '/response.php';
        jsonError('Sesi berakhir. Silakan login ulang.', 401);
    }
    // Pemeriksaan hak akses ditaruh di sini, bukan di tiap endpoint: setiap
    // endpoint API sudah memanggil fungsi ini, jadi endpoint baru ikut
    // terjaga tanpa perlu diingat satu per satu.
    require_once __DIR__ . '/izin.php';
    periksaIzinApi();
}

/**
 * Untuk halaman biasa: alihkan ke login.
 */
function wajibLoginHalaman(): void
{
    if (!sudahLogin()) {
        header('Location: login.php');
        exit;
    }
    // Akun yang dinonaktifkan atau dihapus saat sesinya masih hidup tidak
    // boleh tetap membuka halaman. Tanpa ini, aksesnya baru benar-benar
    // tertutup setelah orangnya logout sendiri.
    require_once __DIR__ . '/izin.php';
    $u = userDb();
    if ($u === null || (int)$u['aktif'] !== 1) {
        logout();
        header('Location: login.php');
        exit;
    }
}

/**
 * Untuk aksi yang hanya boleh admin (mis. hapus permanen, kelola user).
 */
function wajibAdminApi(): void
{
    wajibLoginApi();
    if (!adalahAdmin()) {
        require_once __DIR__ . '/response.php';
        jsonError('Aksi ini hanya untuk admin.', 403);
    }
}

/**
 * Verifikasi kredensial. Mengembalikan data user, atau null bila gagal.
 *
 * Sengaja tidak membedakan pesan "username salah" dan "password salah" —
 * pembedaan itu membocorkan username mana yang terdaftar.
 */
function cobaLogin(string $username, string $password): ?array
{
    $u = dbOne(
        'SELECT id, username, password_hash, nama_lengkap, role, aktif
           FROM users WHERE username = ? LIMIT 1',
        [$username]
    );

    // Jalankan verifikasi palsu bila user tidak ada, supaya waktu respons
    // tidak membocorkan keberadaan username (timing attack).
    if ($u === null) {
        password_verify($password, '$2y$10$usermissingusermissingusermissingusermissingusermissingus');
        return null;
    }

    if ((int)$u['aktif'] !== 1) {
        return null;
    }

    if (!password_verify($password, $u['password_hash'])) {
        return null;
    }

    // Perbarui hash bila algoritma/biaya default sudah berubah.
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        dbExec('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash($password, PASSWORD_DEFAULT),
            $u['id'],
        ]);
    }

    mulaiSesi();
    session_regenerate_id(true);   // cegah session fixation

    $_SESSION['user_id']      = (int)$u['id'];
    $_SESSION['username']     = $u['username'];
    $_SESSION['nama_lengkap'] = $u['nama_lengkap'];
    $_SESSION['role']         = $u['role'];

    dbExec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    catatAktivitas('login', 'auth', (int)$u['id'], ['username' => $u['username']]);

    return userSaatIni();
}

function logout(): void
{
    mulaiSesi();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* -------------------------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------------------- */

function csrfToken(): string
{
    mulaiSesi();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Wajib dipanggil di SETIAP endpoint yang mengubah data.
 * Token dibaca dari header X-CSRF-Token atau field _csrf pada body.
 */
function wajibCsrf(?array $body = null): void
{
    mulaiSesi();
    $dikirim = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ($_POST['_csrf'] ?? ''));
    $asli    = $_SESSION['csrf_token'] ?? '';

    if ($asli === '' || !is_string($dikirim) || !hash_equals($asli, $dikirim)) {
        require_once __DIR__ . '/response.php';
        jsonError('Token keamanan tidak valid. Muat ulang halaman.', 419);
    }
}

/* -------------------------------------------------------------------------
 * Jejak audit (audit F4)
 * ---------------------------------------------------------------------- */

function catatAktivitas(string $aksi, string $entitas, ?int $entitasId = null, array $detail = []): void
{
    try {
        dbExec(
            'INSERT INTO activity_log (user_id, aksi, entitas, entitas_id, detail, ip)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                userId(),
                $aksi,
                $entitas,
                $entitasId,
                $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]
        );
    } catch (Throwable $e) {
        // Kegagalan mencatat log tidak boleh menggagalkan operasi utama.
        error_log('Gagal mencatat aktivitas: ' . $e->getMessage());
    }
}
