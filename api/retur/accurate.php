<?php
/**
 * POST api/retur/accurate.php — tandai retur sudah / belum diinput ke Accurate.
 *
 * Body: { id, sudah }  atau  { ids: [..], sudah }
 *
 * Penanda pembukuan, bukan penggerak stok. Yang menambah stok tetap keterangan
 * returnya; menandai di sini tidak mengubah angka stok sama sekali.
 *
 * Boleh dilakukan operator. Ini memang pekerjaannya — dialah yang memasukkan
 * returnya ke Accurate — dan tandanya tidak menggeser apa pun yang dihitung.
 * Supaya tetap bisa ditelusuri, waktu dan pelakunya ikut dicatat, lalu
 * ditampilkan di kolomnya.
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

$sudah = !empty($in['sudah']);

// Satu baris atau sekaligus banyak. Menandai satu per satu untuk puluhan
// baris yang baru saja diinput ke Accurate tidak masuk akal.
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
    $id = ambilInt($in, 'id', 0);
    if ($id > 0) {
        $ids[] = $id;
    }
}

if (!$ids) {
    jsonError('Tidak ada retur yang dipilih.');
}
if (count($ids) > 500) {
    jsonError('Terlalu banyak baris sekaligus (maksimal 500).');
}

$tanda = implode(',', array_fill(0, count($ids), '?'));
$ada = (int)dbValue(
    "SELECT COUNT(*) FROM retur WHERE deleted_at IS NULL AND id IN ($tanda)",
    $ids
);
if ($ada === 0) {
    jsonError('Retur tidak ditemukan.', 404);
}

$diubah = 0;
dbTransaksi(static function (PDO $pdo) use ($ids, $sudah, $tanda, &$diubah) {
    $st = $pdo->prepare(
        "UPDATE retur
            SET accurate      = ?,
                accurate_at   = " . ($sudah ? 'NOW()' : 'NULL') . ",
                accurate_user = ?
          WHERE deleted_at IS NULL AND id IN ($tanda) AND accurate <> ?"
    );
    $st->execute(array_merge(
        [$sudah ? 1 : 0, $sudah ? userId() : null],
        $ids,
        [$sudah ? 1 : 0]
    ));
    $diubah = $st->rowCount();
});

catatAktivitas('update', 'retur', count($ids) === 1 ? $ids[0] : null, [
    'accurate' => $sudah ? 'sudah' : 'belum',
    'baris'    => $diubah,
]);

jsonOk([
    'diubah' => $diubah,
    'sudah'  => $sudah,
    'pesan'  => $diubah === 0
        ? 'Tidak ada yang berubah; tandanya memang sudah begitu.'
        : $diubah . ' retur ditandai ' . ($sudah ? 'sudah' : 'belum') . ' masuk Accurate.',
]);
