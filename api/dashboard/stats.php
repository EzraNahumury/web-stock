<?php
/**
 * GET api/dashboard/stats.php
 *
 * Pengganti computeStats() milik prototipe. Agregasi dikerjakan MySQL
 * (GROUP BY), bukan browser — memperbaiki audit F6 sekaligus menjaga
 * aplikasi tetap ringan saat data transaksi tumbuh.
 *
 * Parameter: q, kategori, status, page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q        = ambilStr($_GET, 'q', 100);
$kategori = ambilStr($_GET, 'kategori', 30);
$status   = ambilStr($_GET, 'status', 20);
$page     = ambilHalaman();

$akhir  = sqlStokAkhir();
$statusExpr = sqlStatusStok($akhir);

$where  = ['m.deleted_at IS NULL', 'm.aktif = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(m.nama LIKE ? OR m.sku LIKE ? OR m.barcode LIKE ?)';
    $pola = polaLike($q);
    array_push($params, $pola, $pola, $pola);
}
if ($kategori !== '' && $kategori !== 'Semua') {
    $where[] = 'm.kategori = ?';
    $params[] = $kategori;
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

// Status dihitung dari agregat, jadi penyaringannya harus di HAVING.
$having = '';
if (in_array($status, ['kritis', 'rendah', 'aman', 'belum_diatur'], true)) {
    $having = 'HAVING status = ?';
}

$sqlDasar = "
    FROM master_barang m
    " . sqlJoinAgregat() . "
    $sqlWhere";

// --- Ringkasan kartu statistik --------------------------------------------
$ringkasan = dbOne("
    SELECT
      COUNT(*)                                                        AS total_sku,
      COALESCE(SUM($akhir), 0)                                        AS total_stok,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir <= m.stok_minimal THEN 1 ELSE 0 END), 0) AS kritis,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir >  m.stok_minimal
                        AND $akhir <= m.stok_minimal * " . AMBANG_RENDAH . " THEN 1 ELSE 0 END), 0) AS rendah,
      COALESCE(SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END), 0) AS belum_diatur,
      COUNT(DISTINCT NULLIF(m.kategori, ''))                          AS jml_kategori
    $sqlDasar", $params);

// --- Hitung total baris hasil filter --------------------------------------
if ($having !== '') {
    $paramsHitung = array_merge($params, [$status]);
    $total = (int)dbValue("
        SELECT COUNT(*) FROM (
            SELECT $statusExpr AS status $sqlDasar $having
        ) t", $paramsHitung);
} else {
    $paramsHitung = $params;
    $total = (int)dbValue("SELECT COUNT(*) $sqlDasar", $params);
}

$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

// --- Ambil baris halaman ini ----------------------------------------------
$sqlRows = "
    SELECT m.id, m.sku, m.barcode, m.nama, m.kategori,
           m.stok_awal, m.stok_minimal, m.barcode_asli,
           COALESCE(i.total, 0) AS masuk_total,
           COALESCE(o.total, 0) AS keluar_total,
           $akhir                AS stok_akhir,
           $statusExpr           AS status
    $sqlDasar
    $having
    ORDER BY m.nama
    LIMIT " . PAGE_SIZE . " OFFSET $offset";

$rows = dbAll($sqlRows, $paramsHitung);

// Samakan tipe agar JavaScript tidak menerima angka sebagai string.
foreach ($rows as &$r) {
    $r['id']           = (int)$r['id'];
    $r['stok_awal']    = (int)$r['stok_awal'];
    $r['stok_minimal'] = (int)$r['stok_minimal'];
    $r['masuk_total']  = (int)$r['masuk_total'];
    $r['keluar_total'] = (int)$r['keluar_total'];
    $r['stok_akhir']   = (int)$r['stok_akhir'];
    $r['barcode_asli'] = (int)$r['barcode_asli'];
}
unset($r);

// --- Daftar kategori untuk dropdown ---------------------------------------
$kategoriList = dbAll("
    SELECT DISTINCT kategori FROM master_barang
    WHERE deleted_at IS NULL AND aktif = 1 AND kategori <> ''
    ORDER BY kategori");

jsonOk([
    'rows'      => $rows,
    'ringkasan' => [
        'total_sku'    => (int)$ringkasan['total_sku'],
        'total_stok'   => (int)$ringkasan['total_stok'],
        'kritis'       => (int)$ringkasan['kritis'],
        'rendah'       => (int)$ringkasan['rendah'],
        'perlu_order'  => (int)$ringkasan['kritis'] + (int)$ringkasan['rendah'],
        'belum_diatur' => (int)$ringkasan['belum_diatur'],
        'jml_kategori' => (int)$ringkasan['jml_kategori'],
    ],
    'kategori'  => array_column($kategoriList, 'kategori'),
] + $meta);
