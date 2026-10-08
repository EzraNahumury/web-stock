<?php
/**
 * GET api/keterangan/list.php — daftar pilihan keterangan satu arah.
 *
 * Parameter: jenis = masuk | keluar | retur
 *
 * Jumlah pemakaian ikut dihitung: pilihan yang sudah dipakai transaksi tidak
 * boleh dihapus begitu saja, dan admin perlu melihat angkanya sebelum
 * memutuskan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$arah  = arahKeterangan();
$jenis = pilihanValid(ambilStr($_GET, 'jenis', 10), array_keys($arah));
$tabel = $arah[$jenis]['tabel'];
$kolom = $arah[$jenis]['kolom'];

$rows = dbAll(
    "SELECT k.id, k.jenis, k.nama, k.catatan, k.urutan, k.aktif, k.terkunci,
            k.tambah_stok,
            (SELECT COUNT(*) FROM $tabel t
              WHERE t.`$kolom` = k.nama AND t.deleted_at IS NULL) AS dipakai
       FROM keterangan k
      WHERE k.jenis = ? AND k.deleted_at IS NULL
      ORDER BY k.urutan, k.nama",
    [$jenis]
);

foreach ($rows as &$r) {
    $r['id']       = (int)$r['id'];
    $r['urutan']   = (int)$r['urutan'];
    $r['aktif']    = (int)$r['aktif'];
    $r['terkunci'] = (int)$r['terkunci'];
    $r['tambah_stok'] = (int)$r['tambah_stok'];
    $r['dipakai']  = (int)$r['dipakai'];
}
unset($r);

// Transaksi yang keteranganya kosong — bukan galat, tapi berguna diketahui.
$tanpaKeterangan = (int)dbValue(
    "SELECT COUNT(*) FROM $tabel WHERE `$kolom` = '' AND deleted_at IS NULL"
);

jsonOk([
    'rows'             => $rows,
    'jenis'            => $jenis,
    'label'            => $arah[$jenis]['label'],
    // Nilai yang menggerakkan stok, supaya layar bisa menerangkan kenapa
    // barisnya terkunci tanpa menebak namanya sendiri.
    'nilai_sistem'     => $jenis === 'masuk' ? KET_RETUR_MASUK : '',
    // Keterangan retur yang mengembalikan barang ke stok — dipakai layar untuk
    // menerangkan kolom centangnya.
    'tambah_stok_aktif' => $jenis === 'retur' ? keteranganTambahStok() : [],
    'tanpa_keterangan' => $tanpaKeterangan,
    'total'            => count($rows),
]);
