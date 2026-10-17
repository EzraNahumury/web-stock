<?php
/**
 * GET api/minimal/list.php — daftar barang beserta ambang stok minimalnya.
 *
 * Parameter: q, kategori, status, hanya (semua|belum|sudah), page
 *
 * KENAPA PUNYA DAFTAR SENDIRI
 * Menyetel ambang butuh angka yang berbeda dari layar lain: stok akhir saat
 * ini dan rata-rata keluar per bulan, supaya ambangnya diputuskan dari
 * kenyataan, bukan ditebak. Dashboard tidak menghitung rata-rata itu, dan
 * Master barang tidak menghitung stok akhir.
 *
 * Rata-rata keluar diambil dari 90 hari terakhir lalu dibagi tiga. Dipakai
 * sebagai saran, bukan paksaan — yang menentukan tetap orang gudang.
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
$hanya    = pilihanValid(ambilStr($_GET, 'hanya', 10), ['semua', 'belum', 'sudah']);
$page     = ambilHalaman();

$akhir      = sqlStokAkhir();
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
// Penyaring status memakai syarat angka, bukan perbandingan teks — lihat
// sqlKondisiStatus() dan catatan collationnya di includes/helpers.php.
if (in_array($status, statusStokSah(), true)) {
    $where[] = '(' . sqlKondisiStatus($status, $akhir) . ')';
}
// Yang paling dicari: barang yang ambangnya masih 0, karena justru barang itu
// yang tidak pernah bisa dinilai statusnya.
if ($hanya === 'belum') {
    $where[] = 'm.stok_minimal = 0';
} elseif ($hanya === 'sudah') {
    $where[] = 'm.stok_minimal > 0';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);
$sqlDasar = ' FROM master_barang m ' . sqlJoinAgregat() . ' ' . $sqlWhere;

$ringkas = dbOne("
    SELECT COUNT(*)                                                      AS total,
           COALESCE(SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END), 0) AS belum_diatur
    $sqlDasar", $params);

$total  = (int)($ringkas['total'] ?? 0);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

/* Rata-rata keluar per bulan dihitung lewat subquery berkorelasi, bukan JOIN
 * tambahan: hanya baris halaman ini yang perlu angkanya, dan JOIN agregat
 * ketiga akan membebani seluruh katalog hanya demi 50 baris. */
$rows = dbAll("
    SELECT m.id, m.sku, m.barcode, m.nama, m.kategori,
           m.stok_awal, m.stok_minimal,
           COALESCE(i.total, 0) AS masuk_total,
           COALESCE(o.total, 0) AS keluar_total,
           $akhir      AS stok_akhir,
           $statusExpr AS status,
           COALESCE((
               SELECT ROUND(SUM(k.jumlah) / 3)
                 FROM barang_keluar k
                WHERE k.master_id = m.id
                  AND k.deleted_at IS NULL
                  AND k.tanggal >= (CURDATE() - INTERVAL 90 DAY)
           ), 0) AS keluar_per_bulan
    $sqlDasar
    ORDER BY m.nama
    LIMIT " . PAGE_SIZE . " OFFSET $offset", $params);

foreach ($rows as &$r) {
    $r['id']               = (int)$r['id'];
    $r['stok_awal']        = (int)$r['stok_awal'];
    $r['stok_minimal']     = (int)$r['stok_minimal'];
    $r['masuk_total']      = (int)$r['masuk_total'];
    $r['keluar_total']     = (int)$r['keluar_total'];
    $r['stok_akhir']       = (int)$r['stok_akhir'];
    $r['keluar_per_bulan'] = (int)$r['keluar_per_bulan'];
}
unset($r);

$kategoriList = dbAll("
    SELECT DISTINCT kategori FROM master_barang
     WHERE deleted_at IS NULL AND aktif = 1 AND kategori <> ''
     ORDER BY kategori");

jsonOk([
    'rows'         => $rows,
    'kategori'     => array_column($kategoriList, 'kategori'),
    'belum_diatur' => (int)($ringkas['belum_diatur'] ?? 0),
    'hanya'        => $hanya,
] + $meta);
