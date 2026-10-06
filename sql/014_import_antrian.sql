-- ============================================================================
-- 014_import_antrian.sql
--
-- Tempat menampung baris picking list sebelum disimpan.
--
-- SEBABNYA
-- Satu picking list dikirim sebagai satu permintaan berisi seluruh barisnya.
-- Di server produksi, permintaan itu ditolak sebelum sampai ke PHP begitu
-- isinya lewat dari sekitar sepuluh baris — jawabannya bukan JSON, jadi
-- aplikasi tidak pernah tahu alasannya. Pengujian dari luar tidak pernah bisa
-- menirunya, dan impor yang gagal total tidak bisa ditunggu sampai sebabnya
-- ketemu.
--
-- Jadi barisnya dikirim sedikit-sedikit dan ditampung di sini dulu, lalu satu
-- permintaan terakhir memerintahkan penyimpanannya. Ukuran tiap permintaan
-- jadi tetap kecil, berapa pun panjang picking listnya.
--
-- SATU TRANSAKSI TETAP SATU TRANSAKSI
-- Tabel ini bukan tempat penyimpanan akhir: tidak ada satu baris pun masuk ke
-- barang_keluar sampai perintah terakhir datang, dan penulisannya tetap satu
-- transaksi seperti sebelumnya. Kalau pengunggahan terputus di tengah, yang
-- tertinggal hanya isi tabel ini — tidak ada stok yang terlanjur berkurang.
--
-- Barisnya dibuang setelah impornya tersimpan, dan sisa sesi yang telanjur
-- ditinggalkan dibersihkan sendiri setelah sehari.
--
-- Jalankan SETELAH 001_schema.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: import_antrian

CREATE TABLE IF NOT EXISTS import_antrian (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sesi         CHAR(32)     NOT NULL,
  user_id      INT UNSIGNED NULL,
  urut         INT          NOT NULL DEFAULT 0,
  barcode      VARCHAR(50)  NOT NULL DEFAULT '',
  nama         VARCHAR(255) NOT NULL DEFAULT '',
  sku          VARCHAR(50)  NOT NULL DEFAULT '',
  qty          INT          NOT NULL DEFAULT 0,
  keterangan   VARCHAR(50)  NOT NULL DEFAULT '',
  no_pesanan   VARCHAR(100) NOT NULL DEFAULT '',
  asli_barcode VARCHAR(50)  NOT NULL DEFAULT '',
  asli_sku     VARCHAR(50)  NOT NULL DEFAULT '',
  asli_nama    VARCHAR(255) NOT NULL DEFAULT '',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sesi (sesi, urut),
  KEY idx_umur (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
