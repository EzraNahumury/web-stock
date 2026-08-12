<?php
/**
 * includes/transaksi.php — logika bersama barang masuk & barang keluar.
 *
 * Keduanya hampir identik; bedanya hanya kolom no_pesanan/batch_id milik
 * barang keluar dan arah pengaruhnya terhadap stok. Menaruhnya di satu
 * tempat mencegah aturan validasi menyimpang antar keduanya.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/response.php';

/** @return array{tabel:string,ket:array,keluar:bool} */
function konfigTransaksi(string $jenis): array
{
    if ($jenis === 'masuk') {
        return ['tabel' => 'barang_masuk', 'ket' => KET_MASUK, 'keluar' => false];
    }
    if ($jenis === 'keluar') {
        return ['tabel' => 'barang_keluar', 'ket' => KET_KELUAR, 'keluar' => true];
    }
    jsonError('Jenis transaksi tidak dikenal.', 400);
}

/**
 * Daftar transaksi berpaginasi.
 *
 * Memperbaiki audit F1: prototipe memotong di 200 baris tanpa paginasi dan
 * tanpa keterangan, sehingga riwayat lama tampak hilang.
 */
function daftarTransaksi(string $jenis): void
{
    $cfg   = konfigTransaksi($jenis);
    $tabel = $cfg['tabel'];

    $q      = ambilStr($_GET, 'q', 100);
    $dari   = trim((string)($_GET['dari'] ?? ''));
    $sampai = trim((string)($_GET['sampai'] ?? ''));
    $page   = ambilHalaman();

    $where  = ['t.deleted_at IS NULL'];
    $params = [];

    if ($q !== '') {
        $pola = polaLike($q);
        if ($cfg['keluar']) {
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

    $total  = (int)dbValue("SELECT COUNT(*) FROM $tabel t $sqlWhere", $params);
    $meta   = metaPaginasi($total, $page);
    $offset = ($meta['page'] - 1) * PAGE_SIZE;

    $kolomExtra = $cfg['keluar'] ? ', t.no_pesanan, t.batch_id' : '';

    $rows = dbAll(
        "SELECT t.id, t.tanggal, t.barcode, t.nama, t.jumlah, t.keterangan,
                t.master_id, t.created_at, u.nama_lengkap AS oleh $kolomExtra
           FROM $tabel t
           LEFT JOIN users u ON u.id = t.user_id
         $sqlWhere
         ORDER BY t.tanggal DESC, t.id DESC
         LIMIT " . PAGE_SIZE . " OFFSET $offset",
        $params
    );

    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['jumlah']    = (int)$r['jumlah'];
        $r['master_id'] = $r['master_id'] === null ? null : (int)$r['master_id'];
        if (isset($r['batch_id'])) {
            $r['batch_id'] = $r['batch_id'] === null ? null : (int)$r['batch_id'];
        }
    }
    unset($r);

    $totalJumlah = (int)dbValue("SELECT COALESCE(SUM(t.jumlah),0) FROM $tabel t $sqlWhere", $params);

    jsonOk([
        'rows'         => $rows,
        'total_jumlah' => $totalJumlah,
        'ket_options'  => $cfg['ket'],
    ] + $meta);
}

/**
 * Catat satu transaksi.
 *
 * Barang yang belum ada di master tetap boleh dicatat (master_id NULL) —
 * mempertahankan perilaku prototipe supaya operasional gudang tidak terhenti
 * karena master belum lengkap. Responsnya menyertakan peringatan.
 */
function buatTransaksi(string $jenis): void
{
    $cfg   = konfigTransaksi($jenis);
    $tabel = $cfg['tabel'];

    $in = jsonInput();
    wajibCsrf($in);

    $barcode = ambilStr($in, 'barcode', 50);
    $nama    = ambilStr($in, 'nama', 255);
    $jumlah  = ambilInt($in, 'jumlah', 0);
    $tanggal = ambilTanggal($in, 'tanggal');
    $ket     = pilihanValid(ambilStr($in, 'keterangan', 50), $cfg['ket']);

    if ($barcode === '' || $nama === '' || $jumlah <= 0) {
        jsonError('Lengkapi barcode, nama, dan jumlah (minimal 1) dulu.');
    }
    if ($tanggal === null) {
        jsonError('Format tanggal tidak valid.');
    }

    $master     = cariMasterByBarcode($barcode);
    $masterId   = $master ? (int)$master['id'] : null;
    $peringatan = [];

    if ($master === null) {
        $peringatan[] = 'Barcode "' . $barcode . '" belum terdaftar di master barang. '
            . 'Transaksi tetap dicatat, tapi tidak akan mempengaruhi perhitungan stok sampai barangnya didaftarkan.';
    }

    // Validasi stok negatif (audit D3) — hanya bila barangnya dikenal.
    if ($cfg['keluar'] && $masterId !== null) {
        $tersedia = stokAkhirItem($masterId);
        if ($jumlah > $tersedia) {
            if (!IZINKAN_STOK_MINUS) {
                jsonError(
                    'Stok tidak cukup. Tersedia ' . $tersedia . ', diminta ' . $jumlah . '.',
                    422,
                    ['stok_tersedia' => $tersedia]
                );
            }
            $peringatan[] = 'Stok jadi minus: tersedia ' . $tersedia . ', dikeluarkan ' . $jumlah . '.';
        }
    }

    if ($cfg['keluar']) {
        $noPesanan = ambilStr($in, 'no_pesanan', 100);
        dbExec(
            "INSERT INTO $tabel (tanggal, master_id, barcode, nama, jumlah, keterangan, no_pesanan, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$tanggal, $masterId, $barcode, $nama, $jumlah, $ket, $noPesanan, userId()]
        );
    } else {
        dbExec(
            "INSERT INTO $tabel (tanggal, master_id, barcode, nama, jumlah, keterangan, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$tanggal, $masterId, $barcode, $nama, $jumlah, $ket, userId()]
        );
    }

    $id = dbLastId();
    catatAktivitas('create', $jenis, $id, ['barcode' => $barcode, 'nama' => $nama, 'jumlah' => $jumlah]);

    jsonOk([
        'id'         => $id,
        'peringatan' => $peringatan,
        'pesan'      => $cfg['keluar'] ? 'Barang keluar dicatat.' : 'Barang masuk dicatat.',
    ]);
}

/**
 * Hapus transaksi (soft delete, audit F2).
 */
function hapusTransaksi(string $jenis): void
{
    $cfg   = konfigTransaksi($jenis);
    $tabel = $cfg['tabel'];

    $in = jsonInput();
    wajibCsrf($in);

    $id = ambilInt($in, 'id', 0);
    if ($id <= 0) {
        jsonError('ID transaksi tidak valid.');
    }

    $t = dbOne("SELECT * FROM $tabel WHERE id = ? AND deleted_at IS NULL", [$id]);
    if ($t === null) {
        jsonError('Transaksi tidak ditemukan.', 404);
    }

    dbExec("UPDATE $tabel SET deleted_at = NOW() WHERE id = ?", [$id]);

    catatAktivitas('delete', $jenis, $id, [
        'barcode' => $t['barcode'],
        'nama'    => $t['nama'],
        'jumlah'  => (int)$t['jumlah'],
        'tanggal' => $t['tanggal'],
    ]);

    jsonOk(['pesan' => 'Catatan dihapus.']);
}
