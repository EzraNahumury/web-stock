<?php
/**
 * POST api/import/check.php — deteksi picking list yang sudah pernah diimpor.
 *
 * Memperbaiki audit D5: prototipe tidak memeriksa apa pun, sehingga PDF yang
 * sama diunggah dua kali memotong stok dua kali.
 *
 * Dicocokkan lewat dua jalur:
 *   1. file_hash (SHA-256 isi PDF) — bukti paling kuat, file identik
 *   2. no_picking — menangkap kasus PDF dicetak ulang sehingga byte-nya beda
 *      tapi isinya picking list yang sama
 *
 * Body: { no_picking, file_hash }
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

$noPicking = ambilStr($in, 'no_picking', 100);
$fileHash  = ambilStr($in, 'file_hash', 64);

$batch  = null;
$alasan = '';

if ($fileHash !== '') {
    $batch = dbOne(
        'SELECT b.*, u.nama_lengkap AS oleh
           FROM import_batch b
           LEFT JOIN users u ON u.id = b.user_id
          WHERE b.file_hash = ?
          ORDER BY b.id DESC LIMIT 1',
        [$fileHash]
    );
    if ($batch !== null) {
        $alasan = 'File PDF yang sama persis sudah pernah diimpor.';
    }
}

if ($batch === null && $noPicking !== '') {
    $batch = dbOne(
        'SELECT b.*, u.nama_lengkap AS oleh
           FROM import_batch b
           LEFT JOIN users u ON u.id = b.user_id
          WHERE b.no_picking = ? AND b.no_picking <> \'\'
          ORDER BY b.id DESC LIMIT 1',
        [$noPicking]
    );
    if ($batch !== null) {
        $alasan = 'Nomor picking "' . $noPicking . '" sudah pernah diimpor.';
    }
}

if ($batch === null) {
    jsonOk(['duplikat' => false]);
}

jsonOk([
    'duplikat' => true,
    'alasan'   => $alasan,
    'batch'    => [
        'id'           => (int)$batch['id'],
        'no_picking'   => $batch['no_picking'],
        'nama_file'    => $batch['nama_file'],
        'jumlah_baris' => (int)$batch['jumlah_baris'],
        'dicetak_oleh' => $batch['dicetak_oleh'],
        'diimpor_oleh' => $batch['oleh'] ?? '-',
        'created_at'   => $batch['created_at'],
    ],
]);
