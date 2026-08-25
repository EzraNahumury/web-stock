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
require_once __DIR__ . '/../../includes/izin.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibAdminApi();

$rows = dbAll('
    SELECT id, username, nama_lengkap, role, akses, aktif, last_login_at, created_at
      FROM users
     ORDER BY role, username');

$saya    = userId();
$menuSah = array_keys(menuIzin());

foreach ($rows as &$r) {
    $r['id']       = (int)$r['id'];
    $r['aktif']    = (int)$r['aktif'];
    $r['ini_saya'] = ((int)$r['id'] === $saya);

    // Daftar mentah dikirim apa adanya untuk mengisi form, ditambah daftar
    // efektifnya supaya tabel bisa menyebut apa yang benar-benar terbuka.
    $akses = ($r['akses'] === null || $r['akses'] === '') ? null : json_decode($r['akses'], true);
    $r['akses'] = is_array($akses) ? array_values(array_intersect($akses, $menuSah)) : [];

    if ($r['role'] === 'admin') {
        $r['akses_efektif'] = array_merge($menuSah, menuAdminSaja());
    } elseif ($r['akses']) {
        $r['akses_efektif'] = $r['akses'];
    } else {
        $r['akses_efektif'] = menuBawaan();
    }
    $r['boleh_tulis'] = $r['role'] !== 'viewer';
}
unset($r);

$adminAktif = (int)dbValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1");

jsonOk([
    'rows'         => $rows,
    'total'        => count($rows),
    'admin_aktif'  => $adminAktif,
    'saya'         => $saya,
    'menu_options' => menuIzin(),
    'menu_bawaan'  => menuBawaan(),
    'peran_options'=> peranIzin(),
]);
