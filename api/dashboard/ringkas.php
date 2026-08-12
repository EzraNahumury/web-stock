<?php
/**
 * GET api/dashboard/ringkas.php — data untuk panel visual dashboard.
 *
 * Dikirim sekali untuk seluruh panel (status, kategori, pergerakan, perlu
 * order) supaya dashboard tidak menembak lima permintaan saat dibuka.
 *
 * Parameter: hari (rentang pergerakan, default 30)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$hari = ambilInt($_GET, 'hari', 30);
if (!in_array($hari, [7, 30, 90], true)) {
    $hari = 30;
}

$akhir = sqlStokAkhir();

/* --- 1. Sebaran status ---------------------------------------------------
 * Empat keadaan yang saling lepas. Dipakai batang bertumpuk, bukan donat:
 * satu bagian menguasai ~75%, dan donat membuat tiga sisanya tak terbaca. */
$status = dbOne("
    SELECT
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir <= m.stok_minimal THEN 1 ELSE 0 END), 0) AS kritis,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir >  m.stok_minimal
                        AND $akhir <= m.stok_minimal * " . AMBANG_RENDAH . " THEN 1 ELSE 0 END), 0) AS rendah,
      COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir >  m.stok_minimal * " . AMBANG_RENDAH . " THEN 1 ELSE 0 END), 0) AS aman,
      COALESCE(SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END), 0) AS belum_diatur
    FROM master_barang m " . sqlJoinAgregat() . "
    WHERE m.deleted_at IS NULL AND m.aktif = 1");

/* --- 2. Stok per kategori ------------------------------------------------
 * Satu ukuran (unit) dibandingkan antar kategori -> satu warna, bukan
 * palet kategorikal. Warna kategorikal untuk identitas seri yang tumpang
 * tindih; di sini tidak ada seri yang tumpang tindih. */
$kategori = dbAll("
    SELECT m.kategori,
           COUNT(*)                  AS sku,
           COALESCE(SUM($akhir), 0)  AS unit,
           COALESCE(SUM(CASE WHEN m.stok_minimal > 0 AND $akhir <= m.stok_minimal THEN 1 ELSE 0 END), 0) AS kritis
      FROM master_barang m " . sqlJoinAgregat() . "
     WHERE m.deleted_at IS NULL AND m.aktif = 1 AND m.kategori <> ''
     GROUP BY m.kategori
     ORDER BY unit DESC");
foreach ($kategori as &$k) {
    $k['sku']    = (int)$k['sku'];
    $k['unit']   = (int)$k['unit'];
    $k['kritis'] = (int)$k['kritis'];
}
unset($k);

/* --- 3. Pergerakan harian ------------------------------------------------
 * Deret tanggal dibangun penuh di PHP, termasuk hari tanpa transaksi.
 * Kalau hanya memakai baris hasil GROUP BY, hari kosong akan hilang dan
 * garisnya melompat — memberi kesan pergerakan yang tidak pernah terjadi. */
$sejak = date('Y-m-d', strtotime('-' . ($hari - 1) . ' days'));

$masukHarian = dbAll(
    'SELECT tanggal, SUM(jumlah) AS n FROM barang_masuk
      WHERE deleted_at IS NULL AND tanggal >= ? GROUP BY tanggal',
    [$sejak]
);
$keluarHarian = dbAll(
    'SELECT tanggal, SUM(jumlah) AS n FROM barang_keluar
      WHERE deleted_at IS NULL AND tanggal >= ? GROUP BY tanggal',
    [$sejak]
);

$petaMasuk = [];
foreach ($masukHarian as $r) {
    $petaMasuk[$r['tanggal']] = (int)$r['n'];
}
$petaKeluar = [];
foreach ($keluarHarian as $r) {
    $petaKeluar[$r['tanggal']] = (int)$r['n'];
}

$pergerakan = [];
for ($i = $hari - 1; $i >= 0; $i--) {
    $t = date('Y-m-d', strtotime("-$i days"));
    $pergerakan[] = [
        'tanggal' => $t,
        'masuk'   => $petaMasuk[$t] ?? 0,
        'keluar'  => $petaKeluar[$t] ?? 0,
    ];
}

$adaPergerakan = false;
foreach ($pergerakan as $p) {
    if ($p['masuk'] > 0 || $p['keluar'] > 0) {
        $adaPergerakan = true;
        break;
    }
}

/* --- 4. Paling perlu diorder --------------------------------------------
 * Diurutkan menurut kekurangan terhadap ambang, bukan stok terendah:
 * barang dengan stok 0 dan ambang 5 tidak semendesak stok 100 ambang 3.000. */
$perluOrder = dbAll("
    SELECT m.id, m.nama, m.sku, m.barcode, m.kategori, m.stok_minimal,
           $akhir                       AS stok_akhir,
           m.stok_minimal - ($akhir)    AS kurang
      FROM master_barang m " . sqlJoinAgregat() . "
     WHERE m.deleted_at IS NULL AND m.aktif = 1
       AND m.stok_minimal > 0 AND $akhir <= m.stok_minimal
     ORDER BY kurang DESC
     LIMIT 6");
foreach ($perluOrder as &$p) {
    $p['id']           = (int)$p['id'];
    $p['stok_akhir']   = (int)$p['stok_akhir'];
    $p['stok_minimal'] = (int)$p['stok_minimal'];
    $p['kurang']       = (int)$p['kurang'];
}
unset($p);

/* --- 5. Aktivitas terakhir ---------------------------------------------- */
$aktivitas = dbAll("
    (SELECT 'masuk' AS jenis, t.tanggal, t.nama, t.jumlah, t.keterangan, t.created_at
       FROM barang_masuk t WHERE t.deleted_at IS NULL)
    UNION ALL
    (SELECT 'keluar', t.tanggal, t.nama, t.jumlah, t.keterangan, t.created_at
       FROM barang_keluar t WHERE t.deleted_at IS NULL)
    ORDER BY created_at DESC, tanggal DESC
    LIMIT 6");
foreach ($aktivitas as &$a) {
    $a['jumlah'] = (int)$a['jumlah'];
}
unset($a);

jsonOk([
    'status' => [
        'kritis'       => (int)$status['kritis'],
        'rendah'       => (int)$status['rendah'],
        'aman'         => (int)$status['aman'],
        'belum_diatur' => (int)$status['belum_diatur'],
    ],
    'kategori'       => $kategori,
    'pergerakan'     => $pergerakan,
    'ada_pergerakan' => $adaPergerakan,
    'hari'           => $hari,
    'perlu_order'    => $perluOrder,
    'aktivitas'      => $aktivitas,
]);
