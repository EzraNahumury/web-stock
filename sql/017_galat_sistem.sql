-- ============================================================================
-- 017_galat_sistem.sql
--
-- Catatan galat server, supaya bisa dibaca dari dalam aplikasi.
--
-- SEBABNYA
-- Saat sebuah permintaan gagal, layar hanya berbunyi "Terjadi kesalahan di
-- server." Pesan aslinya dikirim ke error log PHP milik hosting, yang tidak
-- bisa dibuka dari aplikasi — jadi setiap kali ada galat di produksi,
-- penelusurannya dimulai dari menebak. Itu sudah terjadi berulang kali, dan
-- tebakannya pernah salah.
--
-- Sekarang galatnya ikut dicatat di sini dan bisa dilihat admin di menu Log
-- aktivitas. Tiap galat diberi kode pendek yang juga disebutkan di layar,
-- jadi tangkapan layar dari gudang bisa langsung dicocokkan dengan barisnya.
--
-- Isinya teknis — pesan basis data, nama berkas, nomor baris — jadi hanya
-- admin yang boleh membukanya. Yang lama dibuang sendiri: tidak ada gunanya
-- menyimpan galat lebih dari sebulan.
--
-- Jalankan SETELAH 001_schema.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: galat_sistem

CREATE TABLE IF NOT EXISTS galat_sistem (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kode       CHAR(6)      NOT NULL,
  endpoint   VARCHAR(100) NOT NULL DEFAULT '',
  pesan      VARCHAR(500) NOT NULL DEFAULT '',
  -- Bukan bernama "sqlstate": itu kata tercadang di MySQL dan MariaDB, dan
  -- memakainya membuat CREATE TABLE ini gagal dengan galat sintaks.
  kode_sql   VARCHAR(10)  NOT NULL DEFAULT '',
  berkas     VARCHAR(200) NOT NULL DEFAULT '',
  baris      INT          NOT NULL DEFAULT 0,
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_waktu (created_at),
  KEY idx_kode (kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
