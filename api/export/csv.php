<?php
/**
 * GET api/export/csv.php — ekspor data ke CSV (audit F3).
 *
 * Parameter: jenis = dashboard | masuk | keluar | master
 *            dari, sampai (khusus masuk/keluar)
 *
 * Ditulis dengan BOM UTF-8 dan pemisah titik koma supaya langsung rapi saat
 * dibuka Excel versi Indonesia.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

wajibMetode('GET');

// Unduhan lewat navigasi biasa, jadi sesi kosong dialihkan ke login
// alih-alih membalas JSON.
if (!sudahLogin()) {
    header('Location: ../../login.php');
    exit;
}

$jenis  = ambilStr($_GET, 'jenis', 20);
$dari   = trim((string)($_GET['dari'] ?? ''));
$sampai = trim((string)($_GET['sampai'] ?? ''));

$labelStatus = [
    'kritis'       => 'Perlu order',
    'rendah'       => 'Menipis',
    'aman'         => 'Aman',
    'belum_diatur' => 'Belum diatur',
];

switch ($jenis) {
    case 'dashboard':
        $judul = ['SKU', 'Barcode', 'Nama barang', 'Kategori', 'Stok awal', 'Masuk', 'Keluar', 'Stok akhir', 'Stok minimal', 'Status'];
        $data  = dbAll('
            SELECT m.sku, m.barcode, m.nama, m.kategori, m.stok_awal,
                   COALESCE(i.total,0) AS masuk, COALESCE(o.total,0) AS keluar,
                   ' . sqlStokAkhir() . ' AS akhir, m.stok_minimal,
                   ' . sqlStatusStok() . ' AS status
              FROM master_barang m ' . sqlJoinAgregat() . '
             WHERE m.deleted_at IS NULL AND m.aktif = 1
             ORDER BY m.nama');
        $baris = static function (array $r) use ($labelStatus): array {
            return [
                $r['sku'], $r['barcode'], $r['nama'], $r['kategori'],
                $r['stok_awal'], $r['masuk'], $r['keluar'], $r['akhir'], $r['stok_minimal'],
                $labelStatus[$r['status']] ?? $r['status'],
            ];
        };
        break;

    case 'master':
        $judul = ['SKU', 'Barcode', 'Nama barang', 'Stok awal', 'Stok minimal', 'Kategori', 'Barcode asli'];
        $data  = dbAll('SELECT sku, barcode, nama, stok_awal, stok_minimal, kategori, barcode_asli
                          FROM master_barang WHERE deleted_at IS NULL ORDER BY nama');
        $baris = static function (array $r): array {
            return [
                $r['sku'], $r['barcode'], $r['nama'], $r['stok_awal'],
                $r['stok_minimal'], $r['kategori'],
                ((int)$r['barcode_asli'] === 1 ? 'Ya' : 'Tidak - perlu dilengkapi'),
            ];
        };
        break;

    case 'masuk':
    case 'keluar':
        $tabel  = $jenis === 'masuk' ? 'barang_masuk' : 'barang_keluar';
        $isKel  = $jenis === 'keluar';
        $where  = ['t.deleted_at IS NULL'];
        $params = [];
        if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
            $where[] = 't.tanggal >= ?';
            $params[] = $dari;
        }
        if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
            $where[] = 't.tanggal <= ?';
            $params[] = $sampai;
        }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);

        $judul = ['Tanggal', 'Barcode', 'Nama barang', 'Jumlah', 'Keterangan'];
        if ($isKel) {
            $judul[] = 'No. Pesanan';
        }
        $judul[] = 'Dicatat oleh';

        $kolomExtra = $isKel ? ', t.no_pesanan' : '';
        $data = dbAll(
            "SELECT t.tanggal, t.barcode, t.nama, t.jumlah, t.keterangan,
                    u.nama_lengkap AS oleh $kolomExtra
               FROM $tabel t LEFT JOIN users u ON u.id = t.user_id
             $sqlWhere ORDER BY t.tanggal DESC, t.id DESC",
            $params
        );
        $baris = static function (array $r) use ($isKel): array {
            $out = [$r['tanggal'], $r['barcode'], $r['nama'], $r['jumlah'], $r['keterangan']];
            if ($isKel) {
                $out[] = $r['no_pesanan'];
            }
            $out[] = $r['oleh'] ?? '-';
            return $out;
        };
        break;

    default:
        http_response_code(400);
        exit('Jenis ekspor tidak dikenal.');
}

$namaFile = 'gudang-' . $jenis . '-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $namaFile . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");            // BOM: Excel butuh ini untuk UTF-8
fputcsv($out, $judul, ';');
foreach ($data as $r) {
    fputcsv($out, $baris($r), ';');
}
fclose($out);

catatAktivitas('export', $jenis, null, ['baris' => count($data)]);
exit;
