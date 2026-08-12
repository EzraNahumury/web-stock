<?php
/**
 * GET api/master/list.php — daftar master barang, dengan cari + paginasi.
 * Parameter: q, page, all (1 = ambil ringkas untuk picker autocomplete)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q = ambilStr($_GET, 'q', 100);

// Mode picker: dipakai autocomplete di form transaksi. Batas 20 hasil,
// sama dengan perilaku prototipe (master.filter(...).slice(0,20)).
if (!empty($_GET['picker'])) {
    if ($q === '') {
        jsonOk(['rows' => []]);
    }
    $pola = polaLike($q);
    $rows = dbAll(
        "SELECT id, sku, barcode, nama
           FROM master_barang
          WHERE deleted_at IS NULL AND aktif = 1
            AND (nama LIKE ? OR barcode LIKE ? OR sku LIKE ?)
          ORDER BY nama
          LIMIT 20",
        [$pola, $pola, $pola]
    );
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
    }
    unset($r);
    jsonOk(['rows' => $rows]);
}

$page   = ambilHalaman();
$where  = ['deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = '(nama LIKE ? OR sku LIKE ? OR barcode LIKE ?)';
    $pola = polaLike($q);
    array_push($params, $pola, $pola, $pola);
}

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue("SELECT COUNT(*) FROM master_barang $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT id, sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif
       FROM master_barang
     $sqlWhere
     ORDER BY nama
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']           = (int)$r['id'];
    $r['stok_awal']    = (int)$r['stok_awal'];
    $r['stok_minimal'] = (int)$r['stok_minimal'];
    $r['barcode_asli'] = (int)$r['barcode_asli'];
    $r['aktif']        = (int)$r['aktif'];
}
unset($r);

jsonOk(['rows' => $rows, 'kategori_options' => KATEGORI_OPTIONS] + $meta);
