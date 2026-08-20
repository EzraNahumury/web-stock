-- ============================================================================
-- 007_opname.sql
--
-- Laporan stok opname: perbandingan stok menurut aplikasi dengan hasil
-- hitungan fisik di gudang dan dengan catatan Accurate.
--
-- Satu sesi = satu periode opname (mis. "JUNI 2026"). Isinya dibekukan saat
-- sesi dibuat: stok_sistem disalin apa adanya ke tiap baris, bukan dihitung
-- ulang saat dibuka. Kalau dihitung ulang, laporan bulan lalu akan berubah
-- sendiri setiap ada transaksi baru dan tidak bisa lagi dipakai sebagai
-- bukti hitungan.
--
-- Selisih tidak disimpan — selalu stok_hitung - stok_accurate, dihitung saat
-- ditampilkan supaya tidak pernah basi terhadap kedua angka itu.
--
-- Jalankan SETELAH 006_retur.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: opname_sesi

CREATE TABLE IF NOT EXISTS opname_sesi (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama       VARCHAR(150) NOT NULL,
  periode    VARCHAR(50)  NOT NULL DEFAULT '',
  tanggal    DATE         NOT NULL,
  kategori   VARCHAR(30)  NOT NULL DEFAULT '',   -- '' = seluruh kategori
  status     VARCHAR(20)  NOT NULL DEFAULT 'draft',  -- draft | selesai
  catatan    VARCHAR(255) NOT NULL DEFAULT '',
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_tanggal (tanggal),
  KEY idx_status  (status),
  CONSTRAINT fk_opname_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS opname_item (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sesi_id       INT UNSIGNED NOT NULL,
  master_id     INT UNSIGNED NULL,

  -- Identitas barang ikut dibekukan, sama alasannya dengan barang_masuk:
  -- master boleh berubah kemudian, laporan lama tidak boleh ikut berubah.
  sku           VARCHAR(50)  NOT NULL DEFAULT '',
  barcode       VARCHAR(50)  NOT NULL DEFAULT '',
  nama          VARCHAR(255) NOT NULL DEFAULT '',
  kategori      VARCHAR(30)  NOT NULL DEFAULT '',

  stok_sistem   INT          NOT NULL DEFAULT 0,
  stok_hitung   INT          NULL,   -- NULL = belum dihitung
  stok_accurate INT          NULL,   -- NULL = belum diisi
  dicek         TINYINT(1)   NOT NULL DEFAULT 0,
  catatan       VARCHAR(255) NOT NULL DEFAULT '',
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_sesi_master (sesi_id, master_id),
  KEY idx_sesi     (sesi_id),
  KEY idx_kategori (kategori),
  CONSTRAINT fk_oitem_sesi   FOREIGN KEY (sesi_id)   REFERENCES opname_sesi(id)   ON DELETE CASCADE,
  CONSTRAINT fk_oitem_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
