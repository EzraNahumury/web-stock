<?php
/**
 * POST api/keterangan/delete.php — hapus satu pilihan keterangan.
 *
 * Keterangan tersimpan sebagai teks di barang_masuk / barang_keluar, bukan
 * sebagai relasi. Menghapus pilihan yang masih dipakai akan meninggalkan
 * transaksi memuat nilai yang tidak ada lagi di daftar — hilang dari
 * penyaringan tanpa pesan apa pun. Jadi ada dua jalan, sama seperti kategori:
 *
 *   - tanpa `pindah_ke`: ditolak bila masih dipakai, disertai jumlahnya
 *   - dengan `pindah_ke`: transaksinya dipindahkan dulu, lalu pilihan dihapus
 *
 * Baris terkunci tidak bisa dihapus sama sekali: nilainya dipakai sistem
 * (retur menulis "Retur Masuk"), jadi menghapusnya akan memutus sambungan
 * itu tanpa ada yang memberi tahu.
 *
 * Body: { id, pindah_ke? }
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

$id       = ambilInt($in, 'id', 0);
$pindahKe = ambilStr($in, 'pindah_ke', 50);

if ($id <= 0) {
    jsonError('ID keterangan tidak valid.');
}

$k = dbOne('SELECT * FROM keterangan WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($k === null) {
    jsonError('Keterangan tidak ditemukan.', 404);
}
if ((int)$k['terkunci'] === 1) {
    jsonError(
        'Keterangan "' . $k['nama'] . '" dipakai sistem dan tidak bisa dihapus.',
        409
    );
}

$jenis = (string)$k['jenis'];
$nama  = (string)$k['nama'];
$tabel = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';

$dipakai = (int)dbValue(
    "SELECT COUNT(*) FROM $tabel WHERE keterangan = ? AND deleted_at IS NULL",
    [$nama]
);

if ($dipakai > 0 && $pindahKe === '') {
    jsonError(
        'Keterangan "' . $nama . '" masih dipakai ' . $dipakai . ' catatan. '
        . 'Pindahkan catatannya ke keterangan lain dulu.',
        409,
        ['dipakai' => $dipakai, 'nama' => $nama]
    );
}

if ($dipakai > 0 && $pindahKe !== '') {
    if ($pindahKe === $nama) {
        jsonError('Tujuan pemindahan tidak boleh keterangan yang sedang dihapus.');
    }
    $tujuanAda = dbOne(
        'SELECT id FROM keterangan WHERE jenis = ? AND nama = ? AND deleted_at IS NULL LIMIT 1',
        [$jenis, $pindahKe]
    );
    if ($tujuanAda === null) {
        jsonError('Keterangan tujuan tidak ditemukan di daftar ini.');
    }
}

$dipindah = 0;
dbTransaksi(static function (PDO $pdo) use ($id, $nama, $pindahKe, $dipakai, $tabel, &$dipindah) {
    if ($dipakai > 0) {
        $st = $pdo->prepare(
            "UPDATE $tabel SET keterangan = ? WHERE keterangan = ? AND deleted_at IS NULL"
        );
        $st->execute([$pindahKe, $nama]);
        $dipindah = $st->rowCount();
    }
    $pdo->prepare('UPDATE keterangan SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
});

catatAktivitas('delete', 'keterangan', $id, [
    'jenis' => $jenis, 'nama' => $nama,
    'dipindah_ke' => $pindahKe, 'jumlah_dipindah' => $dipindah,
]);

$pesan = 'Keterangan "' . $nama . '" dihapus.';
if ($dipindah > 0) {
    $pesan .= ' ' . $dipindah . ' catatan dipindahkan ke "' . $pindahKe . '".';
}

jsonOk(['pesan' => $pesan, 'dipindah' => $dipindah]);
