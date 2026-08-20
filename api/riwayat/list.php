<?php
/**
 * GET api/riwayat/list.php — rekap pergerakan stok per barang.
 *
 * Satu baris = satu barang, bukan satu transaksi. Seluruh barang pada
 * kategori yang dipilih ikut tampil meski tidak bergerak sama sekali,
 * karena "tidak bergerak" juga jawaban yang dicari saat menutup periode.
 *
 * Parameter: q, kategori, dari, sampai, page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/riwayat.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();

$f    = filterRiwayat($_GET);
$page = ambilHalaman();

$total  = jumlahRiwayat($f);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows   = barisRiwayat($f, PAGE_SIZE, $offset);
$jumlah = totalRiwayat($f);

jsonOk([
    'rows'             => $rows,
    'total_awal'       => $jumlah['awal'],
    'total_masuk'      => $jumlah['masuk'],
    'total_keluar'     => $jumlah['keluar'],
    'total_akhir'      => $jumlah['akhir'],
    'kategori_options' => daftarKategori(),
] + $meta);
