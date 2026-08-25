<?php
/**
 * GET api/keterangan/list.php — daftar pilihan keterangan satu arah.
 *
 * Parameter: jenis = masuk | keluar
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

$jenis = pilihanValid(ambilStr($_GET, 'jenis', 10), ['masuk', 'keluar']);
$tabel = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';

$rows = dbAll(
    "SELECT k.id, k.jenis, k.nama, k.catatan, k.urutan, k.aktif, k.terkunci,
            (SELECT COUNT(*) FROM $tabel t
              WHERE t.keterangan = k.nama AND t.deleted_at IS NULL) AS dipakai
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
    $r['dipakai']  = (int)$r['dipakai'];
}
unset($r);

// Transaksi yang keteranganya kosong — bukan galat, tapi berguna diketahui.
$tanpaKeterangan = (int)dbValue(
    "SELECT COUNT(*) FROM $tabel WHERE keterangan = '' AND deleted_at IS NULL"
);

jsonOk([
    'rows'             => $rows,
    'jenis'            => $jenis,
    'tanpa_keterangan' => $tanpaKeterangan,
    'total'            => count($rows),
]);
