-- ============================================================================
-- 005_indeks_aktivitas.sql
--
-- Halaman Log aktivitas mengurutkan seluruh jejak menurut waktu dan
-- menyaringnya per rentang tanggal. Indeks yang ada hanya (user_id,
-- created_at), yang tidak menolong bila penyaringnya bukan pengguna
-- tertentu — tanpa indeks ini setiap pembukaan halaman memindai seluruh
-- tabel lalu mengurutkannya di memori.
--
-- Jalankan SETELAH 004_pertukaran.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- ADD INDEX tidak punya bentuk "IF NOT EXISTS" yang sama di MySQL dan
-- MariaDB, jadi keberadaannya diperiksa penerap migrasi lebih dulu.
-- @lewati-jika-indeks: activity_log.idx_waktu

ALTER TABLE activity_log ADD INDEX idx_waktu (created_at);
