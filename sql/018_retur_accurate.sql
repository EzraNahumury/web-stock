-- ============================================================================
-- 018_retur_accurate.sql
--
-- Penanda: retur ini sudah diinput ke Accurate atau belum.
--
-- Accurate adalah pembukuan yang dipakai di luar aplikasi ini. Retur yang
-- sudah dicatat di gudang masih harus dimasukkan ke sana satu per satu, dan
-- selama ini tidak ada tempat untuk menandai mana yang sudah. Akibatnya
-- pengecekannya dilakukan dari ingatan, dan baris yang sama bisa terinput dua
-- kali atau terlewat sama sekali.
--
-- Tiga kolom, bukan satu: selain tandanya, dicatat juga KAPAN dan OLEH SIAPA.
-- Tanda tanpa jejak tidak bisa ditelusuri ketika angkanya nanti berbeda
-- dengan yang ada di Accurate.
--
-- Penanda ini TIDAK menyentuh stok. Yang menambah stok tetap keterangan
-- returnya (lihat STATUS_RETUR_MASUK); ini murni catatan pembukuan.
--
-- Jalankan SETELAH 006_retur.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-kolom: retur.accurate

ALTER TABLE retur
  ADD COLUMN accurate      TINYINT(1)   NOT NULL DEFAULT 0 AFTER keterangan,
  ADD COLUMN accurate_at   TIMESTAMP    NULL     DEFAULT NULL AFTER accurate,
  ADD COLUMN accurate_user INT UNSIGNED NULL     DEFAULT NULL AFTER accurate_at,
  ADD INDEX idx_accurate (accurate, deleted_at);
