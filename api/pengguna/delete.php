<?php
/**
 * POST api/pengguna/delete.php — hapus akun pengguna.
 *
 * Berbeda dari master barang dan transaksi, akun dihapus permanen: tabel
 * users tidak punya kolom deleted_at, dan menyimpan akun mati hanya
 * memperbesar kemungkinan salah pakai. Untuk menutup akses sementara,
 * pakai tombol nonaktif — bukan hapus.
 *
 * Penjaga: tidak bisa menghapus diri sendiri, dan tidak bisa menghapus
 * admin aktif terakhir. Jejak transaksinya tetap utuh karena kolom user_id
 * di tabel transaksi memakai ON DELETE SET NULL.
 *
 * Body: { id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('ID pengguna tidak valid.');
}

$u = dbOne('SELECT * FROM users WHERE id = ?', [$id]);
if ($u === null) {
    jsonError('Pengguna tidak ditemukan.', 404);
}

if ($id === userId()) {
    jsonError('Tidak bisa menghapus akun sendiri.', 409);
}

if ($u['role'] === 'admin' && (int)$u['aktif'] === 1) {
    $adminAktif = (int)dbValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND aktif = 1");
    if ($adminAktif <= 1) {
        jsonError('Ini satu-satunya admin yang aktif dan tidak bisa dihapus.', 409);
    }
}

// Berapa jejak yang akan kehilangan penanda pencatatnya.
$jejak = (int)dbValue('SELECT COUNT(*) FROM barang_masuk  WHERE user_id = ?', [$id])
       + (int)dbValue('SELECT COUNT(*) FROM barang_keluar WHERE user_id = ?', [$id])
       + (int)dbValue('SELECT COUNT(*) FROM import_batch  WHERE user_id = ?', [$id]);

dbExec('DELETE FROM users WHERE id = ?', [$id]);

catatAktivitas('delete', 'pengguna', $id, [
    'username' => $u['username'], 'role' => $u['role'], 'jejak_transaksi' => $jejak,
]);

$pesan = 'Akun "' . $u['username'] . '" dihapus.';
if ($jejak > 0) {
    $pesan .= ' ' . $jejak . ' catatan transaksinya tetap tersimpan tanpa nama pencatat.';
}

jsonOk(['pesan' => $pesan, 'jejak' => $jejak]);
