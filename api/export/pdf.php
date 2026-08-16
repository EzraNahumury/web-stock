<?php
/**
 * GET api/export/pdf.php — ekspor laporan sebagai PDF.
 *
 * Parameter: jenis = dashboard | masuk | keluar | master
 *            filter mengikuti layar asalnya, jadi yang tercetak sama dengan
 *            yang sedang dilihat:
 *              dashboard : q, kategori, status
 *              masuk/keluar : q, dari, sampai
 *              master : q
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/pdf.php';

wajibMetode('GET');

// Unduhan lewat navigasi biasa, jadi sesi kosong dialihkan ke login
// alih-alih membalas JSON.
if (!sudahLogin()) {
    header('Location: ../../login.php');
    exit;
}

$jenis    = ambilStr($_GET, 'jenis', 20);
$q        = ambilStr($_GET, 'q', 100);
$kategori = ambilStr($_GET, 'kategori', 30);
$status   = ambilStr($_GET, 'status', 20);
$dari     = trim((string)($_GET['dari'] ?? ''));
$sampai   = trim((string)($_GET['sampai'] ?? ''));

$labelStatus = [
    'kritis'       => 'Perlu order',
    'rendah'       => 'Menipis',
    'aman'         => 'Aman',
    'belum_diatur' => 'Belum diatur',
];
$warnaStatus = [
    'kritis'       => [178, 58, 46],
    'rendah'       => [199, 127, 14],
    'aman'         => [14, 128, 96],
    'belum_diatur' => [139, 155, 163],
];

$waktu = date('d/m/Y H:i');
$oleh  = (userSaatIni()['nama_lengkap'] ?? '-');

switch ($jenis) {

    /* ------------------------------------------------------------------ */
    case 'dashboard':
        $akhir  = sqlStokAkhir();
        $ekspr  = sqlStatusStok($akhir);
        $where  = ['m.deleted_at IS NULL', 'm.aktif = 1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(m.nama LIKE ? OR m.sku LIKE ? OR m.barcode LIKE ?)';
            $pola = polaLike($q);
            array_push($params, $pola, $pola, $pola);
        }
        if ($kategori !== '' && $kategori !== 'Semua') {
            $where[] = 'm.kategori = ?';
            $params[] = $kategori;
        }

        $having = '';
        if (in_array($status, ['kritis', 'rendah', 'aman', 'belum_diatur'], true)) {
            $having = 'HAVING status = ?';
            $params[] = $status;
        }

        $data = dbAll("
            SELECT m.sku, m.barcode, m.nama, m.kategori, m.stok_awal, m.stok_minimal,
                   COALESCE(i.total,0) AS masuk, COALESCE(o.total,0) AS keluar,
                   $akhir AS akhir, $ekspr AS status
              FROM master_barang m " . sqlJoinAgregat() . '
             WHERE ' . implode(' AND ', $where) . "
             $having
             ORDER BY m.nama", $params);

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Laporan Stok Barang', [
            'Dicetak'  => $waktu,
            'Oleh'     => $oleh,
            'Kategori' => ($kategori !== '' && $kategori !== 'Semua') ? $kategori : 'Semua',
            'Status'   => $labelStatus[$status] ?? 'Semua',
            'Baris'    => count($data),
        ], [
            ['label' => 'SKU',          'lebar' => 9],
            ['label' => 'Barcode',      'lebar' => 11],
            ['label' => 'Nama barang',  'lebar' => 30],
            ['label' => 'Kategori',     'lebar' => 9],
            ['label' => 'Awal',         'lebar' => 6,   'rata' => 'kanan'],
            ['label' => 'Masuk',        'lebar' => 6,   'rata' => 'kanan'],
            ['label' => 'Keluar',       'lebar' => 6,   'rata' => 'kanan'],
            ['label' => 'Akhir',        'lebar' => 6,   'rata' => 'kanan'],
            ['label' => 'Min.',         'lebar' => 6,   'rata' => 'kanan'],
            ['label' => 'Status',       'lebar' => 11],
        ]);

        $totalAkhir = 0;
        foreach ($data as $r) {
            $totalAkhir += (int)$r['akhir'];
            $pdf->baris([
                $r['sku'], $r['barcode'], $r['nama'], $r['kategori'],
                number_format((int)$r['stok_awal'], 0, ',', '.'),
                number_format((int)$r['masuk'], 0, ',', '.'),
                number_format((int)$r['keluar'], 0, ',', '.'),
                number_format((int)$r['akhir'], 0, ',', '.'),
                number_format((int)$r['stok_minimal'], 0, ',', '.'),
                [$labelStatus[$r['status']] ?? $r['status'], $warnaStatus[$r['status']] ?? null],
            ]);
        }
        $pdf->ringkasan(count($data) . ' barang  ·  total stok akhir '
            . number_format($totalAkhir, 0, ',', '.') . ' unit');
        $pdf->kirim('laporan-stok-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'masuk':
    case 'keluar':
        $tabel = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';
        $isKel = $jenis === 'keluar';

        $where  = ['t.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $pola = polaLike($q);
            if ($isKel) {
                $where[] = '(t.nama LIKE ? OR t.barcode LIKE ? OR t.no_pesanan LIKE ?)';
                array_push($params, $pola, $pola, $pola);
            } else {
                $where[] = '(t.nama LIKE ? OR t.barcode LIKE ?)';
                array_push($params, $pola, $pola);
            }
        }
        if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
            $where[] = 't.tanggal >= ?';
            $params[] = $dari;
        }
        if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
            $where[] = 't.tanggal <= ?';
            $params[] = $sampai;
        }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $extra = $isKel ? ', t.no_pesanan' : '';

        $data = dbAll(
            "SELECT t.tanggal, t.barcode, t.nama, t.jumlah, t.keterangan,
                    u.nama_lengkap AS oleh $extra
               FROM $tabel t LEFT JOIN users u ON u.id = t.user_id
             $sqlWhere ORDER BY t.tanggal DESC, t.id DESC",
            $params
        );

        $kolom = [
            ['label' => 'Tanggal',     'lebar' => 9],
            ['label' => 'Barcode',     'lebar' => 12],
            ['label' => 'Nama barang', 'lebar' => 32],
            ['label' => $isKel ? 'Keluar' : 'Masuk', 'lebar' => 7, 'rata' => 'kanan'],
            ['label' => 'Keterangan',  'lebar' => 12],
        ];
        if ($isKel) {
            $kolom[] = ['label' => 'No. Pesanan', 'lebar' => 15];
        }
        $kolom[] = ['label' => 'Dicatat oleh', 'lebar' => 13];

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan($isKel ? 'Laporan Barang Keluar' : 'Laporan Barang Masuk', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Periode' => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Baris'   => count($data),
        ], $kolom);

        $totalJumlah = 0;
        foreach ($data as $r) {
            $totalJumlah += (int)$r['jumlah'];
            $sel = [
                date('d/m/Y', strtotime($r['tanggal'])),
                $r['barcode'],
                $r['nama'],
                number_format((int)$r['jumlah'], 0, ',', '.'),
                $r['keterangan'],
            ];
            if ($isKel) {
                $sel[] = (string)($r['no_pesanan'] ?? '');
            }
            $sel[] = (string)($r['oleh'] ?? '-');
            $pdf->baris($sel);
        }
        $pdf->ringkasan(count($data) . ' catatan  ·  total '
            . ($isKel ? 'keluar ' : 'masuk ')
            . number_format($totalJumlah, 0, ',', '.') . ' pcs');
        $pdf->kirim('laporan-barang-' . $jenis . '-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'riwayat':
        $arahMinta = ambilStr($_GET, 'arah', 10);
        if (!in_array($arahMinta, ['masuk', 'keluar'], true)) {
            $arahMinta = 'semua';
        }

        $bagian = [];
        $params = [];
        foreach (['masuk', 'keluar'] as $arah) {
            if ($arahMinta !== 'semua' && $arahMinta !== $arah) {
                continue;
            }
            $tabel = $arah === 'masuk' ? 'barang_masuk' : 'barang_keluar';
            $isKel = $arah === 'keluar';

            $where = ['t.deleted_at IS NULL'];
            if ($q !== '') {
                $pola = polaLike($q);
                if ($isKel) {
                    $where[] = '(t.nama LIKE ? OR t.barcode LIKE ? OR t.no_pesanan LIKE ?)';
                    array_push($params, $pola, $pola, $pola);
                } else {
                    $where[] = '(t.nama LIKE ? OR t.barcode LIKE ?)';
                    array_push($params, $pola, $pola);
                }
            }
            if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
                $where[] = 't.tanggal >= ?';
                $params[] = $dari;
            }
            if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
                $where[] = 't.tanggal <= ?';
                $params[] = $sampai;
            }
            $noPesanan = $isKel ? 't.no_pesanan' : "''";
            $bagian[] = "(SELECT '$arah' AS arah, t.id, t.tanggal, t.barcode, t.nama,
                                 t.jumlah, t.keterangan, t.created_at, $noPesanan AS no_pesanan,
                                 t.master_id, t.user_id
                            FROM $tabel t WHERE " . implode(' AND ', $where) . ')';
        }
        $gabung = implode(' UNION ALL ', $bagian);

        $data = dbAll("SELECT g.*, u.nama_lengkap AS oleh
                         FROM ($gabung) g LEFT JOIN users u ON u.id = g.user_id
                        ORDER BY g.tanggal DESC, g.created_at DESC, g.id DESC", $params);

        // Saldo stok pada akhir tanggal tiap catatan.
        $saldo = saldoHarian(array_column($data, 'master_id'));

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Riwayat Keluar Masuk Barang', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Periode' => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Arah'    => $arahMinta === 'semua' ? 'Masuk & keluar' : ucfirst($arahMinta),
            'Baris'   => count($data),
        ], [
            ['label' => 'Tanggal',      'lebar' => 8],
            ['label' => 'Barcode',      'lebar' => 11],
            ['label' => 'Nama barang',  'lebar' => 26],
            ['label' => 'Masuk',        'lebar' => 6,  'rata' => 'kanan'],
            ['label' => 'Keluar',       'lebar' => 6,  'rata' => 'kanan'],
            ['label' => 'Stok akhir',   'lebar' => 7,  'rata' => 'kanan'],
            ['label' => 'Keterangan',   'lebar' => 10],
            ['label' => 'No. Pesanan',  'lebar' => 13],
            ['label' => 'Dicatat oleh', 'lebar' => 11],
        ]);

        $tMasuk = 0;
        $tKeluar = 0;
        foreach ($data as $r) {
            $masuk = $r['arah'] === 'masuk';
            if ($masuk) {
                $tMasuk += (int)$r['jumlah'];
            } else {
                $tKeluar += (int)$r['jumlah'];
            }
            $mid = $r['master_id'] === null ? null : (int)$r['master_id'];
            $akhir = ($mid !== null && isset($saldo[$mid][$r['tanggal']]))
                ? number_format($saldo[$mid][$r['tanggal']], 0, ',', '.')
                : '-';

            $pdf->baris([
                date('d/m/Y', strtotime($r['tanggal'])),
                $r['barcode'],
                $r['nama'],
                $masuk ? ['+' . number_format((int)$r['jumlah'], 0, ',', '.'), [14, 128, 96]] : '',
                $masuk ? '' : ['-' . number_format((int)$r['jumlah'], 0, ',', '.'), [178, 58, 46]],
                $akhir,
                $r['keterangan'],
                (string)($r['no_pesanan'] ?? ''),
                (string)($r['oleh'] ?? '-'),
            ]);
        }
        $pdf->ringkasan(count($data) . ' catatan  ·  masuk '
            . number_format($tMasuk, 0, ',', '.') . ' pcs  ·  keluar '
            . number_format($tKeluar, 0, ',', '.') . ' pcs  ·  selisih '
            . number_format($tMasuk - $tKeluar, 0, ',', '.') . ' pcs');
        $pdf->kirim('riwayat-barang-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'master':
        $where  = ['deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(nama LIKE ? OR sku LIKE ? OR barcode LIKE ?)';
            $pola = polaLike($q);
            array_push($params, $pola, $pola, $pola);
        }
        $data = dbAll('SELECT sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli
                         FROM master_barang WHERE ' . implode(' AND ', $where) . ' ORDER BY nama', $params);

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Master Barang', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Baris'   => count($data),
        ], [
            ['label' => 'SKU',          'lebar' => 10],
            ['label' => 'Barcode',      'lebar' => 13],
            ['label' => 'Nama barang',  'lebar' => 38],
            ['label' => 'Stok awal',    'lebar' => 8,  'rata' => 'kanan'],
            ['label' => 'Stok minimal', 'lebar' => 9,  'rata' => 'kanan'],
            ['label' => 'Kategori',     'lebar' => 11],
            ['label' => 'Barcode asli', 'lebar' => 11],
        ]);
        foreach ($data as $r) {
            $pdf->baris([
                $r['sku'], $r['barcode'], $r['nama'],
                number_format((int)$r['stok_awal'], 0, ',', '.'),
                number_format((int)$r['stok_minimal'], 0, ',', '.'),
                $r['kategori'],
                (int)$r['barcode_asli'] === 1 ? 'Ya' : 'Perlu dilengkapi',
            ]);
        }
        $pdf->ringkasan(count($data) . ' barang dalam katalog');
        $pdf->kirim('master-barang-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        exit('Jenis laporan tidak dikenal.');
}
