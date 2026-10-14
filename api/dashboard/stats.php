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

/* --- Penyaring status ---------------------------------------------------
 *
 * Ditulis sebagai syarat ANGKA, bukan perbandingan teks.
 *
 * Dulu statusnya dihitung jadi teks lewat CASE, lalu dibandingkan dengan
 * parameter. Di server produksi itu gagal:
 *
 *   1267 Illegal mix of collations (utf8mb4_unicode_ci,COERCIBLE)
 *                               and (utf8mb4_general_ci,COERCIBLE)
 *
 * collation_connection di sana utf8mb4_unicode_ci, sementara parameter
 * prepared statement memakai collation bawaan charsetnya, utf8mb4_general_ci.
 * Keduanya sama-sama COERCIBLE, jadi MySQL tidak punya dasar memilih dan
 * menolak. Di mesin pengembangan keduanya kebetulan sama, jadi tidak pernah
 * terlihat — yang diuji berulang kali selalu bentuk querynya, bukan
 * collationnya. Lihat sqlKondisiStatus() di includes/helpers.php.
 *
 * Karena penyaringnya kini syarat biasa, ia langsung ikut WHERE: seluruh
 * pilihan tidak perlu lagi dibungkus tabel turunan, dan pencariannya lebih
 * ringan.
 */
$statusSah = in_array($status, statusStokSah(), true);
$kondisiStatus = $statusSah ? '(' . sqlKondisiStatus($status, $akhir) . ')' : '';

// Dua bentuk WHERE: kartu ringkasan menghitung SELURUH hasil penyaring
// pencarian dan kategori — tanpa status — sedangkan tabelnya ikut status.
$sqlWhere  = 'WHERE ' . implode(' AND ', $where);
$sqlWhereS = 'WHERE ' . implode(' AND ', $kondisiStatus === ''
    ? $where
    : array_merge($where, [$kondisiStatus]));

$sqlDasar  = ' FROM master_barang m ' . sqlJoinAgregat() . ' ' . $sqlWhere;
$sqlDasarS = ' FROM master_barang m ' . sqlJoinAgregat() . ' ' . $sqlWhereS;

/* --- Ringkasan kartu + jumlah baris hasil penyaring ----------------------
 *
 * Keduanya dulu dua query terpisah, padahal menjumlahkan hal yang sama atas
 * baris yang sama. Satu permintaan Dashboard berarti tiga kali menjumlahkan
 * ulang SELURUH barang masuk dan barang keluar; sekarang dua. Diukur di
 * MySQL 8 atas 250.000 baris transaksi, satu lintasan ~78 ms.
 *
 * Penghitung hasil penyaring ikut di sini sebagai SUM berkondisi — tanpa
 * parameter, jadi tidak ada teks yang dibandingkan sama sekali.
 */
$kolomSaring = $statusSah
    ? ",
      COALESCE(SUM(CASE WHEN $kondisiStatus THEN 1 ELSE 0 END), 0) AS total_saring"
    : '';

$ringkasan = dbOne("
    SELECT
      COUNT(*)                                                        AS total_sku,
      COALESCE(SUM($akhir), 0)                                        AS total_stok,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir <= m.stok_minimal THEN 1 ELSE 0 END), 0) AS kritis,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir >  m.stok_minimal
                        AND $akhir <= m.stok_minimal * " . AMBANG_RENDAH . " THEN 1 ELSE 0 END), 0) AS rendah,
      COALESCE(SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END), 0) AS belum_diatur,
      COUNT(DISTINCT NULLIF(m.kategori, ''))                          AS jml_kategori$kolomSaring
    $sqlDasar", $params);

// Query agregat tanpa GROUP BY selalu mengembalikan satu baris. Tapi kalau
// suatu saat tidak, lebih baik nol daripada galat tak berujung sebab.
if ($ringkasan === null) {
    $ringkasan = ['total_sku' => 0, 'total_stok' => 0, 'kritis' => 0,
                  'rendah' => 0, 'belum_diatur' => 0, 'jml_kategori' => 0,
                  'total_saring' => 0];
}

$total = $statusSah
    ? (int)($ringkasan['total_saring'] ?? 0)
    : (int)$ringkasan['total_sku'];

$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

// --- Ambil baris halaman ini ----------------------------------------------
$rows = dbAll("
    SELECT m.id, m.sku, m.barcode, m.nama, m.kategori,
           m.stok_awal, m.stok_minimal, m.barcode_asli,
           COALESCE(i.total, 0) AS masuk_total,
           COALESCE(o.total, 0) AS keluar_total,
           $akhir                AS stok_akhir,
           $statusExpr           AS status
    $sqlDasarS
    ORDER BY m.nama
    LIMIT " . PAGE_SIZE . " OFFSET $offset", $params);

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
