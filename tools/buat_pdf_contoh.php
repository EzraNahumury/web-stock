<?php
/**
 * buat_pdf_contoh.php — hasilkan PDF picking list sintetis untuk pengujian.
 *
 * PENTING: layout di sini adalah TEBAKAN berdasarkan apa yang dicari parser
 * (kolom No/Barcode/Nama Barang/SKU/Qty/No Pesanan, header "Tanggal Cetak",
 * "Dicetak Oleh", "PICK-...", footer halaman). PDF picking list yang asli
 * bisa berbeda. Berkas ini hanya untuk membuktikan alur unggah -> parse ->
 * review -> simpan berjalan; ia BUKAN pengganti uji dengan PDF sungguhan.
 *
 * Jalankan: C:\xampp\php\php.exe tools\buat_pdf_contoh.php
 */

declare(strict_types=1);

$keluar = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contoh' . DIRECTORY_SEPARATOR . 'picking-list-contoh.pdf';
if (!is_dir(dirname($keluar))) {
    mkdir(dirname($keluar), 0777, true);
}

// Kolom: x tiap kolom, meniru tabel picking list.
$kolom = ['no' => 45, 'barcode' => 75, 'nama' => 160, 'sku' => 350, 'qty' => 430, 'pesanan' => 470];

$barang = [
    ['1', '12132519', 'FINGERTAPE BIRU MUDA',  'FI-0002', '3',  'MP-8891023'],
    ['2', '12132520', 'FINGERTAPE BIRU TUA',   'FI-0003', '12', 'MP-8891023'],
    ['3', '12132521', 'FINGERTAPE CREAM',      'FI-0004', '5',  'MP-8891024'],
    // Nama panjang: sengaja dibungkus ke baris kedua untuk menguji
    // penggabungan baris multi-line lewat kolom No.
    ['4', '12132522', 'FINGERTAPE HIJAU MUDA EDISI KHUSUS', 'FI-0005', '7', 'MP-8891024'],
    ['5', '6936047373262', 'AOLIKES ANKLE CREAM', 'AV-0011', '2', 'MP-8891025'],
];

$teks = [];   // [x, y, isi]

// --- Kepala dokumen ---
$teks[] = [45, 800, 'PICKING LIST GUDANG'];
$teks[] = [45, 782, 'No Pick: PICK-20260812-001'];
$teks[] = [45, 768, 'Tanggal Cetak: 12/08/2026'];
$teks[] = [250, 768, 'Dicetak Oleh: admin_gudang'];
$teks[] = [45, 754, 'Jumlah Pesanan: 3'];
$teks[] = [250, 754, 'Jumlah Produk: 29'];

// --- Header tabel ---
$y = 720;
$teks[] = [$kolom['no'], $y, 'No'];
$teks[] = [$kolom['barcode'], $y, 'Barcode'];
$teks[] = [$kolom['nama'], $y, 'Nama Barang'];
$teks[] = [$kolom['sku'], $y, 'SKU'];
$teks[] = [$kolom['qty'], $y, 'Qty'];
$teks[] = [$kolom['pesanan'], $y, 'No Pesanan'];

// --- Baris data ---
$y -= 22;
foreach ($barang as $b) {
    [$no, $barcode, $nama, $sku, $qty, $pes] = $b;

    // Bungkus nama panjang ke baris kedua (tanpa kolom No), meniru PDF asli.
    $pecah = null;
    if (mb_strlen($nama) > 26) {
        $potong = mb_strrpos(mb_substr($nama, 0, 26), ' ');
        $pecah  = mb_substr($nama, $potong + 1);
        $nama   = mb_substr($nama, 0, $potong);
    }

    $teks[] = [$kolom['no'], $y, $no];
    $teks[] = [$kolom['barcode'], $y, $barcode];
    $teks[] = [$kolom['nama'], $y, $nama];
    $teks[] = [$kolom['sku'], $y, $sku];
    $teks[] = [$kolom['qty'], $y, $qty];
    $teks[] = [$kolom['pesanan'], $y, $pes];

    if ($pecah !== null) {
        $y -= 12;
        $teks[] = [$kolom['nama'], $y, $pecah];   // baris sambungan
    }
    $y -= 20;
}

// --- Footer: baris yang HARUS diabaikan parser ---
$teks[] = [45, 60, 'Halaman 1 / 1'];
$teks[] = [250, 60, 'Dicetak Oleh: admin_gudang'];
$teks[] = [420, 60, 'Jumlah Produk: 29'];

// --- Rakit PDF ---
$isi = "BT\n/F1 9 Tf\n";
foreach ($teks as [$x, $yy, $s]) {
    $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $isi .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $x, $yy, $s);
}
$isi .= "ET";

$obj = [];
$obj[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$obj[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$obj[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
        . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>";
$obj[4] = "<< /Length " . strlen($isi) . " >>\nstream\n" . $isi . "\nendstream";
$obj[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

$pdf     = "%PDF-1.4\n";
$offsets = [];
foreach ($obj as $n => $o) {
    $offsets[$n] = strlen($pdf);
    $pdf .= "$n 0 obj\n$o\nendobj\n";
}
$startxref = strlen($pdf);
$pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
foreach ($obj as $n => $o) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
}
$pdf .= "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n$startxref\n%%EOF";

file_put_contents($keluar, $pdf);

echo "Ditulis: $keluar\n";
echo 'Ukuran : ' . strlen($pdf) . " byte\n";
echo 'Barang : ' . count($barang) . " baris (1 di antaranya nama terbungkus 2 baris)\n";
echo "Total qty yang diharapkan: " . array_sum(array_column($barang, 4)) . "\n";
