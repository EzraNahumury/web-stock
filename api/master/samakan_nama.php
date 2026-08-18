<?php
/**
 * POST api/master/samakan_nama.php — samakan nama pada catatan transaksi
 * dengan nama yang tercatat di master barang.
 *
 * Catatan yang dibuat sebelum perbaikan "nama ikut master" masih menyimpan
 * judul etalase marketplace dari picking list — panjang, penuh kata kunci
 * pencarian, dan sulit dibaca di daftar. Barcodenya sudah cocok master,
 * jadi produknya sudah pasti sama dan nama pendek dari master bisa dipakai.
 *
 * Hanya menyentuh baris yang punya master_id. Baris yang barcodenya belum
 * terdaftar dibiarkan apa adanya — tidak ada nama master untuk menggantinya.
 *
 * Body: { pratinjau?: bool, arah?: "semua"|"masuk"|"keluar" }
 *   pratinjau  : hanya menghitung, tidak mengubah apa pun
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();   // mengubah banyak baris sekaligus — hanya admin

$in = jsonInput();
wajibCsrf($in);

$pratinjau = !empty($in['pratinjau']);
$arah      = ambilStr($in, 'arah', 10);
if (!in_array($arah, ['masuk', 'keluar'], true)) {
    $arah = 'semua';
}

$tabelDipakai = [];
if ($arah === 'semua' || $arah === 'masuk') {
    $tabelDipakai[] = 'barang_masuk';
}
if ($arah === 'semua' || $arah === 'keluar') {
    $tabelDipakai[] = 'barang_keluar';
}

/* --- Hitung dulu, selalu ------------------------------------------------- */
$rincian = [];
$totalBeda = 0;
foreach ($tabelDipakai as $tabel) {
    $n = (int)dbValue(
        "SELECT COUNT(*) FROM $tabel t
           JOIN master_barang m ON m.id = t.master_id
          WHERE t.deleted_at IS NULL AND t.nama <> m.nama"
    );
    $rincian[$tabel] = $n;
    $totalBeda += $n;
}

// Beberapa contoh, supaya admin tahu persis apa yang akan berubah.
$contoh = [];
foreach ($tabelDipakai as $tabel) {
    $rows = dbAll(
        "SELECT t.barcode, t.nama AS nama_lama, m.nama AS nama_baru
           FROM $tabel t
           JOIN master_barang m ON m.id = t.master_id
          WHERE t.deleted_at IS NULL AND t.nama <> m.nama
          LIMIT 5"
    );
    foreach ($rows as $r) {
        $contoh[] = $r + ['tabel' => $tabel];
    }
}
$contoh = array_slice($contoh, 0, 5);

if ($pratinjau) {
    jsonOk([
        'pratinjau' => true,
        'jumlah'    => $totalBeda,
        'rincian'   => $rincian,
        'contoh'    => $contoh,
    ]);
}

if ($totalBeda === 0) {
    jsonOk([
        'jumlah'  => 0,
        'rincian' => $rincian,
        'pesan'   => 'Semua nama sudah sama dengan master. Tidak ada yang diubah.',
    ]);
}

/* --- Terapkan ------------------------------------------------------------ */
$diubah = dbTransaksi(static function (PDO $pdo) use ($tabelDipakai) {
    $total = 0;
    foreach ($tabelDipakai as $tabel) {
        $st = $pdo->prepare(
            "UPDATE $tabel t
               JOIN master_barang m ON m.id = t.master_id
                SET t.nama = m.nama
              WHERE t.deleted_at IS NULL AND t.nama <> m.nama"
        );
        $st->execute();
        $total += $st->rowCount();
    }
    return $total;
});

catatAktivitas('update', 'transaksi', null, [
    'aksi'    => 'samakan nama dengan master',
    'arah'    => $arah,
    'rincian' => $rincian,
    'diubah'  => $diubah,
]);

jsonOk([
    'jumlah'  => $diubah,
    'rincian' => $rincian,
    'pesan'   => $diubah . ' catatan namanya disamakan dengan master barang.',
]);
