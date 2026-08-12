<?php
/** POST api/masuk/create.php — catat satu transaksi barang masuk. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();
buatTransaksi('masuk');
