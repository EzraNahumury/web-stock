<?php
/**
 * POST api/pengguna/save.php — tambah atau ubah akun pengguna.
 *
 * Penjaga yang wajib ada, semuanya diperiksa di sisi server:
 *   - hanya admin yang boleh memanggil endpoint ini
 *   - password disimpan sebagai hash, tidak pernah sebagai teks biasa
 *   - username unik dan formatnya dibatasi
 *   - admin aktif terakhir tidak boleh diturunkan perannya atau dinonaktifkan
 *     — kalau itu terjadi, tidak ada lagi yang bisa mengelola aplikasi
 *   - admin tidak bisa menonaktifkan atau menurunkan dirinya sendiri
 *
 * Body: { id?, username, nama_lengkap, role, aktif, password?, akses? }
 * Saat mengubah, password kosong berarti "jangan ganti".
 *
 * `akses` adalah daftar id menu yang boleh dibuka akun ini. Array kosong
 * atau tidak dikirim berarti "menu bawaan" — lihat includes/izin.php.
 * Peran admin selalu punya seluruh menu, jadi aksesnya tidak disimpan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/izin.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();

$in = jsonInput();
wajibCsrf($in);

$id       = ambilInt($in, 'id', 0);
$username = mb_strtolower(ambilStr($in, 'username', 50));
$nama     = ambilStr($in, 'nama_lengkap', 100);
$role     = ambilStr($in, 'role', 10);
$aktif    = !empty($in['aktif']) ? 1 : 0;
$password = (string)($in['password'] ?? '');

/* --------------------------- Hak akses ------------------------------ */
// Hanya id menu yang benar-benar dikenal yang disimpan. Nilai asing dibuang
// diam-diam, bukan ditolak: yang penting kolomnya tidak pernah memuat
// sesuatu yang bisa membuka menu di luar daftar.
$menuSah = array_keys(menuIzin());
$akses   = [];
if (isset($in['akses']) && is_array($in['akses'])) {
    foreach ($in['akses'] as $m) {
        if (is_string($m) && in_array($m, $menuSah, true) && !in_array($m, $akses, true)) {
            $akses[] = $m;
        }
    }
}
// Admin selalu punya semua menu, jadi menyimpan daftarnya hanya akan
// menyesatkan saat perannya kelak diturunkan.
$aksesJson = ($role === 'admin' || !$akses) ? null : json_encode(array_values($akses));

/* ------------------------- Validasi dasar --------------------------- */
if ($username === '' || $nama === '') {
    jsonError('Username dan nama lengkap wajib diisi.');
}
if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
    jsonError('Username 3-50 karakter, hanya huruf kecil, angka, titik, - dan _.');
}
if (!in_array($role, array_keys(peranIzin()), true)) {
    jsonError('Peran tidak dikenal.');
}

$bentrok = dbOne('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $id]);
if ($bentrok !== null) {
    jsonError('Username "' . $username . '" sudah dipakai.');
}

$adminAktif = (int)dbValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1");

/* ----------------------------- Ubah --------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM users WHERE id = ?', [$id]);
    if ($lama === null) {
        jsonError('Pengguna tidak ditemukan.', 404);
    }

    $iniSaya      = ($id === userId());
    $adminSemula  = ($lama['role'] === 'admin' && (int)$lama['aktif'] === 1);
    $jadiBukanAdmin = ($role !== 'admin' || $aktif === 0);

    // Jangan sampai aplikasi kehilangan admin terakhirnya.
    if ($adminSemula && $jadiBukanAdmin && $adminAktif <= 1) {
        jsonError(
            'Ini satu-satunya admin yang aktif. Angkat admin lain dulu sebelum '
            . 'mengubah peran atau menonaktifkan akun ini.',
            409
        );
    }
    // Mengunci diri sendiri di luar aplikasi.
    if ($iniSaya && $jadiBukanAdmin) {
        jsonError('Tidak bisa menurunkan peran atau menonaktifkan akun sendiri.', 409);
    }

    if ($password !== '') {
        if (strlen($password) < 8) {
            jsonError('Password minimal 8 karakter.');
        }
        dbExec(
            'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, aktif = ?, akses = ?,
                    password_hash = ?
              WHERE id = ?',
            [$username, $nama, $role, $aktif, $aksesJson,
             password_hash($password, PASSWORD_DEFAULT), $id]
        );
    } else {
        dbExec(
            'UPDATE users SET username = ?, nama_lengkap = ?, role = ?, aktif = ?, akses = ?
              WHERE id = ?',
            [$username, $nama, $role, $aktif, $aksesJson, $id]
        );
    }

    catatAktivitas('update', 'pengguna', $id, [
        'username'      => $username,
        'role'          => $role,
        'aktif'         => $aktif,
        'akses'         => $akses ? implode(', ', $akses) : 'bawaan',
        'ganti_sandi'   => $password !== '',
        'sebelum'       => ['username' => $lama['username'], 'role' => $lama['role'], 'aktif' => (int)$lama['aktif']],
    ]);

    jsonOk([
        'id'    => $id,
        'pesan' => $password !== ''
            ? 'Akun tersimpan, password diganti.'
            : 'Akun tersimpan.',
    ]);
}

/* ---------------------------- Tambah -------------------------------- */
if (strlen($password) < 8) {
    jsonError('Password minimal 8 karakter untuk akun baru.');
}

dbExec(
    'INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$username, password_hash($password, PASSWORD_DEFAULT), $nama, $role, $aktif, $aksesJson]
);
$baruId = dbLastId();

catatAktivitas('create', 'pengguna', $baruId, [
    'username' => $username,
    'role'     => $role,
    'akses'    => $akses ? implode(', ', $akses) : 'bawaan',
]);

jsonOk(['id' => $baruId, 'pesan' => 'Akun "' . $username . '" dibuat.']);
