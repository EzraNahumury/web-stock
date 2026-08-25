<?php
/**
 * POST api/opname/item.php — isi hasil hitungan satu baris opname.
 *
 * Body: { id, stok_hitung?, stok_accurate?, dicek?, penyesuaian?, petugas?, catatan? }
 *
 * Field yang tidak dikirim tidak diubah, dan mengirim string kosong berarti
 * "kosongkan lagi" (kembali NULL = belum dihitung). Keduanya perlu dibedakan
 * karena 0 adalah hasil hitungan yang sah — barangnya memang habis.
 *
 * MENYENTUH STOK
 * Memilih penyesuaian "Stok Disesuaikan" menulis satu baris barang masuk /
 * barang keluar sebesar selisih stok hitung terhadap stok yang berlaku,
 * sehingga stok akhir di seluruh aplikasi mengikuti hitungan fisik.
 * Mencabutnya membatalkan baris itu lagi. Karena itu seluruh penyimpanan
 * dijalankan dalam satu transaksi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/opname.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id <= 0) {
    jsonError('ID baris tidak valid.');
}

$item = dbOne(
    'SELECT i.*, s.status AS sesi_status
       FROM opname_item i
       JOIN opname_sesi s ON s.id = i.sesi_id
      WHERE i.id = ? AND s.deleted_at IS NULL',
    [$id]
);
if ($item === null) {
    jsonError('Baris opname tidak ditemukan.', 404);
}
if ($item['sesi_status'] === 'selesai') {
    jsonError('Sesi ini sudah ditutup. Buka kembali statusnya bila memang perlu diubah.', 422);
}

/** Angka opsional: null berarti dikosongkan, tidak dikirim berarti tetap. */
$angka = static function (array $in, string $key, $lama) {
    if (!array_key_exists($key, $in)) {
        return $lama;
    }
    $v = $in[$key];
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    if (!is_numeric($v)) {
        return $lama;
    }
    return (int)$v;
};

$hitung   = $angka($in, 'stok_hitung',   $item['stok_hitung']   === null ? null : (int)$item['stok_hitung']);
$accurate = $angka($in, 'stok_accurate', $item['stok_accurate'] === null ? null : (int)$item['stok_accurate']);
$dicek    = array_key_exists('dicek', $in) ? (!empty($in['dicek']) ? 1 : 0) : (int)$item['dicek'];
$catatan  = array_key_exists('catatan', $in) ? ambilStr($in, 'catatan', 255) : (string)$item['catatan'];
$petugas  = array_key_exists('petugas', $in) ? ambilStr($in, 'petugas', 100) : (string)$item['petugas'];

// Penyesuaian hanya mencatat keputusan; stok tidak ikut berubah dari sini.
$penyesuaian = array_key_exists('penyesuaian', $in)
    ? pilihanValid(ambilStr($in, 'penyesuaian', 30), PENYESUAIAN_OPNAME)
    : (string)$item['penyesuaian'];

if ($hitung !== null && $hitung < 0) {
    jsonError('Stok hitung tidak boleh negatif.');
}
if ($accurate !== null && $accurate < 0) {
    jsonError('Stok accurate tidak boleh negatif.');
}

/* --- Penyesuaian stok ---------------------------------------------------- */
$sesuaikan = ($penyesuaian === PENYESUAIAN_DISESUAIKAN);

if ($sesuaikan) {
    // Ditolak lebih dulu supaya pesannya jelas, bukan diam-diam tidak terjadi.
    if ($hitung === null) {
        jsonError('Isi stok hitung dulu sebelum menyesuaikan stok baris ini.', 422);
    }
    if ($item['master_id'] === null) {
        jsonError(
            'Barang ini tidak terhubung ke master, jadi stoknya tidak bisa disesuaikan.',
            422
        );
    }
}

// Nilai baru dipasang ke salinan barisnya supaya sinkronPenyesuaianStok()
// menghitung dari keadaan yang akan disimpan, bukan yang lama.
$item['stok_hitung'] = $hitung;

$adj = dbTransaksi(static function (PDO $pdo) use ($item, $sesuaikan, $id, $hitung, $accurate, $dicek, $penyesuaian, $petugas, $catatan) {
    $hasil = sinkronPenyesuaianStok($pdo, $item, $sesuaikan);

    $st = $pdo->prepare(
        'UPDATE opname_item
            SET stok_hitung = ?, stok_accurate = ?, dicek = ?,
                penyesuaian = ?, petugas = ?, catatan = ?,
                adj_jenis = ?, adj_id = ?, adj_qty = ?
          WHERE id = ?'
    );
    $st->execute([
        $hitung, $accurate, $dicek, $penyesuaian, $petugas, $catatan,
        $hasil['jenis'], $hasil['id'], $hasil['qty'], $id,
    ]);
    return $hasil;
});

if ($sesuaikan) {
    catatAktivitas('update', 'opname', (int)$item['sesi_id'], [
        'aksi'    => 'sesuaikan stok',
        'nama'    => $item['nama'],
        'barcode' => $item['barcode'],
        'jumlah'  => $adj['qty'],
        'arah'    => $adj['jenis'] ?? 'tidak ada selisih',
    ]);
}

$selisih = ($hitung !== null && $accurate !== null) ? $hitung - $accurate : null;

// Stok yang berlaku sesudah penyimpanan — dipakai layar untuk menunjukkan
// hasil penyesuaiannya tanpa memuat ulang seluruh halaman.
$stokKini = $item['master_id'] !== null ? stokAkhirItem((int)$item['master_id']) : null;

$pesan = '';
if ($sesuaikan) {
    $pesan = $adj['qty'] === 0
        ? 'Stok sudah sama dengan hitungan fisik, tidak ada koreksi yang perlu ditulis.'
        : 'Stok disesuaikan: ' . ($adj['jenis'] === 'masuk' ? '+' : '-')
          . number_format((int)$adj['qty'], 0, ',', '.') . ' pcs lewat barang '
          . $adj['jenis'] . ' "' . KET_PENYESUAIAN . '". Stok akhir sekarang '
          . number_format((int)$stokKini, 0, ',', '.') . '.';
}

jsonOk([
    'id'            => $id,
    'stok_hitung'   => $hitung,
    'stok_accurate' => $accurate,
    'dicek'         => $dicek === 1,
    'penyesuaian'   => $penyesuaian,
    'disesuaikan'   => $sesuaikan,
    'petugas'       => $petugas,
    'catatan'       => $catatan,
    'selisih'       => $selisih,
    'adj_jenis'     => $adj['jenis'],
    'adj_qty'       => $adj['qty'],
    'stok_kini'     => $stokKini,
    'pesan'         => $pesan,
]);
