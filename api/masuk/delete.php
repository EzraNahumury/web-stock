<?php
/** POST api/masuk/delete.php — hapus catatan barang masuk (soft delete). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();
hapusTransaksi('masuk');
