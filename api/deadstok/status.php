<?php
/**
 * POST api/deadstok/status.php — pindahkan keadaan satu baris deadstok.
 *
 * Body: { id, status }  atau  { ids: [..], status }
 *        status = 'tertahan' | 'beres' | 'dibuang'
 *
 * INILAH SATU-SATUNYA JALUR YANG MENGGERAKKAN STOK DI MENU DEADSTOK.
 *
 *   tertahan  barang rusak / belum siap jual, masih di rak
 *             -> stok TIDAK bergerak; yang berkurang angka siap jualnya
 *   beres     sudah diperbaiki atau sudah siap dijual
 *             -> stok TIDAK bergerak; barangnya memang tidak ke mana-mana
 *   dibuang   barangnya benar-benar dibuang
 *             -> stok BERKURANG, lewat satu baris barang_keluar berketerangan
 *                "Deadstok Dibuang"
 *
 * Pembuangannya memakai ledger biasa, bukan angka tersendiri, supaya Riwayat,
 * Dashboard, dan stok opname melihat hal yang sama. Dan karena itu aturannya
 * ikut aturan Barang keluar: tidak boleh melebihi stok yang tersedia.
 *
 * Membatalkan pembuangan menghapus baris barang keluarnya (soft delete),
 * sehingga stoknya kembali. Tautannya dipertahankan supaya baris yang sama
 * dipakai ulang, bukan beranak setiap kali statusnya bolak-balik.
 *
 * Boleh dilakukan operator: dialah yang memegang barangnya dan yang tahu
 * barang itu sudah siap jual atau sudah dibuang. Supaya tetap bisa
 * ditelusuri, waktu dan pelakunya ikut dicatat.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/deadstok.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$status = ambilStr($in, 'status', 20);
if (!isset(STATUS_DEADSTOK[$status])) {
    jsonError('Keadaan tidak dikenal. Pilihannya: ' . implode(', ', array_keys(STATUS_DEADSTOK)) . '.');
}

// Satu baris atau sekaligus banyak.
$ids = [];
if (isset($in['ids']) && is_array($in['ids'])) {
    foreach ($in['ids'] as $v) {
        $n = (int)$v;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }
    $ids = array_keys($ids);
} else {
    $satu = ambilInt($in, 'id', 0);
    if ($satu > 0) {
        $ids[] = $satu;
    }
}

if (!$ids) {
    jsonError('Tidak ada catatan deadstok yang dipilih.');
}
if (count($ids) > 200) {
    jsonError('Terlalu banyak baris sekaligus (maksimal 200).');
}

$tanda = implode(',', array_fill(0, count($ids), '?'));
$baris = dbAll(
    "SELECT * FROM deadstok WHERE deleted_at IS NULL AND id IN ($tanda) ORDER BY id",
    $ids
);
if (!$baris) {
    jsonError('Catatan deadstok tidak ditemukan.', 404);
}

$peringatan = [];
$akanBerubah = [];

foreach ($baris as $b) {
    if ((string)$b['status'] === $status) {
        continue;   // sudah begitu; tidak perlu disentuh
    }

    /* Barang yang tidak dikenal master tidak bisa dibuang: pembuangan menulis
     * baris barang keluar, dan tanpa master_id baris itu tidak menggerakkan
     * stok apa pun — hanya jadi baris yatim yang menyesatkan. Ditolak dengan
     * terang, bukan diam-diam dilewati. */
    if ($status === 'dibuang' && $b['master_id'] === null) {
        jsonError('"' . $b['nama'] . '" belum terdaftar di master, jadi pembuangannya belum bisa '
            . 'mengurangi stok. Daftarkan barangnya dulu di Master → Barang.', 422);
    }

    $akanBerubah[] = $b;
}

if (!$akanBerubah) {
    jsonOk([
        'diubah' => 0,
        'status' => $status,
        'pesan'  => 'Tidak ada yang berubah; keadaannya memang sudah begitu.',
    ]);
}

/* --- Pembuangan tidak boleh melebihi stok -------------------------------
 *
 * Diperiksa SEBELUM transaksi dan untuk seluruh baris sekaligus, karena
 * beberapa baris bisa menunjuk barang yang sama: masing-masing muat, tapi
 * jumlahnya tidak. Memeriksanya satu per satu di dalam transaksi akan
 * meloloskan yang terakhir dan membuat stok minus. */
if ($status === 'dibuang' && !IZINKAN_STOK_MINUS) {
    $perBarang = [];
    foreach ($akanBerubah as $b) {
        $mid = (int)$b['master_id'];
        if (!isset($perBarang[$mid])) {
            $perBarang[$mid] = ['nama' => $b['nama'], 'jumlah' => 0];
        }
        $perBarang[$mid]['jumlah'] += (int)$b['jumlah'];
    }
    foreach ($perBarang as $mid => $p) {
        $tersedia = stokAkhirItem($mid);
        if ($p['jumlah'] > $tersedia) {
            jsonError(
                'Stok "' . $p['nama'] . '" tidak cukup untuk dibuang sebanyak itu. '
                    . 'Tersedia ' . $tersedia . ', akan dibuang ' . $p['jumlah'] . '.',
                422,
                ['stok_tersedia' => $tersedia]
            );
        }
    }
}

/* Jejak dicatat untuk "beres" MAUPUN "dibuang": keduanya keputusan yang
 * diambil seseorang. Hanya "tertahan" yang berarti belum ada keputusan, jadi
 * jejaknya dikosongkan lagi. */
$adaKeputusan = $status !== 'tertahan';

$diubah = dbTransaksi(static function (PDO $pdo) use ($akanBerubah, $status, $adaKeputusan) {
    $st = $pdo->prepare(
        'UPDATE deadstok
            SET status = ?, keluar_id = ?,
                selesai_at   = ' . ($adaKeputusan ? 'NOW()' : 'NULL') . ',
                selesai_user = ?
          WHERE id = ?'
    );

    $n = 0;
    foreach ($akanBerubah as $b) {
        $d = [
            'tanggal'   => $b['tanggal'],
            'master_id' => $b['master_id'] === null ? null : (int)$b['master_id'],
            'barcode'   => $b['barcode'],
            'nama'      => $b['nama'],
            'jumlah'    => (int)$b['jumlah'],
            'status'    => $status,
        ];
        $keluarId = sinkronKeluarDeadstok(
            $pdo,
            $d,
            $b['keluar_id'] === null ? null : (int)$b['keluar_id']
        );
        $st->execute([$status, $keluarId, $adaKeputusan ? userId() : null, (int)$b['id']]);
        $n++;
    }
    return $n;
});

foreach ($akanBerubah as $b) {
    catatAktivitas('update', 'deadstok', (int)$b['id'], [
        'nama'   => $b['nama'],
        'jumlah' => (int)$b['jumlah'],
        'status' => $status,
    ]);
}

$unit = 0;
foreach ($akanBerubah as $b) {
    $unit += (int)$b['jumlah'];
}

if ($status === 'dibuang') {
    $peringatan[] = 'Stok berkurang ' . $unit . ' pcs lewat barang keluar "' . KET_DEADSTOK_BUANG . '".';
} elseif ($status === 'beres') {
    $peringatan[] = $unit . ' pcs kembali terhitung siap jual. Stok tidak bergerak — '
        . 'barangnya memang tidak ke mana-mana.';
} else {
    $peringatan[] = 'Kembali tertahan: ' . $unit . ' pcs keluar lagi dari hitungan siap jual.';
}

jsonOk([
    'diubah'     => $diubah,
    'status'     => $status,
    'peringatan' => $peringatan,
    'pesan'      => $diubah . ' catatan deadstok ditandai "' . STATUS_DEADSTOK[$status] . '".',
]);
