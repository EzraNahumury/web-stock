<?php
/**
 * GET api/opname/detail.php — isi satu sesi stok opname.
 *
 * Parameter: id (wajib), q, kategori, hanya (semua|selisih|belum), page
 *
 * Selisih = stok hitung - stok accurate, dihitung saat ditampilkan dan
 * tidak pernah disimpan, supaya tidak mungkin basi terhadap kedua angkanya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/opname.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$id = ambilInt($_GET, 'id', 0);
if ($id <= 0) {
    jsonError('ID sesi tidak valid.');
}

$sesi = dbOne(
    'SELECT s.*, u.nama_lengkap AS oleh
       FROM opname_sesi s LEFT JOIN users u ON u.id = s.user_id
      WHERE s.id = ? AND s.deleted_at IS NULL',
    [$id]
);
if ($sesi === null) {
    jsonError('Sesi opname tidak ditemukan.', 404);
}
$sesi['id'] = (int)$sesi['id'];
unset($sesi['user_id'], $sesi['deleted_at']);

$page = ambilHalaman();

// Penyaringnya dibangun di includes/opname.php supaya layar, pengisian
// massal, dan ekspor PDF tidak mungkin menyaring baris yang berbeda.
$f        = filterOpnameItem($_GET, $id);
$sqlWhere = $f['where'];
$params   = $f['params'];

$total  = (int)dbValue("SELECT COUNT(*) FROM opname_item i $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT i.id, i.master_id, i.sku, i.barcode, i.nama, i.kategori,
            i.stok_sistem, i.stok_hitung, i.stok_accurate, i.dicek,
            i.penyesuaian, i.petugas, i.catatan
       FROM opname_item i
     $sqlWhere
     ORDER BY i.kategori, i.nama, i.id
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']            = (int)$r['id'];
    $r['master_id']     = $r['master_id'] === null ? null : (int)$r['master_id'];
    $r['stok_sistem']   = (int)$r['stok_sistem'];
    $r['stok_hitung']   = $r['stok_hitung']   === null ? null : (int)$r['stok_hitung'];
    $r['stok_accurate'] = $r['stok_accurate'] === null ? null : (int)$r['stok_accurate'];
    $r['dicek']         = (int)$r['dicek'] === 1;
    $r['disesuaikan']   = $r['penyesuaian'] === PENYESUAIAN_DISESUAIKAN;
    $r['selisih']       = ($r['stok_hitung'] !== null && $r['stok_accurate'] !== null)
        ? $r['stok_hitung'] - $r['stok_accurate']
        : null;
}
unset($r);

/* --- Ringkasan seluruh sesi, bukan halaman ini saja ---------------------- */
$ring = dbOne(
    "SELECT COUNT(*) AS jml,
            SUM(CASE WHEN i.dicek = 1 THEN 1 ELSE 0 END) AS dicek,
            SUM(CASE WHEN i.stok_hitung IS NULL THEN 1 ELSE 0 END) AS belum,
            SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL
                          AND i.stok_hitung <> i.stok_accurate THEN 1 ELSE 0 END) AS beda,
            SUM(CASE WHEN i.penyesuaian = ? THEN 1 ELSE 0 END) AS disesuaikan,
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL
                          THEN i.stok_hitung - i.stok_accurate ELSE 0 END), 0) AS total_selisih
       FROM opname_item i WHERE i.sesi_id = ?",
    [PENYESUAIAN_DISESUAIKAN, $id]
);

// Kategori yang benar-benar ada di sesi ini — lembar kerja aslinya memakai
// satu tab per kategori, jadi penyaringnya harus mencerminkan isinya.
$kategoriSesi = [];
foreach (dbAll('SELECT DISTINCT kategori FROM opname_item WHERE sesi_id = ? ORDER BY kategori', [$id]) as $k) {
    if ($k['kategori'] !== '') {
        $kategoriSesi[] = $k['kategori'];
    }
}

jsonOk([
    'sesi'     => $sesi,
    'rows'     => $rows,
    'ringkas'  => [
        'jml'           => (int)($ring['jml'] ?? 0),
        'dicek'         => (int)($ring['dicek'] ?? 0),
        'belum'         => (int)($ring['belum'] ?? 0),
        'beda'          => (int)($ring['beda'] ?? 0),
        'disesuaikan'   => (int)($ring['disesuaikan'] ?? 0),
        'total_selisih' => (int)($ring['total_selisih'] ?? 0),
    ],
    'kategori_options'    => $kategoriSesi,
    'penyesuaian_options' => PENYESUAIAN_OPNAME,
    'penyesuaian_ya'      => PENYESUAIAN_DISESUAIKAN,
] + $meta);
