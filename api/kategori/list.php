<?php
/**
 * GET api/kategori/list.php — daftar kategori beserta jumlah pemakaiannya.
 *
 * Jumlah pemakaian penting: kategori yang sedang dipakai barang tidak boleh
 * dihapus begitu saja, dan admin perlu melihat angkanya sebelum memutuskan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$rows = dbAll("
    SELECT k.id, k.nama, k.keterangan, k.urutan, k.aktif,
           (SELECT COUNT(*) FROM master_barang m
             WHERE m.kategori = k.nama AND m.deleted_at IS NULL) AS dipakai
      FROM kategori k
     WHERE k.deleted_at IS NULL
     ORDER BY k.urutan, k.nama");

foreach ($rows as &$r) {
    $r['id']      = (int)$r['id'];
    $r['urutan']  = (int)$r['urutan'];
    $r['aktif']   = (int)$r['aktif'];
    $r['dipakai'] = (int)$r['dipakai'];
}
unset($r);

// Barang yang kategorinya kosong — bukan galat, tapi berguna ditampilkan
// supaya admin tahu ada yang belum dikelompokkan.
$tanpaKategori = (int)dbValue(
    "SELECT COUNT(*) FROM master_barang WHERE kategori = '' AND deleted_at IS NULL"
);

jsonOk([
    'rows'           => $rows,
    'tanpa_kategori' => $tanpaKategori,
    'total'          => count($rows),
]);
