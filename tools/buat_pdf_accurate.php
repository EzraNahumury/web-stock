<?php
/**
 * buat_pdf_accurate.php — buat PDF contoh bergaya laporan Accurate
 * "Kuantitas Barang per Gudang", untuk menguji pembacanya.
 *
 * Berkas Accurate yang asli memuat harga pokok tiap barang, jadi tidak
 * disimpan di repositori. Contoh ini memakai nama barang nyata dari master
 * dengan angka karangan, supaya pengujian pencocokan namanya tetap berarti.
 *
 * Jalankan: php tools\buat_pdf_accurate.php [jumlah] [berkas] [kategori]
 *
 * Kategori boleh dikosongkan; diisi bila ingin isinya cocok dengan sesi
 * opname yang dibatasi satu kategori.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pdf.php';

$jumlah   = isset($argv[1]) ? max(1, (int)$argv[1]) : 12;
$keluar   = (isset($argv[2]) && $argv[2] !== '')
    ? $argv[2]
    : (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accurate-contoh.pdf');
$kategori = $argv[3] ?? '';

$where  = ['deleted_at IS NULL', 'aktif = 1'];
$params = [];
if ($kategori !== '') {
    $where[]  = 'kategori = ?';
    $params[] = $kategori;
}

$barang = dbAll(
    'SELECT nama FROM master_barang WHERE ' . implode(' AND ', $where)
    . ' ORDER BY nama LIMIT ' . (int)$jumlah,
    $params
);
if (!$barang) {
    fwrite(STDERR, "Master barang kosong.\n");
    exit(1);
}

$pdf = new PdfTabel('lanskap');
$pdf->siapkan('Kuantitas Barang per Gudang', [
    'Per Tgl.' => date('d M Y'),
    'Cabang'   => 'Kantor Pusat, Gudang : GUDANG UTAMA',
], [
    ['label' => 'Nama Barang', 'lebar' => 40],
    ['label' => 'Kuantitas',   'lebar' => 12, 'rata' => 'kanan'],
    ['label' => 'Total Biaya', 'lebar' => 16, 'rata' => 'kanan'],
    ['label' => 'Kuantitas',   'lebar' => 12, 'rata' => 'kanan'],
    ['label' => 'Total Biaya', 'lebar' => 16, 'rata' => 'kanan'],
]);

$daftar = [];
foreach ($barang as $i => $b) {
    // Angka karangan yang tetap sama tiap kali dijalankan, supaya hasil uji
    // bisa dibandingkan antar-jalan.
    $qty   = ($i * 7) % 40;
    $biaya = number_format($qty * 2331.06, 2, ',', '.');
    $daftar[] = ['nama' => $b['nama'], 'qty' => $qty];
    $pdf->baris([
        $b['nama'],
        number_format($qty, 0, ',', '.'),
        $biaya,
        number_format($qty, 0, ',', '.'),
        $biaya,
    ]);
}

$pdf->ringkasan(count($daftar) . ' barang');

// Simpan ke berkas, bukan dikirim ke browser.
$isi = $pdf->keluarkan();
file_put_contents($keluar, $isi);

echo "PDF contoh   : $keluar\n";
echo "Baris        : " . count($daftar) . "\n";
echo "Harapan hasil:\n";
foreach ($daftar as $d) {
    echo '  ' . str_pad((string)$d['qty'], 4, ' ', STR_PAD_LEFT) . '  ' . $d['nama'] . "\n";
}
