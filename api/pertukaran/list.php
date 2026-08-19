<?php
/**
 * GET api/pertukaran/list.php — riwayat pertukaran produk saat impor PDF.
 *
 * Setiap kali admin mengganti barcode atau SKU sebuah baris di tabel review
 * lalu menyimpannya, stok yang dipotong berpindah ke produk lain. Halaman
 * ini menyimpan jejaknya supaya perpindahan itu bisa ditelusuri kemudian.
 *
 * Parameter: q, dari, sampai, page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q      = ambilStr($_GET, 'q', 100);
$dari   = trim((string)($_GET['dari'] ?? ''));
$sampai = trim((string)($_GET['sampai'] ?? ''));
$page   = ambilHalaman();

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(t.barcode_lama LIKE ? OR t.nama_lama LIKE ? OR t.sku_lama LIKE ?
                 OR t.barcode_baru LIKE ? OR t.nama_baru LIKE ? OR t.sku_baru LIKE ?
                 OR t.no_pesanan LIKE ?)';
    $pola = polaLike($q);
    for ($i = 0; $i < 7; $i++) {
        $params[] = $pola;
    }
}
if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
    $where[] = 't.tanggal >= ?';
    $params[] = $dari;
}
if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
    $where[] = 't.tanggal <= ?';
    $params[] = $sampai;
}
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total = (int)dbValue("SELECT COUNT(*) FROM pertukaran_barang t $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT t.*, u.nama_lengkap AS oleh
       FROM pertukaran_barang t
       LEFT JOIN users u ON u.id = t.user_id
     $sqlWhere
     ORDER BY t.tanggal DESC, t.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']             = (int)$r['id'];
    $r['jumlah']         = (int)$r['jumlah'];
    $r['master_id_baru'] = $r['master_id_baru'] === null ? null : (int)$r['master_id_baru'];
    unset($r['user_id'], $r['batch_id'], $r['keluar_id']);
}
unset($r);

$totalUnit = (int)dbValue("SELECT COALESCE(SUM(jumlah),0) FROM pertukaran_barang t $sqlWhere", $params);

jsonOk([
    'rows'       => $rows,
    'total_unit' => $totalUnit,
] + $meta);
