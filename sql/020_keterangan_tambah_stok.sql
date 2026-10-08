-- ============================================================================
-- 020_keterangan_tambah_stok.sql
--
-- Keterangan retur mana yang mengembalikan barang ke stok — bisa diatur.
--
-- SEBABNYA
-- Dulu hanya SATU nama yang menambah stok, dipaku di config sebagai
-- STATUS_RETUR_MASUK = "Lengkap". Begitu daftar keterangan retur bisa
-- dikelola dari menu Master, gudang menambahkan "Pembatalan" dan "Pengiriman
-- Gagal" — dan retur dengan keterangan itu diam-diam tidak pernah menambah
-- stok, karena namanya bukan "Lengkap". Sepanjang Oktober tidak ada satu pun
-- retur yang masuk ke barang masuk, dan tidak ada yang memberi tahu.
--
-- Sekarang sifat itu melekat pada keterangannya, bukan pada namanya: satu
-- kolom `tambah_stok`, bisa dicentang per keterangan dari menu Master.
--
-- NILAI AWALNYA SENGAJA SAMA DENGAN PERILAKU LAMA
-- Hanya "Lengkap" yang dicentang. Mencentang yang lain adalah keputusan
-- gudang, bukan keputusan migrasi ini — dan begitu dicentang, retur lama
-- dengan keterangan itu ikut disusulkan ke barang masuk oleh
-- api/keterangan/save.php, dalam satu transaksi.
--
-- Kolomnya ada untuk semua jenis keterangan supaya tabelnya seragam, tapi
-- yang membacanya hanya retur. Barang masuk dan barang keluar arah stoknya
-- sudah ditentukan oleh tabelnya sendiri.
--
-- Jalankan SETELAH 013_keterangan_retur.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: keterangan.tambah_stok

ALTER TABLE keterangan
  ADD COLUMN tambah_stok TINYINT(1) NOT NULL DEFAULT 0 AFTER terkunci;

UPDATE keterangan
   SET tambah_stok = 1
 WHERE jenis = 'retur' AND nama = 'Lengkap';
