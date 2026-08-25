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
 * Body: { header, fileName, fileHash, tanggal, abaikanDuplikat, rows[] }
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

    if ($barcode === '') {
        $galat[] = "Baris $baris: barcode kosong.";
        continue;
    }
    if ($qty <= 0) {
        $galat[] = "Baris $baris: qty harus lebih dari 0.";
        continue;
    }

    $master = cariMasterByBarcode($barcode);

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
    if ($kurang) {
        jsonError('Stok tidak mencukupi untuk sebagian barang.', 422, ['detail' => $kurang]);
    }
}

$tanpaMaster = 0;
foreach ($bersih as $b) {
    if ($b['master_id'] === null) {
        $tanpaMaster++;
    }
}
if ($tanpaMaster > 0) {
    $peringatan[] = $tanpaMaster . ' baris barcodenya belum terdaftar di master barang. '
        . 'Transaksinya tercatat, tapi belum mempengaruhi perhitungan stok.';
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
