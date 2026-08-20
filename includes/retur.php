<?php
/**
 * includes/retur.php — sambungan retur ke barang masuk.
 *
 * Retur yang sudah "Lengkap" berarti barangnya benar-benar kembali ke rak,
 * jadi stoknya harus naik. Daripada meminta petugas mencatat dua kali —
 * sekali di Retur, sekali di Barang masuk — baris barang masuknya dibuat
 * sendiri oleh sistem dan id-nya disimpan di retur.masuk_id.
 *
 * Karena tertaut, keduanya selalu bergerak bersama:
 *   status jadi Lengkap        -> baris barang masuk dibuat
 *   jumlah / tanggal / barang berubah -> baris itu ikut diperbarui
 *   status kembali belum selesai -> baris itu dihapus (soft delete)
 *   status jadi Lengkap lagi     -> baris yang sama dihidupkan kembali
 *   retur dihapus              -> baris itu ikut dihapus
 *
 * masuk_id sengaja TIDAK dikosongkan saat baris itu dihapus. Kalau
 * dikosongkan, setiap kali statusnya bolak-balik akan lahir baris barang
 * masuk baru dan yang lama menumpuk sebagai sampah terhapus. Karena itu
 * yang menentukan "retur ini menambah stok" adalah statusnya, bukan ada
 * tidaknya masuk_id.
 *
 * Tanpa aturan terakhir, stok akan tetap memuat barang dari retur yang
 * sudah dibatalkan, dan selisihnya baru ketahuan saat stok opname.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Samakan baris barang_masuk dengan keadaan retur sekarang.
 *
 * Dipanggil DI DALAM transaksi milik pemanggil, supaya retur dan barang
 * masuknya tidak pernah tersimpan setengah-setengah.
 *
 * @param  PDO   $pdo
 * @param  array $r        data retur yang sudah bersih
 * @param  ?int  $masukId  baris barang masuk yang sudah ada, bila ada
 * @return ?int  id barang masuk setelah disamakan (null bila tidak ada)
 */
function sinkronMasukRetur(PDO $pdo, array $r, ?int $masukId): ?int
{
    $perluMasuk = $r['status'] === STATUS_RETUR_MASUK;

    if (!$perluMasuk) {
        if ($masukId !== null) {
            // Soft delete, bukan hapus permanen: jejaknya tetap terbaca di
            // log aktivitas dan di riwayat bila kelak ditelusuri.
            $st = $pdo->prepare(
                'UPDATE barang_masuk SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
            );
            $st->execute([$masukId]);
        }
        // Tautannya dipertahankan supaya baris yang sama bisa dihidupkan lagi.
        return $masukId;
    }

    if ($masukId !== null) {
        // deleted_at dikosongkan lagi: retur ini menyatakan barangnya memang
        // masuk, jadi baris yang sempat dibatalkan harus hidup kembali.
        $st = $pdo->prepare(
            'UPDATE barang_masuk
                SET tanggal = ?, master_id = ?, barcode = ?, nama = ?, jumlah = ?,
                    keterangan = ?, deleted_at = NULL
              WHERE id = ?'
        );
        $st->execute([
            $r['tanggal'], $r['master_id'], $r['barcode'], $r['nama'],
            $r['jumlah'], KET_RETUR_MASUK, $masukId,
        ]);
        return $masukId;
    }

    $st = $pdo->prepare(
        'INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $r['tanggal'], $r['master_id'], $r['barcode'], $r['nama'],
        $r['jumlah'], KET_RETUR_MASUK, userId(),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Cari barang di master lewat barcode, lalu SKU sebagai cadangan.
 *
 * Lembar retur gudang ditulis per SKU, bukan barcode — kolom barcode
 * sering kosong. SKU tidak dijamin unik, jadi yang pertama dipakai dan
 * pemanggil diberi tahu bila ada lebih dari satu.
 *
 * @return array{master:?array, ganda:bool}
 */
function cariMasterReturn(string $barcode, string $sku): array
{
    if ($barcode !== '') {
        $m = cariMasterByBarcode($barcode);
        if ($m !== null) {
            return ['master' => $m, 'ganda' => false];
        }
    }
    if ($sku !== '') {
        $rows = dbAll(
            "SELECT * FROM master_barang
              WHERE deleted_at IS NULL AND sku <> '' AND sku = ?
              ORDER BY id LIMIT 2",
            [$sku]
        );
        if ($rows) {
            return ['master' => $rows[0], 'ganda' => count($rows) > 1];
        }
    }
    return ['master' => null, 'ganda' => false];
}
