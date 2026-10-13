<?php
/**
 * POST api/pertukaran/delete.php — hapus satu catatan pertukaran barang.
 *
 * Body: { id }
 *
 * CATATAN, BUKAN PENGGERAK STOK
 * Baris pertukaran hanya menerangkan bahwa barang yang dipesan diganti barang
 * lain. Yang menggeser stok adalah baris barang keluarnya, dan baris itu tidak
 * disentuh di sini — menghapus catatannya tidak mengubah angka stok mana pun.
 *
 * Penghapusannya lunak, sama seperti transaksi: barisnya ditandai, bukan
 * dibuang, jadi jejaknya tetap ada bila kelak perlu ditelusuri.
 *
 * Dipakai untuk membersihkan catatan yang memang salah dicatat — misalnya
 * percobaan pertukaran manual yang tidak pernah benar-benar terjadi.
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
    jsonError('ID pertukaran tidak valid.');
}

$t = dbOne('SELECT * FROM pertukaran_barang WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($t === null) {
    jsonError('Catatan pertukaran tidak ditemukan.', 404);
}

dbExec('UPDATE pertukaran_barang SET deleted_at = NOW() WHERE id = ?', [$id]);

catatAktivitas('delete', 'pertukaran', $id, [
    'dari'       => $t['nama_lama'],
    'ke'         => $t['nama_baru'],
    'jumlah'     => (int)$t['jumlah'],
    'no_pesanan' => $t['no_pesanan'],
]);

jsonOk([
    'pesan' => 'Catatan pertukaran dihapus. Stok tidak berubah — '
             . 'yang menggeser stok adalah barang keluarnya, dan itu tetap utuh.',
]);
