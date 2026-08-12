<?php
/**
 * includes/helpers.php — validasi, sanitasi, dan logika stok bersama.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

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
