-- ============================================================================
-- 022_retur_semua_tambah_stok.sql
--
-- Seluruh keterangan retur mengembalikan barang ke stok.
--
-- SEBABNYA
-- Migrasi 020 sengaja memulai dengan perilaku lama: hanya "Lengkap" yang
-- menambah stok, dan sisanya diserahkan pada keputusan gudang. Keputusan itu
-- sudah diambil — gudang meminta SELURUH retur langsung masuk stok, karena
-- menunggu seseorang mengubahnya satu per satu justru yang menghambat.
--
-- Jadi semua keterangan retur yang aktif ditandai di sini, sekali saja.
-- Sesudahnya tidak ada lagi yang perlu diatur: keterangan retur yang baru
-- dibuat pun bawaannya menambah stok (lihat api/keterangan/save.php).
--
-- YANG TIDAK DILAKUKAN DI SINI
-- Retur yang SUDAH tercatat tidak ikut disusulkan oleh migrasi ini. Tiap
-- retur perlu baris barang masuk miliknya sendiri beserta tautannya, dan
-- menebak tautan itu lewat SQL massal berisiko menyambungkan baris yang
-- salah. Penyusulannya dilakukan tombol "Masukkan semua ke stok" di halaman
-- Retur, yang memakai jalur yang sama dengan pencatatan biasa dan aman
-- diulang.
--
-- Masih bisa dibatalkan per keterangan lewat Master -> Keterangan retur;
-- returnya akan ditarik kembali dari stok.
--
-- Jalankan SETELAH 020_keterangan_tambah_stok.sql.
-- ============================================================================

SET NAMES utf8mb4;

UPDATE keterangan
   SET tambah_stok = 1
 WHERE jenis = 'retur' AND aktif = 1 AND deleted_at IS NULL;
