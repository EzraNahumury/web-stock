-- ============================================================================
-- 004_pertukaran.sql
--
-- Mencatat pertukaran produk yang dilakukan admin saat meninjau hasil impor
-- PDF picking list: barcode atau SKU sebuah baris diganti, sehingga stok
-- yang dipotong berpindah ke produk lain.
--
-- Jalankan SETELAH 003_kategori_pengguna.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- Dilewati otomatis bila keadaan yang dituju sudah tercapai, supaya
-- penerapan ulang tidak menghapus data yang sudah ada.
-- @lewati-jika-tabel: pertukaran_barang

CREATE TABLE IF NOT EXISTS pertukaran_barang (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal        DATE         NOT NULL,

  -- Keadaan baris sebagaimana terbaca dari PDF (setelah dicocokkan master).
  barcode_lama   VARCHAR(50)  NOT NULL DEFAULT '',
  nama_lama      VARCHAR(255) NOT NULL DEFAULT '',
  sku_lama       VARCHAR(50)  NOT NULL DEFAULT '',

  -- Produk pengganti yang benar-benar disimpan sebagai barang keluar.
  master_id_baru INT UNSIGNED NULL,
  barcode_baru   VARCHAR(50)  NOT NULL DEFAULT '',
  nama_baru      VARCHAR(255) NOT NULL DEFAULT '',
  sku_baru       VARCHAR(50)  NOT NULL DEFAULT '',

  jumlah         INT          NOT NULL DEFAULT 0,
  no_pesanan     VARCHAR(255) NOT NULL DEFAULT '',
  alasan         VARCHAR(30)  NOT NULL DEFAULT 'barcode',  -- barcode | sku | keduanya

  batch_id       INT UNSIGNED NULL,
  keluar_id      INT UNSIGNED NULL,   -- baris barang_keluar yang dihasilkan
  user_id        INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_tanggal      (tanggal),
  KEY idx_barcode_lama (barcode_lama),
  KEY idx_barcode_baru (barcode_baru),
  KEY idx_batch        (batch_id),
  CONSTRAINT fk_tukar_master FOREIGN KEY (master_id_baru) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_tukar_batch  FOREIGN KEY (batch_id)       REFERENCES import_batch(id)  ON DELETE SET NULL,
  CONSTRAINT fk_tukar_user   FOREIGN KEY (user_id)        REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
