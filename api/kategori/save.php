<?php
/**
 * POST api/kategori/save.php — tambah atau ubah kategori.
 *
 * Mengganti nama kategori juga memperbarui seluruh baris master_barang yang
 * memakainya, dalam satu transaksi. Tanpa itu, barangnya akan menunjuk ke
 * nama kategori yang sudah tidak ada dan hilang dari penyaringan.
 *
 * Body: { id?, nama, keterangan, urutan, aktif }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();          // hanya admin yang boleh mengubah daftar kategori

$in = jsonInput();
wajibCsrf($in);

$id         = ambilInt($in, 'id', 0);
$nama       = mb_strtoupper(ambilStr($in, 'nama', 30));
$keterangan = ambilStr($in, 'keterangan', 120);
$urutan     = ambilInt($in, 'urutan', 0);
$aktif      = !empty($in['aktif']) ? 1 : 0;

if ($nama === '') {
    jsonError('Nama kategori wajib diisi.');
}
if (!preg_match('/^[A-Z0-9 _-]+$/u', $nama)) {
    jsonError('Nama kategori hanya boleh huruf, angka, spasi, - dan _.');
}

// Unik, kecuali terhadap dirinya sendiri saat mengubah.
$bentrok = dbOne(
    'SELECT id FROM kategori WHERE nama = ? AND id <> ? AND deleted_at IS NULL LIMIT 1',
    [$nama, $id]
);
if ($bentrok !== null) {
    jsonError('Kategori "' . $nama . '" sudah ada.');
}

/* ---------------------------- Ubah ---------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM kategori WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Kategori tidak ditemukan.', 404);
    }

    $namaLama = (string)$lama['nama'];
    $ikut = 0;

    dbTransaksi(static function (PDO $pdo) use ($id, $nama, $keterangan, $urutan, $aktif, $namaLama, &$ikut) {
        $st = $pdo->prepare(
            'UPDATE kategori SET nama = ?, keterangan = ?, urutan = ?, aktif = ? WHERE id = ?'
        );
        $st->execute([$nama, $keterangan, $urutan, $aktif, $id]);

        if ($nama !== $namaLama) {
            $st2 = $pdo->prepare(
                'UPDATE master_barang SET kategori = ? WHERE kategori = ? AND deleted_at IS NULL'
            );
            $st2->execute([$nama, $namaLama]);
            $ikut = $st2->rowCount();
        }
    });

    catatAktivitas('update', 'kategori', $id, [
        'dari' => $namaLama, 'jadi' => $nama, 'barang_ikut_berubah' => $ikut,
    ]);

    $pesan = 'Kategori tersimpan.';
    if ($ikut > 0) {
        $pesan = 'Kategori diubah. ' . $ikut . ' barang ikut diperbarui.';
    }
    jsonOk(['id' => $id, 'pesan' => $pesan, 'barang_ikut' => $ikut]);
}

/* --------------------------- Tambah --------------------------------- */
if ($urutan === 0) {
    $urutan = (int)dbValue('SELECT COALESCE(MAX(urutan), 0) + 10 FROM kategori');
}

dbExec(
    'INSERT INTO kategori (nama, keterangan, urutan, aktif) VALUES (?, ?, ?, ?)',
    [$nama, $keterangan, $urutan, $aktif]
);
$baruId = dbLastId();

catatAktivitas('create', 'kategori', $baruId, ['nama' => $nama]);

jsonOk(['id' => $baruId, 'pesan' => 'Kategori "' . $nama . '" ditambahkan.']);
