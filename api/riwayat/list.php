<?php
/**
 * GET api/riwayat/list.php — riwayat gabungan barang masuk dan keluar.
 *
 * Halaman Barang masuk dan Barang keluar hanya menampilkan satu arah.
 * Untuk menelusuri pergerakan sebuah barang, atau melihat apa saja yang
 * terjadi pada rentang tanggal tertentu, keduanya perlu berdampingan dan
 * terurut waktu.
 *
 * Parameter: q, dari, sampai, jenis (semua|masuk|keluar), page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$q      = ambilStr($_GET, 'q', 100);
$dari   = trim((string)($_GET['dari'] ?? ''));
$sampai = trim((string)($_GET['sampai'] ?? ''));
$jenis  = ambilStr($_GET, 'jenis', 10);
$page   = ambilHalaman();

if (!in_array($jenis, ['masuk', 'keluar'], true)) {
    $jenis = 'semua';
}

/**
 * Bangun satu cabang UNION. Kolom disamakan antara kedua tabel supaya
 * hasilnya bisa diurutkan sebagai satu deret.
 */
$cabang = static function (string $arah) use ($q, $dari, $sampai): array {
    $tabel = $arah === 'masuk' ? 'barang_masuk' : 'barang_keluar';
    $isKel = $arah === 'keluar';

    $where  = ['t.deleted_at IS NULL'];
    $params = [];

    if ($q !== '') {
        $pola = polaLike($q);
        if ($isKel) {
            $where[] = '(t.nama LIKE ? OR t.barcode LIKE ? OR t.no_pesanan LIKE ?)';
            array_push($params, $pola, $pola, $pola);
        } else {
            $where[] = '(t.nama LIKE ? OR t.barcode LIKE ?)';
            array_push($params, $pola, $pola);
        }
    }
    if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
        $where[] = 't.tanggal >= ?';
        $params[] = $dari;
    }
    if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
        $where[] = 't.tanggal <= ?';
        $params[] = $sampai;
    }

    $noPesanan = $isKel ? 't.no_pesanan' : "''";
    $sql = "SELECT '$arah' AS arah, t.id, t.tanggal, t.barcode, t.nama, t.jumlah,
                   t.keterangan, t.master_id, t.created_at, $noPesanan AS no_pesanan,
                   t.user_id
              FROM $tabel t
             WHERE " . implode(' AND ', $where);

    return [$sql, $params];
};

$bagian = [];
$params = [];
foreach (['masuk', 'keluar'] as $arah) {
    if ($jenis !== 'semua' && $jenis !== $arah) {
        continue;
    }
    [$sql, $p] = $cabang($arah);
    $bagian[] = '(' . $sql . ')';
    $params = array_merge($params, $p);
}

$gabung = implode(' UNION ALL ', $bagian);

$total = (int)dbValue("SELECT COUNT(*) FROM ($gabung) g", $params);

$totalMasuk  = (int)dbValue("SELECT COALESCE(SUM(jumlah),0) FROM ($gabung) g WHERE arah='masuk'", $params);
$totalKeluar = (int)dbValue("SELECT COALESCE(SUM(jumlah),0) FROM ($gabung) g WHERE arah='keluar'", $params);

$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT g.*, u.nama_lengkap AS oleh
       FROM ($gabung) g
       LEFT JOIN users u ON u.id = g.user_id
      ORDER BY g.tanggal DESC, g.created_at DESC, g.id DESC
      LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['jumlah']    = (int)$r['jumlah'];
    $r['master_id'] = $r['master_id'] === null ? null : (int)$r['master_id'];
    unset($r['user_id']);
}
unset($r);

// Saldo stok barang pada akhir tanggal masing-masing catatan. Barang yang
// barcodenya belum terdaftar di master tidak punya saldo — dibiarkan null.
$saldo = saldoHarian(array_column($rows, 'master_id'));
foreach ($rows as &$r) {
    $r['stok_akhir'] = ($r['master_id'] !== null && isset($saldo[$r['master_id']][$r['tanggal']]))
        ? $saldo[$r['master_id']][$r['tanggal']]
        : null;
}
unset($r);

jsonOk([
    'rows'         => $rows,
    'total_masuk'  => $totalMasuk,
    'total_keluar' => $totalKeluar,
    'selisih'      => $totalMasuk - $totalKeluar,
] + $meta);
