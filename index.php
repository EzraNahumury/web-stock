<?php
/**
 * index.php — halaman utama Warehouse AVA.
 *
 * Hanya merender kerangka (sidebar, bilah atas, wadah isi) dan menyuntikkan
 * token CSRF. Seluruh isi panel dirender assets/js/app.js dari endpoint API.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
// Terapkan migrasi database yang belum dijalankan. Aman dipanggil tiap
// permintaan: yang sudah pernah diterapkan dicatat dan tidak diulang.
require_once __DIR__ . '/includes/migrasi.php';
$statusMigrasi = jalankanMigrasi();
if ($statusMigrasi['galat'] !== null) {
    http_response_code(500);
    exit('Pembaruan struktur database gagal. Periksa log server.');
}

wajibLoginHalaman();

$user = userSaatIni();
$csrf = csrfToken();

/** Inisial untuk avatar — dua huruf pertama dari nama. */
$inisial = '';
foreach (preg_split('/\s+/', trim($user['nama_lengkap'])) as $kata) {
    if ($kata !== '' && mb_strlen($inisial) < 2) {
        $inisial .= mb_strtoupper(mb_substr($kata, 0, 1));
    }
}
if ($inisial === '') {
    $inisial = mb_strtoupper(mb_substr($user['username'], 0, 2));
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAMA) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(aset('assets/css/app.css')) ?>">
</head>
<body>

<div id="app">

  <!-- ================= Sidebar ================= -->
  <aside class="sisi" id="sisi">
    <div class="sisi-merk">
      <div class="merk-tanda" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
          <rect x="3" y="4" width="2" height="16" fill="currentColor"/>
          <rect x="7" y="4" width="1" height="16" fill="currentColor" opacity=".65"/>
          <rect x="10" y="4" width="3" height="16" fill="currentColor"/>
          <rect x="15" y="4" width="1" height="16" fill="currentColor" opacity=".65"/>
          <rect x="18" y="4" width="2" height="16" fill="currentColor"/>
        </svg>
      </div>
      <div class="merk-teks">
        <div class="merk-nama">Warehouse AVA</div>
        <div class="merk-sub">Kendali stok gudang</div>
      </div>
      <button type="button" class="sisi-tutup" id="sisiTutup" aria-label="Tutup menu">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <nav class="sisi-nav" id="sisiNav" aria-label="Menu utama"></nav>

    <div class="sisi-kaki">
      <div class="sisi-user">
        <div class="avatar" aria-hidden="true"><?= e($inisial) ?></div>
        <div class="sisi-user-teks">
          <div class="sisi-user-nama"><?= e($user['nama_lengkap']) ?></div>
          <div class="sisi-user-peran"><?= e($user['role']) ?></div>
        </div>
      </div>
      <a class="sisi-keluar" href="logout.php">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        Keluar
      </a>
    </div>
  </aside>
  <div class="sisi-tirai" id="sisiTirai" hidden></div>

  <!-- ================= Isi utama ================= -->
  <div class="utama">

    <header class="atas">
      <button type="button" class="sisi-buka" id="sisiBuka" aria-label="Buka menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
          <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>

      <div class="atas-judul">
        <h1 id="judulHalaman">Dashboard stok</h1>
        <p id="subJudulHalaman">Memuat…</p>
      </div>

      <div class="atas-aksi">
        <div class="simpan-status" id="saveStatus">Memuat…</div>
      </div>
    </header>

    <main class="isi" id="content">
      <div class="muat-awal">Memuat data…</div>
    </main>
  </div>

</div>

<div id="toastWrap"></div>

<script>
  window.CSRF_TOKEN = <?= json_encode($csrf) ?>;
  window.APP_USER   = <?= json_encode($user, JSON_UNESCAPED_UNICODE) ?>;
  window.APP_NAMA   = <?= json_encode(APP_NAMA, JSON_UNESCAPED_UNICODE) ?>;
  // Kategori dibaca dari tabel `kategori` (dikelola lewat menu Master).
  // Keterangan masuk/keluar tetap dari config/config.php.
  window.KATEGORI_OPTIONS = <?= json_encode(daftarKategori(), JSON_UNESCAPED_UNICODE) ?>;
  // Dibaca dari tabel keterangan, bukan dari konstanta: daftarnya dikelola
  // lewat menu Master dan bisa berubah tanpa deploy ulang.
  window.KET_MASUK        = <?= json_encode(daftarKeterangan('masuk'), JSON_UNESCAPED_UNICODE) ?>;
  window.KET_KELUAR       = <?= json_encode(daftarKeterangan('keluar'), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/vendor/pdf.min.js"></script>
<script src="<?= e(aset('assets/js/pdf-parser.js')) ?>"></script>
<script src="<?= e(aset('assets/js/api.js')) ?>"></script>
<script src="<?= e(aset('assets/js/grafik.js')) ?>"></script>
<script src="<?= e(aset('assets/js/app.js')) ?>"></script>
</body>
</html>
