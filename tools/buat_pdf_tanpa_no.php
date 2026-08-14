<?php
/**
 * buat_pdf_tanpa_no.php — PDF picking list TANPA kolom nomor urut.
 *
 * Meniru layout picking list marketplace yang dipakai di lapangan:
 *   - tidak ada kolom "No"
 *   - judul kolom "Nama Produk" dan "No.Pesanan" (bukan "Nama Barang")
 *   - nama produk panjang, terbungkus menjadi tiga baris
 *   - nomor pesanan berakhiran " (1)"
 *
 * Dipakai menguji penanda baris adaptif: tanpa kolom No, awal baris harus
 * dikenali dari kolom Barcode.
 *
 * Jalankan: C:\xampp\php\php.exe tools\buat_pdf_tanpa_no.php
 */

declare(strict_types=1);

$keluar = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'contoh'
        . DIRECTORY_SEPARATOR . 'picking-tanpa-kolom-no.pdf';
if (!is_dir(dirname($keluar))) {
    mkdir(dirname($keluar), 0777, true);
}

$kolom = ['barcode' => 45, 'nama' => 150, 'sku' => 330, 'qty' => 400, 'pesanan' => 445];

// [barcode, [baris nama...], sku, qty, no pesanan]
$barang = [
    ['8190888980296',
     ['Kaos Kaki Futsal Pendek A', 'nti Slip Olahraga Sepak Bol', 'a Tebal Sebetis Dewasa...'],
     '100074', '29', '260808AASBC73ZWOML4 (1)'],
    ['12132458',
     ['Elbowpad Ayres Scudo Deke', 'r Penjaga Gawang Dewasa'],
     '100074', '17', '260808AASA4DPDML3TI (1)'],
    ['8119872809863',
     ['Avo Original Sleeve Sock S', 'ambungan Variant: Putih'],
     'AV-0066', '12', '260808AASBP5OY6QZIU (1)'],
    ['12132856',
     ['Pelindung Lutut Kiper Knee', 'Pad Dewasa Hitam'],
     '100055', '6', '260808AAR6RCU3WPJNU (1)'],
];

$teks = [];

// --- Kepala dokumen ---
$teks[] = [45, 800, 'PICKING LIST'];
$teks[] = [45, 784, 'No Pick: PICK-20260814-007'];
$teks[] = [45, 770, 'Tanggal Cetak: 14/08/2026'];
$teks[] = [250, 770, 'Dicetak Oleh: admin_gudang'];

// --- Header tabel: TANPA kolom No, dan "No." dipisah dari "Pesanan" ---
$y = 736;
$teks[] = [$kolom['barcode'], $y, 'Barcode'];
$teks[] = [$kolom['nama'],    $y, 'Nama Produk'];
$teks[] = [$kolom['sku'],     $y, 'SKU'];
$teks[] = [$kolom['qty'],     $y, 'Qty'];
$teks[] = [$kolom['pesanan'],      $y, 'No.'];        // dipecah, seperti pdf.js
$teks[] = [$kolom['pesanan'] + 13, $y, 'Pesanan'];

// --- Baris data ---
$y -= 26;
foreach ($barang as [$barcode, $namaBaris, $sku, $qty, $pesanan]) {
    $teks[] = [$kolom['barcode'], $y, $barcode];
    $teks[] = [$kolom['nama'],    $y, $namaBaris[0]];
    $teks[] = [$kolom['sku'],     $y, $sku];
    $teks[] = [$kolom['qty'],     $y, $qty];
    $teks[] = [$kolom['pesanan'], $y, $pesanan];

    for ($i = 1; $i < count($namaBaris); $i++) {
        $y -= 12;
        $teks[] = [$kolom['nama'], $y, $namaBaris[$i]];   // sambungan nama saja
    }
    $y -= 22;
}

// --- Footer yang harus diabaikan ---
$teks[] = [45, 60, 'Halaman 1 / 1'];
$teks[] = [250, 60, 'Jumlah Produk: 64'];

// --- Rakit PDF ---
$isi = "BT\n/F1 8 Tf\n";
foreach ($teks as [$x, $yy, $s]) {
    $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $isi .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $x, $yy, $s);
}
$isi .= 'ET';

$obj = [];
$obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$obj[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
$obj[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
        . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
$obj[4] = '<< /Length ' . strlen($isi) . " >>\nstream\n" . $isi . "\nendstream";
$obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($obj as $n => $o) {
    $offsets[$n] = strlen($pdf);
    $pdf .= "$n 0 obj\n$o\nendobj\n";
}
$startxref = strlen($pdf);
$pdf .= 'xref' . "\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
foreach ($obj as $n => $o) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
}
$pdf .= 'trailer' . "\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n$startxref\n%%EOF";

file_put_contents($keluar, $pdf);

echo "Ditulis : $keluar\n";
echo 'Barang  : ' . count($barang) . " baris (semua nama terbungkus >1 baris)\n";
echo 'Total qty diharapkan: ' . array_sum(array_column($barang, 3)) . "\n";
