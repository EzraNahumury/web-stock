<?php
/** POST api/keluar/delete.php — hapus catatan barang keluar (soft delete). */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/transaksi.php';
pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();
hapusTransaksi('keluar');
