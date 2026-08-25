-- ============================================================================
-- 010_keterangan.sql
--
-- Daftar pilihan kolom "Keterangan" pada Barang masuk dan Barang keluar.
--
-- Sebelumnya nilainya dipaku di config/config.php sebagai KET_MASUK dan
-- KET_KELUAR, jadi menambah satu pilihan berarti menyunting berkas dan
-- deploy ulang. Sekarang tersimpan di sini dan bisa dikelola dari menu
-- Master, sama seperti kategori.
--
-- KOLOM `terkunci`
-- Sebagian nilai dipakai sistem, bukan hanya oleh manusia: retur yang sudah
-- lengkap menulis barang masuk berketerangan "Retur Masuk". Kalau nilai itu
-- dihapus, returnya tetap menulis baris dengan keterangan yang tidak lagi
-- ada di daftar pilihan. Karena itu barisnya ditandai terkunci dan ditolak
-- saat hendak dihapus atau diganti namanya.
--
-- Jalankan SETELAH 009_akses_pengguna.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: keterangan

CREATE TABLE IF NOT EXISTS keterangan (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jenis      ENUM('masuk','keluar') NOT NULL,
  nama       VARCHAR(50)  NOT NULL,
  catatan    VARCHAR(120) NOT NULL DEFAULT '',
  urutan     INT          NOT NULL DEFAULT 0,
  aktif      TINYINT(1)   NOT NULL DEFAULT 1,
  terkunci   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  UNIQUE KEY uq_jenis_nama (jenis, nama),
  KEY idx_jenis_aktif (jenis, aktif, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Isi awal = daftar lama di config.php, supaya tidak ada transaksi yang
-- keterangannya mendadak hilang dari dropdown.
INSERT INTO keterangan (jenis, nama, catatan, urutan, terkunci) VALUES
  ('masuk',  'Barang Baru',    'Barang yang baru pertama kali masuk gudang', 10, 0),
  ('masuk',  'Restock',        'Penambahan stok barang yang sudah ada',      20, 0),
  ('masuk',  'Retur Masuk',    'Dipakai sistem saat retur ditandai Lengkap', 30, 1),
  ('masuk',  'Lainnya',        '',                                          40, 0),
  ('keluar', 'Pesanan MP',     'Keluar karena pesanan marketplace',          10, 0),
  ('keluar', 'Retur',          'Barang dikembalikan ke pemasok',             20, 0),
  ('keluar', 'Rusak / Reject', 'Barang tidak layak jual',                    30, 0),
  ('keluar', 'Lainnya',        '',                                           40, 0)
ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), urutan = VALUES(urutan);

-- Jaring pengaman: keterangan yang sudah terpakai di transaksi tapi belum
-- terdaftar (misal dari data lama) ikut didaftarkan, supaya dropdown tidak
-- pernah kehilangan nilai yang sudah dipakai data.
INSERT IGNORE INTO keterangan (jenis, nama, catatan, urutan)
SELECT DISTINCT 'masuk', t.keterangan, 'Ditemukan dari data lama', 900
  FROM barang_masuk t
 WHERE t.keterangan <> '';

INSERT IGNORE INTO keterangan (jenis, nama, catatan, urutan)
SELECT DISTINCT 'keluar', t.keterangan, 'Ditemukan dari data lama', 900
  FROM barang_keluar t
 WHERE t.keterangan <> '';
