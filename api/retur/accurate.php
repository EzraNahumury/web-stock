<?php
/**
 * POST api/retur/accurate.php — tandai keadaan retur terhadap pembukuan Accurate.
 *
 * Body: { id, tanda }  atau  { ids: [..], tanda }
 *        tanda = 'belum' | 'sudah' | 'tidak'
 *
 * TIGA KEADAAN, BUKAN DUA
 * Nilainya tetap di kolom accurate yang sama:
 *   0 = Belum        belum diinput ke Accurate; masih pekerjaan tersisa
 *   1 = Sudah        sudah diinput
 *   2 = Tidak masuk  sengaja TIDAK diinput ke Accurate
 *
 * "Tidak masuk" bukan sekadar "belum" yang berumur panjang. Retur yang memang
 * tidak perlu masuk pembukuan selama ini menumpuk di hitungan "belum",
 * sehingga angka itu tidak pernah bisa mencapai nol — dan angka yang tidak
 * pernah bisa nol berhenti memberi tahu apa pun. Sesudah keadaan ketiga ini
 * ada, "Belum ke Accurate" kembali berarti: sisa pekerjaan yang nyata.
 *
 * Bentuk lama { sudah: true|false } masih diterima dan dipetakan ke
 * 'sudah'/'belum', supaya pemanggil lama tidak ikut pecah.
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

/* Nama keadaan -> nilai kolom. Pemetaannya di satu tempat; layar mengirim
 * namanya, bukan angkanya, supaya tidak ada dua pihak yang masing-masing
 * menghafal arti angka. */
$petaTanda = ['belum' => 0, 'sudah' => 1, 'tidak' => 2];
$labelTanda = ['belum' => 'Belum', 'sudah' => 'Sudah', 'tidak' => 'Tidak masuk'];

$keadaan = ambilStr($in, 'tanda', 10);
if ($keadaan === '') {
    // Bentuk lama: { sudah: true|false }.
    $keadaan = !empty($in['sudah']) ? 'sudah' : 'belum';
}
if (!isset($petaTanda[$keadaan])) {
    jsonError('Tanda Accurate tidak dikenal. Pilihannya: belum, sudah, atau tidak.');
}
$nilai = $petaTanda[$keadaan];

/* Jejak dicatat untuk "sudah" MAUPUN "tidak masuk": keduanya keputusan yang
 * diambil seseorang, dan kelak perlu ditelusuri kalau angkanya berbeda dengan
 * yang ada di Accurate. Hanya "belum" yang berarti belum ada keputusan, jadi
 * jejaknya dikosongkan lagi. */
$adaKeputusan = $nilai !== 0;

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
dbTransaksi(static function (PDO $pdo) use ($ids, $nilai, $adaKeputusan, $tanda, &$diubah) {
    $st = $pdo->prepare(
        "UPDATE retur
            SET accurate      = ?,
                accurate_at   = " . ($adaKeputusan ? 'NOW()' : 'NULL') . ",
                accurate_user = ?
          WHERE deleted_at IS NULL AND id IN ($tanda) AND accurate <> ?"
    );
    $st->execute(array_merge(
        [$nilai, $adaKeputusan ? userId() : null],
        $ids,
        [$nilai]
    ));
    $diubah = $st->rowCount();
});

catatAktivitas('update', 'retur', count($ids) === 1 ? $ids[0] : null, [
    'accurate' => $keadaan,
    'baris'    => $diubah,
]);

jsonOk([
    'diubah' => $diubah,
    'tanda'  => $keadaan,
    // Dipertahankan untuk pemanggil lama yang hanya mengenal dua keadaan.
    'sudah'  => $nilai === 1,
    'pesan'  => $diubah === 0
        ? 'Tidak ada yang berubah; tandanya memang sudah begitu.'
        : $diubah . ' retur ditandai "' . $labelTanda[$keadaan] . '".',
]);
