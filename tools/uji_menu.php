<?php
/**
 * uji_menu.php — pemeriksaan sebelum commit untuk menu dan hak akses.
 *
 * BAGIAN 1 — setiap menu punya fungsi penggambarnya
 * Satu penyuntingan pernah menghapus seluruh blok "Log aktivitas" dari
 * app.js. TABS-nya tetap ada, jadi menunya tetap tampil; yang terjadi saat
 * diklik hanya ReferenceError di konsol, judul halaman berganti, dan isi
 * halaman sebelumnya tetap terpampang — terlihat seperti halaman yang
 * "salah isi", bukan seperti berkas yang hilang. Tidak ada yang gagal dengan
 * berisik, jadi lolos sampai dipakai.
 *
 * BAGIAN 2 — setiap endpoint API terdaftar di peta izin
 * includes/izin.php menolak endpoint yang tidak terdaftar, jadi endpoint
 * baru yang lupa didaftarkan akan mati dengan galat 500. Lebih baik
 * ketahuan di sini daripada di tangan pemakai.
 *
 * Keduanya murni membaca teks: tanpa Node, tanpa browser, tanpa paket
 * tambahan, tanpa menyentuh database. Jalankan sebelum commit:
 *
 *   php tools\uji_menu.php
 *
 * Keluar dengan kode 1 bila ada yang tidak cocok, supaya bisa dipasang di
 * CI atau git hook kapan pun diperlukan.
 */

declare(strict_types=1);

$akar  = dirname(__DIR__);
$galat = [];

/* ====================================================================== */
/* Bagian 1 — menu vs fungsi penggambar                                   */
/* ====================================================================== */

$jalur = $akar . '/assets/js/app.js';
$isi   = file_get_contents($jalur);
if ($isi === false) {
    fwrite(STDERR, "Tidak bisa membaca $jalur\n");
    exit(1);
}

// id menu boleh memuat garis bawah (mis. ket_masuk).
preg_match_all('/\{\s*id:"([a-z_]+)"/', $isi, $m);
$tabs = array_unique($m[1]);
if (!$tabs) {
    fwrite(STDERR, "TABS tidak ditemukan di app.js — pola pembacaannya perlu diperbarui.\n");
    exit(1);
}

$awal = strpos($isi, 'function renderContent()');
if ($awal === false) {
    fwrite(STDERR, "renderContent() tidak ditemukan.\n");
    exit(1);
}
$blok = substr($isi, $awal, 1600);
preg_match_all('/tab\s*===\s*"([a-z_]+)"\)\s*([A-Za-z0-9_]+)\(/', $blok, $d);
$dispatch = array_combine($d[1], $d[2]);

preg_match_all('/^(?:async\s+)?function\s+([A-Za-z0-9_]+)\s*\(/m', $isi, $f);
$adaFungsi = array_flip($f[1]);

foreach ($tabs as $tab) {
    if (!isset($dispatch[$tab])) {
        $galat[] = "Menu \"$tab\" ada di TABS tapi tidak ada cabangnya di renderContent().";
        continue;
    }
    if (!isset($adaFungsi[$dispatch[$tab]])) {
        $galat[] = "Menu \"$tab\" memanggil {$dispatch[$tab]}(), tapi fungsi itu tidak ada di app.js.";
    }
}
foreach ($dispatch as $tab => $fn) {
    if (!in_array($tab, $tabs, true)) {
        $galat[] = "renderContent() menangani \"$tab\", tapi menunya tidak ada di TABS.";
    }
}

echo "--- Menu ---\n";
echo 'Menu di TABS     : ' . count($tabs) . ' (' . implode(', ', $tabs) . ")\n";
echo 'Cabang di router : ' . count($dispatch) . "\n";

/* ====================================================================== */
/* Bagian 2 — endpoint API vs peta izin                                   */
/* ====================================================================== */

require_once $akar . '/includes/izin.php';

$peta = petaEndpoint();

$berkas = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar . '/api'));
foreach ($iter as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($akar . '/api/')));
        $berkas[] = $rel;
    }
}
sort($berkas);

foreach ($berkas as $rel) {
    if (!isset($peta[$rel])) {
        $galat[] = "Endpoint api/$rel belum terdaftar di petaEndpoint() — akan ditolak 500.";
    }
}
foreach (array_keys($peta) as $rel) {
    if (!in_array($rel, $berkas, true)) {
        $galat[] = "petaEndpoint() menyebut api/$rel, tapi berkasnya tidak ada.";
    }
}

// Menu yang disebut peta harus benar-benar ada di daftar menu.
$menuSah = array_merge(array_keys(menuIzin()), menuAdminSaja(), ['@ekspor', '@keterangan']);
foreach ($peta as $rel => $baris) {
    if ($baris[0] !== null && !in_array($baris[0], $menuSah, true)) {
        $galat[] = "api/$rel butuh menu \"{$baris[0]}\" yang tidak ada di menuIzin().";
    }
}

echo "\n--- Izin ---\n";
echo 'Endpoint di api/ : ' . count($berkas) . "\n";
echo 'Baris peta izin  : ' . count($peta) . "\n";
echo 'Menu bisa diberi : ' . count(menuIzin()) . ' (' . implode(', ', array_keys(menuIzin())) . ")\n";
echo 'Menu bawaan      : ' . implode(', ', menuBawaan()) . "\n";

/* ====================================================================== */
echo "\n";
if ($galat) {
    foreach ($galat as $g) {
        echo "GAGAL: $g\n";
    }
    exit(1);
}

echo "OK — menu dan peta izin sudah cocok.\n";
