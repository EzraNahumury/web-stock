<?php
/**
 * convert_seed.php — konversi MASTER_SEED dari prototipe HTML menjadi SQL.
 *
 * Skrip sekali pakai. Membaca array MASTER_SEED di dalam
 * "aplikasi-gudang (2).html", lalu menghasilkan sql/002_seed_master.sql.
 *
 * Dua masalah data yang ditangani (lihat audit D1 & D2 di README):
 *   1. 356 item ber-barcode kosong -> digenerate "INT-<sku>", dan bila SKU juga
 *      kosong -> "INT-GEN-<nnnn>". Kolom barcode_asli diisi 0 supaya admin tahu
 *      barcode itu buatan sistem dan masih perlu dilengkapi.
 *   2. Barcode duplikat -> kemunculan pertama dipertahankan apa adanya,
 *      berikutnya diberi akhiran "-D2", "-D3", dst. dan barcode_asli = 0.
 *      Tidak ada baris yang dibuang diam-diam; semuanya dilaporkan.
 *
 * Jalankan:
 *   C:\xampp\php\php.exe tools\convert_seed.php
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$sumber  = $root . DIRECTORY_SEPARATOR . 'aplikasi-gudang (2).html';
$keluar  = $root . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '002_seed_master.sql';

if (!is_file($sumber)) {
    fwrite(STDERR, "GAGAL: berkas sumber tidak ditemukan: $sumber\n");
    exit(1);
}

$html = file_get_contents($sumber);
if ($html === false) {
    fwrite(STDERR, "GAGAL: tidak bisa membaca berkas sumber.\n");
    exit(1);
}

// Ambil array JSON di antara "const MASTER_SEED = " dan "];"
if (!preg_match('/const\s+MASTER_SEED\s*=\s*(\[.*?\]);/s', $html, $m)) {
    fwrite(STDERR, "GAGAL: blok MASTER_SEED tidak ditemukan di berkas sumber.\n");
    exit(1);
}

$items = json_decode($m[1], true);
if (!is_array($items)) {
    fwrite(STDERR, 'GAGAL: MASTER_SEED bukan JSON valid — ' . json_last_error_msg() . "\n");
    exit(1);
}

echo 'Terbaca: ' . count($items) . " item dari MASTER_SEED\n";

// ---------------------------------------------------------------------------
// Normalisasi + penyelesaian konflik barcode
// ---------------------------------------------------------------------------
$dipakai   = [];   // barcode yang sudah terpakai
$baris     = [];
$genUrut   = 0;
$statBaru  = 0;    // barcode digenerate karena kosong
$statDedup = 0;    // barcode diberi akhiran karena duplikat
$laporan   = [];

foreach ($items as $it) {
    $sku      = trim((string)($it['sku'] ?? ''));
    $barcode  = trim((string)($it['barcode'] ?? ''));
    $nama     = trim((string)($it['nama'] ?? ''));
    $stokAwal = (int)($it['stokAwal'] ?? 0);
    $stokMin  = (int)($it['stokMinimal'] ?? 0);
    $kategori = trim((string)($it['kategori'] ?? ''));

    if ($nama === '') {
        $laporan[] = "DILEWATI (nama kosong): sku=$sku barcode=$barcode";
        continue;
    }

    $asli = 1;

    // 1. barcode kosong -> generate
    if ($barcode === '') {
        if ($sku !== '') {
            $barcode = 'INT-' . preg_replace('/\s+/', '', $sku);
        } else {
            $genUrut++;
            $barcode = sprintf('INT-GEN-%04d', $genUrut);
        }
        $asli = 0;
        $statBaru++;
    }

    // 2. bentrok dengan barcode yang sudah dipakai -> beri akhiran
    if (isset($dipakai[$barcode])) {
        $dasar = $barcode;
        $n     = 2;
        while (isset($dipakai[$dasar . '-D' . $n])) {
            $n++;
        }
        $barcode = $dasar . '-D' . $n;
        $asli    = 0;
        $statDedup++;
        $laporan[] = "DUPLIKAT: \"$dasar\" -> \"$barcode\" (nama: $nama)";
    }

    $dipakai[$barcode] = true;

    $baris[] = [
        'sku'          => mb_substr($sku, 0, 50),
        'barcode'      => mb_substr($barcode, 0, 50),
        'nama'         => mb_substr($nama, 0, 255),
        'stok_awal'    => max(0, $stokAwal),
        'stok_minimal' => max(0, $stokMin),
        'kategori'     => mb_substr($kategori, 0, 30),
        'barcode_asli' => $asli,
    ];
}

// ---------------------------------------------------------------------------
// Tulis SQL
// ---------------------------------------------------------------------------
$q = static function (string $s): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
};

$out  = "-- ============================================================================\n";
$out .= "-- 002_seed_master.sql — DIGENERATE OTOMATIS, jangan diedit manual.\n";
$out .= "-- Sumber : aplikasi-gudang (2).html (MASTER_SEED)\n";
$out .= '-- Dibuat : ' . date('Y-m-d H:i:s') . "\n";
$out .= '-- Jumlah : ' . count($baris) . " baris\n";
$out .= "--\n";
$out .= "-- Regenerasi: C:\\xampp\\php\\php.exe tools\\convert_seed.php\n";
$out .= "-- ============================================================================\n\n";
$out .= "SET NAMES utf8mb4;\n\n";

$kolom = '(sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli)';
$chunk = 200;   // batasi ukuran tiap INSERT agar aman terhadap max_allowed_packet

for ($i = 0; $i < count($baris); $i += $chunk) {
    $potong = array_slice($baris, $i, $chunk);
    $out .= "INSERT INTO master_barang $kolom VALUES\n";
    $nilai = [];
    foreach ($potong as $b) {
        $nilai[] = sprintf(
            '(%s,%s,%s,%d,%d,%s,%d)',
            $q($b['sku']),
            $q($b['barcode']),
            $q($b['nama']),
            $b['stok_awal'],
            $b['stok_minimal'],
            $q($b['kategori']),
            $b['barcode_asli']
        );
    }
    $out .= implode(",\n", $nilai) . ";\n\n";
}

if (!is_dir(dirname($keluar))) {
    mkdir(dirname($keluar), 0777, true);
}
file_put_contents($keluar, $out);

// ---------------------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------------------
echo "Ditulis  : $keluar\n";
echo 'Baris    : ' . count($baris) . "\n";
echo "Barcode digenerate (kosong) : $statBaru\n";
echo "Barcode diberi akhiran (dup): $statDedup\n";
echo 'Perlu dilengkapi admin      : ' . ($statBaru + $statDedup) . " item (barcode_asli = 0)\n";

if ($laporan) {
    echo "\n--- Catatan ---\n";
    foreach ($laporan as $l) {
        echo "  $l\n";
    }
}
