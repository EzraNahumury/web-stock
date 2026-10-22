<?php
/**
 * includes/deadstok.php — barang yang ada di gudang tapi tidak bisa dijual.
 *
 * MENGAPA MENCATAT DEADSTOK TIDAK MENGURANGI STOK
 * Barang rusak dan barang yang belum siap jual masih berada di rak. Stok
 * opname menghitung rak. Kalau mencatat deadstok langsung mengurangi
 * stok_akhir, setiap opname akan melaporkan selisih palsu tepat pada
 * barang-barang itu — dan justru laporan itulah yang paling dipercaya gudang.
 *
 * Yang dibutuhkan gudang bukan "stoknya berkurang", melainkan "dari sekian
 * stok, berapa yang benar-benar bisa dijual". Angka itu dihitung saat
 * ditampilkan:
 *
 *     siap jual = stok_akhir - SUM(deadstok berstatus tertahan)
 *
 * sehingga tidak ada baris ledger yang dikarang, dan opname tetap cocok
 * dengan rak.
 *
 * SATU-SATUNYA YANG MENGGERAKKAN STOK
 * Hanya status "dibuang". Barangnya memang benar-benar pergi, jadi stoknya
 * memang harus turun — dan turunnya lewat satu baris barang_keluar biasa,
 * bukan lewat angka tersendiri, supaya Riwayat, Dashboard, dan stok opname
 * melihat hal yang sama.
 *
 *   tertahan -> dibuang   baris barang keluar dibuat
 *   dibuang  -> tertahan  baris itu dihapus (soft delete), stok kembali
 *   dibuang  -> beres     baris itu dihapus juga; barangnya ternyata masih ada
 *   tertahan <-> beres    stok tidak bergerak sama sekali
 *
 * keluar_id sengaja TIDAK dikosongkan saat barisnya dihapus, supaya baris yang
 * sama bisa dihidupkan kembali dan statusnya yang bolak-balik tidak melahirkan
 * sampah terhapus bertumpuk — persis alasan yang sama dengan retur.masuk_id.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Dua kategori deadstok: kode => label.
 *
 * @return array<string,string>
 */
function kategoriDeadstok(): array
{
    return KATEGORI_DEADSTOK;
}

/**
 * Tiga keadaan deadstok: kode => label.
 *
 * @return array<string,string>
 */
function statusDeadstok(): array
{
    return STATUS_DEADSTOK;
}

/** Apakah keadaan ini ikut menahan barang dari penjualan? */
function deadstokMenahan(string $status): bool
{
    return $status === 'tertahan';
}

/**
 * Potongan SQL: berapa unit barang ini yang masih tertahan sebagai deadstok.
 *
 * Subquery berkorelasi, bukan JOIN agregat ketiga. Yang membutuhkannya hanya
 * baris yang sedang tampil di layar; sebuah JOIN membuat seluruh katalog
 * 1.435 barang ikut membayar ongkosnya.
 *
 * @param string $kolomId kolom id master di query pemanggil
 */
function sqlDeadstokTertahan(string $kolomId = 'm.id'): string
{
    return "COALESCE((SELECT SUM(d.jumlah) FROM deadstok d
                       WHERE d.master_id = $kolomId
                         AND d.status = 'tertahan'
                         AND d.deleted_at IS NULL), 0)";
}

/**
 * Samakan baris barang_keluar dengan keadaan deadstok sekarang.
 *
 * Dipanggil DI DALAM transaksi milik pemanggil, supaya deadstok dan baris
 * barang keluarnya tidak pernah tersimpan setengah-setengah.
 *
 * @param  PDO   $pdo
 * @param  array $d        data deadstok yang sudah bersih
 * @param  ?int  $keluarId baris barang keluar yang sudah ada, bila ada
 * @return ?int  id barang keluar setelah disamakan (null bila tidak ada)
 */
function sinkronKeluarDeadstok(PDO $pdo, array $d, ?int $keluarId): ?int
{
    $perluKeluar = $d['status'] === 'dibuang';

    /* Barang yang tidak dikenal master tidak pernah membuat baris barang
     * keluar: tanpa master_id barisnya tidak ikut perhitungan stok mana pun,
     * jadi yang lahir hanya baris yatim yang tidak menggerakkan apa-apa dan
     * tidak bisa ditelusuri. Pencatatnya sudah diberi tahu lewat peringatan. */
    if ($perluKeluar && $d['master_id'] === null) {
        return $keluarId;
    }

    if (!$perluKeluar) {
        if ($keluarId !== null) {
            // Soft delete: jejaknya tetap terbaca di log aktivitas.
            $st = $pdo->prepare(
                'UPDATE barang_keluar SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
            );
            $st->execute([$keluarId]);
        }
        // Tautannya dipertahankan supaya baris yang sama bisa dihidupkan lagi.
        return $keluarId;
    }

    if ($keluarId !== null) {
        $st = $pdo->prepare(
            'UPDATE barang_keluar
                SET tanggal = ?, master_id = ?, barcode = ?, nama = ?, jumlah = ?,
                    keterangan = ?, deleted_at = NULL
              WHERE id = ?'
        );
        $st->execute([
            $d['tanggal'], $d['master_id'], $d['barcode'], $d['nama'],
            $d['jumlah'], KET_DEADSTOK_BUANG, $keluarId,
        ]);
        return $keluarId;
    }

    $st = $pdo->prepare(
        'INSERT INTO barang_keluar (tanggal, master_id, barcode, nama, jumlah, keterangan, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['tanggal'], $d['master_id'], $d['barcode'], $d['nama'],
        $d['jumlah'], KET_DEADSTOK_BUANG, userId(),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Berapa unit barang ini yang masih bisa dijual sekarang.
 *
 * Dipakai validasi: menahan lebih banyak daripada yang ada di rak berarti
 * angka siap jualnya jadi negatif, dan angka negatif pada stok tidak pernah
 * berarti apa pun selain salah catat.
 *
 * @return array{stok_akhir:int, tertahan:int, siap:int}
 */
function hitungSiapJual(int $masterId, ?int $kecualiId = null): array
{
    // Fungsi yang sama dengan yang dipakai validasi stok minus di
    // includes/transaksi.php, supaya kedua layar tidak pernah berbeda angka.
    $akhir = stokAkhirItem($masterId);

    /* Baris yang sedang diubah dikeluarkan dari hitungan, supaya menyunting
     * qty-nya tidak dibandingkan dengan dirinya sendiri — tanpa ini, mengubah
     * 5 jadi 6 akan ditolak seolah 11 unit yang ditahan. */
    $sql = "SELECT COALESCE(SUM(jumlah),0) FROM deadstok
             WHERE master_id = ? AND status = 'tertahan' AND deleted_at IS NULL";
    $par = [$masterId];
    if ($kecualiId !== null) {
        $sql .= ' AND id <> ?';
        $par[] = $kecualiId;
    }
    $tertahan = (int)dbValue($sql, $par);

    return [
        'stok_akhir' => $akhir,
        'tertahan'   => $tertahan,
        'siap'       => $akhir - $tertahan,
    ];
}
