-- ============================================================================
-- 011_penyesuaian_stok.sql
--
-- Membuat kolom "Penyesuaian" di stok opname benar-benar menggeser stok.
--
-- Sebelumnya kolom itu hanya mencatat keputusan. Sekarang memilih
-- "Stok Disesuaikan" menulis satu baris barang masuk / barang keluar
-- berketerangan "Penyesuaian Opname", sebesar selisih antara stok hitung
-- dan stok yang berlaku saat itu — sehingga stok akhir di Dashboard,
-- Riwayat, dan semua tempat lain mengikuti hasil hitungan fisik.
--
-- MENGAPA LEWAT TRANSAKSI, BUKAN MENIMPA ANGKANYA
-- Stok akhir di aplikasi ini selalu stok_awal + masuk - keluar. Kalau
-- opname boleh menimpa angkanya langsung, akan ada dua sumber pergerakan
-- stok yang tidak bisa direkonsiliasi, dan koreksinya tidak akan muncul di
-- Riwayat. Lewat satu baris transaksi, koreksinya terbaca seperti
-- pergerakan lain: ada tanggalnya, ada jumlahnya, ada pelakunya.
--
-- KOLOM BARU
--   adj_jenis  'masuk' atau 'keluar' — arah baris koreksinya
--   adj_id     id baris transaksi yang dihasilkan, supaya bisa diperbarui
--              atau dibatalkan bila hitungannya diubah/penyesuaiannya dicabut
--   adj_qty    besar koreksinya, disimpan untuk ditampilkan tanpa join
--
-- Jalankan SETELAH 010_keterangan.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: opname_item.adj_id

ALTER TABLE opname_item
  ADD COLUMN adj_jenis VARCHAR(10)   NULL AFTER penyesuaian,
  ADD COLUMN adj_id    INT UNSIGNED  NULL AFTER adj_jenis,
  ADD COLUMN adj_qty   INT           NULL AFTER adj_id;

-- Keterangan yang dipakai baris koreksi. Dikunci karena ditulis sistem:
-- kalau namanya diubah atau dihapus, koreksi opname kehilangan penandanya.
INSERT INTO keterangan (jenis, nama, catatan, urutan, terkunci) VALUES
  ('masuk',  'Penyesuaian Opname', 'Ditulis sistem saat stok opname disesuaikan', 35, 1),
  ('keluar', 'Penyesuaian Opname', 'Ditulis sistem saat stok opname disesuaikan', 35, 1)
ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), terkunci = 1;
