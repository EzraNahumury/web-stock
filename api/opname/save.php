<?php
/**
 * POST api/opname/save.php — buat atau ubah sesi stok opname.
 *
 * Body: { id?, nama, periode, tanggal, kategori?, status?, catatan? }
 *
 * Saat sesi dibuat, seluruh barang yang lolos penyaring kategori disalin
 * ke opname_item beserta stok menurut sistem PADA TANGGAL OPNAME. Angka itu
 * dibekukan, tidak dihitung ulang saat laporan dibuka: kalau dihitung ulang,
 * laporan bulan lalu berubah sendiri setiap ada transaksi baru dan tidak
 * bisa lagi dipakai sebagai bukti hitungan.
 *
 * Mengubah sesi tidak pernah menyalin ulang isinya, karena itu akan
 * membuang angka hitungan fisik yang sudah diisi petugas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibAdminApi();   // membuat sesi menyalin ribuan baris — hanya admin

$in = jsonInput();
wajibCsrf($in);

$id       = ambilInt($in, 'id', 0);
$nama     = ambilStr($in, 'nama', 150);
$periode  = ambilStr($in, 'periode', 50);
$tanggal  = ambilTanggal($in, 'tanggal');
$kategori = ambilStr($in, 'kategori', 30);
$status   = pilihanValid(ambilStr($in, 'status', 20), ['draft', 'selesai']);
$catatan  = ambilStr($in, 'catatan', 255);

if ($nama === '') {
    jsonError('Nama laporan wajib diisi.');
}
if ($tanggal === null) {
    jsonError('Format tanggal tidak valid.');
}
if ($kategori === 'Semua') {
    $kategori = '';
}
if ($kategori !== '' && !in_array($kategori, daftarKategori(), true)) {
    jsonError('Kategori tidak dikenal.');
}

/* --- Ubah ---------------------------------------------------------------- */
if ($id > 0) {
    $lama = dbOne('SELECT * FROM opname_sesi WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Sesi opname tidak ditemukan.', 404);
    }

    dbExec(
        'UPDATE opname_sesi SET nama = ?, periode = ?, tanggal = ?, status = ?, catatan = ?
          WHERE id = ?',
        [$nama, $periode, $tanggal, $status, $catatan, $id]
    );

    catatAktivitas('update', 'opname', $id, ['nama' => $nama, 'periode' => $periode]);

    jsonOk([
        'id'    => $id,
        'pesan' => 'Sesi opname diperbarui.',
        // Kategori sengaja tidak ikut diubah: isinya sudah terlanjur disalin
        // menurut kategori lama, mengubahnya hanya akan menyesatkan.
    ]);
}

/* --- Buat + salin isinya ------------------------------------------------- */
$hasil = dbTransaksi(static function (PDO $pdo) use ($nama, $periode, $tanggal, $kategori, $status, $catatan) {
    $st = $pdo->prepare(
        'INSERT INTO opname_sesi (nama, periode, tanggal, kategori, status, catatan, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$nama, $periode, $tanggal, $kategori, $status, $catatan, userId()]);
    $sesiId = (int)$pdo->lastInsertId();

    // Stok menurut sistem sampai dengan tanggal opname.
    $params = [$sesiId, $tanggal, $tanggal];
    $sql = 'INSERT INTO opname_item
              (sesi_id, master_id, sku, barcode, nama, kategori, stok_sistem)
            SELECT ?, m.id, m.sku, m.barcode, m.nama, m.kategori,
                   m.stok_awal + COALESCE(i.total,0) - COALESCE(o.total,0)
              FROM master_barang m
              LEFT JOIN (SELECT master_id, SUM(jumlah) AS total FROM barang_masuk
                          WHERE deleted_at IS NULL AND master_id IS NOT NULL AND tanggal <= ?
                          GROUP BY master_id) i ON i.master_id = m.id
              LEFT JOIN (SELECT master_id, SUM(jumlah) AS total FROM barang_keluar
                          WHERE deleted_at IS NULL AND master_id IS NOT NULL AND tanggal <= ?
                          GROUP BY master_id) o ON o.master_id = m.id
             WHERE m.deleted_at IS NULL AND m.aktif = 1';
    if ($kategori !== '') {
        $sql .= ' AND m.kategori = ?';
        $params[] = $kategori;
    }

    $stIsi = $pdo->prepare($sql);
    $stIsi->execute($params);

    return ['id' => $sesiId, 'jml' => $stIsi->rowCount()];
});

catatAktivitas('create', 'opname', $hasil['id'], [
    'nama'     => $nama,
    'periode'  => $periode,
    'kategori' => $kategori !== '' ? $kategori : 'semua',
    'baris'    => $hasil['jml'],
]);

jsonOk([
    'id'       => $hasil['id'],
    'jml_item' => $hasil['jml'],
    'pesan'    => 'Sesi opname dibuat dengan ' . number_format($hasil['jml'], 0, ',', '.') . ' barang.',
]);
