<?php
/**
 * GET api/retur/list.php — daftar retur barang.
 *
 * Parameter: q, dari, sampai, status, accurate (sudah|belum|tidak), page
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
$status = ambilStr($_GET, 'status', 30);
$page   = ambilHalaman();

$where  = ['r.deleted_at IS NULL'];
$params = [];

if ($q !== '') {
    $where[] = '(r.nama LIKE ? OR r.barcode LIKE ? OR r.sku LIKE ? OR r.no_pesanan LIKE ? OR r.keterangan LIKE ?)';
    $pola = polaLike($q);
    for ($i = 0; $i < 5; $i++) {
        $params[] = $pola;
    }
}
if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
    $where[] = 'r.tanggal >= ?';
    $params[] = $dari;
}
if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
    $where[] = 'r.tanggal <= ?';
    $params[] = $sampai;
}
$pilihanStatus = daftarKeterangan('retur');
if ($status !== '' && in_array($status, $pilihanStatus, true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

/* Penanda pembukuan Accurate: 0 belum, 1 sudah, 2 sengaja tidak dimasukkan.
 * Bukan nilai bebas, jadi dibandingkan dengan angkanya langsung — bukan lewat
 * teks, yang pada koneksi bercollation lain pernah membuat Dashboard 500. */
$accurate = ambilStr($_GET, 'accurate', 10);
if ($accurate === 'sudah') {
    $where[] = 'r.accurate = 1';
} elseif ($accurate === 'belum') {
    $where[] = 'r.accurate = 0';
} elseif ($accurate === 'tidak') {
    $where[] = 'r.accurate = 2';
} else {
    $accurate = '';
}
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue("SELECT COUNT(*) FROM retur r $sqlWhere", $params);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

/* masuk_aktif: baris barang masuk milik retur ini BENAR-BENAR ada dan belum
 * dihapus. Inilah satu-satunya bukti bahwa stoknya sudah bertambah.
 *
 * Sebelumnya layar menyimpulkannya dari nama keterangannya saja. Itu keliru,
 * dan kelirunya mahal: retur yang dicatat SEBELUM keterangannya diubah jadi
 * "mengembalikan barang ke stok" tidak pernah punya baris barang masuk, tapi
 * tetap digambar "stok bertambah" dan ikut dihitung di kartu "Sudah masuk
 * stok". Angkanya meyakinkan, stoknya tidak pernah naik, dan selisihnya baru
 * ketahuan saat stok opname.
 *
 * tambah_stok dibaca dengan JOIN ke tabel keterangan, bukan dengan menyusun
 * daftar nama lalu membandingkannya lewat IN (?). Perbandingannya jadi
 * kolom-lawan-kolom dengan collation yang sama, bukan kolom-lawan-parameter
 * yang COERCIBLE — bentuk yang dulu menjatuhkan Dashboard dengan galat 1267. */
$joinTaut = "
       LEFT JOIN barang_masuk bm ON bm.id = r.masuk_id AND bm.deleted_at IS NULL
       LEFT JOIN keterangan k ON k.jenis = 'retur' AND k.nama = r.status
                             AND k.aktif = 1 AND k.deleted_at IS NULL";

$rows = dbAll(
    "SELECT r.id, r.tanggal, r.no_pesanan, r.master_id, r.barcode, r.sku, r.nama,
            r.jumlah, r.status, r.keterangan, r.masuk_id, r.created_at,
            r.accurate, r.accurate_at,
            u.nama_lengkap  AS oleh,
            ua.nama_lengkap AS accurate_oleh,
            CASE WHEN bm.id IS NULL THEN 0 ELSE 1 END AS masuk_aktif,
            COALESCE(k.tambah_stok, 0) AS ket_tambah_stok
       FROM retur r
       $joinTaut
       LEFT JOIN users u  ON u.id  = r.user_id
       LEFT JOIN users ua ON ua.id = r.accurate_user
     $sqlWhere
     ORDER BY r.tanggal DESC, r.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']          = (int)$r['id'];
    $r['jumlah']      = (int)$r['jumlah'];
    $r['master_id']   = $r['master_id'] === null ? null : (int)$r['master_id'];
    $r['masuk_id']    = $r['masuk_id']  === null ? null : (int)$r['masuk_id'];
    $r['accurate']    = (int)$r['accurate'];
    $r['masuk_aktif'] = (int)$r['masuk_aktif'];
    // Keterangannya menyatakan barangnya kembali ke stok.
    $r['ket_tambah_stok'] = (int)$r['ket_tambah_stok'];
    /* Dan inilah yang perlu diperbaiki: seharusnya menambah stok, tapi baris
     * barang masuknya tidak ada. Dihitung di server supaya layar tidak perlu
     * menyimpulkannya sendiri dari dua angka. */
    $r['belum_tersusul'] = ($r['ket_tambah_stok'] === 1 && $r['masuk_aktif'] === 0) ? 1 : 0;
}
unset($r);

/* Ringkasan dihitung atas SELURUH hasil penyaring, bukan halaman ini saja.
 *
 * Satu query untuk semuanya, menggantikan empat yang dulu berjalan sendiri-
 * sendiri. Bukan sekadar lebih hemat: keempatnya dulu menyaring tabel yang
 * sama berulang kali, dan angka yang dihitung terpisah bisa saling
 * bertentangan kalau salah satu syaratnya tidak persis sama.
 *
 * Tiga ember unit di bawah ini SALING LEPAS dan jumlahnya = total unit:
 *
 *   unit_ke_stok        baris barang masuknya ADA  -> stok memang sudah naik
 *   unit_belum_tersusul keterangannya mengembalikan ke stok, tapi barisnya
 *                       TIDAK ADA -> stok belum naik; inilah yang perlu
 *                       disusulkan lewat "Masukkan semua ke stok"
 *   unit_tertahan       keterangannya memang tidak mengembalikan ke stok
 *
 * baris_kelebihan adalah kebalikannya: barisnya ada padahal keterangannya
 * berkata tidak boleh. Tombol yang sama menariknya kembali. */
$ringkas = dbOne(
    "SELECT
        COALESCE(SUM(r.jumlah), 0) AS unit_total,
        COALESCE(SUM(CASE WHEN bm.id IS NOT NULL
                          THEN r.jumlah ELSE 0 END), 0) AS unit_ke_stok,
        COALESCE(SUM(CASE WHEN bm.id IS NULL AND COALESCE(k.tambah_stok, 0) = 1
                          THEN r.jumlah ELSE 0 END), 0) AS unit_belum_tersusul,
        COALESCE(SUM(CASE WHEN bm.id IS NULL AND COALESCE(k.tambah_stok, 0) = 0
                          THEN r.jumlah ELSE 0 END), 0) AS unit_tertahan,
        COALESCE(SUM(CASE WHEN bm.id IS NULL AND COALESCE(k.tambah_stok, 0) = 1
                          THEN 1 ELSE 0 END), 0) AS baris_belum_tersusul,
        COALESCE(SUM(CASE WHEN bm.id IS NOT NULL AND COALESCE(k.tambah_stok, 0) = 0
                          THEN 1 ELSE 0 END), 0) AS baris_kelebihan,
        COALESCE(SUM(CASE WHEN r.accurate = 0 THEN 1 ELSE 0 END), 0) AS belum_accurate,
        COALESCE(SUM(CASE WHEN r.accurate = 2 THEN 1 ELSE 0 END), 0) AS tidak_accurate
       FROM retur r
       $joinTaut
     $sqlWhere",
    $params
);

$ketMasuk      = keteranganTambahStok();
$totalUnit     = (int)$ringkas['unit_total'];
$masukStok     = (int)$ringkas['unit_ke_stok'];
$belumAccurate = (int)$ringkas['belum_accurate'];
$tidakAccurate = (int)$ringkas['tidak_accurate'];

jsonOk([
    'rows'           => $rows,
    'total_unit'     => $totalUnit,
    'unit_ke_stok'   => $masukStok,
    'unit_tertahan'  => (int)$ringkas['unit_tertahan'],
    /* Retur yang keterangannya mengembalikan barang ke stok tapi barang
     * masuknya belum pernah dibuat. Dikirim terpisah supaya layar bisa
     * menyebutnya apa adanya dan menawarkan perbaikannya, bukan menyembunyikan
     * angkanya di dalam "sudah masuk stok". */
    'unit_belum_tersusul'  => (int)$ringkas['unit_belum_tersusul'],
    'baris_belum_tersusul' => (int)$ringkas['baris_belum_tersusul'],
    'baris_kelebihan'      => (int)$ringkas['baris_kelebihan'],
    'status_options' => $pilihanStatus,
    'accurate'       => $accurate,
    'belum_accurate' => $belumAccurate,
    'tidak_accurate' => $tidakAccurate,
    // Keterangan mana yang berarti "sudah masuk stok" ditentukan server, supaya
    // layar tidak perlu menebaknya dari teks yang bisa berubah. Bentuknya
    // daftar, karena sekarang bisa lebih dari satu.
    'status_masuk_list' => $ketMasuk,
    // Dipertahankan untuk layar versi lama yang masih membaca satu nilai.
    'status_masuk'      => $ketMasuk[0] ?? '',
] + $meta);
