<?php
/**
 * audit_kartu_stok.php — periksa isi KARTU STOK sebelum diimpor.
 *
 * Tidak menulis apa pun ke database. Tujuannya menemukan masalah data
 * (barcode kosong/duplikat, angka rusak, kategori tak dikenal) supaya
 * keputusan impor diambil sadar, bukan menabrak constraint di tengah jalan.
 *
 * Jalankan: C:\xampp\php\php.exe tools\audit_kartu_stok.php
 */

declare(strict_types=1);

require_once __DIR__ . '/baca_xlsx.php';

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'KARTU STOK AGUSTUS 2026 (1).xlsx';
$baris = bacaXlsx($path);

const KOL = ['sku' => 0, 'barcode' => 1, 'nama' => 2, 'stok_awal' => 3,
             'masuk' => 4, 'keluar' => 5, 'stok_akhir' => 6, 'stok_minimal' => 7,
             'penjualan' => 8, 'kategori' => 9, 'tgl_order' => 10, 'estimasi' => 11,
             'gudang_baru' => 12];

const BARIS_AWAL = 6;

$ambil = static function (array $b, string $k): string {
    return trim((string)($b[KOL[$k]] ?? ''));
};

$data = [];
foreach ($baris as $n => $b) {
    if ($n < BARIS_AWAL) {
        continue;
    }
    $nama = $ambil($b, 'nama');
    if ($nama === '') {
        continue;
    }
    $data[$n] = $b;
}

echo "==========================================================\n";
echo " AUDIT: KARTU STOK AGUSTUS 2026\n";
echo "==========================================================\n";
echo 'Baris data (nama terisi) : ' . count($data) . "\n\n";

/* --- 1. Kolom rusak -------------------------------------------------- */
echo "--- 1. Nilai galat rumus (#REF!, #N/A, #VALUE!) ---\n";
$galatKol = [];
foreach ($data as $n => $b) {
    foreach (KOL as $namaKol => $idx) {
        $v = trim((string)($b[$idx] ?? ''));
        if ($v !== '' && preg_match('/^#(REF|N\/A|VALUE|DIV\/0|NAME)/i', $v)) {
            $galatKol[$namaKol] = ($galatKol[$namaKol] ?? 0) + 1;
        }
    }
}
if ($galatKol) {
    foreach ($galatKol as $k => $c) {
        echo "  $k: $c baris bernilai galat\n";
    }
} else {
    echo "  (bersih)\n";
}

/* --- 2. Barcode ------------------------------------------------------- */
echo "\n--- 2. Barcode ---\n";
$kosong = 0;
$hitung = [];
foreach ($data as $n => $b) {
    $bc = $ambil($b, 'barcode');
    if ($bc === '') {
        $kosong++;
    } else {
        $hitung[$bc] = ($hitung[$bc] ?? 0) + 1;
    }
}
$dup = array_filter($hitung, static function($c){ return $c > 1; });
echo '  kosong   : ' . $kosong . "\n";
echo '  unik     : ' . count($hitung) . "\n";
echo '  duplikat : ' . count($dup) . " nilai\n";
foreach (array_slice($dup, 0, 10, true) as $bc => $c) {
    $nama = [];
    foreach ($data as $b) {
        if ($ambil($b, 'barcode') === (string)$bc) {
            $nama[] = $ambil($b, 'nama');
        }
    }
    echo "      \"$bc\" x$c -> " . implode(' | ', $nama) . "\n";
}

/* --- 3. SKU ----------------------------------------------------------- */
echo "\n--- 3. SKU ---\n";
$skuKosong = 0;
$skuHit = [];
foreach ($data as $b) {
    $s = $ambil($b, 'sku');
    if ($s === '') {
        $skuKosong++;
    } else {
        $skuHit[$s] = ($skuHit[$s] ?? 0) + 1;
    }
}
echo '  kosong   : ' . $skuKosong . "\n";
echo '  duplikat : ' . count(array_filter($skuHit, static function($c){ return $c > 1; })) . " nilai\n";

/* --- 4. Stok awal ----------------------------------------------------- */
echo "\n--- 4. Stok awal ---\n";
$sAda = 0; $sKosong = 0; $sNegatif = 0; $sPecahan = 0; $sTotal = 0; $sMaks = 0;
foreach ($data as $b) {
    $v = $ambil($b, 'stok_awal');
    if ($v === '' || !is_numeric($v)) { $sKosong++; continue; }
    $f = (float)$v;
    $sAda++;
    if ($f < 0) $sNegatif++;
    if (floor($f) != $f) $sPecahan++;
    $sTotal += $f;
    $sMaks = max($sMaks, $f);
}
echo "  angka valid : $sAda\n";
echo "  kosong/rusak: $sKosong\n";
echo "  negatif     : $sNegatif\n";
echo "  pecahan     : $sPecahan\n";
echo '  total unit  : ' . number_format($sTotal) . "\n";
echo '  tertinggi   : ' . number_format($sMaks) . "\n";

/* --- 5. Stok minimal -------------------------------------------------- */
echo "\n--- 5. Stok minimal ---\n";
$mAda = 0; $mNol = 0; $mPecahan = 0; $mKosong = 0; $mMaks = 0;
foreach ($data as $b) {
    $v = $ambil($b, 'stok_minimal');
    if ($v === '' || !is_numeric($v)) { $mKosong++; continue; }
    $f = (float)$v;
    $mAda++;
    if ($f == 0) $mNol++;
    if (floor($f) != $f) $mPecahan++;
    $mMaks = max($mMaks, $f);
}
echo "  angka valid : $mAda\n";
echo "  kosong/rusak: $mKosong\n";
echo "  bernilai 0  : $mNol\n";
echo "  PECAHAN     : $mPecahan   <-- kolom DB bertipe INT\n";
echo '  tertinggi   : ' . number_format($mMaks, 2) . "\n";

/* --- 6. Kategori ------------------------------------------------------ */
echo "\n--- 6. Kategori ---\n";
$kat = [];
foreach ($data as $b) {
    $k = $ambil($b, 'kategori');
    $kat[$k === '' ? '(kosong)' : $k] = ($kat[$k === '' ? '(kosong)' : $k] ?? 0) + 1;
}
arsort($kat);
$dikenal = ['FISIO', 'FOX', 'AVO', 'AYRES', 'AC', 'LAINNYA'];
foreach ($kat as $k => $c) {
    $tanda = ($k === '(kosong)' || in_array($k, $dikenal, true)) ? '   ' : ' ! ';
    echo "  $tanda" . str_pad((string)$k, 22) . " $c\n";
}
echo "  (! = di luar KATEGORI_OPTIONS aplikasi)\n";

/* --- 7. Berapa yang akan berstatus merah ------------------------------ */
echo "\n--- 7. Simulasi status (stok awal vs stok minimal, dibulatkan ke atas) ---\n";
$kritis = 0; $rendah = 0; $aman = 0; $belum = 0;
foreach ($data as $b) {
    $sa = $ambil($b, 'stok_awal');
    $sm = $ambil($b, 'stok_minimal');
    $sa = is_numeric($sa) ? (int)round((float)$sa) : 0;
    $sm = is_numeric($sm) ? (int)ceil((float)$sm) : 0;
    if ($sm === 0)              { $belum++; }
    elseif ($sa <= $sm)         { $kritis++; }
    elseif ($sa <= $sm * 1.3)   { $rendah++; }
    else                        { $aman++; }
}
echo "  MERAH  (kritis / perlu order) : $kritis\n";
echo "  AMBER  (menipis)              : $rendah\n";
echo "  HIJAU  (aman)                 : $aman\n";
echo "  ABU    (belum diatur, min=0)  : $belum\n";

/* --- 8. Cocokkan dengan master di database ---------------------------- */
echo "\n--- 8. Kecocokan dengan master_barang di database ---\n";
require_once __DIR__ . '/../includes/db.php';
$adaDb = [];
foreach (dbAll('SELECT barcode, sku, nama FROM master_barang WHERE deleted_at IS NULL') as $r) {
    $adaDb[$r['barcode']] = $r;
}
$cocokBarcode = 0; $baru = 0;
foreach ($data as $b) {
    $bc = $ambil($b, 'barcode');
    if ($bc !== '' && isset($adaDb[$bc])) {
        $cocokBarcode++;
    } else {
        $baru++;
    }
}
echo '  master di DB sekarang     : ' . count($adaDb) . "\n";
echo "  cocok via barcode         : $cocokBarcode\n";
echo "  tidak cocok / barcode kosong: $baru\n";
