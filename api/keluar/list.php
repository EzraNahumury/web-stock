<?php
/** GET api/keluar/list.php — daftar barang keluar (cari, rentang tanggal, paginasi). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();
daftarTransaksi('keluar');
