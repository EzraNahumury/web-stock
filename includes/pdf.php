<?php
/**
 * includes/pdf.php — penulis PDF tabel minimal, tanpa pustaka luar.
 *
 * Aplikasi ini tidak memakai Composer supaya deploy ke Hostinger cukup
 * menyalin folder. Untuk tiga laporan tabel, menarik pustaka PDF beserta
 * seluruh rantai dependensinya tidak sepadan — yang dibutuhkan hanya teks
 * berposisi, garis, dan kotak berwarna.
 *
 * Batasan yang disengaja:
 *   - hanya font bawaan PDF (Helvetica), jadi tidak ada berkas font yang
 *     perlu ikut diunggah
 *   - teks dikonversi ke Windows-1252; karakter di luar itu ditransliterasi
 *   - satu tabel per dokumen, dengan baris kepala yang diulang tiap halaman
 */

declare(strict_types=1);

class PdfTabel
{
    /** Lebar karakter Helvetica per 1000 unit em (dari metrik AFM). */
    private const LEBAR = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889,
        '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278,
        '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611,
        'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722,
        'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469,
        '_' => 556, '`' => 333,
        'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278,
        'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222,
        'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
        's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500,
        'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    /** @var float */ private $lebarHalaman;
    /** @var float */ private $tinggiHalaman;
    /** @var float */ private $tepi = 26.0;

    /* Jarak teks dari tepi selnya.
     *
     * Dinaikkan dari 4 ke 7 bukan demi tampilan, melainkan demi pengubah
     * PDF -> Excel. Alat itu menebak batas kolom dari celah kosong di antara
     * teks; dengan celah 8 poin (2 x 4) di antara dua sel berisi penuh, tebakan
     * itu meleset dan kata pertama nama barang ikut masuk ke kolom SKU. */
    private const PAD = 7.0;

    /** @var string[] isi stream tiap halaman */
    /** @var string[] */ private $halaman = [];
    /** @var string */ private $isi = '';
    /** @var float */ private $y = 0.0;
    /** @var int */ private $nomorHalaman = 0;

    /** @var array<int,array{label:string,lebar:float,rata:string}> */
    private $kolom = [];
    /** @var string */ private $judul = '';
    /** @var array */ private $meta = [];

    public function __construct(string $orientasi = 'lanskap')
    {
        // A4
        if ($orientasi === 'potret') {
            $this->lebarHalaman  = 595.28;
            $this->tinggiHalaman = 841.89;
        } else {
            $this->lebarHalaman  = 841.89;
            $this->tinggiHalaman = 595.28;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Teks                                                                */
    /* ------------------------------------------------------------------ */

    /** Ubah UTF-8 ke Windows-1252 dan lolos-kan karakter khusus PDF. */
    private function sandi(string $s): string
    {
        if (function_exists('iconv')) {
            $konversi = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
            if ($konversi !== false) {
                $s = $konversi;
            }
        }
        // Buang sisa karakter kendali yang bisa merusak stream.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Lebar teks dalam poin. */
    private function lebarTeks(string $s, float $ukuran, bool $tebal = false): float
    {
        $total = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $total += self::LEBAR[$s[$i]] ?? 556;
        }
        $lebar = $total / 1000 * $ukuran;
        return $tebal ? $lebar * 1.06 : $lebar;
    }

    /** Potong teks agar muat, dengan elipsis bila perlu. */
    private function potong(string $s, float $maks, float $ukuran, bool $tebal = false): string
    {
        if ($this->lebarTeks($s, $ukuran, $tebal) <= $maks) {
            return $s;
        }
        $elipsis = '...';
        $ruang = $maks - $this->lebarTeks($elipsis, $ukuran, $tebal);
        $hasil = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $uji = $hasil . $s[$i];
            if ($this->lebarTeks($uji, $ukuran, $tebal) > $ruang) {
                break;
            }
            $hasil = $uji;
        }
        return rtrim($hasil) . $elipsis;
    }

    /**
     * Pecah teks jadi beberapa baris yang masing-masing muat di satu kolom.
     *
     * ADA KARENA 63% NAMA BARANG TIDAK MUAT
     * Nama barang di katalog ini panjangnya sampai 93 karakter, dan 908 dari
     * 1.435 barang melewati 28 karakter. Dipotong dengan elipsis, laporan stok
     * opname jadi memuat belasan baris "AYRES CELANA RUNNING DOUBLE LAYE..."
     * yang tidak bisa dibedakan satu sama lain — persis pada laporan yang
     * dipakai menghitung barang di rak. Melebarkan kolomnya tidak menolong:
     * 93 karakter tidak akan pernah muat dalam satu baris.
     *
     * Dipenggal di spasi; kata yang sendirian saja tidak muat dipenggal per
     * karakter. Baris melebihi $maksBaris dibuang dengan elipsis, supaya satu
     * nama yang aneh tidak membuat satu baris tabel setinggi setengah halaman.
     *
     * @return string[] minimal satu baris, maksimal $maksBaris
     */
    private function bungkus(string $s, float $maks, float $ukuran, int $maksBaris = 3): array
    {
        $s = trim($s);
        if ($s === '') {
            return [''];
        }
        if ($this->lebarTeks($s, $ukuran) <= $maks) {
            return [$s];
        }

        $kata  = preg_split('/\s+/', $s) ?: [$s];
        $baris = [];
        $kini  = '';

        foreach ($kata as $w) {
            $uji = $kini === '' ? $w : $kini . ' ' . $w;
            if ($this->lebarTeks($uji, $ukuran) <= $maks) {
                $kini = $uji;
                continue;
            }
            if ($kini !== '') {
                $baris[] = $kini;
                $kini = '';
            }
            // Satu kata yang sendirian pun tidak muat: dipenggal per karakter.
            while ($this->lebarTeks($w, $ukuran) > $maks) {
                $pas = '';
                $n = strlen($w);
                for ($i = 0; $i < $n; $i++) {
                    if ($this->lebarTeks($pas . $w[$i], $ukuran) > $maks) {
                        break;
                    }
                    $pas .= $w[$i];
                }
                if ($pas === '') {
                    // Kolomnya lebih sempit daripada satu karakter. Tidak
                    // mungkin dalam pemakaian nyata, tapi tanpa jaring ini
                    // perulangannya tidak pernah berhenti.
                    $pas = substr($w, 0, 1);
                }
                $baris[] = $pas;
                $w = substr($w, strlen($pas));
            }
            $kini = $w;
        }
        if ($kini !== '') {
            $baris[] = $kini;
        }
        if (!$baris) {
            $baris = [''];
        }

        if (count($baris) > $maksBaris) {
            $baris = array_slice($baris, 0, $maksBaris);
            $akhir = $baris[$maksBaris - 1];
            $baris[$maksBaris - 1] = $this->potong($akhir . ' ...', $maks, $ukuran);
        }
        return $baris;
    }

    private function teks(float $x, float $y, string $s, float $ukuran, bool $tebal = false, array $warna = null): void
    {
        if ($s === '') {
            return;
        }
        $font = $tebal ? '/F2' : '/F1';
        $this->isi .= "BT\n";
        if ($warna) {
            $this->isi .= sprintf("%.3f %.3f %.3f rg\n", $warna[0] / 255, $warna[1] / 255, $warna[2] / 255);
        }
        $this->isi .= sprintf("%s %.1f Tf\n1 0 0 1 %.2f %.2f Tm (%s) Tj\n",
            $font, $ukuran, $x, $y, $this->sandi($s));
        if ($warna) {
            $this->isi .= "0 0 0 rg\n";
        }
        $this->isi .= "ET\n";
    }

    private function kotak(float $x, float $y, float $l, float $t, array $warna): void
    {
        $this->isi .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n0 0 0 rg\n",
            $warna[0] / 255, $warna[1] / 255, $warna[2] / 255, $x, $y, $l, $t);
    }

    private function garis(float $x1, float $y1, float $x2, float $y2, array $warna, float $tebal = 0.5): void
    {
        $this->isi .= sprintf("%.3f %.3f %.3f RG\n%.2f w\n%.2f %.2f m %.2f %.2f l S\n0 0 0 RG\n",
            $warna[0] / 255, $warna[1] / 255, $warna[2] / 255, $tebal, $x1, $y1, $x2, $y2);
    }

    /* ------------------------------------------------------------------ */
    /* Susunan                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int,array{label:string,lebar:float,rata?:string}> $kolom
     *        lebar dalam bagian relatif; dinormalkan ke lebar halaman
     */
    public function siapkan(string $judul, array $meta, array $kolom): void
    {
        $this->judul = $judul;
        $this->meta  = $meta;

        $tersedia = $this->lebarHalaman - 2 * $this->tepi;
        $jumlah = 0.0;
        foreach ($kolom as $k) {
            $jumlah += $k['lebar'];
        }
        $this->kolom = [];
        foreach ($kolom as $k) {
            $this->kolom[] = [
                'label' => $k['label'],
                'lebar' => $tersedia * ($k['lebar'] / $jumlah),
                'rata'  => $k['rata'] ?? 'kiri',
                // Kolom yang boleh memakai beberapa baris. Sengaja opt-in:
                // kolom angka dan lencana justru lebih terbaca satu baris.
                'bungkus' => !empty($k['bungkus']),
            ];
        }
        $this->mulaiHalaman();
    }

    private function mulaiHalaman(): void
    {
        if ($this->isi !== '') {
            $this->halaman[] = $this->isi;
        }
        $this->isi = '';
        $this->nomorHalaman++;
        $this->y = $this->tinggiHalaman - $this->tepi;

        // Judul hanya di halaman pertama; halaman berikutnya langsung tabel.
        if ($this->nomorHalaman === 1) {
            $this->teks($this->tepi, $this->y - 12, $this->judul, 15, true);
            $this->y -= 26;

            $baris = [];
            foreach ($this->meta as $k => $v) {
                $baris[] = $k . ': ' . $v;
            }
            if ($baris) {
                $this->teks($this->tepi, $this->y - 8, implode('   |   ', $baris), 8.5, false, [92, 110, 118]);
                $this->y -= 18;
            }
            $this->y -= 4;
        }

        $this->kepalaTabel();
    }

    private function kepalaTabel(): void
    {
        $tinggi = 18.0;
        $this->kotak($this->tepi, $this->y - $tinggi, $this->lebarHalaman - 2 * $this->tepi, $tinggi, [238, 242, 244]);

        $x = $this->tepi;
        foreach ($this->kolom as $k) {
            $teks = $this->potong($k['label'], $k['lebar'] - 2 * self::PAD, 8, true);
            $tx = $k['rata'] === 'kanan'
                ? $x + $k['lebar'] - self::PAD - $this->lebarTeks($teks, 8, true)
                : $x + self::PAD;
            $this->teks($tx, $this->y - 12.5, $teks, 8, true, [40, 58, 66]);
            $x += $k['lebar'];
        }
        $this->garisKolom($this->y, $tinggi, [180, 196, 202]);
        $this->y -= $tinggi;
        $this->garis($this->tepi, $this->y, $this->lebarHalaman - $this->tepi, $this->y, [180, 196, 202], 0.8);
    }

    /**
     * Garis tegak pemisah kolom, setinggi satu baris.
     *
     * ADA KARENA LAPORANNYA DIUBAH KE EXCEL
     * Tabel ini dulu hanya bergaris mendatar. Pengubah PDF -> Excel yang tidak
     * menemukan garis tegak terpaksa menebak batas kolom dari celah kosong di
     * antara teks — dan tebakannya meleset: SKU "103286" dan kata pertama nama
     * barang "AYRES" berakhir di satu kolom yang sama. Dengan garisnya ada,
     * alat itu mengikuti grid yang tergambar, bukan menebak.
     *
     * Garis terluar kiri-kanan sengaja dilewati: bingkai penuh membuat tabelnya
     * terbaca berat, sedangkan yang dibutuhkan pengubahnya hanya batas antar
     * kolom.
     */
    private function garisKolom(float $yAtas, float $tinggi, array $warna): void
    {
        $x = $this->tepi;
        $akhir = count($this->kolom) - 1;
        foreach ($this->kolom as $i => $k) {
            $x += $k['lebar'];
            if ($i === $akhir) {
                break;
            }
            $this->garis($x, $yAtas, $x, $yAtas - $tinggi, $warna, 0.4);
        }
    }

    /**
     * Tambah satu baris data.
     *
     * @param array<int,string|array{0:string,1:array}> $sel
     *        tiap sel berupa teks, atau [teks, warnaRGB]
     */
    public function baris(array $sel): void
    {
        /* Isinya disiapkan lebih dulu, baru tingginya ditentukan: baris yang
         * memuat nama panjang perlu lebih tinggi, dan halaman harus diputus
         * berdasarkan tinggi yang sebenarnya — bukan 15 poin yang ditebak. */
        $siap     = [];
        $maksunit = 1;
        foreach ($this->kolom as $i => $k) {
            $isi = $sel[$i] ?? '';
            $warna = null;
            if (is_array($isi)) {
                $warna = $isi[1] ?? null;
                $isi = (string)($isi[0] ?? '');
            }
            $lebarIsi = $k['lebar'] - 2 * self::PAD;
            $potongan = $k['bungkus']
                ? $this->bungkus((string)$isi, $lebarIsi, 8.5)
                : [$this->potong((string)$isi, $lebarIsi, 8.5)];
            $siap[$i] = ['baris' => $potongan, 'warna' => $warna];
            if (count($potongan) > $maksunit) {
                $maksunit = count($potongan);
            }
        }

        $tinggi = 15.0 + ($maksunit - 1) * 10.0;
        if ($this->y - $tinggi < $this->tepi + 22) {
            $this->mulaiHalaman();
        }

        $x = $this->tepi;
        foreach ($this->kolom as $i => $k) {
            $yb = $this->y - 10.5;
            foreach ($siap[$i]['baris'] as $teks) {
                $tx = $k['rata'] === 'kanan'
                    ? $x + $k['lebar'] - self::PAD - $this->lebarTeks($teks, 8.5)
                    : $x + self::PAD;
                $this->teks($tx, $yb, $teks, 8.5, false, $siap[$i]['warna']);
                $yb -= 10.0;
            }
            $x += $k['lebar'];
        }

        $this->garisKolom($this->y, $tinggi, [232, 238, 240]);
        $this->y -= $tinggi;
        $this->garis($this->tepi, $this->y, $this->lebarHalaman - $this->tepi, $this->y, [225, 232, 234], 0.4);
    }

    /** Baris ringkasan di akhir tabel. */
    public function ringkasan(string $teks): void
    {
        if ($this->y - 24 < $this->tepi + 22) {
            $this->mulaiHalaman();
        }
        $this->y -= 6;
        $this->teks($this->tepi, $this->y - 10, $teks, 9, true);
        $this->y -= 18;
    }

    /* ------------------------------------------------------------------ */
    /* Keluaran                                                            */
    /* ------------------------------------------------------------------ */

    private function kakiHalaman(int $nomor, int $total): string
    {
        $kiri = APP_NAMA . ' — ' . $this->judul;
        $kanan = 'Halaman ' . $nomor . ' dari ' . $total;
        $s  = "BT\n/F1 7.5 Tf\n0.42 0.47 0.49 rg\n";
        $s .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $this->tepi, $this->tepi - 4, $this->sandi($kiri));
        $s .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n",
            $this->lebarHalaman - $this->tepi - $this->lebarTeks($kanan, 7.5), $this->tepi - 4, $this->sandi($kanan));
        $s .= "0 0 0 rg\nET\n";
        return $s;
    }

    /** Kirim PDF sebagai unduhan. */
    /**
     * Susun berkas PDF-nya dan kembalikan sebagai string.
     *
     * Dipisah dari kirim() supaya isinya bisa disimpan ke berkas — dipakai
     * tools/buat_pdf_accurate.php untuk membuat PDF contoh pengujian tanpa
     * melewati HTTP.
     */
    public function keluarkan(): string
    {
        if ($this->isi !== '') {
            $this->halaman[] = $this->isi;
            $this->isi = '';
        }
        if (!$this->halaman) {
            $this->halaman[] = '';
        }

        $jumlah = count($this->halaman);
        $obj = [];

        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $idHalaman = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $idHalaman[] = (3 + $i * 2) . ' 0 R';
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $idHalaman) . '] /Count ' . $jumlah . ' >>';

        $fontHal = 3 + $jumlah * 2;
        for ($i = 0; $i < $jumlah; $i++) {
            $nHal = 3 + $i * 2;
            $nIsi = $nHal + 1;
            $obj[$nHal] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] '
                . '/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $this->lebarHalaman, $this->tinggiHalaman, $fontHal, $fontHal + 1, $nIsi
            );
            $stream = $this->halaman[$i] . $this->kakiHalaman($i + 1, $jumlah);
            $obj[$nIsi] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $obj[$fontHal]     = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[$fontHal + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        ksort($obj);

        $pdf = "%PDF-1.4\n";
        $offset = [];
        foreach ($obj as $n => $o) {
            $offset[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$o\nendobj\n";
        }
        $startxref = strlen($pdf);
        $maks = max(array_keys($obj));
        $pdf .= "xref\n0 " . ($maks + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $maks; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offset[$n] ?? 0);
        }
        $pdf .= 'trailer' . "\n<< /Size " . ($maks + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n$startxref\n%%EOF";

        return $pdf;
    }

    /** Kirim PDF-nya sebagai unduhan lalu hentikan permintaan. */
    public function kirim(string $namaBerkas): void
    {
        $pdf = $this->keluarkan();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $namaBerkas . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        echo $pdf;
        exit;
    }
}
