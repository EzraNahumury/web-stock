<?php
/**
 * GET api/opname/list.php — daftar sesi stok opname.
 *
 * Satu sesi = satu periode hitungan (mis. "JUNI 2026"). Jumlah baris,
 * berapa yang sudah dicek, dan total selisihnya ikut dihitung supaya
 * daftar bisa langsung menunjukkan sesi mana yang belum selesai.
 *
 * Parameter: q, page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q    = ambilStr($_GET, 'q', 100);
$page = ambilHalaman();

$where  = ['s.deleted_at IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(s.nama LIKE ? OR s.periode LIKE ? OR s.catatan LIKE ?)';
    $pola = polaLike($q);
    array_push($params, $pola, $pola, $pola);
}
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue("SELECT COUNT(*) FROM opname_sesi s $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT s.id, s.nama, s.periode, s.tanggal, s.kategori, s.status, s.catatan,
            s.created_at, u.nama_lengkap AS oleh,
            (SELECT COUNT(*) FROM opname_item i WHERE i.sesi_id = s.id) AS jml_item,
            (SELECT COUNT(*) FROM opname_item i WHERE i.sesi_id = s.id AND i.dicek = 1) AS jml_dicek,
            (SELECT COUNT(*) FROM opname_item i WHERE i.sesi_id = s.id
                              AND i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL
                              AND i.stok_hitung <> i.stok_accurate) AS jml_selisih,
            (SELECT COUNT(*) FROM opname_item i WHERE i.sesi_id = s.id
                              AND i.penyesuaian = ?) AS jml_disesuaikan
       FROM opname_sesi s
       LEFT JOIN users u ON u.id = s.user_id
     $sqlWhere
     ORDER BY s.tanggal DESC, s.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    // Parameter subquery mendahului parameter WHERE, mengikuti urutan
    // kemunculannya di SQL.
    array_merge([PENYESUAIAN_DISESUAIKAN], $params)
);

foreach ($rows as &$r) {
    $r['id']          = (int)$r['id'];
    $r['jml_item']    = (int)$r['jml_item'];
    $r['jml_dicek']   = (int)$r['jml_dicek'];
    $r['jml_selisih'] = (int)$r['jml_selisih'];
    $r['jml_disesuaikan'] = (int)$r['jml_disesuaikan'];
}
unset($r);

jsonOk([
    'rows'             => $rows,
    'kategori_options' => daftarKategori(),
] + $meta);
