<?php
/**
 * GET api/pengguna/list.php — daftar akun yang bisa masuk ke aplikasi.
 *
 * Hash password TIDAK PERNAH dikirim ke klien, bahkan untuk admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibAdminApi();

$rows = dbAll('
    SELECT id, username, nama_lengkap, role, aktif, last_login_at, created_at
      FROM users
     ORDER BY role, username');

$saya = userId();
foreach ($rows as &$r) {
    $r['id']       = (int)$r['id'];
    $r['aktif']    = (int)$r['aktif'];
    $r['ini_saya'] = ((int)$r['id'] === $saya);
}
unset($r);

$adminAktif = (int)dbValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1");

jsonOk([
    'rows'        => $rows,
    'total'       => count($rows),
    'admin_aktif' => $adminAktif,
    'saya'        => $saya,
]);
