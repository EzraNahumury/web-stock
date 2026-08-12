<?php
/**
 * GET api/master/riwayat.php — riwayat transaksi satu barang.
 *
 * Dipakai popup yang muncul saat angka MASUK / KELUAR di dashboard diklik.
 *
 * Parameter:
 *   master_id  wajib
 *   jenis      masuk | keluar
 *   page       opsional
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$masterId = ambilInt($_GET, 'master_id', 0);
$jenis    = ambilStr($_GET, 'jenis', 10);
$page     = ambilHalaman();

if ($masterId <= 0) {
    jsonError('ID barang tidak valid.');
}
if (!in_array($jenis, ['masuk', 'keluar'], true)) {
    jsonError('Jenis transaksi tidak dikenal.');
}

$item = dbOne(
    'SELECT id, sku, barcode, nama, stok_awal, stok_minimal, kategori
       FROM master_barang WHERE id = ? AND deleted_at IS NULL',
    [$masterId]
);
if ($item === null) {
    jsonError('Barang tidak ditemukan.', 404);
}

$tabel   = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';
$isKel   = $jenis === 'keluar';
$perPage = 25;   // popup, bukan halaman penuh

$total = (int)dbValue(
    "SELECT COUNT(*) FROM $tabel WHERE master_id = ? AND deleted_at IS NULL",
    [$masterId]
);
$totalJumlah = (int)dbValue(
    "SELECT COALESCE(SUM(jumlah), 0) FROM $tabel WHERE master_id = ? AND deleted_at IS NULL",
    [$masterId]
);

$meta   = metaPaginasi($total, $page, $perPage);
$offset = ($meta['page'] - 1) * $perPage;

// Untuk barang keluar, sertakan asal impor PDF-nya bila ada.
$kolomExtra = $isKel ? ', t.no_pesanan, t.batch_id, b.no_picking, b.nama_file' : '';
$joinExtra  = $isKel ? ' LEFT JOIN import_batch b ON b.id = t.batch_id' : '';

$rows = dbAll(
    "SELECT t.id, t.tanggal, t.jumlah, t.keterangan, t.created_at,
            u.nama_lengkap AS oleh $kolomExtra
       FROM $tabel t
       LEFT JOIN users u ON u.id = t.user_id
       $joinExtra
      WHERE t.master_id = ? AND t.deleted_at IS NULL
      ORDER BY t.tanggal DESC, t.id DESC
      LIMIT $perPage OFFSET $offset",
    [$masterId]
);

foreach ($rows as &$r) {
    $r['id']     = (int)$r['id'];
    $r['jumlah'] = (int)$r['jumlah'];
    if (array_key_exists('batch_id', $r)) {
        $r['batch_id'] = $r['batch_id'] === null ? null : (int)$r['batch_id'];
    }
}
unset($r);

// Ringkasan per keterangan — memperlihatkan komposisi tanpa perlu
// menelusuri seluruh halaman.
$perKet = dbAll(
    "SELECT keterangan, COUNT(*) AS jml, SUM(jumlah) AS unit
       FROM $tabel WHERE master_id = ? AND deleted_at IS NULL
      GROUP BY keterangan ORDER BY unit DESC",
    [$masterId]
);
foreach ($perKet as &$k) {
    $k['jml']  = (int)$k['jml'];
    $k['unit'] = (int)$k['unit'];
}
unset($k);

jsonOk([
    'jenis'        => $jenis,
    'item'         => [
        'id'           => (int)$item['id'],
        'sku'          => $item['sku'],
        'barcode'      => $item['barcode'],
        'nama'         => $item['nama'],
        'stok_awal'    => (int)$item['stok_awal'],
        'stok_minimal' => (int)$item['stok_minimal'],
        'kategori'     => $item['kategori'],
    ],
    'rows'         => $rows,
    'total_jumlah' => $totalJumlah,
    'per_keterangan' => $perKet,
] + $meta);
