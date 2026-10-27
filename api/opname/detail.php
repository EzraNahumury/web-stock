<?php
/**
 * GET api/opname/detail.php — isi satu sesi stok opname.
 *
 * Parameter: id (wajib), q, kategori, hanya (semua|selisih|belum), page
 *
 * SELISIH = STOK HITUNG - STOK AKHIR, yaitu seberapa jauh stok sistem meleset
 * dari kenyataan di rak. Itu pula yang dipakai penyesuaian stok, jadi angka di
 * layar dan angka yang dikerjakan tombolnya selalu sama. Pembanding terhadap
 * Accurate dibawa terpisah sebagai selisih_acc.
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

/* STOK AKHIR YANG BERLAKU SEKARANG, bukan i.stok_sistem.
 *
 * i.stok_sistem adalah salinan stok pada saat sesi opname DIBUAT. Stok terus
 * bergerak sesudah itu — barang masuk, pesanan keluar — sementara penyesuaian
 * selalu membawa stok YANG BERLAKU SEKARANG ke angka hitungan fisik. Selisih
 * yang dihitung terhadap salinan lama karena itu tetap berbeda dari koreksi
 * yang akan dikerjakan tombolnya, dan itulah yang membuat angkanya tidak bisa
 * dipercaya.
 *
 * Salinannya tetap disimpan di basis data sebagai jejak audit, dan ikut
 * dikirim sebagai stok_sistem; yang ditampilkan di kolom "Stok akhir" adalah
 * angka hidup ini.
 *
 * Barang yang tidak terhubung ke master memakai salinannya, karena tidak ada
 * yang bisa dihitung — dan memang tidak bisa disesuaikan juga. */
$sqlStokHidup = 'CASE WHEN i.master_id IS NULL THEN i.stok_sistem ELSE '
    . '(m.stok_awal'
    . '  + COALESCE((SELECT SUM(jumlah) FROM barang_masuk'
    . '              WHERE master_id = m.id AND deleted_at IS NULL), 0)'
    . '  - COALESCE((SELECT SUM(jumlah) FROM barang_keluar'
    . '              WHERE master_id = m.id AND deleted_at IS NULL), 0))'
    . ' END';

$rows = dbAll(
    "SELECT i.id, i.master_id, i.sku, i.barcode, i.nama, i.kategori,
            i.stok_sistem, i.stok_hitung, i.stok_accurate, i.dicek,
            i.penyesuaian, i.adj_jenis, i.adj_qty, i.petugas, i.catatan,
            $sqlStokHidup AS stok_akhir
       FROM opname_item i
       LEFT JOIN master_barang m ON m.id = i.master_id
     $sqlWhere
     ORDER BY i.kategori, i.nama, i.id
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']            = (int)$r['id'];
    $r['master_id']     = $r['master_id'] === null ? null : (int)$r['master_id'];
    $r['stok_sistem']   = (int)$r['stok_sistem'];
    // Yang dipakai layar sebagai kolom "Stok akhir".
    $r['stok_akhir']    = (int)$r['stok_akhir'];
    $r['stok_hitung']   = $r['stok_hitung']   === null ? null : (int)$r['stok_hitung'];
    $r['stok_accurate'] = $r['stok_accurate'] === null ? null : (int)$r['stok_accurate'];
    $r['dicek']         = (int)$r['dicek'] === 1;
    $r['disesuaikan']   = $r['penyesuaian'] === PENYESUAIAN_DISESUAIKAN;
    $r['adj_qty']       = $r['adj_qty'] === null ? null : (int)$r['adj_qty'];
    /* SELISIH = STOK HITUNG - STOK AKHIR.
     *
     * Dulu dihitung terhadap stok accurate, dan itu keliru dalam satu hal yang
     * penting: angka yang ditampilkan bukan angka yang menggerakkan koreksinya.
     * Penyesuaian stok selalu memakai hitung - stok akhir (lihat
     * sinkronPenyesuaianStok di includes/opname.php), jadi layar yang
     * menampilkan hitung - accurate memperlihatkan selisih yang berbeda dari
     * yang akan dikerjakan tombolnya. Di gudang itu terbaca sebagai angka yang
     * tidak bisa dipercaya.
     *
     * Cukup stok hitung yang perlu diisi: stok akhir selalu ada. Dulu baris
     * yang stok accurate-nya kosong tidak punya selisih sama sekali, padahal
     * selisihnya terhadap stok sistem sudah bisa dihitung. */
    $r['selisih'] = $r['stok_hitung'] !== null
        ? $r['stok_hitung'] - $r['stok_akhir']
        : null;

    /* Pembanding terhadap Accurate tetap dibawa, sebagai angka kedua. Gudang
     * memang memakainya — ada tombol unggah PDF Accurate untuk mengisinya —
     * jadi menghapusnya berarti membuang pemeriksaan silang yang sudah jalan. */
    $r['selisih_acc'] = ($r['stok_hitung'] !== null && $r['stok_accurate'] !== null)
        ? $r['stok_hitung'] - $r['stok_accurate']
        : null;
}
unset($r);

/* --- Ringkasan seluruh sesi, bukan halaman ini saja ---------------------- */
$ring = dbOne(
    "SELECT COUNT(*) AS jml,
            SUM(CASE WHEN i.dicek = 1 THEN 1 ELSE 0 END) AS dicek,
            SUM(CASE WHEN i.stok_hitung IS NULL THEN 1 ELSE 0 END) AS belum,
            SUM(CASE WHEN i.stok_hitung IS NOT NULL
                          AND i.stok_hitung <> $sqlStokHidup THEN 1 ELSE 0 END) AS beda,
            SUM(CASE WHEN i.penyesuaian = ? THEN 1 ELSE 0 END) AS disesuaikan,
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NOT NULL
                          THEN i.stok_hitung - $sqlStokHidup ELSE 0 END), 0) AS total_selisih,
            SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL
                          AND i.stok_hitung <> i.stok_accurate THEN 1 ELSE 0 END) AS beda_acc,
            COALESCE(SUM(CASE WHEN i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL
                          THEN i.stok_hitung - i.stok_accurate ELSE 0 END), 0) AS total_selisih_acc
       FROM opname_item i
       LEFT JOIN master_barang m ON m.id = i.master_id
      WHERE i.sesi_id = ?",
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
        // Pemeriksaan silang terhadap Accurate, dihitung terpisah.
        'beda_acc'          => (int)($ring['beda_acc'] ?? 0),
        'total_selisih_acc' => (int)($ring['total_selisih_acc'] ?? 0),
    ],
    'kategori_options'    => $kategoriSesi,
    'penyesuaian_options' => PENYESUAIAN_OPNAME,
    'penyesuaian_ya'      => PENYESUAIAN_DISESUAIKAN,
] + $meta);
