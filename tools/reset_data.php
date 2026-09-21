<?php
/**
 * reset_data.php — kosongkan data operasional, lalu isi master dari KARTU STOK.
 *
 * Dipakai saat pendataan dimulai ulang dari nol: seluruh transaksi dan
 * katalog barang dibuang, lalu katalog baru diambil dari berkas KARTU STOK
 * periode yang bersangkutan.
 *
 * YANG DIKOSONGKAN
 *   barang_masuk, barang_keluar   transaksi stok (Riwayat dihitung dari ini)
 *   import_batch                  jejak impor picking list PDF
 *   pertukaran_barang             riwayat tukar produk saat impor
 *   retur                         retur barang
 *   opname_sesi, opname_item      laporan stok opname
 *   master_barang                 katalog barang
 *
 * YANG TIDAK DISENTUH
 *   users            akun dan hak aksesnya
 *   kategori         daftar kategori
 *   keterangan       pilihan dropdown Keterangan
 *   activity_log     jejak audit — justru harus utuh melewati pendataan ulang
 *   migrasi          catatan migrasi yang sudah diterapkan
 *
 * Aturan barcode mengikuti yang sudah dipakai sejak awal (lihat
 * tools/convert_seed.php), supaya katalog baru tidak berbeda bentuk dengan
 * yang sudah pernah dikirim:
 *   - barcode kosong  -> "INT-<SKU>", atau "INT-GEN-<nnnn>" bila SKU juga
 *                        kosong; barcode_asli = 0 agar ditandai SEMENTARA
 *   - barcode kembar  -> yang pertama apa adanya, berikutnya "-D2", "-D3";
 *                        barcode_asli = 0
 * Tidak ada baris yang dibuang diam-diam — semuanya dilaporkan.
 *
 * Stok minimal di sumber berupa pecahan (40% penjualan bulanan) sedangkan
 * kolomnya INT. DIBULATKAN KE ATAS: ambang order yang dibulatkan ke bawah
 * membuat peringatan terlambat menyala.
 *
 * Jalankan:
 *   php tools\reset_data.php                          <- simulasi, tidak menulis
 *   php tools\reset_data.php --tulis                  <- benar-benar mengganti
 *   php tools\reset_data.php "BERKAS.xlsx" --tulis    <- berkas tertentu
 *   php tools\reset_data.php --tulis --tanpa-cadangan <- lewati cadangan
 *
 * Setiap jalan dengan --tulis menulis dua berkas ke deploy/ (folder itu tidak
 * pernah masuk repo karena memuat data operasional nyata):
 *   cadangan-<waktu>.sql   isi tabel sebelum dikosongkan, untuk dipulihkan
 *   reset-<periode>.sql    perintah yang sama untuk dijalankan di server
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Alat ini hanya untuk dijalankan dari terminal.');
}

require_once __DIR__ . '/baca_xlsx.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

const K_SKU = 0, K_BARCODE = 1, K_NAMA = 2, K_STOK_AWAL = 3,
      K_STOK_MINIMAL = 7, K_KATEGORI = 9;
const BARIS_DATA_AWAL = 6;

/** Tabel yang dikosongkan, berurutan dari yang paling bergantung. */
const TABEL_DIKOSONGKAN = [
    'opname_item',
    'opname_sesi',
    'pertukaran_barang',
    'retur',
    'barang_masuk',
    'barang_keluar',
    'import_batch',
    'master_barang',
];

$TULIS    = in_array('--tulis', $argv, true);
$CADANGAN = !in_array('--tanpa-cadangan', $argv, true);

$berkasArg = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') !== 0) {
        $berkasArg = $a;
        break;
    }
}
$sumber = berkasKartuStok($berkasArg);

echo "==========================================================\n";
echo ' RESET DATA + IMPOR KATALOG   [' . ($TULIS ? 'MENULIS' : 'SIMULASI') . "]\n";
echo "==========================================================\n";
echo 'Sumber : ' . basename($sumber) . "\n\n";

/* ====================================================================== */
/* 1. Baca dan normalisasi berkas sumber                                  */
/* ====================================================================== */

$baris = bacaXlsx($sumber);
$g = static function (array $b, int $i): string {
    return trim((string)($b[$i] ?? ''));
};

$periode = '';
foreach ($baris as $n => $b) {
    if ($n > 4) {
        break;
    }
    $t = $g($b, 0);
    if (preg_match('/PERIODE\s+(.+)$/i', $t, $m)) {
        $periode = trim($m[1]);
    }
}

$catatan = [];
$rows    = [];

foreach ($baris as $n => $b) {
    if ($n < BARIS_DATA_AWAL) {
        continue;
    }
    $nama = $g($b, K_NAMA);
    if ($nama === '') {
        continue;
    }

    $rawAwal = $g($b, K_STOK_AWAL);
    if ($rawAwal === '' || !is_numeric($rawAwal)) {
        $stokAwal = 0;
        $catatan[] = "Baris $n \"$nama\": stok awal "
            . ($rawAwal === '' ? 'kosong' : "\"$rawAwal\" tidak terbaca") . ' -> 0';
    } else {
        $f = (float)$rawAwal;
        if ($f < 0) {
            $catatan[] = "Baris $n \"$nama\": stok awal negatif ($f) -> 0";
            $stokAwal = 0;
        } else {
            $stokAwal = (int)round($f);
        }
    }

    $rawMin = $g($b, K_STOK_MINIMAL);
    $stokMinimal = 0;
    if ($rawMin !== '' && is_numeric($rawMin)) {
        $fm = (float)$rawMin;
        $stokMinimal = $fm > 0 ? (int)ceil($fm) : 0;
    }

    $rows[] = [
        'excel_row'    => $n,
        'sku'          => mb_substr($g($b, K_SKU), 0, 50),
        'barcode'      => mb_substr($g($b, K_BARCODE), 0, 50),
        'nama'         => mb_substr($nama, 0, 255),
        'stok_awal'    => $stokAwal,
        'stok_minimal' => $stokMinimal,
        'kategori'     => mb_substr(mb_strtoupper($g($b, K_KATEGORI)), 0, 30),
        'barcode_asli' => 1,
    ];
}

echo 'Periode di berkas   : ' . ($periode !== '' ? $periode : '(tidak tertulis)') . "\n";
echo 'Baris barang terbaca: ' . count($rows) . "\n";

/* ====================================================================== */
/* 2. Rapikan barcode — kolomnya UNIQUE NOT NULL di database              */
/* ====================================================================== */

$dipakai   = [];
$genUrut   = 0;
$nBuatan   = 0;
$nDedup    = 0;
$dedupInfo = [];

foreach ($rows as &$r) {
    $bc = $r['barcode'];

    if ($bc === '') {
        $sku = preg_replace('/\s+/', '', $r['sku']);
        if ($sku !== '') {
            $bc = 'INT-' . $sku;
        } else {
            $genUrut++;
            $bc = sprintf('INT-GEN-%04d', $genUrut);
        }
        $r['barcode_asli'] = 0;
        $nBuatan++;
    }

    if (isset($dipakai[$bc])) {
        $asal = $bc;
        $i = 2;
        while (isset($dipakai[$bc . '-D' . $i])) {
            $i++;
        }
        $bc = $bc . '-D' . $i;
        $r['barcode_asli'] = 0;
        $nDedup++;
        if (count($dedupInfo) < 12) {
            $dedupInfo[] = "  \"$asal\" -> \"$bc\"  (" . $r['nama'] . ')';
        }
    }

    $dipakai[$bc] = true;
    $r['barcode'] = mb_substr($bc, 0, 50);
}
unset($r);

echo 'Barcode digenerate  : ' . $nBuatan . " (kosong di sumber)\n";
echo 'Barcode kembar      : ' . $nDedup . " (diberi akhiran -Dn)\n";
foreach ($dedupInfo as $d) {
    echo $d . "\n";
}
echo 'Barcode unik akhir  : ' . count($dipakai) . "\n";

if (count($dipakai) !== count($rows)) {
    fwrite(STDERR, "GAGAL: jumlah barcode unik tidak sama dengan jumlah baris.\n");
    exit(1);
}

/* ====================================================================== */
/* 3. Kategori yang belum terdaftar                                        */
/* ====================================================================== */

$katSumber = [];
foreach ($rows as $r) {
    if ($r['kategori'] !== '') {
        $katSumber[$r['kategori']] = ($katSumber[$r['kategori']] ?? 0) + 1;
    }
}
ksort($katSumber);

$katAda  = [];
foreach (dbAll('SELECT nama FROM kategori WHERE deleted_at IS NULL') as $k) {
    $katAda[mb_strtoupper($k['nama'])] = true;
}
$katBaru = array_diff(array_keys($katSumber), array_keys($katAda));

echo "\nKategori di sumber  : " . count($katSumber) . "\n";
foreach ($katSumber as $k => $c) {
    echo '  ' . str_pad($k, 12) . str_pad((string)$c, 6, ' ', STR_PAD_LEFT)
        . (isset($katAda[$k]) ? '' : '   <- belum terdaftar, akan ditambahkan') . "\n";
}
$tanpaKat = count($rows) - array_sum($katSumber);
if ($tanpaKat > 0) {
    echo '  (tanpa kategori)' . str_pad((string)$tanpaKat, 3, ' ', STR_PAD_LEFT) . "\n";
}

/* ====================================================================== */
/* 4. Isi tabel sekarang                                                   */
/* ====================================================================== */

echo "\n--- Isi database sekarang ---\n";
$sebelum = [];
foreach (TABEL_DIKOSONGKAN as $t) {
    $sebelum[$t] = (int)dbValue("SELECT COUNT(*) FROM `$t`");
    echo '  ' . str_pad($t, 20) . str_pad(number_format($sebelum[$t], 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
        . " baris\n";
}
echo '  ' . str_pad('(total stok awal)', 20)
    . str_pad(number_format((int)dbValue('SELECT COALESCE(SUM(stok_awal),0) FROM master_barang'), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
    . " unit\n";

foreach (['users', 'kategori', 'keterangan', 'activity_log'] as $t) {
    echo '  ' . str_pad($t . ' (tetap)', 20)
        . str_pad(number_format((int)dbValue("SELECT COUNT(*) FROM `$t`"), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
        . " baris\n";
}

if ($catatan) {
    echo "\n--- Catatan pembacaan (" . count($catatan) . ") ---\n";
    foreach (array_slice($catatan, 0, 15) as $c) {
        echo "  $c\n";
    }
    if (count($catatan) > 15) {
        echo '  ... dan ' . (count($catatan) - 15) . " lagi\n";
    }
}

if (!$TULIS) {
    echo "\n==========================================================\n";
    echo " SIMULASI — tidak ada yang diubah.\n";
    echo ' Jalankan ulang dengan --tulis untuk benar-benar mengganti data.' . "\n";
    echo "==========================================================\n";
    exit(0);
}

/* ====================================================================== */
/* 5. Cadangan                                                             */
/* ====================================================================== */

$folderDeploy = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'deploy';
if (!is_dir($folderDeploy)) {
    mkdir($folderDeploy, 0777, true);
}

if ($CADANGAN) {
    $jalurCadangan = $folderDeploy . DIRECTORY_SEPARATOR . 'cadangan-' . date('Ymd-His') . '.sql';
    $isi  = "-- Cadangan sebelum reset data, dibuat " . date('c') . "\n";
    $isi .= "-- Impor berkas ini untuk memulihkan keadaan sebelum reset.\n";
    $isi .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach (array_reverse(TABEL_DIKOSONGKAN) as $t) {
        $isi .= "-- $t\n";
        $baris2 = dbAll("SELECT * FROM `$t`");
        foreach ($baris2 as $b2) {
            $kol = array_map(static function ($k) {
                return '`' . $k . '`';
            }, array_keys($b2));
            $nilai = array_map(static function ($v) {
                return $v === null ? 'NULL' : db()->quote((string)$v);
            }, array_values($b2));
            $isi .= "INSERT INTO `$t` (" . implode(', ', $kol) . ') VALUES ('
                . implode(', ', $nilai) . ");\n";
        }
        $isi .= "\n";
    }
    $isi .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    file_put_contents($jalurCadangan, $isi);
    echo "\nCadangan ditulis    : $jalurCadangan (" . number_format(strlen($isi) / 1024, 1) . " KB)\n";
}

/* ====================================================================== */
/* 6. Kosongkan lalu isi — satu transaksi                                  */
/* ====================================================================== */

$hasil = dbTransaksi(static function (PDO $pdo) use ($rows, $katBaru) {
    foreach (TABEL_DIKOSONGKAN as $t) {
        $pdo->exec("DELETE FROM `$t`");
        // Nomor urut dikembalikan ke 1 supaya id barang dimulai dari awal
        // lagi; tanpa ini katalog baru bernomor lanjutan dari yang lama.
        $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
    }

    // Kategori baru didaftarkan supaya dropdown tidak kehilangan nilai yang
    // sudah dipakai data.
    $stKat = $pdo->prepare(
        'INSERT INTO kategori (nama, keterangan, urutan) VALUES (?, ?, 900)'
    );
    foreach ($katBaru as $k) {
        $stKat->execute([$k, 'Ditemukan saat pendataan ulang']);
    }

    $st = $pdo->prepare(
        'INSERT INTO master_barang
            (sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $n = 0;
    foreach ($rows as $r) {
        $st->execute([
            $r['sku'], $r['barcode'], $r['nama'], $r['stok_awal'],
            $r['stok_minimal'], $r['kategori'], $r['barcode_asli'],
        ]);
        $n++;
    }
    return $n;
});

echo "Master dimasukkan   : " . number_format($hasil, 0, ',', '.') . " baris\n";

/* ====================================================================== */
/* 7. SQL untuk dijalankan di server                                       */
/* ====================================================================== */

$slug = $periode !== ''
    ? strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $periode))
    : date('Ymd');
$jalurReset = $folderDeploy . DIRECTORY_SEPARATOR . 'reset-' . trim($slug, '-') . '.sql';

$sql  = "-- ============================================================\n";
$sql .= "-- Pendataan ulang katalog barang — " . ($periode !== '' ? $periode : date('Y-m-d')) . "\n";
$sql .= "-- Sumber: " . basename($sumber) . "\n";
$sql .= "-- Dibuat: " . date('c') . " oleh tools/reset_data.php\n";
$sql .= "--\n";
$sql .= "-- CADANGKAN DATABASE DULU sebelum menjalankan berkas ini.\n";
$sql .= "-- Seluruh transaksi dan katalog barang akan dihapus dan diganti.\n";
$sql .= "-- Akun, kategori, keterangan, dan log aktivitas tidak disentuh.\n";
$sql .= "-- ============================================================\n\n";
$sql .= "SET NAMES utf8mb4;\n\n";

foreach (TABEL_DIKOSONGKAN as $t) {
    $sql .= "DELETE FROM `$t`;\n";
}
$sql .= "\n";
foreach (TABEL_DIKOSONGKAN as $t) {
    $sql .= "ALTER TABLE `$t` AUTO_INCREMENT = 1;\n";
}
$sql .= "\n";

foreach ($katBaru as $k) {
    $sql .= 'INSERT INTO kategori (nama, keterangan, urutan) VALUES ('
        . db()->quote($k) . ", 'Ditemukan saat pendataan ulang', 900);\n";
}
if ($katBaru) {
    $sql .= "\n";
}

$sql .= "INSERT INTO master_barang\n"
    . "  (sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli, aktif)\nVALUES\n";
$potong = [];
foreach ($rows as $r) {
    $potong[] = '  (' . db()->quote($r['sku'])
        . ', ' . db()->quote($r['barcode'])
        . ', ' . db()->quote($r['nama'])
        . ', ' . $r['stok_awal']
        . ', ' . $r['stok_minimal']
        . ', ' . db()->quote($r['kategori'])
        . ', ' . $r['barcode_asli']
        . ', 1)';
}
$sql .= implode(",\n", $potong) . ";\n";

file_put_contents($jalurReset, $sql);
echo "SQL untuk server    : $jalurReset (" . number_format(strlen($sql) / 1024, 1) . " KB)\n";

/* ====================================================================== */
/* 8. Verifikasi                                                           */
/* ====================================================================== */

echo "\n--- Verifikasi ---\n";
foreach (TABEL_DIKOSONGKAN as $t) {
    echo '  ' . str_pad($t, 20)
        . str_pad(number_format((int)dbValue("SELECT COUNT(*) FROM `$t`"), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
        . " baris\n";
}
echo '  ' . str_pad('total stok awal', 20)
    . str_pad(number_format((int)dbValue('SELECT COALESCE(SUM(stok_awal),0) FROM master_barang'), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
    . " unit\n";
echo '  ' . str_pad('barcode SEMENTARA', 20)
    . str_pad(number_format((int)dbValue('SELECT COUNT(*) FROM master_barang WHERE barcode_asli = 0'), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
    . " baris\n";
echo '  ' . str_pad('ambang belum diatur', 20)
    . str_pad(number_format((int)dbValue('SELECT COUNT(*) FROM master_barang WHERE stok_minimal = 0'), 0, ',', '.'), 8, ' ', STR_PAD_LEFT)
    . " baris\n";

echo "\n==========================================================\n";
echo " SELESAI. Katalog diganti dengan " . number_format($hasil, 0, ',', '.') . " barang.\n";
echo "==========================================================\n";
