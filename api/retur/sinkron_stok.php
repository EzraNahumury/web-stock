<?php
/**
 * POST api/retur/sinkron_stok.php — samakan seluruh retur dengan stoknya.
 *
 * Body: { semua_keterangan?: bool }
 *
 * DUA PEKERJAAN
 * 1. Bila `semua_keterangan` diisi, SELURUH keterangan retur yang aktif
 *    ditandai mengembalikan barang ke stok. Ini yang diminta gudang ketika
 *    seluruh retur memang harus masuk, tanpa harus membuka Master dan
 *    mencentangnya satu per satu.
 * 2. Setiap retur disamakan dengan keterangannya: yang seharusnya menambah
 *    stok tapi belum punya baris barang masuk dibuatkan, dan sebaliknya
 *    yang seharusnya tidak dibatalkan barisnya.
 *
 * Aman diulang. Retur yang sudah benar tidak disentuh, jadi menekan tombolnya
 * dua kali tidak melahirkan baris ganda — itu sebabnya penyamaannya lewat
 * sinkronMasukRetur(), jalur yang sama dengan pencatatan biasa, bukan INSERT
 * massal yang tidak tahu apa yang sudah ada.
 *
 * Khusus admin: sekali jalan ini menggeser stok ratusan barang.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/retur.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();

$in = jsonInput();
wajibCsrf($in);

$semuaKeterangan = !empty($in['semua_keterangan']);

if ($semuaKeterangan) {
    dbExec(
        "UPDATE keterangan SET tambah_stok = 1
          WHERE jenis = 'retur' AND aktif = 1 AND deleted_at IS NULL"
    );
}

// Dibaca SESUDAH pembaruan di atas, dan tanpa lewat keteranganTambahStok()
// yang menyimpan hasilnya di cache permintaan ini.
$ketMasuk = array_column(
    dbAll("SELECT nama FROM keterangan
            WHERE jenis = 'retur' AND tambah_stok = 1
              AND aktif = 1 AND deleted_at IS NULL"),
    'nama'
);

$hasil = dbTransaksi(static function (PDO $pdo) use ($ketMasuk) {
    $retur = $pdo->query(
        'SELECT id, tanggal, master_id, barcode, nama, jumlah, status, masuk_id
           FROM retur WHERE deleted_at IS NULL ORDER BY id'
    );
    $stTaut = $pdo->prepare('UPDATE retur SET masuk_id = ? WHERE id = ?');

    $ditambah = 0;
    $ditarik  = 0;
    $tetap    = 0;

    foreach ($retur->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $masukId   = $r['masuk_id'] === null ? null : (int)$r['masuk_id'];
        $perluStok = in_array((string)$r['status'], $ketMasuk, true);

        // Keadaan sebelum disamakan, supaya yang dilaporkan perubahan nyata —
        // bukan sekadar jumlah baris yang diperiksa.
        $adaBaris = false;
        if ($masukId !== null) {
            $cek = $pdo->prepare('SELECT deleted_at FROM barang_masuk WHERE id = ?');
            $cek->execute([$masukId]);
            $baris = $cek->fetch(PDO::FETCH_ASSOC);
            $adaBaris = $baris !== false && $baris['deleted_at'] === null;
        }

        $baru = sinkronMasukRetur($pdo, $r, $masukId, $perluStok);
        if ($baru !== $masukId) {
            $stTaut->execute([$baru, $r['id']]);
        }

        if ($perluStok && !$adaBaris) {
            $ditambah++;
        } elseif (!$perluStok && $adaBaris) {
            $ditarik++;
        } else {
            $tetap++;
        }
    }

    return ['ditambah' => $ditambah, 'ditarik' => $ditarik, 'tetap' => $tetap];
});

catatAktivitas('update', 'retur', null, [
    'aksi'            => 'samakan retur dengan stok',
    'semua_keterangan' => $semuaKeterangan ? 'ya' : 'tidak',
    'ditambah'        => $hasil['ditambah'],
    'ditarik'         => $hasil['ditarik'],
]);

$bagian = [];
if ($hasil['ditambah'] > 0) {
    $bagian[] = number_format($hasil['ditambah'], 0, ',', '.') . ' retur masuk ke stok';
}
if ($hasil['ditarik'] > 0) {
    $bagian[] = number_format($hasil['ditarik'], 0, ',', '.') . ' retur ditarik dari stok';
}
if (!$bagian) {
    $bagian[] = 'semuanya sudah sesuai, tidak ada yang perlu diubah';
}

jsonOk([
    'ditambah'   => $hasil['ditambah'],
    'ditarik'    => $hasil['ditarik'],
    'tetap'      => $hasil['tetap'],
    'keterangan' => $ketMasuk,
    'pesan'      => ucfirst(implode(' dan ', $bagian)) . '.',
]);
