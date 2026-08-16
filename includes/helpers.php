<?php
/**
 * includes/helpers.php — validasi, sanitasi, dan logika stok bersama.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

/**
 * URL aset dengan penangkal cache dari waktu ubah berkasnya.
 *
 * .htaccess menyimpan CSS/JS di cache browser selama 7 hari. Sebelumnya
 * penangkalnya memakai APP_VERSI — konstanta yang tidak pernah berubah —
 * sehingga setiap deploy mengirim berkas baru yang tidak pernah diambil
 * browser sampai cache-nya kedaluwarsa sendiri.
 *
 * Memakai filemtime() membuat URL-nya berubah otomatis begitu isinya
 * berubah, tanpa perlu menaikkan nomor versi secara manual.
 */
function aset(string $relatif): string
{
    $penuh = dirname(__DIR__) . '/' . ltrim($relatif, '/');
    $cap = is_file($penuh) ? (string)filemtime($penuh) : APP_VERSI;
    return $relatif . '?v=' . $cap;
}

/** Escape untuk keluaran HTML. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ambil string dari array input, sudah di-trim dan dibatasi panjangnya. */
function ambilStr(array $src, string $key, int $maks = 255): string
{
    $v = $src[$key] ?? '';
    if (!is_scalar($v)) {
        return '';
    }
    return mb_substr(trim((string)$v), 0, $maks);
}

/** Ambil bilangan bulat dari array input. */
function ambilInt(array $src, string $key, int $default = 0): int
{
    $v = $src[$key] ?? $default;
    if (is_string($v)) {
        $v = trim($v);
    }
    return is_numeric($v) ? (int)$v : $default;
}

/**
 * Validasi tanggal ISO (yyyy-mm-dd). Kembalikan tanggal hari ini bila kosong,
 * atau null bila formatnya salah.
 */
function ambilTanggal(array $src, string $key): ?string
{
    $v = trim((string)($src[$key] ?? ''));
    if ($v === '') {
        return date('Y-m-d');
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        return null;
    }
    return $v;
}

/** Pastikan nilai ada di dalam daftar pilihan; bila tidak, pakai yang pertama. */
function pilihanValid(string $nilai, array $daftar): string
{
    return in_array($nilai, $daftar, true) ? $nilai : $daftar[0];
}

/** Nomor halaman minimal 1. */
function ambilHalaman(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

/* -------------------------------------------------------------------------
 * Logika stok
 * ---------------------------------------------------------------------- */

/**
 * Potongan SQL penentu status stok — dipakai bersama oleh dashboard dan
 * validasi, supaya aturannya hanya ada di satu tempat.
 *
 * Perbedaan dari prototipe (audit D4): stok_minimal = 0 berarti ambangnya
 * BELUM DIATUR, bukan kritis. Tanpa ini seluruh 1.404 item seed berstatus
 * kritis sejak aplikasi dibuka, dan peringatannya jadi tidak berarti.
 */
function sqlStatusStok(string $ekspresiAkhir = 'stok_akhir', string $kolomMin = 'm.stok_minimal'): string
{
    return "CASE
        WHEN $kolomMin = 0 THEN 'belum_diatur'
        WHEN $ekspresiAkhir <= $kolomMin THEN 'kritis'
        WHEN $ekspresiAkhir <= $kolomMin * " . AMBANG_RENDAH . " THEN 'rendah'
        ELSE 'aman'
    END";
}

/** Ekspresi SQL stok akhir = stok awal + masuk - keluar. */
function sqlStokAkhir(): string
{
    return 'm.stok_awal + COALESCE(i.total, 0) - COALESCE(o.total, 0)';
}

/** JOIN agregat masuk & keluar, dipakai bersama beberapa endpoint. */
function sqlJoinAgregat(): string
{
    return '
        LEFT JOIN (
            SELECT master_id, SUM(jumlah) AS total
            FROM barang_masuk WHERE deleted_at IS NULL AND master_id IS NOT NULL
            GROUP BY master_id
        ) i ON i.master_id = m.id
        LEFT JOIN (
            SELECT master_id, SUM(jumlah) AS total
            FROM barang_keluar WHERE deleted_at IS NULL AND master_id IS NOT NULL
            GROUP BY master_id
        ) o ON o.master_id = m.id';
}

/**
 * Stok akhir satu item berdasarkan master_id.
 */
function stokAkhirItem(int $masterId): int
{
    $row = dbOne(
        'SELECT m.stok_awal
              + COALESCE((SELECT SUM(jumlah) FROM barang_masuk  WHERE master_id = m.id AND deleted_at IS NULL), 0)
              - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL), 0)
              AS akhir
         FROM master_barang m WHERE m.id = ?',
        [$masterId]
    );
    return $row ? (int)$row['akhir'] : 0;
}

/* -------------------------------------------------------------------------
 * Kategori
 *
 * Daftarnya kini tersimpan di tabel `kategori`, bukan konstanta PHP, supaya
 * bisa dikelola lewat menu Master. KATEGORI_OPTIONS di config/config.php
 * hanya dipakai sebagai cadangan bila tabelnya belum ada (mis. database
 * lama yang belum menjalankan 003_kategori_pengguna.sql).
 * ---------------------------------------------------------------------- */

/** @return string[] nama kategori aktif, terurut */
function daftarKategori(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $rows = dbAll('SELECT nama FROM kategori
                        WHERE deleted_at IS NULL AND aktif = 1
                        ORDER BY urutan, nama');
        $cache = array_column($rows, 'nama');
    } catch (Throwable $e) {
        error_log('Tabel kategori belum ada, memakai daftar bawaan: ' . $e->getMessage());
        $cache = KATEGORI_OPTIONS;
    }
    return $cache;
}

/** Cari master berdasarkan barcode. */
function cariMasterByBarcode(string $barcode): ?array
{
    if ($barcode === '') {
        return null;
    }
    return dbOne(
        'SELECT id, sku, barcode, nama, stok_awal, stok_minimal, kategori
           FROM master_barang WHERE barcode = ? AND deleted_at IS NULL LIMIT 1',
        [$barcode]
    );
}

/**
 * Saldo stok tiap barang pada akhir setiap tanggal yang punya pergerakan.
 *
 * Dipakai kolom "Stok akhir" di halaman Riwayat: tiap catatan menunjukkan
 * posisi stok barang itu pada akhir hari tersebut, bukan sekadar jumlah
 * yang bergerak.
 *
 * Dihitung di PHP, bukan lewat window function SQL: versi MySQL di server
 * produksi belum tentu mendukungnya (MySQL 5.7 tidak), dan menghitung ulang
 * lewat subquery berkorelasi akan memicu satu query per baris.
 *
 * Granularitasnya sengaja per HARI, bukan per transaksi. Beberapa catatan
 * pada barang dan tanggal yang sama akan menunjukkan saldo akhir hari yang
 * sama — itu nilai yang tidak ambigu, sedangkan mengurutkan antar dua tabel
 * dalam detik yang sama tidak bisa dipastikan.
 *
 * @param  array $masterIds daftar id master (boleh berisi null dan duplikat)
 * @return array [masterId][tanggal] => saldo akhir hari itu
 */
function saldoHarian(array $masterIds): array
{
    $ids = [];
    foreach ($masterIds as $v) {
        if ($v !== null && (int)$v > 0) {
            $ids[(int)$v] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        return [];
    }

    $tanda = implode(',', array_fill(0, count($ids), '?'));

    $awal = [];
    foreach (dbAll("SELECT id, stok_awal FROM master_barang WHERE id IN ($tanda)", $ids) as $r) {
        $awal[(int)$r['id']] = (int)$r['stok_awal'];
    }

    // Pergerakan bersih per barang per tanggal, kedua arah digabung.
    $gerak = dbAll(
        "SELECT master_id, tanggal, SUM(delta) AS delta FROM (
             SELECT master_id, tanggal,  jumlah AS delta
               FROM barang_masuk  WHERE deleted_at IS NULL AND master_id IN ($tanda)
             UNION ALL
             SELECT master_id, tanggal, -jumlah AS delta
               FROM barang_keluar WHERE deleted_at IS NULL AND master_id IN ($tanda)
         ) t
         GROUP BY master_id, tanggal
         ORDER BY master_id, tanggal",
        array_merge($ids, $ids)
    );

    $saldo = [];
    $berjalan = [];
    foreach ($gerak as $g) {
        $mid = (int)$g['master_id'];
        if (!isset($berjalan[$mid])) {
            $berjalan[$mid] = $awal[$mid] ?? 0;
        }
        $berjalan[$mid] += (int)$g['delta'];
        $saldo[$mid][$g['tanggal']] = $berjalan[$mid];
    }
    return $saldo;
}

/**
 * Bentuk metadata paginasi yang konsisten untuk semua endpoint list.
 */
function metaPaginasi(int $total, int $page, int $perPage = PAGE_SIZE): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    return [
        'total'       => $total,
        'page'        => min($page, $totalPages),
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
    ];
}

/**
 * Ubah string pencarian menjadi pola LIKE yang aman.
 * Karakter wildcard milik pengguna di-escape agar "%" tidak mencocokkan semua.
 */
function polaLike(string $q): string
{
    $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    return '%' . $q . '%';
}
