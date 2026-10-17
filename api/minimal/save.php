<?php
/**
 * POST api/minimal/save.php — setel ambang stok minimal.
 *
 * Body: { id, stok_minimal }                 satu barang
 *    atau { ids: [..], stok_minimal }         beberapa barang sekaligus
 *    atau { semua_tersaring: {...}, stok_minimal }  seluruh hasil penyaring
 *
 * BOLEH DILAKUKAN OPERATOR
 * Mengubah data barang yang sudah tersimpan umumnya khusus admin, karena
 * menggeser angka stok tanpa meninggalkan jejak di daftar transaksi. Ambang
 * minimal berbeda: ia TIDAK menyentuh stok sama sekali, hanya menentukan kapan
 * barang disebut "perlu order". Yang paling tahu angkanya adalah orang gudang,
 * dan setiap perubahan tercatat di Log aktivitas berikut nilai lama dan
 * barunya. Sama dasarnya dengan pengisian hasil hitungan stok opname.
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

if (!array_key_exists('stok_minimal', $in)) {
    jsonError('Nilai stok minimal belum diisi.');
}
$nilai = ambilInt($in, 'stok_minimal', -1);
if ($nilai < 0) {
    jsonError('Stok minimal tidak boleh negatif.');
}
if ($nilai > 1000000) {
    jsonError('Stok minimal sebesar itu hampir pasti salah ketik.');
}

/* --- Barang mana yang disetel ------------------------------------------- */
$ids = [];

if (isset($in['ids']) && is_array($in['ids'])) {
    foreach ($in['ids'] as $v) {
        $n = (int)$v;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }
    $ids = array_keys($ids);
} elseif (isset($in['semua_tersaring']) && is_array($in['semua_tersaring'])) {
    /* Seluruh hasil penyaring, bukan hanya halaman yang terlihat.
     *
     * Penyaringnya dibangun ulang di sini dari parameter yang dikirim layar,
     * memakai potongan SQL yang sama dengan daftarnya. Kalau yang dikirim
     * hanya daftar id halaman ini, menyetel 1.189 barang berarti membuka 24
     * halaman satu per satu. */
    $f = $in['semua_tersaring'];

    // Seluruh syarat ditulis dengan awalan m. sejak awal. Aliasnya tetap sah
    // walau JOIN agregatnya tidak dipakai, jadi tidak ada nama kolom yang
    // perlu ditambal belakangan.
    $where  = ['m.deleted_at IS NULL', 'm.aktif = 1'];
    $params = [];

    $q = ambilStr($f, 'q', 100);
    if ($q !== '') {
        $where[] = '(m.nama LIKE ? OR m.sku LIKE ? OR m.barcode LIKE ?)';
        $pola = polaLike($q);
        array_push($params, $pola, $pola, $pola);
    }
    $kategori = ambilStr($f, 'kategori', 30);
    if ($kategori !== '' && $kategori !== 'Semua') {
        $where[] = 'm.kategori = ?';
        $params[] = $kategori;
    }
    $hanya = pilihanValid(ambilStr($f, 'hanya', 10), ['semua', 'belum', 'sudah']);
    if ($hanya === 'belum') {
        $where[] = 'm.stok_minimal = 0';
    } elseif ($hanya === 'sudah') {
        $where[] = 'm.stok_minimal > 0';
    }

    // Penyaring status butuh stok akhir, jadi JOIN agregatnya hanya dipasang
    // bila status memang diminta.
    $status = ambilStr($f, 'status', 20);
    $pakaiStatus = in_array($status, statusStokSah(), true);
    if ($pakaiStatus) {
        $where[] = '(' . sqlKondisiStatus($status, sqlStokAkhir()) . ')';
    }

    $sql = 'SELECT m.id FROM master_barang m '
         . ($pakaiStatus ? sqlJoinAgregat() : '')
         . ' WHERE ' . implode(' AND ', $where);

    $ids = array_map('intval', array_column(dbAll($sql, $params), 'id'));
} else {
    $id = ambilInt($in, 'id', 0);
    if ($id > 0) {
        $ids[] = $id;
    }
}

if (!$ids) {
    jsonError('Tidak ada barang yang dipilih.');
}
if (count($ids) > 5000) {
    jsonError('Terlalu banyak barang sekaligus (maksimal 5000).');
}

/* --- Setel --------------------------------------------------------------- */
$tanda = implode(',', array_fill(0, count($ids), '?'));

$sebelum = dbAll(
    "SELECT id, nama, stok_minimal FROM master_barang
      WHERE deleted_at IS NULL AND id IN ($tanda)",
    $ids
);
if (!$sebelum) {
    jsonError('Barang tidak ditemukan.', 404);
}

$diubah = 0;
dbTransaksi(static function (PDO $pdo) use ($ids, $nilai, $tanda, &$diubah) {
    // Yang nilainya memang sudah sama tidak disentuh, supaya angka yang
    // dilaporkan adalah perubahan nyata.
    $st = $pdo->prepare(
        "UPDATE master_barang SET stok_minimal = ?
          WHERE deleted_at IS NULL AND stok_minimal <> ? AND id IN ($tanda)"
    );
    $st->execute(array_merge([$nilai, $nilai], $ids));
    $diubah = $st->rowCount();
});

if ($diubah > 0) {
    // Satu baris: sebutkan barangnya dan nilai lamanya, supaya bisa
    // dikembalikan bila keliru. Banyak baris: cukup jumlahnya.
    if (count($sebelum) === 1) {
        catatAktivitas('update', 'master', (int)$sebelum[0]['id'], [
            'aksi'    => 'setel stok minimal',
            'nama'    => $sebelum[0]['nama'],
            'sebelum' => (int)$sebelum[0]['stok_minimal'],
            'sesudah' => $nilai,
        ]);
    } else {
        catatAktivitas('update', 'master', null, [
            'aksi'    => 'setel stok minimal massal',
            'jumlah'  => $diubah,
            'sesudah' => $nilai,
        ]);
    }
}

jsonOk([
    'diubah'       => $diubah,
    'diperiksa'    => count($ids),
    'stok_minimal' => $nilai,
    'pesan'        => $diubah === 0
        ? 'Tidak ada yang berubah; nilainya memang sudah ' . number_format($nilai, 0, ',', '.') . '.'
        : 'Stok minimal ' . number_format($diubah, 0, ',', '.') . ' barang disetel ke '
          . number_format($nilai, 0, ',', '.') . '.',
]);
