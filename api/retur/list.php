<?php
/**
 * GET api/retur/list.php — daftar retur barang.
 *
 * Parameter: q, dari, sampai, status, page
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
$status = ambilStr($_GET, 'status', 30);
$page   = ambilHalaman();

$where  = ['r.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = '(r.nama LIKE ? OR r.barcode LIKE ? OR r.sku LIKE ? OR r.no_pesanan LIKE ? OR r.keterangan LIKE ?)';
    $pola = polaLike($q);
    for ($i = 0; $i < 5; $i++) {
        $params[] = $pola;
    }
}
if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
    $where[] = 'r.tanggal >= ?';
    $params[] = $dari;
}
if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
    $where[] = 'r.tanggal <= ?';
    $params[] = $sampai;
}
if ($status !== '' && in_array($status, STATUS_RETUR, true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue("SELECT COUNT(*) FROM retur r $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT r.id, r.tanggal, r.no_pesanan, r.master_id, r.barcode, r.sku, r.nama,
            r.jumlah, r.status, r.keterangan, r.masuk_id, r.created_at,
            u.nama_lengkap AS oleh
       FROM retur r
       LEFT JOIN users u ON u.id = r.user_id
     $sqlWhere
     ORDER BY r.tanggal DESC, r.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['jumlah']    = (int)$r['jumlah'];
    $r['master_id'] = $r['master_id'] === null ? null : (int)$r['master_id'];
    $r['masuk_id']  = $r['masuk_id']  === null ? null : (int)$r['masuk_id'];
}
unset($r);

// Ringkasan dihitung atas seluruh hasil penyaring, bukan halaman ini saja.
$totalUnit = (int)dbValue("SELECT COALESCE(SUM(r.jumlah),0) FROM retur r $sqlWhere", $params);
$masukStok = (int)dbValue(
    "SELECT COALESCE(SUM(r.jumlah),0) FROM retur r $sqlWhere AND r.status = ?",
    array_merge($params, [STATUS_RETUR_MASUK])
);

jsonOk([
    'rows'           => $rows,
    'total_unit'     => $totalUnit,
    'unit_ke_stok'   => $masukStok,
    'unit_tertahan'  => $totalUnit - $masukStok,
    'status_options' => STATUS_RETUR,
    // Status mana yang berarti "sudah masuk stok" ditentukan server, supaya
    // layar tidak perlu menebaknya dari teks yang bisa berubah.
    'status_masuk'   => STATUS_RETUR_MASUK,
] + $meta);
