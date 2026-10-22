-- ============================================================================
-- 023_deadstok.sql
--
-- Barang deadstok: barang yang ADA di gudang tapi TIDAK BISA DIJUAL.
--
-- Dua kategori, sesuai permintaan gudang:
--   rusak       Barang Rusak
--   belum_siap  Barang Belum Siap Jual (belum dipacking, belum dilabeli, ...)
--
-- MENGAPA INI TIDAK MENGURANGI STOK
-- Barang rusak dan barang yang belum siap jual masih berada di rak. Stok
-- opname menghitung rak. Kalau deadstok langsung mengurangi stok_akhir, maka
-- setiap opname akan melaporkan selisih palsu tepat pada barang-barang itu —
-- dan justru laporan itulah yang paling dipercaya gudang.
--
-- Yang sebenarnya dibutuhkan gudang bukan "stok berkurang", melainkan "dari
-- sekian stok, berapa yang benar-benar bisa dijual". Angka itu dihitung saat
-- ditampilkan:
--
--   siap jual = stok_akhir - SUM(deadstok yang masih tertahan)
--
-- jadi tidak ada baris ledger palsu, dan opname tetap cocok dengan rak.
--
-- TIGA KEADAAN
--   tertahan  masih deadstok; ikut mengurangi angka siap jual
--   beres     sudah diperbaiki / sudah siap jual; stok TIDAK bergerak, karena
--             barangnya memang tidak pernah ke mana-mana
--   dibuang   barangnya benar-benar dibuang; BARU di sini stok berkurang,
--             lewat satu baris barang_keluar berketerangan "Deadstok Dibuang"
--
-- Hanya "dibuang" yang menyentuh stok, dan sentuhnya lewat ledger biasa —
-- bukan lewat angka yang dikarang — supaya Riwayat, Dashboard, dan opname
-- melihat hal yang sama. Tautannya disimpan di kolom keluar_id, sama seperti
-- retur.masuk_id, supaya pembatalannya bisa menarik kembali baris itu.
--
-- Jalankan SETELAH 022_retur_semua_tambah_stok.sql.
-- ============================================================================

SET NAMES utf8mb4;

-- @lewati-jika-tabel: deadstok

CREATE TABLE IF NOT EXISTS deadstok (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,

  master_id  INT UNSIGNED NULL,
  barcode    VARCHAR(50)  NOT NULL DEFAULT '',
  sku        VARCHAR(50)  NOT NULL DEFAULT '',
  nama       VARCHAR(255) NOT NULL DEFAULT '',
  jumlah     INT          NOT NULL DEFAULT 1,

  -- Dipaku di skema, bukan tabel yang bisa dikelola: keduanya diminta gudang
  -- sebagai dua hal yang berbeda, dan kode di atasnya memperlakukan keduanya
  -- berbeda pula. Daftar yang bisa diubah sendiri hanya membuka peluang nama
  -- baru yang tidak ada artinya bagi kode.
  kategori   ENUM('rusak','belum_siap') NOT NULL DEFAULT 'rusak',
  keterangan VARCHAR(255) NOT NULL DEFAULT '',

  status     ENUM('tertahan','beres','dibuang') NOT NULL DEFAULT 'tertahan',

  -- Baris barang_keluar yang dihasilkan bila statusnya "dibuang". Sengaja
  -- TIDAK dikosongkan saat statusnya dikembalikan, supaya baris yang sama
  -- bisa dipakai ulang dan tidak melahirkan sampah terhapus bertumpuk —
  -- persis alasan yang sama dengan retur.masuk_id.
  keluar_id  INT UNSIGNED NULL,

  selesai_at   TIMESTAMP    NULL DEFAULT NULL,
  selesai_user INT UNSIGNED NULL DEFAULT NULL,

  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,

  KEY idx_tanggal  (tanggal),
  KEY idx_master   (master_id),
  -- Penyaring yang paling sering dipakai layar: kategori + status, dengan
  -- deleted_at ikut supaya barisnya tidak perlu diambil hanya untuk dibuang.
  KEY idx_kategori (kategori, status, deleted_at),
  -- Dipakai subquery "berapa yang masih tertahan per barang".
  KEY idx_tahan    (status, deleted_at, master_id),
  KEY idx_keluar   (keluar_id),
  CONSTRAINT fk_deadstok_master  FOREIGN KEY (master_id)    REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_deadstok_keluar  FOREIGN KEY (keluar_id)    REFERENCES barang_keluar(id) ON DELETE SET NULL,
  CONSTRAINT fk_deadstok_user    FOREIGN KEY (user_id)      REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT fk_deadstok_selesai FOREIGN KEY (selesai_user) REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_deadstok_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Keterangan barang keluar untuk deadstok yang dibuang.
--
-- Terkunci, dengan alasan yang sama seperti "Retur Masuk": namanya disebut
-- apa adanya oleh includes/deadstok.php. Kalau namanya diganti atau barisnya
-- dihapus, baris barang keluar hasil pembuangan berhenti lolos validasi dan
-- pembuangan gagal tanpa ada yang memberi tahu sebabnya.
INSERT INTO keterangan (jenis, nama, catatan, urutan, terkunci) VALUES
  ('keluar', 'Deadstok Dibuang', 'Dipakai sistem saat deadstok ditandai dibuang', 35, 1)
ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), terkunci = VALUES(terkunci);
