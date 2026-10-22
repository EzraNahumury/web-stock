<?php
/**
 * POST api/deadstok/delete.php — hapus satu catatan deadstok.
 *
 * Body: { id }
 *
 * Khusus admin (lewat bendera hapus di petaEndpoint). Catatan yang sudah
 * berstatus "dibuang" memegang baris barang keluar, jadi menghapusnya
 * menggeser stok — dan yang terhapus tidak terlihat lagi di layar mana pun,
 * sehingga salah hapus baru ketahuan saat stok opname.
 *
 * Baris barang keluarnya ikut dihapus dalam transaksi yang sama. Tanpa itu
 * stok tetap berkurang sementara catatan yang menerangkan pengurangannya
 * sudah hilang — pengurangan tanpa sebab yang bisa dibaca.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/deadstok.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('Id tidak valid.');
}

$row = dbOne('SELECT * FROM deadstok WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($row === null) {
    jsonError('Catatan deadstok tidak ditemukan.', 404);
}

$keluarId = $row['keluar_id'] === null ? null : (int)$row['keluar_id'];
$ikutStok = (string)$row['status'] === 'dibuang' && $keluarId !== null;

dbTransaksi(static function (PDO $pdo) use ($id, $keluarId, $ikutStok) {
    if ($ikutStok) {
        $st = $pdo->prepare(
            'UPDATE barang_keluar SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $st->execute([$keluarId]);
    }
    $st = $pdo->prepare('UPDATE deadstok SET deleted_at = NOW() WHERE id = ?');
    $st->execute([$id]);
});

catatAktivitas('delete', 'deadstok', $id, [
    'nama'     => $row['nama'],
    'jumlah'   => (int)$row['jumlah'],
    'kategori' => $row['kategori'],
    'status'   => $row['status'],
]);

$peringatan = [];
if ($ikutStok) {
    $peringatan[] = 'Stok kembali bertambah ' . (int)$row['jumlah'] . ' pcs, karena baris barang '
        . 'keluar hasil pembuangannya ikut dihapus.';
} elseif ((string)$row['status'] === 'tertahan') {
    $peringatan[] = (int)$row['jumlah'] . ' pcs kembali terhitung siap jual.';
}

jsonOk([
    'peringatan' => $peringatan,
    'pesan'      => 'Catatan deadstok dihapus.',
]);
