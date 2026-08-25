-- ============================================================================
-- 009_akses_pengguna.sql
--
-- Hak akses per akun:
--
--   role = 'viewer'   akun yang boleh melihat tapi tidak boleh mengubah
--                     apa pun. Seluruh endpoint yang menulis ditolak untuk
--                     peran ini, bukan sekadar tombolnya disembunyikan.
--
--   akses             daftar menu yang boleh dibuka akun ini, disimpan
--                     sebagai JSON array id menu. NULL atau array kosong
--                     berarti "semua menu yang boleh diberikan".
--
-- Menu Pengguna sengaja TIDAK bisa diberikan lewat kolom ini. Akun non-admin
-- yang bisa mengelola pengguna dapat membuat akun admin baru untuk dirinya
-- sendiri, jadi pengelolaan akun tetap milik admin saja.
--
-- Jalankan SETELAH 008_opname_penyesuaian.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: users.akses

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  ADD COLUMN akses TEXT NULL AFTER role;
