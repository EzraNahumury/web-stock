-- ============================================================================
-- 008_opname_penyesuaian.sql
--
-- Dua kolom tambahan pada baris stok opname, mengikuti lembar kerja gudang:
--
--   penyesuaian  keputusan atas selisihnya — stok sistem dibetulkan mengikuti
--                hitungan fisik, atau dibiarkan apa adanya. Ini CATATAN
--                keputusan, bukan pemicu: menandainya tidak mengubah stok.
--                Penyesuaian stok yang sebenarnya tetap lewat barang masuk /
--                barang keluar, supaya setiap pergerakan stok punya satu
--                jalur yang sama dan bisa ditelusuri di Riwayat.
--
--   petugas      nama orang yang menghitung baris itu. Di lembar aslinya ini
--                berupa satu kolom bernama orangnya; disimpan sebagai teks
--                supaya penghitungnya boleh berbeda antar baris.
--
-- Jalankan SETELAH 007_opname.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- ADD COLUMN tidak punya bentuk "IF NOT EXISTS" yang sama di MySQL dan
-- MariaDB, jadi keberadaannya diperiksa penerap migrasi lebih dulu.
-- @lewati-jika-kolom: opname_item.penyesuaian

ALTER TABLE opname_item
  ADD COLUMN penyesuaian VARCHAR(30)  NOT NULL DEFAULT 'Tidak Disesuaikan' AFTER dicek,
  ADD COLUMN petugas     VARCHAR(100) NOT NULL DEFAULT ''                  AFTER penyesuaian,
  ADD KEY idx_penyesuaian (penyesuaian);
