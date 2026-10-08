-- ============================================================================
-- 019_pertukaran_hapus.sql
--
-- Pertukaran barang ikut terhapus bersama barang keluarnya.
--
-- SEBABNYA
-- Baris pertukaran lahir dari sebuah baris barang keluar: barang A yang
-- dipesan diganti barang B yang benar-benar dikirim. Ketika baris barang
-- keluarnya dihapus, catatan pertukarannya tetap tinggal — menyebutkan
-- perpindahan stok yang sudah tidak ada lagi. Gudang melihat satu pertukaran
-- di layar sementara transaksinya sendiri sudah tidak ada.
--
-- Penghapusannya lunak, sama seperti transaksi: barisnya tidak dibuang,
-- hanya ditandai. Jejaknya tetap ada bila suatu saat perlu ditelusuri.
--
-- Jalankan SETELAH 004_pertukaran.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: pertukaran_barang.deleted_at

ALTER TABLE pertukaran_barang
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX idx_hapus (deleted_at);
