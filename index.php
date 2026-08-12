<?php
/**
 * index.php — halaman utama Papan Kendali Gudang.
 *
 * Hanya merender kerangka dan menyuntikkan token CSRF. Seluruh isi tab
 * dirender assets/js/app.js dari data endpoint API.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

wajibLoginHalaman();

$user = userSaatIni();
$csrf = csrfToken();
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAMA) ?> — Papan Kendali Gudang</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSI) ?>">
</head>
<body>
<div id="app">
  <div class="header">
    <div class="barcode-stripe" id="barcodeStripe"></div>
    <div style="position:relative; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px;">
      <div>
        <div class="eyebrow">Papan kendali gudang</div>
        <h1 class="title"><?= e(APP_NAMA) ?></h1>
      </div>
      <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
        <div class="userbar">
          <span><b><?= e($user['nama_lengkap']) ?></b> · <?= e($user['role']) ?></span>
          <a href="logout.php">Keluar</a>
        </div>
        <div class="save-status" id="saveStatus">Memuat…</div>
      </div>
    </div>
  </div>

  <div class="tabs" id="tabs"></div>
  <div class="content" id="content">
    <div style="padding:60px; text-align:center; color:var(--slate); font-size:13.5px;">Memuat data…</div>
  </div>
</div>
<div id="toastWrap"></div>

<script>
  window.CSRF_TOKEN = <?= json_encode($csrf) ?>;
  window.APP_USER   = <?= json_encode($user, JSON_UNESCAPED_UNICODE) ?>;
  // Sumber tunggal: config/config.php. Jangan disalin ulang di app.js.
  window.KATEGORI_OPTIONS = <?= json_encode(KATEGORI_OPTIONS, JSON_UNESCAPED_UNICODE) ?>;
  window.KET_MASUK        = <?= json_encode(KET_MASUK, JSON_UNESCAPED_UNICODE) ?>;
  window.KET_KELUAR       = <?= json_encode(KET_KELUAR, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/vendor/pdf.min.js"></script>
<script src="assets/js/pdf-parser.js?v=<?= e(APP_VERSI) ?>"></script>
<script src="assets/js/api.js?v=<?= e(APP_VERSI) ?>"></script>
<script src="assets/js/app.js?v=<?= e(APP_VERSI) ?>"></script>
</body>
</html>
