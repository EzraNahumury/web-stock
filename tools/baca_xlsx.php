<?php
/**
 * baca_xlsx.php — pembaca XLSX minimal (tanpa pustaka luar).
 *
 * XLSX adalah ZIP berisi XML. Cukup baca sharedStrings.xml (tabel teks) dan
 * worksheets/sheet1.xml (sel), lalu petakan ke grid baris/kolom.
 *
 * Dipakai tools/impor_kartu_stok.php. Bisa juga dijalankan langsung untuk
 * mengintip isi berkas:
 *   C:\xampp\php\php.exe tools\baca_xlsx.php "KARTU STOK AGUSTUS 2026 (1).xlsx" 1 12
 */

declare(strict_types=1);

/** Ubah referensi kolom Excel (A, B, ..., AA) menjadi indeks 0-basis. */
function kolomKeIndeks(string $ref): int
{
    $n = 0;
    $len = strlen($ref);
    for ($i = 0; $i < $len; $i++) {
        $c = $ref[$i];
        if ($c < 'A' || $c > 'Z') {
            break;
        }
        $n = $n * 26 + (ord($c) - 64);
    }
    return $n - 1;
}

/** Ubah indeks 0-basis menjadi huruf kolom Excel. */
function indeksKeKolom(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/**
 * Baca seluruh sheet pertama menjadi array baris.
 * @return array<int, array<int, string>> baris[nomorBaris][indeksKolom] = nilai
 */
function bacaXlsx(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Berkas tidak ditemukan: $path");
    }

    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException("Gagal membuka XLSX: $path");
    }

    // --- Tabel teks bersama ---
    $shared = [];
    $ssXml = $z->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $x = new XMLReader();
        $x->XML($ssXml);
        $buf = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->name === 'si') {
                $buf = '';
            } elseif ($x->nodeType === XMLReader::ELEMENT && $x->name === 't' && $buf !== null) {
                $buf .= $x->readString();
            } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->name === 'si') {
                $shared[] = $buf;
                $buf = null;
            }
        }
        $x->close();
    }

    // --- Sel ---
    $sheetXml = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();
    if ($sheetXml === false) {
        throw new RuntimeException('sheet1.xml tidak ditemukan.');
    }

    $baris = [];
    $x = new XMLReader();
    $x->XML($sheetXml);

    $barisKini = 0;
    while ($x->read()) {
        if ($x->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($x->name === 'row') {
            $barisKini = (int)$x->getAttribute('r');
            continue;
        }
        if ($x->name !== 'c') {
            continue;
        }

        $ref  = (string)$x->getAttribute('r');
        $tipe = (string)$x->getAttribute('t');
        $kol  = kolomKeIndeks($ref);

        // Baca isi <c> sampai penutupnya.
        $nilai = '';
        $node  = new SimpleXMLElement($x->readOuterXml());
        if ($tipe === 'inlineStr') {
            $nilai = (string)($node->is->t ?? '');
        } elseif (isset($node->v)) {
            $v = (string)$node->v;
            $nilai = ($tipe === 's') ? ($shared[(int)$v] ?? '') : $v;
        }

        if ($nilai !== '') {
            $baris[$barisKini][$kol] = $nilai;
        }
    }
    $x->close();

    return $baris;
}

// --- Mode CLI: intip isi berkas ---
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $path = $argv[1] ?? '';
    $dari = (int)($argv[2] ?? 1);
    $smp  = (int)($argv[3] ?? 15);
    if ($path === '') {
        fwrite(STDERR, "Pakai: php tools/baca_xlsx.php <file.xlsx> [barisAwal] [barisAkhir]\n");
        exit(1);
    }
    $baris = bacaXlsx($path);
    echo 'Total baris terisi: ' . count($baris) . "\n";
    echo 'Baris terakhir     : ' . max(array_keys($baris)) . "\n\n";
    for ($i = $dari; $i <= $smp; $i++) {
        if (!isset($baris[$i])) {
            continue;
        }
        echo "--- baris $i ---\n";
        ksort($baris[$i]);
        foreach ($baris[$i] as $k => $v) {
            echo '  ' . str_pad(indeksKeKolom($k), 4) . ' = ' . mb_substr($v, 0, 60) . "\n";
        }
    }
}
