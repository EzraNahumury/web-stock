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

// Status barang dihitung dari agregat masuk/keluar, jadi tidak bisa
// disaring di WHERE bersama kolom biasa.
//
// Dulu memakai "HAVING status = ?" tanpa GROUP BY. MariaDB menerimanya,
// tapi MySQL menganggap HAVING tanpa GROUP BY sebagai satu grup tunggal
// dan menolak kolom non-agregat di dalamnya bila sql_mode memuat
// ONLY_FULL_GROUP_BY — yang berlaku secara bawaan di MySQL 8. Akibatnya
// memilih status apa pun di Dashboard gagal 500 di server, sementara di
// mesin pengembangan berjalan mulus.
//
// Sekarang seluruh pilihannya dibungkus tabel turunan dan disaring dengan
// WHERE biasa: sama hasilnya, dan tidak bergantung pada sql_mode.
$statusSah = in_array($status, ['kritis', 'rendah', 'aman', 'belum_diatur'], true);

$sqlDasar = "
    FROM master_barang m
    " . sqlJoinAgregat() . "
    $sqlWhere";

/* --- Ringkasan kartu statistik + jumlah baris hasil penyaring ------------
 *
 * Keduanya dulu dua query terpisah, padahal menjumlahkan hal yang sama atas
 * baris yang sama. Satu permintaan Dashboard berarti tiga kali menjumlahkan
 * ulang SELURUH barang masuk dan barang keluar; sekarang dua. Diukur di
 * MySQL 8 atas 250.000 baris transaksi, satu lintasan ~78 ms — jadi yang
 * dihemat nyata, dan di hosting bersama itu juga memperkecil peluang
 * permintaan ditutup di tengah jalan oleh batas waktu.
 *
 * Penghitung hasil penyaring ikut di sini sebagai SUM berkondisi. Parameternya
 * berada di daftar SELECT, jadi urutannya HARUS di depan parameter WHERE —
 * placeholder dibaca dari kiri ke kanan sesuai kemunculannya di SQL.
 */
$kolomSaring = '';
$paramRingkas = $params;
if ($statusSah) {
    $kolomSaring  = ",
      COALESCE(SUM(CASE WHEN $statusExpr = ? THEN 1 ELSE 0 END), 0) AS total_saring";
    $paramRingkas = array_merge([$status], $params);
}

$ringkasan = dbOne("
    SELECT
      COUNT(*)                                                        AS total_sku,
      COALESCE(SUM($akhir), 0)                                        AS total_stok,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir <= m.stok_minimal THEN 1 ELSE 0 END), 0) AS kritis,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir >  m.stok_minimal
                        AND $akhir <= m.stok_minimal * " . AMBANG_RENDAH . " THEN 1 ELSE 0 END), 0) AS rendah,
      COALESCE(SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END), 0) AS belum_diatur,
      COUNT(DISTINCT NULLIF(m.kategori, ''))                          AS jml_kategori$kolomSaring
    $sqlDasar", $paramRingkas);

// Query agregat tanpa GROUP BY selalu mengembalikan satu baris. Tapi kalau
// suatu saat tidak, lebih baik nol daripada galat tak berujung sebab.
if ($ringkasan === null) {
    $ringkasan = ['total_sku' => 0, 'total_stok' => 0, 'kritis' => 0,
                  'rendah' => 0, 'belum_diatur' => 0, 'jml_kategori' => 0,
                  'total_saring' => 0];
}

// Satu bentuk pilihan dipakai baik untuk menghitung maupun mengambil baris,
// supaya jumlah halaman tidak mungkin menyimpang dari isinya.
$sqlPilih = "
    SELECT m.id, m.sku, m.barcode, m.nama, m.kategori,
           m.stok_awal, m.stok_minimal, m.barcode_asli,
           COALESCE(i.total, 0) AS masuk_total,
           COALESCE(o.total, 0) AS keluar_total,
           $akhir                AS stok_akhir,
           $statusExpr           AS status
    $sqlDasar";

$saring       = $statusSah ? 'WHERE t.status = ?' : '';
$paramsHitung = $statusSah ? array_merge($params, [$status]) : $params;

// Jumlah baris hasil penyaring sudah dihitung bersama ringkasan di atas.
$total = $statusSah
    ? (int)($ringkasan['total_saring'] ?? 0)
    : (int)$ringkasan['total_sku'];

$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

// --- Ambil baris halaman ini ----------------------------------------------
$rows = dbAll(
    "SELECT * FROM ($sqlPilih) t $saring ORDER BY t.nama LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $paramsHitung
);

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
