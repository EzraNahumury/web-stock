-- ============================================================================
-- 021_galat_endpoint_panjang.sql
--
-- Kolom endpoint pada catatan galat dilebarkan, supaya parameter penyaringnya
-- ikut muat.
--
-- Sebelumnya hanya jalurnya yang dicatat: "dashboard/stats.php". Ketika gudang
-- melaporkan galat yang hanya muncul pada kombinasi penyaring tertentu,
-- kombinasinya tetap harus ditebak. Sekarang query string ikut disimpan —
-- "dashboard/stats.php?kategori=FASHION&status=rendah" — dan 100 karakter
-- tidak cukup untuk itu.
--
-- Jalankan SETELAH 017_galat_sistem.sql.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE galat_sistem MODIFY endpoint VARCHAR(255) NOT NULL DEFAULT '';
