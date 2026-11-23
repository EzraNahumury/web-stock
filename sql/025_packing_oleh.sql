-- ============================================================================
-- 025_packing_oleh.sql
--
-- Siapa yang mem-packing barang keluar.
--
-- SEBABNYA
-- Kolom user_id sudah mencatat siapa yang MENGETIK barisnya ke aplikasi, dan
-- itu pertanyaan yang berbeda: yang mengetik biasanya admin gudang, sedangkan
-- yang mem-packing orang lain. Ketika sebuah paket sampai ke pembeli dalam
-- keadaan kurang atau salah isi, yang perlu ditanyai orang yang memasukkan
-- barangnya ke kardus — dan selama ini tidak ada tempat untuk mencatatnya.
--
-- BUKAN RELASI KE users
-- Yang mem-packing sering bukan pemegang akun aplikasi: anak magang, pekerja
-- harian, orang gudang yang tidak pernah login. Dibuat relasi ke tabel users,
-- nama-nama itu tidak akan pernah bisa diisi, dan kolomnya berakhir kosong
-- untuk sebagian besar baris. Jadi teks bebas, sama seperti kolom petugas di
-- stok opname yang memang sudah dipakai begitu.
--
-- Hanya di barang_keluar. Barang masuk tidak di-packing.
--
-- Jalankan SETELAH 024_opname_stok_tutup.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: barang_keluar.packing_oleh

ALTER TABLE barang_keluar
  ADD COLUMN packing_oleh VARCHAR(100) NOT NULL DEFAULT '' AFTER no_pesanan,
  -- Dipakai penyaring "siapa yang packing" di layar Barang keluar.
  ADD INDEX idx_packing (packing_oleh, deleted_at);
