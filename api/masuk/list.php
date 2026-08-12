<?php
/** GET api/masuk/list.php — daftar barang masuk (cari, rentang tanggal, paginasi). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('GET');
wajibLoginApi();
daftarTransaksi('masuk');
