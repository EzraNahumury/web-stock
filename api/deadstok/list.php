<?php
/**
 * GET api/deadstok/list.php — daftar barang deadstok.
 *
 * Parameter: q, kategori (rusak|belum_siap), status (tertahan|beres|dibuang),
 *            dari, sampai, page
 *
 * Tiap baris membawa stok akhir dan angka SIAP JUAL barangnya, karena itulah
 * yang sebenarnya dicari di layar ini: bukan "berapa stoknya", melainkan
 * "berapa yang benar-benar bisa dijual sesudah yang rusak dan yang belum siap
 * dikeluarkan dari hitungan".
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/deadstok.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q        = ambilStr($_GET, 'q', 100);
$dari     = trim((string)($_GET['dari'] ?? ''));
$sampai   = trim((string)($_GET['sampai'] ?? ''));
$kategori = ambilStr($_GET, 'kategori', 20);
$status   = ambilStr($_GET, 'status', 20);
$page     = ambilHalaman();

$where  = ['d.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = '(d.nama LIKE ? OR d.barcode LIKE ? OR d.sku LIKE ? OR d.keterangan LIKE ?)';
    $pola = polaLike($q);
    for ($i = 0; $i < 4; $i++) {
        $params[] = $pola;
    }
}
if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
    $where[] = 'd.tanggal >= ?';
    $params[] = $dari;
}
if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
    $where[] = 'd.tanggal <= ?';
    $params[] = $sampai;
}
/* Kategori dan status dibandingkan dengan daftar tertutup lebih dulu. Nilai di
 * luar daftar diabaikan, bukan dikirim ke SQL: kolomnya ENUM, dan nilai asing
 * pada ENUM memicu galat, bukan hasil kosong. */
if (isset(KATEGORI_DEADSTOK[$kategori])) {
    $where[] = 'd.kategori = ?';
    $params[] = $kategori;
} else {
    $kategori = '';
}
if (isset(STATUS_DEADSTOK[$status])) {
    $where[] = 'd.status = ?';
    $params[] = $status;
} else {
    $status = '';
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue("SELECT COUNT(*) FROM deadstok d $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT d.id, d.tanggal, d.master_id, d.barcode, d.sku, d.nama, d.jumlah,
            d.kategori, d.keterangan, d.status, d.keluar_id,
            d.selesai_at, d.created_at,
            m.kategori AS kategori_barang,
            u.nama_lengkap  AS oleh,
            us.nama_lengkap AS selesai_oleh,
            CASE WHEN d.master_id IS NULL THEN NULL ELSE (
                m.stok_awal
                + COALESCE((SELECT SUM(jumlah) FROM barang_masuk
                             WHERE master_id = m.id AND deleted_at IS NULL), 0)
                - COALESCE((SELECT SUM(jumlah) FROM barang_keluar
                             WHERE master_id = m.id AND deleted_at IS NULL), 0)
            ) END AS stok_akhir,
            CASE WHEN d.master_id IS NULL THEN NULL
                 ELSE " . sqlDeadstokTertahan('m.id') . " END AS tertahan
       FROM deadstok d
       LEFT JOIN master_barang m ON m.id = d.master_id
       LEFT JOIN users u  ON u.id  = d.user_id
       LEFT JOIN users us ON us.id = d.selesai_user
     $sqlWhere
     ORDER BY d.tanggal DESC, d.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']         = (int)$r['id'];
    $r['jumlah']     = (int)$r['jumlah'];
    $r['master_id']  = $r['master_id'] === null ? null : (int)$r['master_id'];
    $r['keluar_id']  = $r['keluar_id'] === null ? null : (int)$r['keluar_id'];
    $r['stok_akhir'] = $r['stok_akhir'] === null ? null : (int)$r['stok_akhir'];
    $r['tertahan']   = $r['tertahan']   === null ? null : (int)$r['tertahan'];
    // Angka yang sebenarnya dicari di layar ini.
    $r['siap'] = ($r['stok_akhir'] === null || $r['tertahan'] === null)
        ? null
        : $r['stok_akhir'] - $r['tertahan'];
}
unset($r);

/* Ringkasan dihitung atas SELURUH hasil penyaring, bukan halaman ini saja.
 *
 * "Unit tertahan" hanya menghitung yang berstatus tertahan. Yang sudah beres
 * atau sudah dibuang tidak lagi menahan apa pun — menjumlahkan ketiganya
 * membuat angkanya terus naik selamanya dan berhenti berarti apa-apa, persis
 * seperti yang terjadi pada hitungan "belum ke Accurate" sebelum keadaan
 * ketiganya ada. */
$ringkas = dbOne(
    "SELECT
        COALESCE(SUM(CASE WHEN d.status = 'tertahan' THEN d.jumlah ELSE 0 END), 0) AS unit_tertahan,
        COALESCE(SUM(CASE WHEN d.status = 'beres'    THEN d.jumlah ELSE 0 END), 0) AS unit_beres,
        COALESCE(SUM(CASE WHEN d.status = 'dibuang'  THEN d.jumlah ELSE 0 END), 0) AS unit_dibuang,
        COALESCE(SUM(CASE WHEN d.kategori = 'rusak'      AND d.status = 'tertahan'
                          THEN d.jumlah ELSE 0 END), 0) AS unit_rusak,
        COALESCE(SUM(CASE WHEN d.kategori = 'belum_siap' AND d.status = 'tertahan'
                          THEN d.jumlah ELSE 0 END), 0) AS unit_belum_siap,
        COUNT(DISTINCT CASE WHEN d.status = 'tertahan' THEN d.master_id END) AS barang_tertahan
       FROM deadstok d $sqlWhere",
    $params
);

jsonOk([
    'rows'             => $rows,
    'unit_tertahan'    => (int)$ringkas['unit_tertahan'],
    'unit_beres'       => (int)$ringkas['unit_beres'],
    'unit_dibuang'     => (int)$ringkas['unit_dibuang'],
    'unit_rusak'       => (int)$ringkas['unit_rusak'],
    'unit_belum_siap'  => (int)$ringkas['unit_belum_siap'],
    'barang_tertahan'  => (int)$ringkas['barang_tertahan'],
    'kategori'         => $kategori,
    'status'           => $status,
    // Daftar pilihannya datang dari server, supaya layar dan validasi tidak
    // pernah memegang daftar yang berbeda.
    'kategori_options' => KATEGORI_DEADSTOK,
    'status_options'   => STATUS_DEADSTOK,
] + $meta);
