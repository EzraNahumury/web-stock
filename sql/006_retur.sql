-- ============================================================================
-- 006_retur.sql
--
-- Retur barang dari pembeli. Formatnya mengikuti lembar kerja gudang:
-- tanggal, no. pesanan, produk (SKU + nama + qty), keterangan retur, dan
-- catatan bebas.
--
-- HUBUNGAN DENGAN BARANG MASUK
-- Barang yang kembali dan sudah lengkap berarti stoknya bertambah lagi.
-- Karena itu retur berstatus "Lengkap" ikut membuat satu baris di
-- barang_masuk (keterangan "Retur Masuk"), dan id-nya disimpan di kolom
-- masuk_id supaya keduanya tetap terhubung: bila returnya diubah atau
-- dihapus, baris barang masuknya ikut menyesuaikan.
--
-- Retur berstatus "Sistem Belum Selesai" belum menyentuh stok sama sekali —
-- barangnya memang belum bisa diproses.
--
-- Jalankan SETELAH 005_indeks_aktivitas.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: retur

CREATE TABLE IF NOT EXISTS retur (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,
  no_pesanan VARCHAR(100) NOT NULL DEFAULT '',

  master_id  INT UNSIGNED NULL,
  barcode    VARCHAR(50)  NOT NULL DEFAULT '',
  sku        VARCHAR(50)  NOT NULL DEFAULT '',
  nama       VARCHAR(255) NOT NULL DEFAULT '',
  jumlah     INT          NOT NULL DEFAULT 1,

  -- "Lengkap" | "Sistem Belum Selesai"
  status     VARCHAR(30)  NOT NULL DEFAULT 'Lengkap',
  keterangan VARCHAR(255) NOT NULL DEFAULT '',

  -- Baris barang_masuk yang dihasilkan retur ini, bila statusnya Lengkap.
  masuk_id   INT UNSIGNED NULL,

  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,

  KEY idx_tanggal    (tanggal),
  KEY idx_no_pesanan (no_pesanan),
  KEY idx_master     (master_id),
  KEY idx_status     (status),
  KEY idx_masuk      (masuk_id),
  CONSTRAINT fk_retur_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_retur_masuk  FOREIGN KEY (masuk_id)  REFERENCES barang_masuk(id)  ON DELETE SET NULL,
  CONSTRAINT fk_retur_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_retur_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
