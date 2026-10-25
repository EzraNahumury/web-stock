<?php
/**
 * GET api/export/pdf.php — ekspor laporan sebagai PDF.
 *
 * Parameter: jenis = dashboard | masuk | keluar | riwayat | pertukaran |
 *                    master | aktivitas | retur | deadstok | opname
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
require_once __DIR__ . '/../../includes/aktivitas.php';
require_once __DIR__ . '/../../includes/riwayat.php';
require_once __DIR__ . '/../../includes/izin.php';

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

// Laporan hanya boleh diunduh dari menu yang memang bisa dibuka akun ini.
// Diperiksa di sini, bukan lewat wajibLoginApi(): unduhan ini adalah
// navigasi biasa, jadi penolakannya harus berupa halaman, bukan JSON.
//
// Berlaku sebelum apa pun dicatat, supaya permintaan yang ditolak tidak
// meninggalkan jejak unduhan yang tidak pernah terjadi.
$menuLaporan = menuLaporan($jenis);
if ($menuLaporan !== null && !bolehMenu($menuLaporan)) {
    http_response_code(403);
    exit('Akun ini tidak punya akses ke laporan tersebut.');
}

$waktu = date('d/m/Y H:i');
$oleh  = (userSaatIni()['nama_lengkap'] ?? '-');

// Pencetakan laporan ikut masuk jejak aktivitas. Di gudang, siapa mengunduh
// data apa dan kapan sama pentingnya dengan siapa mengubahnya.
if (in_array($jenis, ['dashboard', 'masuk', 'keluar', 'riwayat', 'pertukaran',
                     'master', 'aktivitas', 'retur', 'deadstok', 'opname'], true)) {
    catatAktivitas('ekspor', 'laporan', null, array_filter([
        'jenis'  => $jenis,
        'dari'   => $dari,
        'sampai' => $sampai,
        'cari'   => $q,
    ], static function ($v) { return $v !== ''; }));
}

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

        // Penyaring status ditulis sebagai syarat angka, bukan perbandingan
        // teks — lihat sqlKondisiStatus() di includes/helpers.php. Yang lama
        // membandingkan hasil CASE dengan parameter dan gagal di server
        // dengan "1267 Illegal mix of collations".
        if (in_array($status, statusStokSah(), true)) {
            $where[] = '(' . sqlKondisiStatus($status, $akhir) . ')';
        }

        $data = dbAll("
            SELECT m.sku, m.barcode, m.nama, m.kategori, m.stok_awal, m.stok_minimal,
                   COALESCE(i.total,0) AS masuk, COALESCE(o.total,0) AS keluar,
                   $akhir AS akhir, $ekspr AS status
              FROM master_barang m " . sqlJoinAgregat() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY m.nama', $params);

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
            ['label' => 'Nama barang',  'lebar' => 30, 'bungkus' => true],
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
        // Penyaring keterangan — sama dengan di layar, supaya laporan yang
        // diunduh memuat persis yang sedang dilihat.
        $ketSaring = ambilStr($_GET, 'keterangan', 50);
        if ($ketSaring !== '') {
            $where[] = 't.keterangan = ?';
            $params[] = $ketSaring;
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
            ['label' => 'Nama barang', 'lebar' => 32, 'bungkus' => true],
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
            'Keterangan' => $ketSaring !== '' ? $ketSaring : 'Semua',
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
        $f    = filterRiwayat($_GET);
        $data = barisRiwayat($f);
        $tot  = totalRiwayat($f);

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Riwayat Keluar Masuk Barang', [
            'Dicetak'  => $waktu,
            'Oleh'     => $oleh,
            'Periode'  => ($f['dari'] !== '' || $f['sampai'] !== '')
                ? (($f['dari'] !== '' ? $f['dari'] : 'awal') . ' s/d ' . ($f['sampai'] !== '' ? $f['sampai'] : 'kini'))
                : 'Sejak awal',
            'Kategori' => $f['kategori'] !== '' && $f['kategori'] !== 'Semua' ? $f['kategori'] : 'Semua',
            'Barang'   => count($data),
        ], [
            ['label' => 'SKU',         'lebar' => 9],
            ['label' => 'Barcode',     'lebar' => 12],
            ['label' => 'Nama barang', 'lebar' => 32, 'bungkus' => true],
            ['label' => 'Kategori',    'lebar' => 9],
            ['label' => 'Stok awal',   'lebar' => 8, 'rata' => 'kanan'],
            ['label' => 'Stok masuk',  'lebar' => 8, 'rata' => 'kanan'],
            ['label' => 'Stok keluar', 'lebar' => 8, 'rata' => 'kanan'],
            ['label' => 'Stok akhir',  'lebar' => 8, 'rata' => 'kanan'],
        ]);

        foreach ($data as $r) {
            $pdf->baris([
                $r['sku'],
                $r['barcode'],
                $r['nama'],
                $r['kategori'],
                number_format($r['stok_awal'], 0, ',', '.'),
                $r['masuk']  > 0 ? ['+' . number_format($r['masuk'], 0, ',', '.'), [14, 128, 96]] : '-',
                $r['keluar'] > 0 ? ['-' . number_format($r['keluar'], 0, ',', '.'), [178, 58, 46]] : '-',
                number_format($r['stok_akhir'], 0, ',', '.'),
            ]);
        }
        $pdf->ringkasan(count($data) . ' barang  ·  awal '
            . number_format($tot['awal'], 0, ',', '.') . '  ·  masuk '
            . number_format($tot['masuk'], 0, ',', '.') . '  ·  keluar '
            . number_format($tot['keluar'], 0, ',', '.') . '  ·  akhir '
            . number_format($tot['akhir'], 0, ',', '.') . ' pcs');
        $pdf->kirim('riwayat-barang-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'pertukaran':
        // Sama dengan layarnya: yang barang keluarnya sudah dihapus tidak ikut.
        $where  = ['t.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(t.barcode_lama LIKE ? OR t.nama_lama LIKE ? OR t.barcode_baru LIKE ?
                         OR t.nama_baru LIKE ? OR t.no_pesanan LIKE ?)';
            $pola = polaLike($q);
            for ($i = 0; $i < 5; $i++) {
                $params[] = $pola;
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
        $data = dbAll('SELECT t.*, u.nama_lengkap AS oleh
                         FROM pertukaran_barang t LEFT JOIN users u ON u.id = t.user_id
                        WHERE ' . implode(' AND ', $where) . '
                        ORDER BY t.tanggal DESC, t.id DESC', $params);

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Riwayat Pertukaran Barang', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Periode' => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Baris'   => count($data),
        ], [
            ['label' => 'Tanggal',        'lebar' => 8],
            ['label' => 'Barcode lama',   'lebar' => 10],
            ['label' => 'Produk lama',    'lebar' => 19],
            ['label' => 'Barcode baru',   'lebar' => 10],
            ['label' => 'Produk baru',    'lebar' => 19],
            ['label' => 'Qty',            'lebar' => 5, 'rata' => 'kanan'],
            ['label' => 'Alasan',         'lebar' => 7],
            ['label' => 'No. Pesanan',    'lebar' => 13],
            ['label' => 'Oleh',           'lebar' => 9],
        ]);

        $totalUnit = 0;
        foreach ($data as $r) {
            $totalUnit += (int)$r['jumlah'];
            $pdf->baris([
                date('d/m/Y', strtotime($r['tanggal'])),
                [$r['barcode_lama'], [178, 58, 46]],
                $r['nama_lama'],
                [$r['barcode_baru'], [14, 128, 96]],
                $r['nama_baru'],
                number_format((int)$r['jumlah'], 0, ',', '.'),
                $r['alasan'],
                (string)($r['no_pesanan'] ?? ''),
                (string)($r['oleh'] ?? '-'),
            ]);
        }
        $pdf->ringkasan(count($data) . ' pertukaran  ·  total '
            . number_format($totalUnit, 0, ',', '.') . ' pcs berpindah produk');
        $pdf->kirim('pertukaran-barang-' . date('Ymd-His') . '.pdf');
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
            ['label' => 'Nama barang',  'lebar' => 38, 'bungkus' => true],
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
    case 'aktivitas':
        $aksi    = ambilStr($_GET, 'aksi', 50);
        $entitas = ambilStr($_GET, 'entitas', 50);
        $userId  = ambilInt($_GET, 'user', 0);

        $where  = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(a.detail LIKE ? OR u.nama_lengkap LIKE ? OR u.username LIKE ?
                         OR a.aksi LIKE ? OR a.entitas LIKE ? OR a.ip LIKE ?)';
            $pola = polaLike($q);
            for ($i = 0; $i < 6; $i++) {
                $params[] = $pola;
            }
        }
        if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $dari . ' 00:00:00';
        }
        if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
            $where[] = 'a.created_at <= ?';
            $params[] = $sampai . ' 23:59:59';
        }
        if ($aksi !== '') {
            $where[] = 'a.aksi = ?';
            $params[] = $aksi;
        }
        if ($entitas !== '') {
            $where[] = 'a.entitas = ?';
            $params[] = $entitas;
        }
        if ($userId > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $userId;
        }

        $data = dbAll(
            'SELECT a.aksi, a.entitas, a.detail, a.ip, a.created_at,
                    u.nama_lengkap AS oleh, u.username
               FROM activity_log a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.created_at DESC, a.id DESC',
            $params
        );

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Log Aktivitas', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Periode' => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Aksi'    => $aksi !== '' ? labelAksi($aksi) : 'Semua',
            'Modul'   => $entitas !== '' ? modulAktivitas($entitas) : 'Semua',
            'Baris'   => count($data),
        ], [
            ['label' => 'Tanggal',   'lebar' => 8],
            ['label' => 'Jam',       'lebar' => 6],
            ['label' => 'Aktivitas', 'lebar' => 17],
            ['label' => 'Rincian',   'lebar' => 32],
            ['label' => 'Modul',     'lebar' => 10],
            ['label' => 'Oleh',      'lebar' => 13],
            ['label' => 'IP',        'lebar' => 9],
        ]);

        foreach ($data as $r) {
            $label = labelAktivitas($r);
            $t = strtotime($r['created_at']);
            $pdf->baris([
                date('d/m/Y', $t),
                date('H:i:s', $t),
                [$label['judul'], $label['nada'] === 'bahaya' ? [178, 58, 46] : null],
                $label['rincian'],
                $label['modul'],
                (string)($r['oleh'] ?? ($r['username'] ?? '-')),
                (string)$r['ip'],
            ]);
        }
        $pdf->ringkasan(count($data) . ' aktivitas tercatat');
        $pdf->kirim('log-aktivitas-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'retur':
        $status = ambilStr($_GET, 'status', 30);

        $where  = ['r.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(r.nama LIKE ? OR r.barcode LIKE ? OR r.sku LIKE ?
                         OR r.no_pesanan LIKE ? OR r.keterangan LIKE ?)';
            $pola = polaLike($q);
            for ($i = 0; $i < 5; $i++) {
                $params[] = $pola;
            }
        }
        if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
            $where[] = 'r.tanggal >= ?';
            $params[] = $dari;
        }
        if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
            $where[] = 'r.tanggal <= ?';
            $params[] = $sampai;
        }
        if ($status !== '' && in_array($status, daftarKeterangan('retur'), true)) {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        // Penanda pembukuan Accurate — penyaringnya sama dengan di layar,
        // supaya laporan yang diunduh memuat persis yang sedang dilihat.
        // 0 belum, 1 sudah, 2 sengaja tidak dimasukkan.
        $accurate = ambilStr($_GET, 'accurate', 10);
        if ($accurate === 'sudah') {
            $where[] = 'r.accurate = 1';
        } elseif ($accurate === 'belum') {
            $where[] = 'r.accurate = 0';
        } elseif ($accurate === 'tidak') {
            $where[] = 'r.accurate = 2';
        } else {
            $accurate = '';
        }

        $data = dbAll(
            'SELECT r.*, u.nama_lengkap AS oleh
               FROM retur r LEFT JOIN users u ON u.id = r.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY r.tanggal DESC, r.id DESC',
            $params
        );

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Laporan Retur Barang', [
            'Dicetak' => $waktu,
            'Oleh'    => $oleh,
            'Periode' => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Status'  => $status !== '' ? $status : 'Semua',
            'Accurate' => $accurate === 'tidak' ? 'tidak masuk'
                : ($accurate !== '' ? $accurate : 'Semua'),
            'Baris'   => count($data),
        ], [
            ['label' => 'Tanggal',          'lebar' => 9],
            ['label' => 'No. Pesanan',      'lebar' => 16],
            ['label' => 'SKU',              'lebar' => 9],
            ['label' => 'Nama produk',      'lebar' => 25, 'bungkus' => true],
            ['label' => 'Qty',              'lebar' => 5, 'rata' => 'kanan'],
            ['label' => 'Keterangan retur', 'lebar' => 14],
            ['label' => 'Ket.',             'lebar' => 10],
            // 11, bukan 8: "Tidak masuk" terpotong jadi "Tidak m…" di lebar 8.
            ['label' => 'Accurate',         'lebar' => 11],
            ['label' => 'Dicatat oleh',     'lebar' => 10],
        ]);

        /* Tiga keadaan penanda Accurate, dengan warnanya masing-masing.
         * "Tidak masuk" sengaja abu-abu tua dan bukan merah: itu keputusan
         * yang sah, bukan kesalahan yang perlu diperbaiki. */
        $tandaAccuratePdf = static function (int $nilai): array {
            if ($nilai === 1) {
                return ['Sudah', [14, 128, 96]];
            }
            if ($nilai === 2) {
                return ['Tidak masuk', [92, 110, 118]];
            }
            return ['Belum', [139, 155, 163]];
        };

        $totalQty = 0;
        $qtyStok  = 0;
        foreach ($data as $r) {
            $totalQty += (int)$r['jumlah'];
            $masukStok = returMenambahStok((string)$r['status']);
            if ($masukStok) {
                $qtyStok += (int)$r['jumlah'];
            }
            $pdf->baris([
                date('d/m/Y', strtotime($r['tanggal'])),
                $r['no_pesanan'],
                $r['sku'],
                $r['nama'],
                number_format((int)$r['jumlah'], 0, ',', '.'),
                [$r['status'], $masukStok ? [14, 128, 96] : [178, 58, 46]],
                $r['keterangan'],
                $tandaAccuratePdf((int)$r['accurate']),
                (string)($r['oleh'] ?? '-'),
            ]);
        }
        /* "Tidak masuk" dihitung sendiri, tidak digabung ke "belum". Laporan
         * yang menjumlahkan keduanya mengembalikan persis masalah yang membuat
         * keadaan ketiga ini dibuat: sisa pekerjaan yang tidak pernah nol. */
        $belumAccurate = 0;
        $tidakAccurate = 0;
        foreach ($data as $r) {
            if ((int)$r['accurate'] === 0) {
                $belumAccurate++;
            } elseif ((int)$r['accurate'] === 2) {
                $tidakAccurate++;
            }
        }
        $pdf->ringkasan(count($data) . ' retur  ·  total '
            . number_format($totalQty, 0, ',', '.') . ' pcs  ·  sudah masuk stok '
            . number_format($qtyStok, 0, ',', '.') . ' pcs  ·  belum masuk stok '
            . number_format($totalQty - $qtyStok, 0, ',', '.') . ' pcs  ·  belum ke Accurate '
            . number_format($belumAccurate, 0, ',', '.') . ' baris  ·  tidak masuk Accurate '
            . number_format($tidakAccurate, 0, ',', '.') . ' baris');
        $pdf->kirim('laporan-retur-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    case 'deadstok':
        require_once __DIR__ . '/../../includes/deadstok.php';

        $where  = ['d.deleted_at IS NULL'];
        $params = [];

        if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
            $where[] = 'd.tanggal >= ?';
            $params[] = $dari;
        }
        if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
            $where[] = 'd.tanggal <= ?';
            $params[] = $sampai;
        }
        if ($q !== '') {
            $where[] = '(d.nama LIKE ? OR d.barcode LIKE ? OR d.sku LIKE ? OR d.keterangan LIKE ?)';
            $pola = polaLike($q);
            for ($i = 0; $i < 4; $i++) {
                $params[] = $pola;
            }
        }
        /* Kategori dan keadaan dibandingkan dengan daftar tertutup lebih dulu.
         * Kolomnya ENUM: nilai asing memicu galat, bukan hasil kosong. */
        $kat = ambilStr($_GET, 'kategori', 20);
        if (isset(KATEGORI_DEADSTOK[$kat])) {
            $where[] = 'd.kategori = ?';
            $params[] = $kat;
        } else {
            $kat = '';
        }
        $keadaan = ambilStr($_GET, 'status', 20);
        if (isset(STATUS_DEADSTOK[$keadaan])) {
            $where[] = 'd.status = ?';
            $params[] = $keadaan;
        } else {
            $keadaan = '';
        }

        $data = dbAll(
            'SELECT d.*, u.nama_lengkap AS oleh
               FROM deadstok d LEFT JOIN users u ON u.id = d.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.tanggal DESC, d.id DESC',
            $params
        );

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan('Laporan Barang Deadstok', [
            'Dicetak'  => $waktu,
            'Oleh'     => $oleh,
            'Periode'  => ($dari !== '' || $sampai !== '')
                ? (($dari !== '' ? $dari : 'awal') . ' s/d ' . ($sampai !== '' ? $sampai : 'kini'))
                : 'Semua',
            'Kategori' => $kat !== '' ? KATEGORI_DEADSTOK[$kat] : 'Semua',
            'Keadaan'  => $keadaan !== '' ? STATUS_DEADSTOK[$keadaan] : 'Semua',
            'Baris'    => count($data),
        ], [
            ['label' => 'Tanggal',     'lebar' => 9],
            ['label' => 'SKU',         'lebar' => 9],
            ['label' => 'Nama produk', 'lebar' => 27, 'bungkus' => true],
            ['label' => 'Kategori',    'lebar' => 15],
            ['label' => 'Qty',         'lebar' => 5, 'rata' => 'kanan'],
            ['label' => 'Keadaan',     'lebar' => 12],
            ['label' => 'Ket.',        'lebar' => 13],
            ['label' => 'Dicatat oleh', 'lebar' => 10],
        ]);

        $unitTertahan = 0;
        $unitBeres    = 0;
        $unitDibuang  = 0;
        foreach ($data as $r) {
            $jml = (int)$r['jumlah'];
            $st  = (string)$r['status'];
            if ($st === 'tertahan') {
                $unitTertahan += $jml;
            } elseif ($st === 'beres') {
                $unitBeres += $jml;
            } else {
                $unitDibuang += $jml;
            }

            /* Warnanya mengikuti arti keadaannya, bukan sekadar berbeda-beda:
             * yang masih tertahan itu yang perlu dikerjakan (amber), yang sudah
             * siap jual sudah beres (hijau), dan yang dibuang memang sudah
             * selesai urusannya (abu-abu tua). */
            $warnaKeadaan = ['tertahan' => [199, 127, 14],
                             'beres'    => [14, 128, 96],
                             'dibuang'  => [92, 110, 118]];

            $pdf->baris([
                date('d/m/Y', strtotime($r['tanggal'])),
                $r['sku'],
                $r['nama'],
                [KATEGORI_DEADSTOK[$r['kategori']] ?? $r['kategori'],
                 $r['kategori'] === 'rusak' ? [178, 58, 46] : [92, 110, 118]],
                number_format($jml, 0, ',', '.'),
                [STATUS_DEADSTOK[$st] ?? $st, $warnaKeadaan[$st] ?? [139, 155, 163]],
                $r['keterangan'],
                (string)($r['oleh'] ?? '-'),
            ]);
        }

        /* Hanya "tertahan" yang benar-benar menahan barang dari penjualan.
         * Menjumlahkan ketiganya membuat angkanya naik selamanya dan berhenti
         * berarti apa pun. */
        $pdf->ringkasan(count($data) . ' catatan  ·  masih tertahan '
            . number_format($unitTertahan, 0, ',', '.') . ' pcs  ·  sudah siap jual '
            . number_format($unitBeres, 0, ',', '.') . ' pcs  ·  dibuang '
            . number_format($unitDibuang, 0, ',', '.') . ' pcs'
            . '  ·  hanya yang dibuang yang mengurangi stok');
        $pdf->kirim('laporan-deadstok-' . date('Ymd-His') . '.pdf');
        break;

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

        $where  = ['i.sesi_id = ?'];
        $params = [$sesiId];
        if ($q !== '') {
            $where[] = '(i.nama LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)';
            $pola = polaLike($q);
            array_push($params, $pola, $pola, $pola);
        }
        if ($kategori !== '' && $kategori !== 'Semua') {
            $where[] = 'i.kategori = ?';
            $params[] = $kategori;
        }

        $data = dbAll(
            'SELECT * FROM opname_item i WHERE ' . implode(' AND ', $where)
            . ' ORDER BY i.kategori, i.nama, i.id',
            $params
        );

        $pdf = new PdfTabel('lanskap');
        $pdf->siapkan($sesi['nama'], [
            'Dicetak'  => $waktu,
            'Oleh'     => $oleh,
            'Periode'  => $sesi['periode'] !== '' ? $sesi['periode'] : date('d/m/Y', strtotime($sesi['tanggal'])),
            'Kategori' => $kategori !== '' && $kategori !== 'Semua'
                ? $kategori
                : ($sesi['kategori'] !== '' ? $sesi['kategori'] : 'Semua'),
            'Baris'    => count($data),
        ], [
            ['label' => 'SKU',            'lebar' => 8],
            // Dilebarkan DAN dibungkus: 63% nama barang di katalog ini lebih
            // dari 28 karakter, jadi melebarkan saja tidak pernah cukup.
            ['label' => 'Nama barang',    'lebar' => 27, 'bungkus' => true],
            ['label' => 'Stok akhir',     'lebar' => 7,  'rata' => 'kanan'],
            ['label' => 'Stok hitung',    'lebar' => 7,  'rata' => 'kanan'],
            ['label' => 'Stok accurate',  'lebar' => 7,  'rata' => 'kanan'],
            ['label' => 'Dicek',          'lebar' => 5],
            ['label' => 'Petugas',        'lebar' => 9],
            ['label' => 'Kategori',       'lebar' => 7],
            ['label' => 'Selisih barang', 'lebar' => 7,  'rata' => 'kanan'],
            ['label' => 'Penyesuaian',    'lebar' => 11],
        ]);

        $totalSelisih   = 0;
        $adaSelisih     = 0;
        $adaPenyesuaian = 0;
        foreach ($data as $r) {
            $h = $r['stok_hitung']   === null ? null : (int)$r['stok_hitung'];
            $a = $r['stok_accurate'] === null ? null : (int)$r['stok_accurate'];
            $selisih = ($h !== null && $a !== null) ? $h - $a : null;
            if ($selisih !== null) {
                $totalSelisih += $selisih;
                if ($selisih !== 0) {
                    $adaSelisih++;
                }
            }
            $disesuaikan = $r['penyesuaian'] === PENYESUAIAN_DISESUAIKAN;
            if ($disesuaikan) {
                $adaPenyesuaian++;
            }
            $pdf->baris([
                $r['sku'],
                $r['nama'],
                number_format((int)$r['stok_sistem'], 0, ',', '.'),
                $h === null ? '-' : number_format($h, 0, ',', '.'),
                $a === null ? '-' : number_format($a, 0, ',', '.'),
                (int)$r['dicek'] === 1 ? ['Ya', [14, 128, 96]] : '',
                $r['petugas'],
                $r['kategori'],
                $selisih === null
                    ? '-'
                    : [($selisih > 0 ? '+' : '') . number_format($selisih, 0, ',', '.'),
                       $selisih === 0 ? null : ($selisih > 0 ? [199, 127, 14] : [178, 58, 46])],
                [$r['penyesuaian'], $disesuaikan ? [199, 127, 14] : [139, 155, 163]],
            ]);
        }
        $pdf->ringkasan(count($data) . ' barang  ·  ' . $adaSelisih
            . ' berselisih  ·  ' . $adaPenyesuaian . ' disesuaikan  ·  jumlah selisih '
            . ($totalSelisih > 0 ? '+' : '') . number_format($totalSelisih, 0, ',', '.') . ' pcs');
        $pdf->kirim('stok-opname-' . date('Ymd-His') . '.pdf');
        break;

    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        exit('Jenis laporan tidak dikenal.');
}
