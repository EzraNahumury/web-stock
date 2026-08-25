<?php
/**
 * POST api/keterangan/save.php — tambah atau ubah satu pilihan keterangan.
 *
 * Body: { id?, jenis, nama, catatan?, urutan?, aktif? }
 *
 * MENGGANTI NAMA IKUT MEMPERBARUI TRANSAKSI
 * Keterangan disimpan sebagai teks di barang_masuk / barang_keluar, bukan
 * sebagai relasi. Kalau namanya diubah tanpa memperbarui transaksinya,
 * catatan lama akan memuat nilai yang tidak lagi ada di daftar pilihan dan
 * tidak bisa disaring lagi. Karena itu keduanya diubah dalam satu transaksi.
 *
 * Baris terkunci tidak boleh diganti namanya: nilainya dipakai sistem
 * (retur menulis "Retur Masuk"), jadi mengubahnya akan memutus sambungan itu.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();

$in = jsonInput();
wajibCsrf($in);

$id      = ambilInt($in, 'id', 0);
$jenis   = pilihanValid(ambilStr($in, 'jenis', 10), ['masuk', 'keluar']);
$nama    = ambilStr($in, 'nama', 50);
$catatan = ambilStr($in, 'catatan', 120);
$urutan  = ambilInt($in, 'urutan', 0);
$aktif   = array_key_exists('aktif', $in) ? (!empty($in['aktif']) ? 1 : 0) : 1;

if ($nama === '') {
    jsonError('Nama keterangan wajib diisi.');
}
if ($urutan < 0) {
    jsonError('Urutan tidak boleh negatif.');
}

$tabel = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';

// Unik per arah: "Retur" boleh ada di keluar sekaligus di masuk.
$bentrok = dbOne(
    'SELECT id FROM keterangan WHERE jenis = ? AND nama = ? AND id <> ? AND deleted_at IS NULL LIMIT 1',
    [$jenis, $nama, $id]
);
if ($bentrok !== null) {
    jsonError('Keterangan "' . $nama . '" sudah ada di daftar ini.');
}

/* --- Ubah ---------------------------------------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM keterangan WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Keterangan tidak ditemukan.', 404);
    }
    if ($lama['jenis'] !== $jenis) {
        jsonError('Arah keterangan tidak bisa dipindah. Buat baru di daftar yang satunya.');
    }

    $gantiNama = ($lama['nama'] !== $nama);

    if ((int)$lama['terkunci'] === 1) {
        if ($gantiNama) {
            jsonError(
                'Keterangan "' . $lama['nama'] . '" dipakai sistem, jadi namanya tidak bisa diubah.',
                409
            );
        }
        if ($aktif === 0) {
            jsonError(
                'Keterangan "' . $lama['nama'] . '" dipakai sistem, jadi tidak bisa dinonaktifkan.',
                409
            );
        }
    }

    $ikut = dbTransaksi(static function (PDO $pdo) use ($id, $jenis, $nama, $catatan, $urutan, $aktif, $lama, $gantiNama, $tabel) {
        $n = 0;
        if ($gantiNama) {
            $st = $pdo->prepare("UPDATE $tabel SET keterangan = ? WHERE keterangan = ?");
            $st->execute([$nama, $lama['nama']]);
            $n = $st->rowCount();
        }
        $st = $pdo->prepare(
            'UPDATE keterangan SET jenis = ?, nama = ?, catatan = ?, urutan = ?, aktif = ? WHERE id = ?'
        );
        $st->execute([$jenis, $nama, $catatan, $urutan, $aktif, $id]);
        return $n;
    });

    catatAktivitas('update', 'keterangan', $id, [
        'jenis'   => $jenis,
        'nama'    => $nama,
        'sebelum' => $lama['nama'],
        'ikut'    => $ikut,
    ]);

    jsonOk([
        'id'    => $id,
        'ikut'  => $ikut,
        'pesan' => $ikut > 0
            ? 'Keterangan tersimpan. ' . number_format($ikut, 0, ',', '.')
              . ' catatan transaksi ikut diperbarui.'
            : 'Keterangan tersimpan.',
    ]);
}

/* --- Tambah -------------------------------------------------------------- */
dbExec(
    'INSERT INTO keterangan (jenis, nama, catatan, urutan, aktif) VALUES (?, ?, ?, ?, ?)',
    [$jenis, $nama, $catatan, $urutan, $aktif]
);
$baruId = dbLastId();

catatAktivitas('create', 'keterangan', $baruId, ['jenis' => $jenis, 'nama' => $nama]);

jsonOk(['id' => $baruId, 'pesan' => 'Keterangan "' . $nama . '" ditambahkan.']);
