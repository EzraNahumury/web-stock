-- ============================================================================
-- 003_kategori_pengguna.sql
--
-- Memindahkan daftar kategori dari konstanta PHP ke database supaya bisa
-- dikelola lewat aplikasi, dan menyiapkan pengelolaan akun pengguna.
--
-- Jalankan SETELAH 001_schema.sql dan 002_seed_master.sql.
--   php: C:\xampp\mysql\bin\mysql.exe -u root web_stock < sql\003_kategori_pengguna.sql
-- ============================================================================

SET NAMES utf8mb4;

-- Dilewati otomatis bila keadaan yang dituju sudah tercapai, supaya
-- penerapan ulang tidak menghapus data yang sudah ada.
-- @lewati-jika-tabel: kategori

-- ---------------------------------------------------------------------------
-- kategori
--
-- master_barang.kategori tetap disimpan sebagai teks, bukan diubah menjadi
-- foreign key. Alasannya: 1.404 baris sudah terisi nama kategori, dan
-- mengubah skema relasinya berisiko tanpa manfaat nyata di skala ini.
-- Tabel ini menjadi sumber daftar pilihan; mengganti nama kategori akan
-- memperbarui seluruh baris master yang memakainya dalam satu transaksi.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kategori (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama       VARCHAR(30)  NOT NULL,
  keterangan VARCHAR(120) NOT NULL DEFAULT '',
  urutan     INT          NOT NULL DEFAULT 0,
  aktif      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  UNIQUE KEY uq_kategori_nama (nama),
  KEY idx_aktif (aktif, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Isi dari kategori yang benar-benar dipakai data, ditambah LAINNYA sebagai
-- penampung. Urutan mengikuti daftar KATEGORI_OPTIONS lama.
INSERT INTO kategori (nama, keterangan, urutan) VALUES
  ('ACC',      'Aksesori dan perlengkapan kecil',        10),
  ('AOLIKES',  'Produk merek Aolikes',                   20),
  ('AVO',      'Produk merek Avo',                       30),
  ('AYRES',    'Produk merek Ayres',                     40),
  ('FASHION',  'Pakaian dan kaos kaki',                  50),
  ('FISIO',    'Fingertape, wristape, dan alat fisio',   60),
  ('GYM',      'Perlengkapan gym',                       70),
  ('JERSEY',   'Jersey dan teamwear',                    80),
  ('SAIFENU',  'Produk merek Saifenu',                   90),
  ('TRAINING', 'Perlengkapan latihan',                  100),
  ('LAINNYA',  'Belum dikelompokkan',                   110)
ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan), urutan = VALUES(urutan);

-- Jaring pengaman: bila ada kategori terpakai di master_barang yang belum
-- terdaftar di sini (misal hasil impor lain), daftarkan otomatis supaya
-- daftar pilihan tidak kehilangan nilai yang sudah dipakai data.
INSERT INTO kategori (nama, keterangan, urutan)
SELECT DISTINCT m.kategori, 'Ditemukan dari data master', 900
  FROM master_barang m
 WHERE m.kategori <> ''
   AND m.deleted_at IS NULL
   AND NOT EXISTS (SELECT 1 FROM kategori k WHERE k.nama = m.kategori);
