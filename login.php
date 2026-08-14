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
<meta name="color-scheme" content="dark">
<title>Masuk — <?= e(APP_NAMA) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(aset('assets/css/login.css')) ?>">
</head>
<body>

<main class="masuk">

  <!-- ============ Panel kiri: identitas + ilustrasi rak ============ -->
  <section class="panel">
    <div class="merk tahap" style="--d:120ms">
      <div class="merk-tanda" aria-hidden="true">
        <!-- Tanda: label barcode. -->
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none">
          <rect x="3" y="4" width="2"  height="16" fill="currentColor"/>
          <rect x="7" y="4" width="1"  height="16" fill="currentColor" opacity=".65"/>
          <rect x="10" y="4" width="3" height="16" fill="currentColor"/>
          <rect x="15" y="4" width="1" height="16" fill="currentColor" opacity=".65"/>
          <rect x="18" y="4" width="2" height="16" fill="currentColor"/>
        </svg>
      </div>
      <div>
        <div class="merk-nama">Stok Fingertape<br>&amp; Perlengkapan</div>
        <div class="merk-sub">Papan kendali gudang</div>
      </div>
    </div>

    <!-- Ilustrasi: rak gudang dengan kotak berlabel barcode, disapu
         sinar pemindai. Menggantikan ilustrasi natal di referensi dengan
         sesuatu yang benar-benar menggambarkan pekerjaan di sini. -->
    <div class="gambar tahap" style="--d:220ms">
      <svg viewBox="0 0 300 250" role="img" aria-label="Rak gudang berisi kotak berlabel barcode yang sedang dipindai">
        <defs>
          <linearGradient id="gBlob" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%"  stop-color="#E4EFEA"/>
            <stop offset="100%" stop-color="#D3E4DC"/>
          </linearGradient>
          <linearGradient id="gSinar" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%"   stop-color="#E2543C" stop-opacity="0"/>
            <stop offset="50%"  stop-color="#E2543C" stop-opacity=".9"/>
            <stop offset="100%" stop-color="#E2543C" stop-opacity="0"/>
          </linearGradient>
        </defs>

        <!-- Blob latar, mengutip bentuk organik di referensi.
             Sengaja asimetris: menggembung di kiri-bawah, melandai di
             kanan-atas, supaya tidak terbaca sebagai lingkaran. -->
        <path d="M146 10c62 0 110 26 128 76 18 50 2 106-44 132-46 26-116 22-158-14C30 168 8 118 16 74 24 30 74 10 146 10z"
              fill="url(#gBlob)"/>
        <circle cx="46"  cy="46"  r="17" fill="#fff" opacity=".5"/>
        <circle cx="262" cy="196" r="24" fill="#fff" opacity=".34"/>
        <circle cx="252" cy="54"  r="10" fill="#fff" opacity=".5"/>
        <circle cx="30"  cy="150" r="8"  fill="#fff" opacity=".42"/>

        <!-- Tiang rak -->
        <rect x="62"  y="66" width="6" height="150" rx="2" fill="#1E313A" opacity=".85"/>
        <rect x="232" y="66" width="6" height="150" rx="2" fill="#1E313A" opacity=".85"/>
        <!-- Papan rak -->
        <rect x="56" y="124" width="188" height="6" rx="2" fill="#1E313A" opacity=".7"/>
        <rect x="56" y="176" width="188" height="6" rx="2" fill="#1E313A" opacity=".7"/>
        <rect x="56" y="212" width="188" height="7" rx="2" fill="#1E313A"/>

        <!-- Kotak baris atas -->
        <g>
          <rect x="80" y="92" width="46" height="32" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="86" y="99" width="2" height="13" fill="#1E313A" class="bar-hidup"/>
          <rect x="90" y="99" width="1" height="13" fill="#1E313A" opacity=".5"/>
          <rect x="93" y="99" width="3" height="13" fill="#1E313A" class="bar-hidup" style="animation-delay:.2s"/>
          <rect x="98" y="99" width="1" height="13" fill="#1E313A" opacity=".5"/>
          <rect x="86" y="115" width="26" height="3" rx="1.5" fill="#DAE2E5"/>

          <rect x="136" y="86" width="52" height="38" rx="3" fill="#25725C"/>
          <rect x="143" y="95" width="30" height="3" rx="1.5" fill="#fff" opacity=".55"/>
          <rect x="143" y="102" width="20" height="3" rx="1.5" fill="#fff" opacity=".35"/>
          <rect x="143" y="112" width="2" height="8" fill="#fff" opacity=".8" class="bar-hidup" style="animation-delay:.4s"/>
          <rect x="147" y="112" width="1" height="8" fill="#fff" opacity=".5"/>
          <rect x="150" y="112" width="3" height="8" fill="#fff" opacity=".8"/>

          <rect x="198" y="100" width="34" height="24" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="204" y="107" width="18" height="3" rx="1.5" fill="#DAE2E5"/>
          <rect x="204" y="113" width="12" height="3" rx="1.5" fill="#DAE2E5"/>
        </g>

        <!-- Kotak baris tengah -->
        <g>
          <rect x="72" y="146" width="56" height="30" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="79" y="153" width="2"  height="12" fill="#1E313A" class="bar-hidup" style="animation-delay:.6s"/>
          <rect x="83" y="153" width="3"  height="12" fill="#1E313A" opacity=".55"/>
          <rect x="88" y="153" width="1"  height="12" fill="#1E313A"/>
          <rect x="91" y="153" width="2"  height="12" fill="#1E313A" opacity=".55"/>
          <rect x="79" y="168" width="30" height="3" rx="1.5" fill="#DAE2E5"/>

          <rect x="138" y="152" width="42" height="24" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="145" y="159" width="22" height="3" rx="1.5" fill="#DAE2E5"/>
          <rect x="145" y="165" width="14" height="3" rx="1.5" fill="#DAE2E5"/>

          <!-- Kotak bertanda stok menipis -->
          <rect x="190" y="144" width="44" height="32" rx="3" fill="#FBF0DC" stroke="#EAC280"/>
          <path d="M212 152l7 12h-14l7-12z" fill="#C77F0E"/>
          <rect x="209.5" y="157" width="2" height="4" rx="1" fill="#FBF0DC"/>
          <rect x="209.5" y="162" width="2" height="2" rx="1" fill="#FBF0DC"/>
          <rect x="197" y="168" width="30" height="3" rx="1.5" fill="#EAC280" opacity=".6"/>
        </g>

        <!-- Kotak baris bawah -->
        <g>
          <rect x="76"  y="186" width="48" height="26" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="83"  y="193" width="24" height="3" rx="1.5" fill="#DAE2E5"/>
          <rect x="83"  y="199" width="16" height="3" rx="1.5" fill="#DAE2E5"/>
          <rect x="134" y="190" width="44" height="22" rx="3" fill="#25725C" opacity=".82"/>
          <rect x="141" y="197" width="22" height="3" rx="1.5" fill="#fff" opacity=".5"/>
          <rect x="188" y="184" width="46" height="28" rx="3" fill="#fff" stroke="#C1CCD0"/>
          <rect x="195" y="191" width="2"  height="11" fill="#1E313A" class="bar-hidup" style="animation-delay:.8s"/>
          <rect x="199" y="191" width="1"  height="11" fill="#1E313A" opacity=".5"/>
          <rect x="202" y="191" width="3"  height="11" fill="#1E313A"/>
          <rect x="207" y="191" width="1"  height="11" fill="#1E313A" opacity=".5"/>
        </g>

        <!-- Sinar pemindai: elemen penanda halaman -->
        <g class="sinar">
          <rect x="52" y="78" width="196" height="2" rx="1" fill="url(#gSinar)"/>
          <rect x="52" y="80" width="196" height="14" fill="url(#gSinar)" opacity=".14"/>
        </g>
      </svg>
    </div>

    <div class="panel-kaki tahap" style="--d:320ms">
      <h2>Setiap kotak terhitung</h2>
      <p>Catat barang masuk dan keluar, impor picking list dari PDF, dan lihat stok mana yang perlu diorder.</p>
    </div>
  </section>

  <!-- ============ Panel kanan: formulir ============ -->
  <section class="form-sisi" id="sisiForm">
    <h1 class="judul" id="judul" aria-label="Masuk">
      <!-- Diisi split-text oleh JS; teks dasar tetap ada untuk tanpa-JS. -->
      Masuk
    </h1>
    <p class="judul-sub tahap" style="--d:420ms">Masuk untuk mengelola stok gudang.</p>

    <?php if ($galat !== ''): ?>
      <div class="galat" role="alert">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16.5" x2="12.01" y2="16.5"/>
        </svg>
        <span><?= e($galat) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="on" id="formMasuk">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

      <div class="ruas tahap" style="--d:480ms">
        <input type="text" id="username" name="username" placeholder=" "
               required autofocus autocomplete="username"
               value="<?= e($_POST['username'] ?? '') ?>">
        <label for="username">Username</label>
      </div>

      <div class="ruas ada-ikon tahap" style="--d:540ms">
        <input type="password" id="password" name="password" placeholder=" "
               required autocomplete="current-password">
        <label for="password">Password</label>
        <button type="button" class="lihat" id="tglPassword"
                aria-label="Tampilkan password" aria-pressed="false">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="ikonMata">
            <path d="M1.5 12s4-7.5 10.5-7.5S22.5 12 22.5 12s-4 7.5-10.5 7.5S1.5 12 1.5 12z"/>
            <circle cx="12" cy="12" r="3.2"/>
          </svg>
        </button>
      </div>

      <div class="capslock" id="capslock" hidden>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M12 4l8 8h-5v5H9v-5H4l8-8z"/>
        </svg>
        Caps Lock menyala
      </div>

      <button type="submit" class="kirim tahap" id="tblMasuk" style="--d:600ms">
        <span class="teks">Masuk</span>
        <span class="putar" aria-hidden="true"></span>
      </button>
    </form>

    <div class="form-kaki tahap" style="--d:660ms">
      <span>v<?= e(APP_VERSI) ?></span>
      <span class="lencana"><?= e(APP_ENV) ?></span>
    </div>
  </section>

</main>

<script>
(function(){
  "use strict";

  var kurangiGerak = window.matchMedia
    && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* --- Split text pada judul: tiap huruf beranimasi bertahap. --------- */
  var judul = document.getElementById("judul");
  if(judul && !kurangiGerak){
    var teks = judul.textContent.trim();
    judul.textContent = "";
    teks.split("").forEach(function(huruf, i){
      var s = document.createElement("span");
      if(huruf === " "){
        s.className = "spasi";
      } else {
        s.textContent = huruf;
      }
      s.style.setProperty("--d", (260 + i * 45) + "ms");
      judul.appendChild(s);
    });
  }

  /* --- Sorot mengikuti kursor. --------------------------------------- */
  var sisi = document.getElementById("sisiForm");
  if(sisi && !kurangiGerak && window.matchMedia("(hover: hover)").matches){
    sisi.addEventListener("pointermove", function(e){
      var r = sisi.getBoundingClientRect();
      sisi.style.setProperty("--mx", (e.clientX - r.left) + "px");
      sisi.style.setProperty("--my", (e.clientY - r.top) + "px");
      sisi.classList.add("sorot");
    });
    sisi.addEventListener("pointerleave", function(){
      sisi.classList.remove("sorot");
    });
  }

  /* --- Label mengambang: tandai ruas yang sudah terisi. --------------- */
  function segarkanTerisi(inp){
    if(!inp) return;
    inp.parentNode.classList.toggle("terisi", inp.value !== "");
  }
  ["username","password"].forEach(function(id){
    var inp = document.getElementById(id);
    if(!inp) return;
    segarkanTerisi(inp);
    inp.addEventListener("input", function(){ segarkanTerisi(inp); });
  });

  /* --- Tampilkan / sembunyikan password. ------------------------------ */
  var tgl = document.getElementById("tglPassword");
  var pwd = document.getElementById("password");
  var ikon = document.getElementById("ikonMata");
  if(tgl && pwd && ikon){
    var MATA = '<path d="M1.5 12s4-7.5 10.5-7.5S22.5 12 22.5 12s-4 7.5-10.5 7.5S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3.2"/>';
    var CORET = '<path d="M9.9 5.1A10.5 10.5 0 0 1 12 4.9c6.5 0 10.5 7.1 10.5 7.1a19 19 0 0 1-3.6 4.6M6.2 6.7A19 19 0 0 0 1.5 12s4 7.1 10.5 7.1c2 0 3.7-.6 5.2-1.5"/><line x1="3" y1="3" x2="21" y2="21"/>';
    tgl.addEventListener("click", function(){
      var tampil = pwd.type === "password";
      pwd.type = tampil ? "text" : "password";
      ikon.innerHTML = tampil ? CORET : MATA;
      tgl.setAttribute("aria-pressed", tampil ? "true" : "false");
      tgl.setAttribute("aria-label", tampil ? "Sembunyikan password" : "Tampilkan password");
      pwd.focus();
    });
  }

  /* --- Peringatan Caps Lock. ------------------------------------------ */
  var caps = document.getElementById("capslock");
  if(caps && pwd){
    function cekCaps(e){
      if(!e.getModifierState) return;
      caps.hidden = !e.getModifierState("CapsLock");
    }
    pwd.addEventListener("keydown", cekCaps);
    pwd.addEventListener("keyup", cekCaps);
    pwd.addEventListener("blur", function(){ caps.hidden = true; });
  }

  /* --- Keadaan mengirim.
     Formulir tetap dikirim secara biasa (bukan fetch) — alur POST/redirect
     dan CSRF-nya sengaja tidak diubah. Ini hanya penanda visual. ------- */
  var form = document.getElementById("formMasuk");
  var tbl  = document.getElementById("tblMasuk");
  if(form && tbl){
    form.addEventListener("submit", function(){
      // Biarkan validasi bawaan browser berjalan lebih dulu.
      if(!form.checkValidity()) return;
      tbl.classList.add("sibuk");
    });
  }
})();
</script>

</body>
</html>
