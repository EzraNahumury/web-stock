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

    /* --- Membekukan angkanya saat sesi ditutup --------------------------
     *
     * Selama sesinya berjalan, kolom "Stok akhir" menampilkan stok yang
     * BERLAKU SEKARANG — harus begitu, karena penyesuaian stok membawa stok
     * hidup ke angka hitungan fisik, jadi selisih yang ditampilkan mesti
     * dihitung terhadap angka yang sama.
     *
     * Begitu ditutup, laporannya berubah sifat: ia jadi dokumen bulan itu.
     * Kalau angkanya ikut bergeser, membukanya bulan depan tidak bisa dipakai
     * menelusuri selisih antarbulan — padahal justru itu gunanya disimpan.
     * Jadi stok yang berlaku saat penutupan disalin sekali ke stok_tutup, dan
     * sesudah itu laporannya membaca salinan itu.
     *
     * Dibuka kembali -> salinannya dihapus, laporannya hidup lagi. Tanpa itu,
     * sesi yang dibuka untuk diperbaiki akan menampilkan angka beku sementara
     * koreksinya bekerja pada stok hidup — persis ketidakcocokan yang hendak
     * dihindari. */
    $tutupSekarang = $status === 'selesai' && $lama['status'] !== 'selesai';
    $bukaLagi      = $status !== 'selesai' && $lama['status'] === 'selesai';

    dbTransaksi(static function (PDO $pdo) use (
        $id, $nama, $periode, $tanggal, $status, $catatan, $tutupSekarang, $bukaLagi
    ) {
        $pdo->prepare(
            'UPDATE opname_sesi SET nama = ?, periode = ?, tanggal = ?, status = ?, catatan = ?
              WHERE id = ?'
        )->execute([$nama, $periode, $tanggal, $status, $catatan, $id]);

        if ($tutupSekarang) {
            /* Satu UPDATE untuk seluruh baris sesi ini. Barang yang tidak
             * terhubung ke master memakai salinan awalnya: tidak ada stok
             * hidup yang bisa dihitung untuknya, dan memang tidak pernah bisa
             * disesuaikan juga. */
            $pdo->prepare(
                "UPDATE opname_item i
                    LEFT JOIN master_barang m ON m.id = i.master_id
                      SET i.stok_tutup = CASE WHEN i.master_id IS NULL THEN i.stok_sistem ELSE (
                              m.stok_awal
                              + COALESCE((SELECT SUM(jumlah) FROM barang_masuk
                                           WHERE master_id = m.id AND deleted_at IS NULL), 0)
                              - COALESCE((SELECT SUM(jumlah) FROM barang_keluar
                                           WHERE master_id = m.id AND deleted_at IS NULL), 0)
                          ) END
                    WHERE i.sesi_id = ?"
            )->execute([$id]);
        } elseif ($bukaLagi) {
            $pdo->prepare('UPDATE opname_item SET stok_tutup = NULL WHERE sesi_id = ?')
                ->execute([$id]);
        }
    });

    catatAktivitas('update', 'opname', $id, array_filter([
        'nama'    => $nama,
        'periode' => $periode,
        'aksi'    => $tutupSekarang ? 'tutup sesi, angkanya dibekukan'
            : ($bukaLagi ? 'buka kembali sesi, angkanya hidup lagi' : ''),
    ], static function ($v) { return $v !== ''; }));

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
