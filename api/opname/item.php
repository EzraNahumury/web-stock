<?php
/**
 * POST api/opname/item.php — isi hasil hitungan satu baris opname.
 *
 * Body: { id, stok_hitung?, stok_accurate?, dicek?, penyesuaian?, petugas?, catatan? }
 *
 * Field yang tidak dikirim tidak diubah, dan mengirim string kosong berarti
 * "kosongkan lagi" (kembali NULL = belum dihitung). Keduanya perlu dibedakan
 * karena 0 adalah hasil hitungan yang sah — barangnya memang habis.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

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

dbExec(
    'UPDATE opname_item
        SET stok_hitung = ?, stok_accurate = ?, dicek = ?,
            penyesuaian = ?, petugas = ?, catatan = ?
      WHERE id = ?',
    [$hitung, $accurate, $dicek, $penyesuaian, $petugas, $catatan, $id]
);

$selisih = ($hitung !== null && $accurate !== null) ? $hitung - $accurate : null;

jsonOk([
    'id'            => $id,
    'stok_hitung'   => $hitung,
    'stok_accurate' => $accurate,
    'dicek'         => $dicek === 1,
    'penyesuaian'   => $penyesuaian,
    'disesuaikan'   => $penyesuaian === PENYESUAIAN_DISESUAIKAN,
    'petugas'       => $petugas,
    'catatan'       => $catatan,
    'selisih'       => $selisih,
]);
