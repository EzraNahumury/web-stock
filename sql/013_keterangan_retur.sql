-- ============================================================================
-- 013_keterangan_retur.sql
--
-- Keterangan retur ikut dikelola dari menu Master.
--
-- Isi dropdown "Keterangan retur" sebelumnya dipaku di config/config.php
-- sebagai STATUS_RETUR, jadi menambah satu pilihan berarti menyunting berkas
-- dan deploy ulang. Sekarang tersimpan di tabel keterangan yang sama dengan
-- keterangan barang masuk dan barang keluar, dengan jenis 'retur'.
--
-- "Lengkap" DITANDAI TERKUNCI
-- Satu nilai di daftar ini bukan sekadar label: retur yang berketerangan
-- "Lengkap" menambah stok lewat baris barang masuk. Nilai itu disebut di
-- config sebagai STATUS_RETUR_MASUK dan dibandingkan apa adanya oleh
-- includes/retur.php. Kalau namanya diganti atau barisnya dihapus, retur
-- berhenti menambah stok tanpa ada yang memberi tahu — jadi barisnya dikunci,
-- persis seperti "Retur Masuk" pada keterangan barang masuk.
--
-- Jalankan SETELAH 010_keterangan.sql.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE keterangan
  MODIFY jenis ENUM('masuk','keluar','retur') NOT NULL;

-- Isi awal = STATUS_RETUR di config.php, supaya tidak ada retur yang
-- keterangannya mendadak hilang dari dropdown.
INSERT INTO keterangan (jenis, nama, catatan, urutan, terkunci) VALUES
  ('retur', 'Lengkap',              'Retur selesai; barangnya kembali menambah stok', 10, 1),
  ('retur', 'Sistem Belum Selesai', 'Retur tercatat tapi belum menambah stok',        20, 0)
ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), urutan = VALUES(urutan), terkunci = VALUES(terkunci);

-- Jaring pengaman: keterangan yang sudah terpakai di retur tapi belum
-- terdaftar (misal dari data lama) ikut didaftarkan, supaya dropdown tidak
-- pernah kehilangan nilai yang sudah dipakai data.
INSERT IGNORE INTO keterangan (jenis, nama, catatan, urutan)
SELECT DISTINCT 'retur', r.status, 'Ditemukan dari data lama', 900
  FROM retur r
 WHERE r.status <> '';
