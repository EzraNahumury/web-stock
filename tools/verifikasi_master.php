<?php
/**
 * verifikasi_master.php — bandingkan master_barang dengan berkas KARTU STOK.
 *
 * Dijalankan setelah impor atau pendataan ulang. Membaca ulang berkas
 * sumbernya dan memeriksa SETIAP baris, bukan sampel: nama, SKU, stok awal,
 * stok minimal (dibulatkan ke atas), dan kategori.
 *
 * Barcode dibandingkan dengan toleransi, karena barcode kosong dan kembar
 * memang diubah saat impor mengikuti aturan di tools/reset_data.php:
 * "INT-<SKU>" / "INT-GEN-<nnnn>" untuk yang kosong, akhiran "-Dn" untuk yang
 * kembar. Baris seperti itu dicocokkan lewat nama + SKU dan dilaporkan
 * terpisah, bukan dihitung sebagai selisih.
 *
 * Tidak menulis apa pun. Keluar dengan kode 1 bila ada selisih, supaya bisa
 * dipakai sebagai pemeriksaan otomatis.
 *
 * Jalankan: php tools\verifikasi_master.php ["NAMA BERKAS.xlsx"]
 */

declare(strict_types=1);

require_once __DIR__ . '/baca_xlsx.php';
require_once __DIR__ . '/../includes/db.php';

const V_SKU = 0, V_BARCODE = 1, V_NAMA = 2, V_STOK_AWAL = 3,
      V_STOK_MINIMAL = 7, V_KATEGORI = 9;
const V_BARIS_AWAL = 6;

$sumber = berkasKartuStok($argv[1] ?? null);
$baris  = bacaXlsx($sumber);

$g = static function (array $b, int $i): string {
    return trim((string)($b[$i] ?? ''));
};

echo "==========================================================\n";
echo " VERIFIKASI master_barang vs " . basename($sumber) . "\n";
echo "==========================================================\n";

/* --- Harapan dari berkas sumber ---------------------------------------- */
$harap = [];
foreach ($baris as $n => $b) {
    if ($n < V_BARIS_AWAL) {
        continue;
    }
    $nama = $g($b, V_NAMA);
    if ($nama === '') {
        continue;
    }
    $rawAwal = $g($b, V_STOK_AWAL);
    $rawMin  = $g($b, V_STOK_MINIMAL);

    $harap[] = [
        'baris'        => $n,
        'sku'          => mb_substr($g($b, V_SKU), 0, 50),
        'barcode'      => mb_substr($g($b, V_BARCODE), 0, 50),
        'nama'         => mb_substr($nama, 0, 255),
        'stok_awal'    => (is_numeric($rawAwal) && (float)$rawAwal > 0) ? (int)round((float)$rawAwal) : 0,
        'stok_minimal' => (is_numeric($rawMin) && (float)$rawMin > 0) ? (int)ceil((float)$rawMin) : 0,
        'kategori'     => mb_substr(mb_strtoupper($g($b, V_KATEGORI)), 0, 30),
    ];
}

/* --- Keadaan database -------------------------------------------------- */
$db = dbAll('SELECT id, sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli
               FROM master_barang WHERE deleted_at IS NULL');

echo 'Baris di berkas : ' . count($harap) . "\n";
echo 'Baris di DB     : ' . count($db) . "\n\n";

$olehBarcode = [];
$olehKunci   = [];   // nama|sku -> daftar baris DB
foreach ($db as $m) {
    $olehBarcode[$m['barcode']] = $m;
    $olehKunci[mb_strtoupper($m['nama']) . '|' . $m['sku']][] = $m;
}

$dipakai      = [];
$cocokBc      = 0;
$cocokKunci   = 0;
$bcDiubah     = 0;
$takKetemu    = [];
$selisih      = [];

foreach ($harap as $h) {
    $m = null;

    if ($h['barcode'] !== '' && isset($olehBarcode[$h['barcode']])
        && !isset($dipakai[$olehBarcode[$h['barcode']]['id']])) {
        $m = $olehBarcode[$h['barcode']];
        $cocokBc++;
    } else {
        $k = mb_strtoupper($h['nama']) . '|' . $h['sku'];
        foreach ($olehKunci[$k] ?? [] as $c) {
            if (!isset($dipakai[$c['id']])) {
                $m = $c;
                $cocokKunci++;
                break;
            }
        }
    }

    if ($m === null) {
        $takKetemu[] = $h;
        continue;
    }
    $dipakai[$m['id']] = true;

    // Barcode boleh berbeda hanya bila memang ditandai buatan sistem.
    if ($m['barcode'] !== $h['barcode']) {
        if ((int)$m['barcode_asli'] === 1) {
            $selisih[] = "baris {$h['baris']} \"{$h['nama']}\": barcode berbeda "
                . "(berkas \"{$h['barcode']}\", DB \"{$m['barcode']}\") tapi tidak ditandai buatan";
        } else {
            $bcDiubah++;
        }
    }

    foreach (['nama', 'sku', 'kategori'] as $kol) {
        if ((string)$m[$kol] !== (string)$h[$kol]) {
            $selisih[] = "baris {$h['baris']} \"{$h['nama']}\": $kol berbeda "
                . "(berkas \"{$h[$kol]}\", DB \"{$m[$kol]}\")";
        }
    }
    foreach (['stok_awal', 'stok_minimal'] as $kol) {
        if ((int)$m[$kol] !== (int)$h[$kol]) {
            $selisih[] = "baris {$h['baris']} \"{$h['nama']}\": $kol berbeda "
                . "(berkas {$h[$kol]}, DB {$m[$kol]})";
        }
    }
}

$dbTakTerpakai = [];
foreach ($db as $m) {
    if (!isset($dipakai[$m['id']])) {
        $dbTakTerpakai[] = $m;
    }
}

/* --- Laporan ----------------------------------------------------------- */
echo "--- Kecocokan ---\n";
echo '  lewat barcode        : ' . $cocokBc . "\n";
echo '  lewat nama + SKU     : ' . $cocokKunci . "\n";
echo '  barcode diubah impor : ' . $bcDiubah . " (kosong atau kembar di sumber)\n";
echo '  di berkas tapi tidak di DB : ' . count($takKetemu) . "\n";
echo '  di DB tapi tidak di berkas : ' . count($dbTakTerpakai) . "\n";

foreach (array_slice($takKetemu, 0, 10) as $h) {
    echo "      berkas baris {$h['baris']}: {$h['nama']}\n";
}
foreach (array_slice($dbTakTerpakai, 0, 10) as $m) {
    echo "      DB id {$m['id']}: {$m['nama']}\n";
}

$totalBerkas = 0;
foreach ($harap as $h) {
    $totalBerkas += $h['stok_awal'];
}
$totalDb = (int)dbValue('SELECT COALESCE(SUM(stok_awal),0) FROM master_barang WHERE deleted_at IS NULL');

echo "\n--- Total stok awal ---\n";
echo '  berkas : ' . number_format($totalBerkas, 0, ',', '.') . "\n";
echo '  DB     : ' . number_format($totalDb, 0, ',', '.') . "\n";
if ($totalBerkas !== $totalDb) {
    $selisih[] = "total stok awal berbeda (berkas $totalBerkas, DB $totalDb)";
}

echo "\n--- Selisih nilai ---\n";
if (!$selisih && !$takKetemu && !$dbTakTerpakai) {
    echo '  TIDAK ADA. ' . count($harap) . '/' . count($harap) . " baris cocok persis.\n";
    echo "\n==========================================================\n";
    echo " LULUS\n";
    echo "==========================================================\n";
    exit(0);
}

foreach (array_slice($selisih, 0, 30) as $d) {
    echo "  $d\n";
}
if (count($selisih) > 30) {
    echo '  ... dan ' . (count($selisih) - 30) . " lagi\n";
}

echo "\n==========================================================\n";
echo ' GAGAL: ' . (count($selisih) + count($takKetemu) + count($dbTakTerpakai)) . " masalah.\n";
echo "==========================================================\n";
exit(1);
