<?php
/**
 * POST api/master/delete.php — hapus master barang (soft delete, audit F2).
 *
 * Data tidak dibuang dari database, hanya ditandai deleted_at. Transaksi
 * yang sudah terlanjur menunjuk barang ini tetap punya jejak nama & barcode
 * historisnya, jadi riwayat stok tidak rusak.
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
    jsonError('ID barang tidak valid.');
}

$m = dbOne('SELECT * FROM master_barang WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($m === null) {
    jsonError('Barang tidak ditemukan.', 404);
}

// Peringatkan bila barang masih punya riwayat transaksi.
$jmlMasuk  = (int)dbValue('SELECT COUNT(*) FROM barang_masuk  WHERE master_id = ? AND deleted_at IS NULL', [$id]);
$jmlKeluar = (int)dbValue('SELECT COUNT(*) FROM barang_keluar WHERE master_id = ? AND deleted_at IS NULL', [$id]);

dbExec('UPDATE master_barang SET deleted_at = NOW() WHERE id = ?', [$id]);

catatAktivitas('delete', 'master', $id, [
    'nama'          => $m['nama'],
    'barcode'       => $m['barcode'],
    'punya_riwayat' => $jmlMasuk + $jmlKeluar,
]);

$pesan = 'Barang dihapus.';
if ($jmlMasuk + $jmlKeluar > 0) {
    $pesan = 'Barang dihapus. Riwayat transaksinya (' . ($jmlMasuk + $jmlKeluar) . ') tetap tersimpan.';
}

jsonOk(['pesan' => $pesan, 'punya_riwayat' => $jmlMasuk + $jmlKeluar]);
