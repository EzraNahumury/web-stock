<?php
/**
 * POST api/deadstok/save.php — catat atau ubah satu baris barang deadstok.
 *
 * Body: { id?, tanggal, barcode, sku, nama, jumlah, kategori, keterangan }
 *
 * Statusnya TIDAK diatur di sini. Baris baru selalu lahir "tertahan", dan
 * perpindahan keadaannya lewat api/deadstok/status.php — satu-satunya tempat
 * yang bisa menggerakkan stok. Memisahkannya begitu membuat jalur yang
 * menyentuh stok tetap sempit dan mudah dibaca ulang.
 *
 * Mencatat deadstok baru boleh dilakukan operator; dialah yang memegang
 * barangnya. MENGUBAH baris yang sudah tersimpan hanya untuk admin: baris yang
 * sudah berstatus "dibuang" punya baris barang keluar, jadi mengubah qty atau
 * tanggalnya menggeser stok.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/deadstok.php';
require_once __DIR__ . '/../../includes/izin.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id > 0 && !bolehUbahCatatan()) {
    jsonError('Mengubah catatan deadstok yang sudah tersimpan hanya bisa dilakukan admin.', 403);
}

$tanggal  = ambilTanggal($in, 'tanggal');
$barcode  = ambilStr($in, 'barcode', 50);
$sku      = ambilStr($in, 'sku', 50);
$nama     = ambilStr($in, 'nama', 255);
$jumlah   = ambilInt($in, 'jumlah', 0);
$kategori = ambilStr($in, 'kategori', 20);
$ket      = ambilStr($in, 'keterangan', 255);

if ($tanggal === null) {
    jsonError('Format tanggal tidak valid.');
}
if (!isset(KATEGORI_DEADSTOK[$kategori])) {
    jsonError('Pilih kategorinya: ' . implode(' atau ', array_values(KATEGORI_DEADSTOK)) . '.');
}
if ($barcode === '' && $sku === '') {
    jsonError('Isi barcode atau SKU barangnya dulu.');
}
if ($jumlah <= 0) {
    jsonError('Qty harus lebih dari 0.');
}

$peringatan = [];

// Barang dicari lewat barcode dulu, lalu SKU — sama seperti pada retur.
$cari   = cariMasterBarcodeAtauSku($barcode, $sku);
$master = $cari['master'];

if ($master !== null) {
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
    $peringatan[] = 'Barang ini belum terdaftar di master. Catatannya tetap disimpan, tapi '
        . 'belum bisa dihitung terhadap stok — dan karena itu belum bisa ditandai dibuang.';
}

$lama = null;
if ($id > 0) {
    $lama = dbOne('SELECT * FROM deadstok WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Catatan deadstok tidak ditemukan.', 404);
    }
}

/* Status baris lama dipertahankan; yang memindahkannya hanya status.php.
 * Tanpa ini, menyunting qty sebuah baris "dibuang" akan diam-diam
 * mengembalikannya jadi "tertahan" dan stok yang sudah turun naik lagi. */
$status = $lama === null ? 'tertahan' : (string)$lama['status'];

$bersih = [
    'tanggal'    => $tanggal,
    'master_id'  => $master ? (int)$master['id'] : null,
    'barcode'    => $barcode,
    'sku'        => $sku,
    'nama'       => $nama,
    'jumlah'     => $jumlah,
    'kategori'   => $kategori,
    'keterangan' => $ket,
    'status'     => $status,
];

/* --- Berapa yang sebenarnya ada di rak --------------------------------- */
if ($bersih['master_id'] !== null) {
    $hitung = hitungSiapJual($bersih['master_id'], $id > 0 ? $id : null);

    if ($status === 'dibuang') {
        /* Baris yang sudah dibuang menulis baris barang keluar, jadi aturannya
         * sama persis dengan menu Barang keluar: tidak boleh melebihi stok.
         * Lihat IZINKAN_STOK_MINUS di config. */
        $tersedia = $hitung['stok_akhir'];
        if ($jumlah > $tersedia && !IZINKAN_STOK_MINUS) {
            jsonError(
                'Stok tidak cukup untuk dibuang sebanyak itu. Tersedia ' . $tersedia
                    . ', diminta ' . $jumlah . '.',
                422,
                ['stok_tersedia' => $tersedia]
            );
        }
    } elseif ($jumlah > $hitung['siap']) {
        /* Menahan lebih banyak daripada yang terhitung siap jual TIDAK ditolak.
         * Barang rusak yang sedang dipegang petugas itu nyata; menolaknya
         * berarti melarang mencatat apa yang terlihat di depan mata. Yang
         * terjadi justru kebalikannya: angka siap jual yang jadi minus adalah
         * tanda bahwa stok sistemnya yang perlu diperiksa. */
        $peringatan[] = 'Setelah ini angka siap jual jadi ' . ($hitung['siap'] - $jumlah)
            . ' (stok ' . $hitung['stok_akhir'] . ', tertahan ' . ($hitung['tertahan'] + $jumlah) . '). '
            . 'Tetap dicatat — periksa stok sistemnya, atau qty yang baru saja diisi.';
    }
}

/* --- Ubah --------------------------------------------------------------- */
if ($id > 0) {
    $keluarLama = $lama['keluar_id'] === null ? null : (int)$lama['keluar_id'];

    dbTransaksi(static function (PDO $pdo) use ($id, $bersih, $keluarLama) {
        // Baris barang keluarnya ikut disamakan, supaya qty dan tanggalnya
        // tidak pernah berbeda dengan catatan deadstoknya.
        $keluarId = sinkronKeluarDeadstok($pdo, $bersih, $keluarLama);

        $st = $pdo->prepare(
            'UPDATE deadstok
                SET tanggal = ?, master_id = ?, barcode = ?, sku = ?, nama = ?,
                    jumlah = ?, kategori = ?, keterangan = ?, keluar_id = ?
              WHERE id = ?'
        );
        $st->execute([
            $bersih['tanggal'], $bersih['master_id'], $bersih['barcode'], $bersih['sku'],
            $bersih['nama'], $bersih['jumlah'], $bersih['kategori'], $bersih['keterangan'],
            $keluarId, $id,
        ]);
    });

    catatAktivitas('update', 'deadstok', $id, [
        'nama'     => $nama,
        'barcode'  => $barcode,
        'jumlah'   => $jumlah,
        'kategori' => $kategori,
        'status'   => $status,
    ]);

    jsonOk([
        'id'         => $id,
        'peringatan' => $peringatan,
        'pesan'      => 'Catatan deadstok diperbarui.',
    ]);
}

/* --- Tambah -------------------------------------------------------------- */
$baruId = dbTransaksi(static function (PDO $pdo) use ($bersih) {
    $st = $pdo->prepare(
        'INSERT INTO deadstok
            (tanggal, master_id, barcode, sku, nama, jumlah, kategori, keterangan, status, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $bersih['tanggal'], $bersih['master_id'], $bersih['barcode'], $bersih['sku'],
        $bersih['nama'], $bersih['jumlah'], $bersih['kategori'], $bersih['keterangan'],
        $bersih['status'], userId(),
    ]);
    return (int)$pdo->lastInsertId();
});

catatAktivitas('create', 'deadstok', $baruId, [
    'nama'     => $nama,
    'barcode'  => $barcode,
    'jumlah'   => $jumlah,
    'kategori' => $kategori,
]);

$peringatan[] = 'Stok TIDAK berkurang: barangnya masih di rak, jadi stok opname harus tetap '
    . 'menemukannya. Yang berkurang angka siap jualnya. Stok baru turun bila nanti ditandai dibuang.';

jsonOk([
    'id'         => $baruId,
    'peringatan' => $peringatan,
    'pesan'      => 'Barang dicatat sebagai ' . KATEGORI_DEADSTOK[$kategori] . '.',
]);
