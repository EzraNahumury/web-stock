-- ============================================================================
-- 024_opname_stok_tutup.sql
--
-- Laporan stok opname yang sudah DITUTUP tidak boleh berubah angkanya lagi.
--
-- MASALAHNYA
-- Kolom "Stok akhir" di laporan opname menampilkan stok yang BERLAKU SEKARANG.
-- Itu benar selama sesinya masih berjalan: penyesuaian stok membawa stok hidup
-- ke angka hitungan fisik, jadi selisih yang ditampilkan harus dihitung
-- terhadap stok hidup pula — kalau tidak, angka di layar bukan angka yang
-- dikerjakan tombolnya.
--
-- Tapi begitu sesinya ditutup, laporannya berubah sifat: ia jadi dokumen
-- bulan itu. Membukanya bulan depan dan menemukan angkanya sudah bergeser
-- membuatnya tidak bisa dipakai menelusuri selisih antarbulan — padahal
-- justru itu gunanya disimpan.
--
-- YANG DILAKUKAN KOLOM INI
-- stok_tutup menyimpan stok yang berlaku PADA SAAT sesinya ditandai selesai.
-- Sesudah itu laporannya membaca angka beku ini, bukan stok hidup.
--
--   sesi berjalan (draft)  stok_tutup NULL  -> dibaca stok hidup
--   sesi ditutup           stok_tutup diisi -> dibaca angka beku
--   sesi dibuka lagi       stok_tutup NULL  -> kembali ke stok hidup
--
-- Karena itu seluruh pembacanya cukup memakai satu bentuk yang sama:
--
--   COALESCE(i.stok_tutup, <stok hidup>)
--
-- tanpa perlu memeriksa status sesinya di tiap tempat — satu cabang yang
-- terlupa di salah satu pembaca sudah cukup untuk membuat angkanya berbeda
-- antara layar dan laporan.
--
-- BEDANYA DENGAN stok_sistem
-- stok_sistem adalah salinan stok saat sesi DIBUAT, yaitu sebelum dihitung.
-- stok_tutup adalah stok saat sesi DITUTUP, yaitu sesudah koreksinya ditulis.
-- Keduanya disimpan: yang pertama menerangkan dari mana berangkat, yang kedua
-- menerangkan di mana berhenti.
--
-- Jalankan SETELAH 023_deadstok.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: opname_item.stok_tutup

ALTER TABLE opname_item
  ADD COLUMN stok_tutup INT NULL DEFAULT NULL AFTER stok_sistem;
