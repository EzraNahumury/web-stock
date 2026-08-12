<?php
/**
 * impor_kartu_stok.php — muat data nyata dari KARTU STOK ke master_barang.
 *
 * Sumber : "KARTU STOK AGUSTUS 2026 (1).xlsx", sheet "DAFTAR REKAP BARANG"
 * Header : baris 4, data mulai baris 6
 *
 * Kolom yang dipakai:
 *   A SKU   B KODE BARCODE   C NAMA BARANG   D STOK AWAL
 *   H STOK MINIMAL           J KATEGORI
 *
 * Kolom E (BARANG MASUK), F (BARANG KELUAR), dan G (STOK AKHIR) SENGAJA
 * TIDAK dipakai: seluruh 1.404 barisnya bernilai #REF! — rumusnya rusak di
 * berkas sumber. Lagipula ketiganya adalah nilai turunan; aplikasi
 * menghitungnya sendiri dari tabel transaksi.
 *
 * Pencocokan ke baris master yang sudah ada dilakukan berurutan:
 *   1. barcode persis
 *   2. SKU persis (bila hanya satu kandidat)
 *   3. nama persis (bila hanya satu kandidat yang belum diklaim)
 * Baris DB yang sudah diklaim baris Excel lain tidak ikut dipertimbangkan
 * lagi, supaya dua produk bernama sama tidak berebut baris yang sama.
 *
 * Jalankan:
 *   php tools\impor_kartu_stok.php            <- simulasi, tidak menulis
 *   php tools\impor_kartu_stok.php --tulis    <- benar-benar menyimpan
 */

declare(strict_types=1);

require_once __DIR__ . '/baca_xlsx.php';
require_once __DIR__ . '/../includes/db.php';

$TULIS = in_array('--tulis', $argv, true);

const K_SKU = 0, K_BARCODE = 1, K_NAMA = 2, K_STOK_AWAL = 3,
      K_STOK_MINIMAL = 7, K_KATEGORI = 9;
const BARIS_DATA_AWAL = 6;

$sumber = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'KARTU STOK AGUSTUS 2026 (1).xlsx';

echo "==========================================================\n";
echo ' IMPOR KARTU STOK -> master_barang   [' . ($TULIS ? 'MENULIS' : 'SIMULASI') . "]\n";
echo "==========================================================\n";

$baris = bacaXlsx($sumber);
$g = static function (array $b, int $i): string {
    return trim((string)($b[$i] ?? ''));
};

/* ---------------------------------------------------------------- */
/* Baca & normalisasi baris Excel                                    */
/* ---------------------------------------------------------------- */
$catatan = [];
$sumberBaris = [];

foreach ($baris as $n => $b) {
    if ($n < BARIS_DATA_AWAL) {
        continue;
    }
    $nama = $g($b, K_NAMA);
    if ($nama === '') {
        continue;
    }

    // Stok awal: kosong / #REF! -> 0; negatif -> 0 (stok pembuka tidak
    // mungkin minus, itu galat pencatatan di sumber).
    $rawAwal = $g($b, K_STOK_AWAL);
    if ($rawAwal === '' || !is_numeric($rawAwal)) {
        $stokAwal = 0;
        if ($rawAwal !== '') {
            $catatan[] = "Baris $n \"$nama\": stok awal \"$rawAwal\" tidak terbaca -> 0";
        } else {
            $catatan[] = "Baris $n \"$nama\": stok awal kosong -> 0";
        }
    } else {
        $f = (float)$rawAwal;
        if ($f < 0) {
            $catatan[] = "Baris $n \"$nama\": stok awal negatif ($f) -> 0";
            $stokAwal = 0;
        } else {
            $stokAwal = (int)round($f);
        }
    }

    // Stok minimal: kolom sumber berisi pecahan (40% penjualan bulanan).
    // DIBULATKAN KE ATAS — ambang order yang dibulatkan ke bawah membuat
    // peringatan terlambat menyala.
    $rawMin = $g($b, K_STOK_MINIMAL);
    if ($rawMin === '' || !is_numeric($rawMin)) {
        $stokMinimal = 0;
    } else {
        $fm = (float)$rawMin;
        $stokMinimal = $fm > 0 ? (int)ceil($fm) : 0;
    }

    $sumberBaris[] = [
        'excel_row'    => $n,
        'sku'          => mb_substr($g($b, K_SKU), 0, 50),
        'barcode'      => mb_substr($g($b, K_BARCODE), 0, 50),
        'nama'         => mb_substr($nama, 0, 255),
        'stok_awal'    => $stokAwal,
        'stok_minimal' => $stokMinimal,
        'kategori'     => mb_substr(mb_strtoupper($g($b, K_KATEGORI)), 0, 30),
    ];
}

echo 'Baris Excel terbaca : ' . count($sumberBaris) . "\n";

/* ---------------------------------------------------------------- */
/* Indeks master yang ada                                            */
/* ---------------------------------------------------------------- */
$master = dbAll('SELECT id, sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli
                   FROM master_barang WHERE deleted_at IS NULL');
echo 'Master di database  : ' . count($master) . "\n\n";

$olehBarcode = [];
$olehSku     = [];
$olehNama    = [];
foreach ($master as $m) {
    $olehBarcode[$m['barcode']] = $m;
    if ($m['sku'] !== '') {
        $olehSku[$m['sku']][] = $m;
    }
    $olehNama[mb_strtoupper(trim($m['nama']))][] = $m;
}

/* ---------------------------------------------------------------- */
/* Cocokkan                                                          */
/* ---------------------------------------------------------------- */
$diklaim   = [];   // id master -> excel_row
$rencana   = [];   // perubahan yang akan ditulis
$takCocok  = [];
$viaBc = $viaSku = $viaNama = 0;

foreach ($sumberBaris as $r) {
    $target = null;

    if ($r['barcode'] !== '' && isset($olehBarcode[$r['barcode']])) {
        $kand = $olehBarcode[$r['barcode']];
        if (!isset($diklaim[$kand['id']])) {
            $target = $kand;
            $viaBc++;
        }
    }

    if ($target === null && $r['sku'] !== '' && isset($olehSku[$r['sku']])) {
        $bebas = [];
        foreach ($olehSku[$r['sku']] as $c) {
            if (!isset($diklaim[$c['id']])) {
                $bebas[] = $c;
            }
        }
        if (count($bebas) === 1) {
            $target = $bebas[0];
            $viaSku++;
        }
    }

    if ($target === null) {
        $k = mb_strtoupper(trim($r['nama']));
        if (isset($olehNama[$k])) {
            $bebas = [];
            foreach ($olehNama[$k] as $c) {
                if (!isset($diklaim[$c['id']])) {
                    $bebas[] = $c;
                }
            }
            if (count($bebas) === 1) {
                $target = $bebas[0];
                $viaNama++;
            }
        }
    }

    if ($target === null) {
        $takCocok[] = $r;
        continue;
    }

    $diklaim[$target['id']] = $r['excel_row'];

    // Barcode: hanya diperbarui bila Excel punya barcode asli, baris DB
    // masih memakai barcode generate (INT-... / akhiran -D2), DAN barcode
    // itu belum dipegang baris master lain.
    //
    // Syarat terakhir penting: tiga produk di berkas sumber berbagi barcode
    // yang sama (12132848, 12132897, 12132898). Saat konversi awal, yang
    // kedua diberi akhiran -D2. Mengembalikannya ke barcode asli akan
    // menabrak UNIQUE dan — lebih buruk — menggabungkan dua produk berbeda
    // menjadi satu dalam perhitungan stok. Akhiran itu dipertahankan sampai
    // gudang memutuskan barcode mana yang benar.
    $barcodeBaru = null;
    if ($r['barcode'] !== '' && $r['barcode'] !== $target['barcode']
        && (int)$target['barcode_asli'] === 0) {
        $pemilikLain = isset($olehBarcode[$r['barcode']]) ? (int)$olehBarcode[$r['barcode']]['id'] : null;
        if ($pemilikLain === null || $pemilikLain === (int)$target['id']) {
            $barcodeBaru = $r['barcode'];
        } else {
            $catatan[] = "Baris {$r['excel_row']} \"{$r['nama']}\": barcode \"{$r['barcode']}\" "
                . "sudah dipegang produk lain (id=$pemilikLain) — "
                . "dipertahankan sebagai \"{$target['barcode']}\", perlu diputuskan gudang";
        }
    }

    $rencana[] = [
        'id'          => (int)$target['id'],
        'excel_row'   => $r['excel_row'],
        'lama'        => $target,
        'sku'         => $r['sku'] !== '' ? $r['sku'] : $target['sku'],
        'nama'        => $r['nama'],
        'stok_awal'   => $r['stok_awal'],
        'stok_minimal'=> $r['stok_minimal'],
        'kategori'    => $r['kategori'],
        'barcode_baru'=> $barcodeBaru,
    ];
}

echo "--- Pencocokan ---\n";
echo "  via barcode : $viaBc\n";
echo "  via SKU     : $viaSku\n";
echo "  via nama    : $viaNama\n";
echo '  TOTAL cocok : ' . count($rencana) . "\n";
echo '  tidak cocok : ' . count($takCocok) . "\n";
foreach ($takCocok as $r) {
    echo "      baris {$r['excel_row']}: sku=\"{$r['sku']}\" bc=\"{$r['barcode']}\" | {$r['nama']}\n";
}

$takTersentuh = [];
foreach ($master as $m) {
    if (!isset($diklaim[$m['id']])) {
        $takTersentuh[] = $m;
    }
}
echo '  baris DB tanpa pasangan di Excel : ' . count($takTersentuh) . "\n";

/* ---------------------------------------------------------------- */
/* Ringkasan perubahan                                               */
/* ---------------------------------------------------------------- */
$ubahAwal = $ubahMin = $ubahKat = $ubahNama = $ubahSku = $ubahBc = 0;
foreach ($rencana as $p) {
    $l = $p['lama'];
    if ((int)$l['stok_awal']    !== $p['stok_awal'])    $ubahAwal++;
    if ((int)$l['stok_minimal'] !== $p['stok_minimal']) $ubahMin++;
    if ($l['kategori']          !== $p['kategori'])     $ubahKat++;
    if (trim($l['nama'])        !== $p['nama'])         $ubahNama++;
    if ($l['sku']               !== $p['sku'])          $ubahSku++;
    if ($p['barcode_baru'] !== null)                    $ubahBc++;
}

echo "\n--- Perubahan yang akan ditulis ---\n";
echo "  stok_awal    : $ubahAwal baris\n";
echo "  stok_minimal : $ubahMin baris\n";
echo "  kategori     : $ubahKat baris\n";
echo "  nama         : $ubahNama baris\n";
echo "  sku          : $ubahSku baris\n";
echo "  barcode      : $ubahBc baris\n";

$totalUnit = 0;
foreach ($rencana as $p) {
    $totalUnit += $p['stok_awal'];
}
echo '  total unit stok awal : ' . number_format($totalUnit) . "\n";

/* --- Pemeriksaan tabrakan barcode SEBELUM menulis ------------------------
 * Kolom barcode UNIQUE. Satu tabrakan akan menggagalkan seluruh transaksi
 * di tengah jalan, jadi diperiksa lebih dulu dan dilaporkan sebagai daftar
 * utuh, bukan dibiarkan meledak pada baris pertama yang bentrok.            */
$barcodeSetelah = [];
foreach ($master as $m) {
    $barcodeSetelah[$m['barcode']] = (int)$m['id'];
}
$bentrok = [];
foreach ($rencana as $p) {
    if ($p['barcode_baru'] === null) {
        continue;
    }
    echo "    barcode baris {$p['excel_row']}: \"{$p['lama']['barcode']}\" -> \"{$p['barcode_baru']}\"  ({$p['nama']})\n";
    if (isset($barcodeSetelah[$p['barcode_baru']]) && $barcodeSetelah[$p['barcode_baru']] !== $p['id']) {
        $bentrok[] = "baris {$p['excel_row']} \"{$p['nama']}\": barcode \"{$p['barcode_baru']}\" "
            . 'sudah dipakai master id=' . $barcodeSetelah[$p['barcode_baru']];
        continue;
    }
    unset($barcodeSetelah[$p['lama']['barcode']]);
    $barcodeSetelah[$p['barcode_baru']] = $p['id'];
}
if ($bentrok) {
    echo "\n!!! TABRAKAN BARCODE — impor dibatalkan !!!\n";
    foreach ($bentrok as $x) {
        echo "  $x\n";
    }
    exit(1);
}

/* --- Distribusi status setelah impor --- */
$kritis = $rendah = $aman = $belum = 0;
foreach ($rencana as $p) {
    $sa = $p['stok_awal'];
    $sm = $p['stok_minimal'];
    if ($sm === 0)            $belum++;
    elseif ($sa <= $sm)       $kritis++;
    elseif ($sa <= $sm * 1.3) $rendah++;
    else                      $aman++;
}
echo "\n--- Status setelah impor ---\n";
echo "  MERAH (perlu order)  : $kritis\n";
echo "  AMBER (menipis)      : $rendah\n";
echo "  HIJAU (aman)         : $aman\n";
echo "  ABU   (belum diatur) : $belum\n";

if ($catatan) {
    echo "\n--- Catatan data (" . count($catatan) . ") ---\n";
    foreach (array_slice($catatan, 0, 20) as $c) {
        echo "  $c\n";
    }
    if (count($catatan) > 20) {
        echo '  ... dan ' . (count($catatan) - 20) . " lainnya\n";
    }
}

/* ---------------------------------------------------------------- */
/* Tulis                                                             */
/* ---------------------------------------------------------------- */
if (!$TULIS) {
    echo "\n==========================================================\n";
    echo " SIMULASI — tidak ada yang ditulis.\n";
    echo " Jalankan ulang dengan --tulis untuk menyimpan.\n";
    echo "==========================================================\n";
    exit(0);
}

echo "\nMenulis ke database...\n";

$ditulis = dbTransaksi(static function (PDO $pdo) use ($rencana) {
    $st = $pdo->prepare(
        'UPDATE master_barang
            SET sku = ?, nama = ?, stok_awal = ?, stok_minimal = ?, kategori = ?
          WHERE id = ?'
    );
    $stBc = $pdo->prepare('UPDATE master_barang SET barcode = ?, barcode_asli = 1 WHERE id = ?');

    $n = 0;
    foreach ($rencana as $p) {
        $st->execute([
            $p['sku'], $p['nama'], $p['stok_awal'], $p['stok_minimal'], $p['kategori'], $p['id'],
        ]);
        if ($p['barcode_baru'] !== null) {
            $stBc->execute([$p['barcode_baru'], $p['id']]);
        }
        $n++;
    }
    return $n;
});

echo "Selesai. $ditulis baris master diperbarui.\n";

$cek = dbOne('
    SELECT COUNT(*) AS total,
           COALESCE(SUM(stok_awal), 0) AS unit,
           SUM(CASE WHEN stok_minimal > 0 THEN 1 ELSE 0 END) AS ada_ambang,
           SUM(CASE WHEN stok_minimal > 0 AND stok_awal <= stok_minimal THEN 1 ELSE 0 END) AS merah
      FROM master_barang WHERE deleted_at IS NULL');
echo "\nVerifikasi dari database:\n";
echo "  master        : {$cek['total']}\n";
echo '  total unit    : ' . number_format((float)$cek['unit']) . "\n";
echo "  punya ambang  : {$cek['ada_ambang']}\n";
echo "  status MERAH  : {$cek['merah']}\n";
