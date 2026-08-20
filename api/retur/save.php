<?php
/**
 * POST api/retur/save.php — tambah atau ubah satu retur.
 *
 * Body: { id?, tanggal, no_pesanan, barcode, sku, nama, jumlah, status, keterangan }
 *
 * Retur dan baris barang masuknya selalu ditulis dalam SATU transaksi. Bila
 * salah satunya gagal, keduanya batal — stok tidak boleh pernah bertambah
 * tanpa retur yang menerangkannya, dan sebaliknya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/retur.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id        = ambilInt($in, 'id', 0);
$tanggal   = ambilTanggal($in, 'tanggal');
$noPesanan = ambilStr($in, 'no_pesanan', 100);
$barcode   = ambilStr($in, 'barcode', 50);
$sku       = ambilStr($in, 'sku', 50);
$nama      = ambilStr($in, 'nama', 255);
$jumlah    = ambilInt($in, 'jumlah', 0);
$status    = pilihanValid(ambilStr($in, 'status', 30), STATUS_RETUR);
$ket       = ambilStr($in, 'keterangan', 255);

if ($tanggal === null) {
    jsonError('Format tanggal tidak valid.');
}
if ($barcode === '' && $sku === '') {
    jsonError('Isi barcode atau SKU barangnya dulu.');
}
if ($jumlah <= 0) {
    jsonError('Qty harus lebih dari 0.');
}

$peringatan = [];

// Barang dicari lewat barcode dulu, lalu SKU. Lembar retur ditulis per SKU.
$cari   = cariMasterReturn($barcode, $sku);
$master = $cari['master'];

if ($master !== null) {
    // Identitas mengikuti master supaya nama di daftar tetap pendek dan
    // barcode/SKU-nya konsisten dengan katalog.
    $barcode = (string)$master['barcode'];
    $sku     = (string)$master['sku'];
    if ($nama === '') {
        $nama = (string)$master['nama'];
    }
    if ($cari['ganda']) {
        $peringatan[] = 'SKU "' . $sku . '" dipakai lebih dari satu barang di master. '
            . 'Yang dipakai: ' . $master['nama'] . '. Isi barcode bila ingin memastikan.';
    }
} else {
    if ($nama === '') {
        jsonError('Barang tidak ditemukan di master. Isi nama barangnya secara manual.');
    }
    $peringatan[] = 'Barang ini belum terdaftar di master. Returnya tetap dicatat, '
        . 'tapi belum mempengaruhi perhitungan stok.';
}

$bersih = [
    'tanggal'    => $tanggal,
    'no_pesanan' => $noPesanan,
    'master_id'  => $master ? (int)$master['id'] : null,
    'barcode'    => $barcode,
    'sku'        => $sku,
    'nama'       => $nama,
    'jumlah'     => $jumlah,
    'status'     => $status,
    'keterangan' => $ket,
];

/* --- Ubah ---------------------------------------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM retur WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Retur tidak ditemukan.', 404);
    }

    $masukIdLama = $lama['masuk_id'] === null ? null : (int)$lama['masuk_id'];

    dbTransaksi(static function (PDO $pdo) use ($id, $bersih, $masukIdLama) {
        $masukId = sinkronMasukRetur($pdo, $bersih, $masukIdLama);

        $st = $pdo->prepare(
            'UPDATE retur
                SET tanggal = ?, no_pesanan = ?, master_id = ?, barcode = ?, sku = ?,
                    nama = ?, jumlah = ?, status = ?, keterangan = ?, masuk_id = ?
              WHERE id = ?'
        );
        $st->execute([
            $bersih['tanggal'], $bersih['no_pesanan'], $bersih['master_id'],
            $bersih['barcode'], $bersih['sku'], $bersih['nama'], $bersih['jumlah'],
            $bersih['status'], $bersih['keterangan'], $masukId, $id,
        ]);
    });

    catatAktivitas('update', 'retur', $id, [
        'nama'       => $nama,
        'barcode'    => $barcode,
        'jumlah'     => $jumlah,
        'no_pesanan' => $noPesanan,
        'status'     => $status,
    ]);

    jsonOk([
        'id'         => $id,
        'peringatan' => $peringatan,
        'pesan'      => 'Retur diperbarui.',
    ]);
}

/* --- Tambah -------------------------------------------------------------- */
$baruId = dbTransaksi(static function (PDO $pdo) use ($bersih) {
    $masukId = sinkronMasukRetur($pdo, $bersih, null);

    $st = $pdo->prepare(
        'INSERT INTO retur
            (tanggal, no_pesanan, master_id, barcode, sku, nama, jumlah, status,
             keterangan, masuk_id, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $bersih['tanggal'], $bersih['no_pesanan'], $bersih['master_id'],
        $bersih['barcode'], $bersih['sku'], $bersih['nama'], $bersih['jumlah'],
        $bersih['status'], $bersih['keterangan'], $masukId, userId(),
    ]);
    return (int)$pdo->lastInsertId();
});

catatAktivitas('create', 'retur', $baruId, [
    'nama'       => $nama,
    'barcode'    => $barcode,
    'jumlah'     => $jumlah,
    'no_pesanan' => $noPesanan,
    'status'     => $status,
]);

// Hanya barang yang dikenal master yang benar-benar menambah stok; untuk
// yang tidak dikenal peringatannya sudah dikirim di atas, dan menambahkan
// "stok bertambah" di sini hanya akan bertentangan dengan peringatan itu.
if ($status === STATUS_RETUR_MASUK && $master !== null) {
    $peringatan[] = 'Stok bertambah ' . $jumlah . ' pcs lewat barang masuk "' . KET_RETUR_MASUK . '".';
}

jsonOk([
    'id'         => $baruId,
    'peringatan' => $peringatan,
    'pesan'      => 'Retur dicatat.',
]);
