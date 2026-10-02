<?php
/**
 * POST api/import/commit.php — simpan hasil review PDF sebagai barang keluar.
 *
 * Seluruhnya dalam SATU transaksi SQL: bila satu baris gagal, semuanya
 * di-ROLLBACK. Prototipe menulis satu array raksasa sekaligus tanpa jaminan
 * apa pun bila penulisan terputus di tengah (audit B3).
 *
 * Validasi diulang di sini meski klien sudah memeriksanya — pemeriksaan di
 * sisi klien saja tidak pernah cukup, karena permintaan bisa dikirim langsung
 * tanpa melewati antarmuka.
 *
 * Body: { header, fileName, fileHash, tanggal, abaikanDuplikat,
 *          abaikanStokKurang, rows[] }
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

$header   = is_array($in['header'] ?? null) ? $in['header'] : [];
$rows     = is_array($in['rows'] ?? null) ? $in['rows'] : [];
$fileName = ambilStr($in, 'fileName', 255);
$fileHash = ambilStr($in, 'fileHash', 64);
$tanggal  = ambilTanggal($in, 'tanggal');
$abaikan  = !empty($in['abaikanDuplikat']);
$abaikanStok = !empty($in['abaikanStokKurang']);

if (!$rows) {
    jsonError('Tidak ada data untuk disimpan.');
}
if ($tanggal === null) {
    jsonError('Format tanggal tidak valid.');
}
if (count($rows) > 5000) {
    jsonError('Terlalu banyak baris dalam satu impor (maksimal 5000).');
}

$noPicking = ambilStr($header, 'noPicking', 100);

// --- Cegah impor ganda kecuali admin sudah mengonfirmasi ------------------
if (!$abaikan) {
    $dup = null;
    if ($fileHash !== '') {
        $dup = dbOne('SELECT id FROM import_batch WHERE file_hash = ? LIMIT 1', [$fileHash]);
    }
    if ($dup === null && $noPicking !== '') {
        $dup = dbOne('SELECT id FROM import_batch WHERE no_picking = ? AND no_picking <> \'\' LIMIT 1', [$noPicking]);
    }
    if ($dup !== null) {
        jsonError(
            'Picking list ini sudah pernah diimpor. Konfirmasi ulang bila memang ingin mengimpornya lagi.',
            409,
            ['duplikat' => true, 'batch_id' => (int)$dup['id']]
        );
    }
}

// --- Validasi & normalisasi seluruh baris SEBELUM transaksi dimulai -------
$bersih  = [];
$galat   = [];
$viaSku  = 0;
foreach ($rows as $i => $r) {
    if (!is_array($r)) {
        continue;
    }
    $baris = $i + 1;

    // Hanya baris yang dicentang admin yang disimpan. Baris tak tercentang
    // dilewati diam-diam, bukan dianggap galat: mengabaikan sebagian baris
    // memang tujuan tombol centangnya.
    if (array_key_exists('pilih', $r) && !$r['pilih']) {
        continue;
    }

    $barcode = ambilStr($r, 'barcode', 50);
    $nama    = ambilStr($r, 'nama', 255);
    $qty     = ambilInt($r, 'qty', 0);
    $ket     = pilihanValid(ambilStr($r, 'keterangan', 50), daftarKeterangan('keluar'));
    $sku     = ambilStr($r, 'sku', 50);
    $noPes   = ambilStr($r, 'noPesanan', 100);

    if ($qty <= 0) {
        $galat[] = "Baris $baris: qty harus lebih dari 0.";
        continue;
    }

    /* Barcode boleh kosong asal SKU-nya mengenali barangnya.
     *
     * Picking list Desty tidak mencetak digit barcode untuk barang yang di
     * sana belum punya barcode terdaftar — kolomnya hanya memuat gambar,
     * sehingga pembacaan PDF yang benar pun menghasilkan sel kosong. Baris
     * seperti itu tetap membawa SKU, dan SKU-nya ada di master kita. Dulu
     * satu baris semacam ini menggagalkan SELURUH impor, karena satu galat
     * membatalkan semuanya.
     *
     * Bila cocok lewat SKU, barcode master yang dipakai — barcode yang
     * tercatat dan master_id-nya tidak boleh menunjuk barang berbeda.
     */
    $master = cariMasterByBarcode($barcode);
    if ($master === null && $sku !== '') {
        $cocokSku = cariMasterBySku($sku);
        if (count($cocokSku) === 1) {
            $master  = $cocokSku[0];
            $barcode = (string)$master['barcode'];
            $viaSku++;
        } elseif ($barcode === '' && count($cocokSku) > 1) {
            $galat[] = "Baris $baris: barcode kosong dan SKU \"$sku\" dipakai "
                . "lebih dari satu barang — isi barcodenya.";
            continue;
        }
    }
    if ($barcode === '') {
        $galat[] = $sku === ''
            ? "Baris $baris: barcode dan SKU dua-duanya kosong."
            : "Baris $baris: barcode kosong dan SKU \"$sku\" tidak ada di master.";
        continue;
    }

    // Nama: hasil parse -> nama master -> penanda. Sama seperti prototipe.
    if ($nama === '') {
        $nama = $master ? $master['nama'] : '(tanpa nama)';
    }

    // Keadaan baris sebagaimana terbaca dari PDF, dikirim klien apa adanya.
    // Dipakai mendeteksi apakah admin menukar produknya sebelum menyimpan.
    $asli = is_array($r['asli'] ?? null) ? $r['asli'] : [];
    $barcodeLama = mb_substr(trim((string)($asli['barcode'] ?? '')), 0, 50);
    $skuLama     = mb_substr(trim((string)($asli['sku'] ?? '')), 0, 50);
    $namaLama    = mb_substr(trim((string)($asli['nama'] ?? '')), 0, 255);

    $tukarBarcode = $barcodeLama !== '' && $barcodeLama !== $barcode;
    $tukarSku     = $skuLama !== '' && $skuLama !== $sku;

    $bersih[] = [
        'barcode'    => $barcode,
        'nama'       => $nama,
        'sku'        => $sku,
        'qty'        => $qty,
        'keterangan' => $ket,
        'no_pesanan' => $noPes !== '' ? $noPes : ($noPicking !== '' ? $noPicking : $fileName),
        'master_id'  => $master ? (int)$master['id'] : null,
        'tukar'      => ($tukarBarcode || $tukarSku) ? [
            'barcode_lama' => $barcodeLama,
            'nama_lama'    => $namaLama,
            'sku_lama'     => $skuLama,
            'alasan'       => $tukarBarcode && $tukarSku ? 'keduanya' : ($tukarBarcode ? 'barcode' : 'sku'),
        ] : null,
    ];
}

if ($galat) {
    jsonError('Ada baris yang belum lengkap.', 422, ['detail' => $galat]);
}
if (!$bersih) {
    jsonError('Tidak ada baris yang dicentang untuk disimpan.');
}

// --- Cek kecukupan stok (audit D3) ---------------------------------------
// Digabung per master_id dulu, karena satu picking list bisa memuat barang
// yang sama di beberapa baris pesanan berbeda.
$peringatan = [];
if (!IZINKAN_STOK_MINUS) {
    $perItem = [];
    foreach ($bersih as $b) {
        if ($b['master_id'] !== null) {
            $perItem[$b['master_id']] = ($perItem[$b['master_id']] ?? 0) + $b['qty'];
        }
    }
    $kurang = [];
    foreach ($perItem as $mid => $totalQty) {
        $tersedia = stokAkhirItem((int)$mid);
        if ($totalQty > $tersedia) {
            $m = dbOne('SELECT nama, barcode FROM master_barang WHERE id = ?', [$mid]);
            $kurang[] = ($m['nama'] ?? $mid) . ' (' . ($m['barcode'] ?? '') . '): '
                . 'tersedia ' . $tersedia . ', diminta ' . $totalQty;
        }
    }
    /* Stok kurang: ditanyakan, bukan ditolak mati.
     *
     * Picking list adalah catatan barang yang SUDAH diambil dan dikirim.
     * Menolaknya karena stok tercatat kurang membuat sistem makin jauh dari
     * kenyataan gudang, dan satu barang bersaldo nol cukup untuk
     * menggagalkan seluruh berkas — itulah sebabnya sebagian picking list
     * "terbaca tapi gagal input".
     *
     * Jadi jawabannya 409 berisi daftar barangnya. Petugas memutuskan:
     * batal, atau simpan dan biarkan stoknya minus sebagai tanda bahwa
     * barang itu perlu ditelusuri lewat stok opname. Pola konfirmasinya
     * sama dengan impor ganda di atas.
     */
    if ($kurang && !$abaikanStok) {
        jsonError('Stok tidak mencukupi untuk sebagian barang.', 409, [
            'stok_kurang' => true,
            'detail'      => $kurang,
        ]);
    }
    if ($kurang) {
        $peringatan[] = count($kurang) . ' barang dicatat keluar melebihi stok '
            . 'tercatat, jadi stoknya kini minus. Telusuri lewat stok opname.';
    }
}

$tanpaMaster = 0;
foreach ($bersih as $b) {
    if ($b['master_id'] === null) {
        $tanpaMaster++;
    }
}
if ($viaSku > 0) {
    $peringatan[] = $viaSku . ' baris tidak mencantumkan barcode di PDF dan '
        . 'dicocokkan lewat SKU.';
}
if ($tanpaMaster > 0) {
    $peringatan[] = $tanpaMaster . ' baris barcodenya belum terdaftar di master barang. '
        . 'Transaksinya tercatat, tapi belum mempengaruhi perhitungan stok.';
}

/* Mode periksa: jalankan seluruh pemeriksaan, jangan tulis apa pun.
 *
 * Dipakai antarmuka ketika penyimpanan gagal dengan jawaban yang tidak bisa
 * diurai. Dengan ini permintaan yang sama persis bisa dikirim ulang — utuh,
 * lalu separuh, lalu seperempat — untuk mengetahui apakah yang menghalangi
 * itu isinya, ukurannya, atau bukan keduanya, tanpa risiko menyimpan impor
 * separuh jadi. Berguna juga sebagai "cek dulu" biasa. */
if (!empty($in['periksa'])) {
    jsonOk([
        'periksa'    => true,
        'baris'      => count($bersih),
        'unit'       => array_sum(array_column($bersih, 'qty')),
        'via_sku'    => $viaSku,
        'peringatan' => $peringatan,
    ]);
}

// --- Simpan dalam satu transaksi -----------------------------------------
$tanggalCetak = null;
$tc = ambilStr($header, 'tanggalCetak', 20);
if ($tc !== '') {
    // PDF memakai dd/mm/yyyy atau dd-mm-yyyy.
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $tc);
        if ($d !== false) {
            $tanggalCetak = $d->format('Y-m-d');
            break;
        }
    }
}

$hasil = dbTransaksi(static function (PDO $pdo) use (
    $noPicking, $fileName, $fileHash, $tanggalCetak, $header, $bersih, $tanggal
) {
    $stBatch = $pdo->prepare(
        'INSERT INTO import_batch
            (no_picking, nama_file, file_hash, tanggal_cetak, dicetak_oleh,
             jumlah_pesanan, jumlah_produk, jumlah_baris, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stBatch->execute([
        $noPicking,
        $fileName,
        $fileHash,
        $tanggalCetak,
        mb_substr((string)($header['dicetakOleh'] ?? ''), 0, 100),
        (int)($header['jumlahPesanan'] ?? 0),
        (int)($header['jumlahProduk'] ?? 0),
        count($bersih),
        userId(),
    ]);
    $batchId = (int)$pdo->lastInsertId();

    $stRow = $pdo->prepare(
        'INSERT INTO barang_keluar
            (tanggal, master_id, barcode, nama, jumlah, keterangan, no_pesanan, batch_id, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // Pertukaran produk dicatat di transaksi yang sama dengan barang
    // keluarnya: kalau salah satunya gagal, keduanya batal, sehingga tidak
    // pernah ada stok terpotong tanpa jejak pertukarannya.
    $stTukar = $pdo->prepare(
        'INSERT INTO pertukaran_barang
            (tanggal, barcode_lama, nama_lama, sku_lama,
             master_id_baru, barcode_baru, nama_baru, sku_baru,
             jumlah, no_pesanan, alasan, batch_id, keluar_id, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($bersih as $b) {
        $stRow->execute([
            $tanggal,
            $b['master_id'],
            $b['barcode'],
            $b['nama'],
            $b['qty'],
            $b['keterangan'],
            $b['no_pesanan'],
            $batchId,
            userId(),
        ]);
        $keluarId = (int)$pdo->lastInsertId();

        if ($b['tukar'] !== null) {
            $stTukar->execute([
                $tanggal,
                $b['tukar']['barcode_lama'],
                $b['tukar']['nama_lama'],
                $b['tukar']['sku_lama'],
                $b['master_id'],
                $b['barcode'],
                $b['nama'],
                $b['sku'],
                $b['qty'],
                mb_substr($b['no_pesanan'], 0, 255),
                $b['tukar']['alasan'],
                $batchId,
                $keluarId,
                userId(),
            ]);
        }
    }

    return $batchId;
});

catatAktivitas('import', 'batch', $hasil, [
    'no_picking' => $noPicking,
    'nama_file'  => $fileName,
    'baris'      => count($bersih),
]);

$jmlTukar = 0;
foreach ($bersih as $b) {
    if ($b['tukar'] !== null) {
        $jmlTukar++;
    }
}
if ($jmlTukar > 0) {
    $peringatan[] = $jmlTukar . ' baris produknya ditukar. Tercatat di menu Pertukaran barang.';
}

jsonOk([
    'batch_id'     => $hasil,
    'tersimpan'    => count($bersih),
    'pertukaran'   => $jmlTukar,
    'tanpa_master' => $tanpaMaster,
    'peringatan'   => $peringatan,
    'pesan'        => count($bersih) . ' barang keluar berhasil disimpan.',
]);
