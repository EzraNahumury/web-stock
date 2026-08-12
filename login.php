<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

mulaiSesi();

if (sudahLogin()) {
    header('Location: index.php');
    exit;
}

$galat = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', is_string($token) ? $token : '')) {
        $galat = 'Token keamanan tidak valid. Coba lagi.';
    } else {
        $username = ambilStr($_POST, 'username', 50);
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $galat = 'Username dan password wajib diisi.';
        } elseif (cobaLogin($username, $password) !== null) {
            header('Location: index.php');
            exit;
        } else {
            $galat = 'Username atau password salah.';
            usleep(400000);   // perlambat percobaan beruntun
        }
    }
}

$csrf = csrfToken();
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — <?= e(APP_NAMA) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#EAEFF1; --card:#FFFFFF; --ink:#101A20; --slate:#5C6E76;
    --line:#DAE2E5; --lineStrong:#C1CCD0;
    --danger:#B23A2E; --dangerBg:#FAE7E4; --dangerLine:#E8B3AA;
  }
  *{ box-sizing:border-box; }
  html,body{ margin:0; padding:0; background:var(--paper); min-height:100%; }
  body{ font-family:'Inter',sans-serif; color:var(--ink); display:flex; align-items:center;
        justify-content:center; min-height:100vh; padding:20px; }
  .box{ background:var(--card); border:1px solid var(--line); border-radius:12px;
        padding:28px; width:100%; max-width:360px; }
  .eyebrow{ font-size:11.5px; font-weight:600; letter-spacing:.12em; color:var(--slate);
        text-transform:uppercase; margin-bottom:2px; }
  h1{ font-family:'Barlow Condensed',sans-serif; font-weight:800; font-size:27px;
      margin:0 0 20px; line-height:1.05; }
  label{ display:block; font-size:11.5px; font-weight:600; color:var(--slate);
         margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
  input{ font-family:'Inter'; font-size:14px; color:var(--ink); background:#fff;
         border:1px solid var(--lineStrong); border-radius:6px; padding:9px 11px;
         outline:none; width:100%; margin-bottom:14px; }
  input:focus{ border-color:var(--ink); }
  button{ font-family:'Inter'; font-size:13.5px; font-weight:600; border-radius:6px;
          padding:10px 14px; cursor:pointer; border:1px solid var(--ink);
          background:var(--ink); color:#fff; width:100%; }
  .galat{ background:var(--dangerBg); border:1px solid var(--dangerLine); color:var(--danger);
          border-radius:6px; padding:9px 12px; font-size:12.5px; font-weight:500; margin-bottom:14px; }
  .catatan{ margin-top:16px; font-size:11.5px; color:var(--slate); line-height:1.5; }
</style>
</head>
<body>
  <form class="box" method="post" action="login.php" autocomplete="off">
    <div class="eyebrow">Papan kendali gudang</div>
    <h1><?= e(APP_NAMA) ?></h1>

    <?php if ($galat !== ''): ?>
      <div class="galat"><?= e($galat) ?></div>
    <?php endif; ?>

    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label for="username">Username</label>
    <input type="text" id="username" name="username" required autofocus
           value="<?= e($_POST['username'] ?? '') ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Masuk</button>

    <?php if (APP_ENV === 'lokal'): ?>
      <div class="catatan">
        Lingkungan lokal. Akun awal <b>admin</b> / <b>admin123</b> —
        ganti passwordnya sebelum aplikasi dipakai sungguhan.
      </div>
    <?php endif; ?>
  </form>
</body>
</html>
