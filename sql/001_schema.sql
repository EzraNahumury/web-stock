-- ============================================================================
-- 001_schema.sql — Papan Kendali Gudang
--
-- Target : MariaDB 10.4+ (XAMPP)  |  MySQL 5.7+ / 8.0 (Hostinger)
-- Charset: utf8mb4
--
-- Jalankan setelah database dibuat:
--   XAMPP     : phpMyAdmin -> Databases -> "web_stock" -> utf8mb4_general_ci
--   Hostinger : hPanel -> Databases -> Management (nama berawalan uXXXXXX_)
--
-- Impor lewat CLI (lebih cepat, tanpa batas unggah phpMyAdmin):
--   C:\xampp\mysql\bin\mysql.exe -u root web_stock < sql\001_schema.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS barang_keluar;
DROP TABLE IF EXISTS barang_masuk;
DROP TABLE IF EXISTS import_batch;
DROP TABLE IF EXISTS master_barang;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,      -- password_hash(), PASSWORD_DEFAULT
  nama_lengkap  VARCHAR(100) NOT NULL,
  role          ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  aktif         TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP    NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- master_barang
--
-- barcode UNIQUE NOT NULL memperbaiki audit D1 (3 barcode duplikat di seed)
-- dan D2 (356 item barcode kosong -> digenerate INT-<sku> saat konversi).
-- ---------------------------------------------------------------------------
CREATE TABLE master_barang (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku          VARCHAR(50)  NOT NULL DEFAULT '',
  barcode      VARCHAR(50)  NOT NULL,
  nama         VARCHAR(255) NOT NULL,
  stok_awal    INT          NOT NULL DEFAULT 0,
  stok_minimal INT          NOT NULL DEFAULT 0,   -- 0 = ambang belum diatur (audit D4)
  kategori     VARCHAR(30)  NOT NULL DEFAULT '',
  barcode_asli TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = barcode hasil generate, perlu dilengkapi
  aktif        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP    NULL DEFAULT NULL,    -- soft delete (audit F2)
  UNIQUE KEY uq_barcode (barcode),
  KEY idx_sku          (sku),
  KEY idx_nama         (nama),
  KEY idx_kategori     (kategori),
  KEY idx_aktif_hapus  (aktif, deleted_at),
  KEY idx_barcode_asli (barcode_asli),
  FULLTEXT KEY ft_cari (nama, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- import_batch — satu baris per PDF picking list yang diimpor
--
-- file_hash (SHA-256 isi PDF) + no_picking dipakai mendeteksi impor ganda
-- (audit D5). Tidak UNIQUE supaya impor ulang yang disengaja tetap mungkin
-- setelah admin mengonfirmasi; pemeriksaan dilakukan di api/import/check.php.
-- ---------------------------------------------------------------------------
CREATE TABLE import_batch (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_picking     VARCHAR(100) NOT NULL DEFAULT '',
  nama_file      VARCHAR(255) NOT NULL DEFAULT '',
  file_hash      CHAR(64)     NOT NULL DEFAULT '',
  tanggal_cetak  DATE         NULL,
  dicetak_oleh   VARCHAR(100) NOT NULL DEFAULT '',
  jumlah_pesanan INT          NOT NULL DEFAULT 0,
  jumlah_produk  INT          NOT NULL DEFAULT 0,
  jumlah_baris   INT          NOT NULL DEFAULT 0,
  user_id        INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_no_picking (no_picking),
  KEY idx_file_hash  (file_hash),
  CONSTRAINT fk_batch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- barang_masuk
--
-- master_id = relasi kuat (audit D1). barcode & nama tetap disimpan sebagai
-- jejak historis: bila master kelak diubah/dihapus, riwayat transaksi tetap
-- menunjukkan apa yang sebenarnya dicatat saat itu.
-- ---------------------------------------------------------------------------
CREATE TABLE barang_masuk (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,
  master_id  INT UNSIGNED NULL,
  barcode    VARCHAR(50)  NOT NULL,
  nama       VARCHAR(255) NOT NULL,
  jumlah     INT          NOT NULL,
  keterangan VARCHAR(50)  NOT NULL DEFAULT 'Restock',
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_tanggal      (tanggal),
  KEY idx_master       (master_id),
  KEY idx_barcode      (barcode),
  KEY idx_master_hapus (master_id, deleted_at),
  CONSTRAINT fk_masuk_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_masuk_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_masuk_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- barang_keluar
--
-- batch_id NULL = input manual; terisi = berasal dari impor PDF picking list.
-- ---------------------------------------------------------------------------
CREATE TABLE barang_keluar (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,
  master_id  INT UNSIGNED NULL,
  barcode    VARCHAR(50)  NOT NULL,
  nama       VARCHAR(255) NOT NULL,
  jumlah     INT          NOT NULL,
  keterangan VARCHAR(50)  NOT NULL DEFAULT 'Pesanan MP',
  no_pesanan VARCHAR(100) NOT NULL DEFAULT '',
  batch_id   INT UNSIGNED NULL,
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_tanggal      (tanggal),
  KEY idx_master       (master_id),
  KEY idx_barcode      (barcode),
  KEY idx_batch        (batch_id),
  KEY idx_no_pesanan   (no_pesanan),
  KEY idx_master_hapus (master_id, deleted_at),
  CONSTRAINT fk_keluar_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_keluar_batch  FOREIGN KEY (batch_id)  REFERENCES import_batch(id)  ON DELETE SET NULL,
  CONSTRAINT fk_keluar_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_keluar_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- activity_log — jejak audit (audit F4)
--
-- Kolom detail bertipe JSON. Di MariaDB ini alias LONGTEXT + CHECK json_valid();
-- di MySQL 8 tipe asli. Jangan pakai operator ->> khas MySQL agar portabel.
-- ---------------------------------------------------------------------------
CREATE TABLE activity_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  aksi       VARCHAR(50)  NOT NULL,   -- create|update|delete|import|login
  entitas    VARCHAR(50)  NOT NULL,   -- master|masuk|keluar|batch|auth
  entitas_id INT UNSIGNED NULL,
  detail     JSON         NULL,
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_waktu (user_id, created_at),
  KEY idx_entitas    (entitas, entitas_id),
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Akun awal
--
-- PENTING: hash di bawah adalah untuk password "admin123".
-- Ganti password lewat aplikasi segera setelah login pertama, dan JANGAN
-- pernah membawa akun ini apa adanya ke Hostinger.
-- ---------------------------------------------------------------------------
INSERT INTO users (username, password_hash, nama_lengkap, role) VALUES
  ('admin', '$2y$10$lrtWrpzYHCu2.J/JM5uxheSGLq5V6hmX2gvz3wnmI.OjOB3i2ItCK', 'Administrator Gudang', 'admin');
