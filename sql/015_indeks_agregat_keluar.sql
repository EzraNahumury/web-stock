-- ============================================================================
-- 015_indeks_agregat_keluar.sql
--
-- Indeks penutup untuk penjumlahan stok keluar.
--
-- Dashboard menghitung stok akhir tiap barang dengan menjumlahkan seluruh
-- barang masuk dan barang keluar per barang. Indeks yang ada hanya memuat
-- (master_id, deleted_at), jadi mesin basis data masih harus membuka baris
-- tabelnya satu per satu hanya untuk mengambil kolom `jumlah`.
--
-- Dengan `jumlah` ikut di dalam indeks, penjumlahannya selesai di indeks saja.
-- Diukur di MySQL 8 atas 200.000 baris barang keluar dan 50.000 barang masuk:
-- satu query Dashboard turun dari ~250 ms menjadi ~78 ms, dan hasilnya sama
-- persis.
--
-- Bukan sekadar soal cepat. Di hosting bersama, query yang lama memperbesar
-- peluang permintaan ditutup di tengah jalan oleh batas waktu atau batas
-- koneksi — dan itulah yang terbaca sebagai "Terjadi kesalahan di server"
-- yang datang sesekali saat menyaring atau mencari.
--
-- SATU INDEKS SATU BERKAS
-- ALTER TABLE tidak bisa dibatalkan setengah jalan. Kalau dua indeks ditaruh
-- dalam satu berkas lalu yang kedua terputus karena batas waktu, berkasnya
-- tidak tercatat selesai, dan percobaan berikutnya akan menabrak indeks
-- pertama yang sudah terlanjur ada. Dipisah begini, masing-masing punya
-- penjaganya sendiri dan aman diulang.
--
-- Jalankan SETELAH 001_schema.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-indeks: barang_keluar.idx_agregat

ALTER TABLE barang_keluar ADD INDEX idx_agregat (master_id, deleted_at, jumlah);
