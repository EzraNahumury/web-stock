<?php
/** POST api/keluar/create.php — catat satu transaksi barang keluar. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();
buatTransaksi('keluar');
