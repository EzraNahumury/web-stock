<?php
/**
 * GET api/export/csv.php — ekspor laporan sebagai berkas Excel (CSV).
 *
 * Parameter: jenis = opname (dengan id sesi), q, kategori
 *
 * ADA KARENA LAPORANNYA MEMANG DIBAWA KE EXCEL
 * Alur kerja gudang selama ini: unduh PDF, lalu ubah ke Excel. Pengubah PDF
 * selalu MENEBAK batas kolom dari posisi teks, dan tebakannya meleset — SKU
 * "103286" dan kata pertama nama barang "AYRES" berakhir di satu kolom yang
 * sama. Memperbaiki PDF-nya (kolom lebih lebar, garis pemisah kolom) membuat
 * tebakan itu jauh lebih mudah, tapi ia tetap tebakan.
 *
 * Berkas ini menghapus langkah menebaknya sama sekali: kolomnya sudah kolom
 * sejak awal, angkanya sudah angka, dan tidak ada yang perlu diurai ulang.
 *
 * SENGAJA CSV, BUKAN .XLSX
 * .xlsx adalah arsip ZIP berisi beberapa XML; membuatnya tanpa pustaka luar
 * berarti menulis penyusun ZIP sendiri — banyak kode untuk sesuatu yang bisa
 * salah diam-diam. CSV dibuka Excel apa adanya, dan dengan tiga hal di bawah
 * ini hasilnya tetap rapi:
 *
 *   1. BOM UTF-8      supaya huruf beraksen tidak jadi karakter aneh
 *   2. baris "sep=;"  arahan khusus Excel; dengan ini Excel memakai titik koma
 *                     APA PUN kesetelan wilayahnya, jadi tidak ada lagi
 *                     seluruh baris yang menumpuk di satu kolom
 *   3. angka polos    tanpa pemisah ribuan, supaya Excel membacanya sebagai
 *                     angka yang bisa dijumlahkan — bukan teks
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/opname.php';
require_once __DIR__ . '/../../includes/izin.php';

wajibMetode('GET');

// Unduhan lewat navigasi biasa, jadi sesi kosong dialihkan ke login alih-alih
// dibalas JSON — sama seperti ekspor PDF.
if (!sudahLogin()) {
    header('Location: ../../login.php');
    exit;
}

$jenis    = ambilStr($_GET, 'jenis', 20);
$q        = ambilStr($_GET, 'q', 100);
$kategori = ambilStr($_GET, 'kategori', 30);

/* Laporan hanya boleh diunduh dari menu yang memang bisa dibuka akun ini.
 * menuLaporan() mengembalikan null untuk jenis yang tidak terdaftar, dan jenis
 * yang tidak dikenal memang ditolak di bawah — jadi tidak ada jalur yang lolos
 * tanpa diperiksa. */
$menuLaporan = menuLaporan($jenis);
if ($menuLaporan === null) {
    http_response_code(404);
    exit('Jenis laporan tidak dikenal.');
}
if (!bolehMenu($menuLaporan)) {
    http_response_code(403);
    exit('Akun ini tidak punya akses ke laporan tersebut.');
}

/**
 * Satu sel CSV.
 *
 * Dikutip hanya bila perlu, supaya angka tetap terbaca Excel sebagai angka.
 * Tanda kutip di dalam isinya digandakan, sesuai aturan CSV.
 */
function selCsv(string $v): string
{
    if ($v === '') {
        return '';
    }
    if (strpbrk($v, ";\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

/** Satu baris CSV, dipisah titik koma. */
function barisCsv(array $sel): string
{
    $keluar = [];
    foreach ($sel as $v) {
        $keluar[] = selCsv((string)$v);
    }
    // CRLF: akhir baris yang dipahami Excel di Windows maupun di tempat lain.
    return implode(';', $keluar) . "\r\n";
}

switch ($jenis) {

    /* ------------------------------------------------------------------ */
    case 'opname':
        $sesiId = ambilInt($_GET, 'id', 0);
        $sesi   = $sesiId > 0
            ? dbOne('SELECT * FROM opname_sesi WHERE id = ? AND deleted_at IS NULL', [$sesiId])
            : null;
        if ($sesi === null) {
            http_response_code(404);
            exit('Sesi opname tidak ditemukan.');
        }

        // Query yang sama persis dengan ekspor PDF-nya. Lihat
        // dataLaporanOpname() di includes/opname.php.
        $data = dataLaporanOpname($sesiId, $q, $kategori);

        catatAktivitas('ekspor', 'laporan', null, array_filter([
            'jenis'    => 'opname (excel)',
            'sesi'     => $sesi['nama'],
            'kategori' => $kategori,
            'cari'     => $q,
        ], static function ($v) { return $v !== ''; }));

        $nama = 'laporan-opname-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nama . '"');
        header('Cache-Control: no-store');

        // 1. BOM, 2. arahan pemisah kolom untuk Excel.
        echo "\xEF\xBB\xBF";
        echo "sep=;\r\n";

        /* Keterangan laporannya ditaruh sebagai beberapa baris di atas, lalu
         * satu baris kosong. Excel membacanya apa adanya, dan orang yang
         * membuka berkasnya tahu laporan mana yang sedang dilihat tanpa harus
         * menebak dari nama berkas. */
        echo barisCsv(['Laporan', $sesi['nama']]);
        echo barisCsv(['Periode', $sesi['periode'] !== ''
            ? $sesi['periode']
            : date('d/m/Y', strtotime($sesi['tanggal']))]);
        echo barisCsv(['Kategori', $kategori !== '' && $kategori !== 'Semua'
            ? $kategori
            : ($sesi['kategori'] !== '' ? $sesi['kategori'] : 'Semua')]);
        echo barisCsv(['Dicetak', date('d/m/Y H:i')]);
        echo barisCsv(['Oleh', (string)(userSaatIni()['nama_lengkap'] ?? '-')]);
        echo barisCsv(['Baris', count($data)]);
        echo "\r\n";

        echo barisCsv([
            'SKU', 'Barcode', 'Nama barang', 'Kategori',
            'Stok akhir', 'Stok saat opname dibuat', 'Stok hitung', 'Stok accurate',
            'Selisih (hitung - stok akhir)', 'Selisih Accurate (hitung - accurate)',
            'Dicek', 'Petugas', 'Penyesuaian', 'Koreksi stok', 'Ket.',
        ]);

        $totalSelisih = 0;
        foreach ($data as $r) {
            $h = $r['stok_hitung']   === null ? null : (int)$r['stok_hitung'];
            $a = $r['stok_accurate'] === null ? null : (int)$r['stok_accurate'];
            /* Selisih = stok hitung - stok akhir YANG BERLAKU SEKARANG;
             * itulah yang dikoreksi. Salinan saat sesi dibuat ikut dikirim di
             * kolomnya sendiri sebagai jejak audit. */
            $stokKini = $r['master_id'] !== null
                ? stokAkhirItem((int)$r['master_id'])
                : (int)$r['stok_sistem'];
            $selisih    = $h !== null ? $h - $stokKini : null;
            $selisihAcc = ($h !== null && $a !== null) ? $h - $a : null;
            if ($selisih !== null) {
                $totalSelisih += $selisih;
            }

            /* Koreksi stok yang benar-benar ditulis baris ini, bertanda:
             * positif berarti stok ditambah, negatif berarti dikurangi.
             * Kolom ini tidak ada di PDF, dan justru inilah yang paling dicari
             * ketika laporannya dibuka lagi di Excel. */
            $koreksi = '';
            if ($r['adj_jenis'] !== null && $r['adj_qty'] !== null) {
                $koreksi = ($r['adj_jenis'] === 'keluar' ? -1 : 1) * (int)$r['adj_qty'];
            }

            echo barisCsv([
                $r['sku'],
                $r['barcode'],
                $r['nama'],
                $r['kategori'],
                $stokKini,
                (int)$r['stok_sistem'],
                $h === null ? '' : $h,
                $a === null ? '' : $a,
                $selisih === null ? '' : $selisih,
                $selisihAcc === null ? '' : $selisihAcc,
                (int)$r['dicek'] === 1 ? 'Ya' : 'Tidak',
                $r['petugas'],
                $r['penyesuaian'],
                $koreksi,
                $r['catatan'],
            ]);
        }

        echo "\r\n";
        echo barisCsv(['Total selisih', $totalSelisih]);
        exit;

    /* ------------------------------------------------------------------ */
    default:
        http_response_code(404);
        exit('Jenis laporan tidak dikenal.');
}
