<?php
/**
 * POST api/master/save.php — tambah atau ubah master barang.
 * Body: { id?, sku, barcode, nama, stok_awal, stok_minimal, kategori }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id          = ambilInt($in, 'id', 0);
$sku         = ambilStr($in, 'sku', 50);
$barcode     = ambilStr($in, 'barcode', 50);
$nama        = ambilStr($in, 'nama', 255);
$stokAwal    = ambilInt($in, 'stok_awal', 0);
$stokMinimal = ambilInt($in, 'stok_minimal', 0);
$kategori    = ambilStr($in, 'kategori', 30);

// --- Validasi -------------------------------------------------------------
if ($barcode === '' || $nama === '') {
    jsonError('Barcode dan nama barang wajib diisi.');
}
if ($stokAwal < 0 || $stokMinimal < 0) {
    jsonError('Stok awal dan stok minimal tidak boleh negatif.');
}
// Kategori boleh kosong — audit D7: seluruh data seed berkategori kosong,
// jadi memaksa kategori akan diam-diam mengubah data lama saat diedit.
if ($kategori !== '' && !in_array($kategori, daftarKategori(), true)) {
    jsonError('Kategori tidak dikenal.');
}

// Barcode harus unik, kecuali terhadap dirinya sendiri saat mengubah.
$bentrok = dbOne(
    'SELECT id, nama FROM master_barang WHERE barcode = ? AND id <> ? AND deleted_at IS NULL LIMIT 1',
    [$barcode, $id]
);
if ($bentrok !== null) {
    jsonError('Barcode "' . $barcode . '" sudah dipakai barang lain: ' . $bentrok['nama']);
}

// --- Simpan ---------------------------------------------------------------
if ($id > 0) {
    $lama = dbOne('SELECT * FROM master_barang WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Barang tidak ditemukan.', 404);
    }

    // Barcode yang diisi manual dianggap asli lagi.
    $asli = ($barcode !== $lama['barcode']) ? 1 : (int)$lama['barcode_asli'];

    dbExec(
        'UPDATE master_barang
            SET sku = ?, barcode = ?, nama = ?, stok_awal = ?, stok_minimal = ?,
                kategori = ?, barcode_asli = ?
          WHERE id = ?',
        [$sku, $barcode, $nama, $stokAwal, $stokMinimal, $kategori, $asli, $id]
    );

    catatAktivitas('update', 'master', $id, [
        'nama'    => $nama,
        'barcode' => $barcode,
        'sebelum' => [
            'nama'         => $lama['nama'],
            'barcode'      => $lama['barcode'],
            'stok_awal'    => (int)$lama['stok_awal'],
            'stok_minimal' => (int)$lama['stok_minimal'],
        ],
    ]);

    jsonOk(['id' => $id, 'pesan' => 'Perubahan tersimpan.']);
}

dbExec(
    'INSERT INTO master_barang (sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli)
     VALUES (?, ?, ?, ?, ?, ?, 1)',
    [$sku, $barcode, $nama, $stokAwal, $stokMinimal, $kategori]
);
$baruId = dbLastId();

catatAktivitas('create', 'master', $baruId, ['nama' => $nama, 'barcode' => $barcode]);

jsonOk(['id' => $baruId, 'pesan' => 'Barang ditambahkan.']);
