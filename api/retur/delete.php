<?php
/**
 * POST api/retur/delete.php — hapus satu retur (soft delete).
 *
 * Baris barang masuk yang dihasilkannya ikut dihapus dalam transaksi yang
 * sama. Tanpa itu, stok akan tetap memuat barang dari retur yang sudah
 * dibatalkan dan selisihnya baru ketahuan saat stok opname.
 *
 * Body: { id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('ID retur tidak valid.');
}

$r = dbOne('SELECT * FROM retur WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($r === null) {
    jsonError('Retur tidak ditemukan.', 404);
}

$masukId = $r['masuk_id'] === null ? null : (int)$r['masuk_id'];

dbTransaksi(static function (PDO $pdo) use ($id, $masukId) {
    if ($masukId !== null) {
        $st = $pdo->prepare('UPDATE barang_masuk SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$masukId]);
    }
    $st = $pdo->prepare('UPDATE retur SET deleted_at = NOW() WHERE id = ?');
    $st->execute([$id]);
});

catatAktivitas('delete', 'retur', $id, [
    'nama'       => $r['nama'],
    'barcode'    => $r['barcode'],
    'jumlah'     => (int)$r['jumlah'],
    'no_pesanan' => $r['no_pesanan'],
    'status'     => $r['status'],
]);

jsonOk([
    'pesan' => $masukId !== null
        ? 'Retur dihapus, dan barang masuk yang dihasilkannya ikut dibatalkan.'
        : 'Retur dihapus.',
]);
