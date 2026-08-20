<?php
/**
 * POST api/opname/delete.php — hapus sesi stok opname (soft delete).
 *
 * Barisnya tidak ikut dihapus: bila sesi dipulihkan lewat database, hasil
 * hitungan fisiknya masih utuh. Sesi yang terhapus tidak lagi muncul di
 * mana pun karena seluruh query menyaring deleted_at.
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
    jsonError('ID sesi tidak valid.');
}

$s = dbOne('SELECT * FROM opname_sesi WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($s === null) {
    jsonError('Sesi opname tidak ditemukan.', 404);
}

dbExec('UPDATE opname_sesi SET deleted_at = NOW() WHERE id = ?', [$id]);

catatAktivitas('delete', 'opname', $id, ['nama' => $s['nama'], 'periode' => $s['periode']]);

jsonOk(['pesan' => 'Sesi opname dihapus.']);
