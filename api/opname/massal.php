<?php
/**
 * POST api/opname/massal.php — isi petugas / penyesuaian untuk banyak baris.
 *
 * Body: { id, q?, kategori?, hanya?, petugas?, penyesuaian?, pratinjau? }
 *
 * Satu sesi opname bisa memuat ribuan barang, dan biasanya dihitung oleh
 * orang yang sama. Mengetik namanya per baris tidak masuk akal, jadi nilai
 * itu bisa diisikan sekaligus untuk seluruh baris yang SEDANG TERSARING —
 * penyaringnya sama persis dengan yang dipakai layar, dibangun di
 * includes/opname.php.
 *
 * `pratinjau` hanya menghitung, tidak mengubah apa pun. Antarmuka memakainya
 * untuk menyebut angkanya di dialog konfirmasi sebelum menimpa apa pun.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/opname.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('ID sesi tidak valid.');
}

$sesi = dbOne('SELECT * FROM opname_sesi WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($sesi === null) {
    jsonError('Sesi opname tidak ditemukan.', 404);
}
if ($sesi['status'] === 'selesai') {
    jsonError('Sesi ini sudah ditutup. Buka kembali statusnya bila memang perlu diubah.', 422);
}

$f     = filterOpnameItem($in, $id);
$total = (int)dbValue("SELECT COUNT(*) FROM opname_item i " . $f['where'], $f['params']);

if (!empty($in['pratinjau'])) {
    jsonOk(['pratinjau' => true, 'jumlah' => $total]);
}

/* --- Kolom mana yang diisi ------------------------------------------------
 * Field yang tidak dikirim tidak disentuh, supaya mengisi petugas tidak
 * diam-diam ikut menimpa keputusan penyesuaian, dan sebaliknya.
 * ---------------------------------------------------------------------- */
$set    = [];
$isiSet = [];

if (array_key_exists('petugas', $in)) {
    $set[]    = 'i.petugas = ?';
    $isiSet[] = ambilStr($in, 'petugas', 100);
}
if (array_key_exists('penyesuaian', $in)) {
    $set[]    = 'i.penyesuaian = ?';
    $isiSet[] = pilihanValid(ambilStr($in, 'penyesuaian', 30), PENYESUAIAN_OPNAME);
}

if (!$set) {
    jsonError('Tidak ada yang diisi.');
}
if ($total === 0) {
    jsonOk(['jumlah' => 0, 'pesan' => 'Tidak ada baris yang cocok dengan penyaring ini.']);
}

$diubah = dbTransaksi(static function (PDO $pdo) use ($set, $isiSet, $f) {
    $st = $pdo->prepare('UPDATE opname_item i SET ' . implode(', ', $set) . ' ' . $f['where']);
    $st->execute(array_merge($isiSet, $f['params']));
    return $st->rowCount();
});

catatAktivitas('update', 'opname', $id, [
    'nama'     => $sesi['nama'],
    'aksi'     => 'isi massal',
    'kategori' => $f['kategori'] !== '' ? $f['kategori'] : 'semua',
    'baris'    => $total,
]);

jsonOk([
    'jumlah' => $diubah,
    'cocok'  => $total,
    'pesan'  => $total . ' baris diperbarui.',
]);
