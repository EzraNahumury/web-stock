<?php
/**
 * POST api/kategori/delete.php — hapus kategori.
 *
 * Kategori yang masih dipakai barang TIDAK dihapus begitu saja. Karena
 * master_barang.kategori berupa teks, menghapusnya akan meninggalkan barang
 * menunjuk nama yang tidak ada lagi — hilang dari penyaringan tanpa pesan.
 *
 * Ada dua jalan:
 *   - tanpa `pindah_ke`: ditolak bila masih dipakai, disertai jumlahnya
 *   - dengan `pindah_ke`: barangnya dipindahkan dulu, lalu kategori dihapus
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
$pindahKe = ambilStr($in, 'pindah_ke', 30);

if ($id <= 0) {
    jsonError('ID kategori tidak valid.');
}

$k = dbOne('SELECT * FROM kategori WHERE id = ? AND deleted_at IS NULL', [$id]);
if ($k === null) {
    jsonError('Kategori tidak ditemukan.', 404);
}

$nama    = (string)$k['nama'];
$dipakai = (int)dbValue(
    'SELECT COUNT(*) FROM master_barang WHERE kategori = ? AND deleted_at IS NULL',
    [$nama]
);

if ($dipakai > 0 && $pindahKe === '') {
    jsonError(
        'Kategori "' . $nama . '" masih dipakai ' . $dipakai . ' barang. '
        . 'Pindahkan barangnya ke kategori lain dulu.',
        409,
        ['dipakai' => $dipakai, 'nama' => $nama]
    );
}

// Tujuan pemindahan harus kategori lain yang benar-benar ada, atau kosong
// (artinya barangnya dikembalikan ke "tanpa kategori").
if ($dipakai > 0 && $pindahKe !== '') {
    if ($pindahKe === $nama) {
        jsonError('Tujuan pemindahan tidak boleh kategori yang sedang dihapus.');
    }
    $tujuanAda = dbOne(
        'SELECT id FROM kategori WHERE nama = ? AND deleted_at IS NULL LIMIT 1',
        [$pindahKe]
    );
    if ($tujuanAda === null) {
        jsonError('Kategori tujuan tidak ditemukan.');
    }
}

$dipindah = 0;
dbTransaksi(static function (PDO $pdo) use ($id, $nama, $pindahKe, $dipakai, &$dipindah) {
    if ($dipakai > 0) {
        $st = $pdo->prepare(
            'UPDATE master_barang SET kategori = ? WHERE kategori = ? AND deleted_at IS NULL'
        );
        $st->execute([$pindahKe, $nama]);
        $dipindah = $st->rowCount();
    }
    $pdo->prepare('UPDATE kategori SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
});

catatAktivitas('delete', 'kategori', $id, [
    'nama' => $nama, 'dipindah_ke' => $pindahKe, 'jumlah_dipindah' => $dipindah,
]);

$pesan = 'Kategori "' . $nama . '" dihapus.';
if ($dipindah > 0) {
    $pesan .= ' ' . $dipindah . ' barang dipindahkan ke '
        . ($pindahKe !== '' ? '"' . $pindahKe . '"' : 'tanpa kategori') . '.';
}

jsonOk(['pesan' => $pesan, 'dipindah' => $dipindah]);
