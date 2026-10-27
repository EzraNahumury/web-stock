<?php
/**
 * includes/opname.php — penyaring baris stok opname, dipakai bersama.
 *
 * Layar isi sesi, pengisian massal, dan ekspor PDF harus menyaring baris
 * dengan aturan yang sama persis. Kalau tidak, tombol "isi untuk semua
 * baris" bisa mengenai baris yang tidak sedang terlihat — jenis kesalahan
 * yang baru ketahuan setelah ribuan baris terlanjur tertimpa.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Samakan stok sebenarnya dengan hasil hitungan fisik satu baris opname.
 *
 * Dipanggil DI DALAM transaksi milik pemanggil.
 *
 * CARA KERJA
 * Stok akhir di aplikasi ini selalu stok_awal + masuk - keluar, jadi
 * penyesuaian tidak menimpa angka mana pun: ia menulis SATU baris
 * barang masuk / barang keluar berketerangan "Penyesuaian Opname"
 * sebesar selisihnya. Koreksinya lalu terbaca seperti pergerakan lain —
 * ada tanggalnya, jumlahnya, dan pelakunya — dan muncul di Riwayat.
 *
 * SELISIHNYA DIHITUNG TERHADAP STOK SAAT INI, BUKAN stok_sistem
 * stok_sistem adalah potret saat sesi dibuat. Kalau dipakai sebagai dasar,
 * transaksi yang terjadi sesudahnya akan terhitung dua kali dan stok
 * akhirnya meleset dari hitungan fisik. Yang dijanjikan ke pemakai adalah
 * "stok akhir jadi sama dengan stok hitung", jadi dasarnya harus stok yang
 * berlaku sekarang.
 *
 * Baris koreksi lama selalu dibatalkan lebih dulu supaya perhitungannya
 * tidak menghitung dirinya sendiri, lalu dipakai ulang bila arahnya sama.
 *
 * @param  PDO   $pdo
 * @param  array $item  baris opname_item apa adanya dari database
 * @param  bool  $sesuaikan  true bila penyesuaiannya aktif
 * @return array{jenis:?string, id:?int, qty:?int}
 */
function sinkronPenyesuaianStok(PDO $pdo, array $item, bool $sesuaikan): array
{
    $adjJenis = $item['adj_jenis'] !== null ? (string)$item['adj_jenis'] : null;
    $adjId    = $item['adj_id']    !== null ? (int)$item['adj_id']       : null;
    $kosong   = ['jenis' => null, 'id' => null, 'qty' => null];

    // Langkah 1: batalkan koreksi lama, apa pun keputusannya. Tanpa ini,
    // stok yang dihitung di langkah 3 masih memuat koreksi sebelumnya.
    if ($adjId !== null && $adjJenis !== null) {
        $tabelLama = $adjJenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';
        $pdo->prepare("UPDATE $tabelLama SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")
            ->execute([$adjId]);
    }

    if (!$sesuaikan) {
        return $kosong;
    }

    $masterId = $item['master_id'] !== null ? (int)$item['master_id'] : null;
    $hitung   = $item['stok_hitung'] !== null ? (int)$item['stok_hitung'] : null;

    // Tanpa salah satu dari keduanya tidak ada yang bisa disesuaikan.
    // Pemanggil sudah menolak lebih dulu; ini jaring pengaman terakhir.
    if ($masterId === null || $hitung === null) {
        return $kosong;
    }

    // Langkah 2 & 3: stok yang berlaku sekarang, lalu selisihnya.
    $delta = $hitung - stokAkhirItem($masterId);
    if ($delta === 0) {
        // Sudah sama. Tidak ada baris yang perlu ditulis, dan koreksi lama
        // sudah dibatalkan di langkah 1.
        return ['jenis' => null, 'id' => null, 'qty' => 0];
    }

    $jenis = $delta > 0 ? 'masuk' : 'keluar';
    $qty   = abs($delta);
    $tabel = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';
    $hariIni = date('Y-m-d');

    // Pakai ulang baris lama bila arahnya sama, supaya bolak-balik memilih
    // penyesuaian tidak menumpuk baris terhapus.
    if ($adjId !== null && $adjJenis === $jenis) {
        $pdo->prepare(
            "UPDATE $tabel
                SET tanggal = ?, master_id = ?, barcode = ?, nama = ?, jumlah = ?,
                    keterangan = ?, deleted_at = NULL
              WHERE id = ?"
        )->execute([
            $hariIni, $masterId, $item['barcode'], $item['nama'], $qty,
            KET_PENYESUAIAN, $adjId,
        ]);
        return ['jenis' => $jenis, 'id' => $adjId, 'qty' => $qty];
    }

    // no_pesanan pada barang_keluar punya DEFAULT '', jadi tidak perlu
    // disebut — kolomnya sama untuk kedua arah.
    $st = $pdo->prepare(
        "INSERT INTO $tabel (tanggal, master_id, barcode, nama, jumlah, keterangan, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([$hariIni, $masterId, $item['barcode'], $item['nama'], $qty, KET_PENYESUAIAN, userId()]);

    return ['jenis' => $jenis, 'id' => (int)$pdo->lastInsertId(), 'qty' => $qty];
}

/**
 * Bangun WHERE untuk baris opname pada satu sesi.
 *
 * @param  array $src  sumber penyaring ($_GET atau body JSON)
 * @param  int   $sesiId
 * @return array{where:string, params:array, hanya:string, kategori:string, q:string}
 */
/**
 * Baris laporan satu sesi opname, untuk diunduh.
 *
 * Dipakai BERSAMA oleh ekspor PDF dan ekspor Excel. Sengaja satu fungsi: dua
 * salinan query yang sama akan menyimpang cepat atau lambat, dan selisihnya
 * muncul sebagai laporan PDF dan laporan Excel yang isinya berbeda untuk sesi
 * yang sama — jenis selisih yang paling sulit dipercaya ketika ketahuan.
 *
 * Penyaringnya sengaja hanya q dan kategori, sama seperti yang dipakai kedua
 * tombol unduh di layar.
 *
 * @return array<int,array<string,mixed>>
 */
function dataLaporanOpname(int $sesiId, string $q, string $kategori): array
{
    $where  = ['i.sesi_id = ?'];
    $params = [$sesiId];

    if ($q !== '') {
        $where[] = '(i.nama LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)';
        $pola = polaLike($q);
        array_push($params, $pola, $pola, $pola);
    }
    if ($kategori !== '' && $kategori !== 'Semua') {
        $where[] = 'i.kategori = ?';
        $params[] = $kategori;
    }

    return dbAll(
        'SELECT * FROM opname_item i WHERE ' . implode(' AND ', $where)
        . ' ORDER BY i.kategori, i.nama, i.id',
        $params
    );
}

function filterOpnameItem(array $src, int $sesiId): array
{
    $q        = ambilStr($src, 'q', 100);
    $kategori = ambilStr($src, 'kategori', 30);
    $hanya    = pilihanValid(ambilStr($src, 'hanya', 20), ['semua', 'selisih', 'belum', 'disesuaikan']);

    $where  = ['i.sesi_id = ?'];
    $params = [$sesiId];

    if ($q !== '') {
        $where[] = '(i.nama LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)';
        $pola = polaLike($q);
        array_push($params, $pola, $pola, $pola);
    }
    if ($kategori !== '' && $kategori !== 'Semua') {
        $where[] = 'i.kategori = ?';
        $params[] = $kategori;
    }

    if ($hanya === 'selisih') {
        /* Terhadap stok akhir YANG BERLAKU SEKARANG, sejalan dengan kolom
         * Selisih di layar dan dengan apa yang dikerjakan penyesuaian stok.
         * Subquery, bukan join: pemanggilnya menyusun WHERE-nya sendiri dan
         * menambahkan join di sini akan menggandakan barisnya. */
        $where[] = "i.stok_hitung IS NOT NULL AND i.stok_hitung <> (
            CASE WHEN i.master_id IS NULL THEN i.stok_sistem ELSE (
                SELECT m.stok_awal
                     + COALESCE((SELECT SUM(jumlah) FROM barang_masuk
                                  WHERE master_id = m.id AND deleted_at IS NULL), 0)
                     - COALESCE((SELECT SUM(jumlah) FROM barang_keluar
                                  WHERE master_id = m.id AND deleted_at IS NULL), 0)
                  FROM master_barang m WHERE m.id = i.master_id
            ) END)";
    } elseif ($hanya === 'belum') {
        $where[] = 'i.stok_hitung IS NULL';
    } elseif ($hanya === 'disesuaikan') {
        $where[] = 'i.penyesuaian = ?';
        $params[] = PENYESUAIAN_DISESUAIKAN;
    }

    return [
        'where'    => 'WHERE ' . implode(' AND ', $where),
        'params'   => $params,
        'hanya'    => $hanya,
        'kategori' => $kategori,
        'q'        => $q,
    ];
}
