<?php
/**
 * uji_menu.php — pastikan setiap menu punya fungsi penggambarnya.
 *
 * Latar: satu penyuntingan pernah menghapus seluruh blok "Log aktivitas"
 * dari app.js. TABS-nya tetap ada, jadi menunya tetap tampil; yang terjadi
 * saat diklik hanya ReferenceError di konsol, judul halaman berganti, dan
 * isi halaman sebelumnya tetap terpampang — terlihat seperti halaman yang
 * "salah isi", bukan seperti berkas yang hilang. Tidak ada yang gagal
 * dengan berisik, jadi lolos sampai dipakai.
 *
 * Pemeriksaan ini murni membaca teks app.js: tanpa Node, tanpa browser,
 * tanpa paket tambahan. Jalankan sebelum commit:
 *
 *   php tools\uji_menu.php
 *
 * Keluar dengan kode 1 bila ada yang tidak cocok, supaya bisa dipasang di
 * CI atau git hook kapan pun diperlukan.
 */

declare(strict_types=1);

$jalur = dirname(__DIR__) . '/assets/js/app.js';
$isi   = file_get_contents($jalur);
if ($isi === false) {
    fwrite(STDERR, "Tidak bisa membaca $jalur\n");
    exit(1);
}

/* --- 1. Kumpulkan id menu dari TABS -------------------------------------- */
preg_match_all('/\{\s*id:"([a-z]+)"/', $isi, $m);
$tabs = array_unique($m[1]);
if (!$tabs) {
    fwrite(STDERR, "TABS tidak ditemukan di app.js — pola pembacaannya perlu diperbarui.\n");
    exit(1);
}

/* --- 2. Kumpulkan pasangan tab -> fungsi dari renderContent() ------------- */
$awal = strpos($isi, 'function renderContent()');
if ($awal === false) {
    fwrite(STDERR, "renderContent() tidak ditemukan.\n");
    exit(1);
}
$blok = substr($isi, $awal, 1200);
preg_match_all('/tab\s*===\s*"([a-z]+)"\)\s*([A-Za-z0-9_]+)\(/', $blok, $d);
$dispatch = array_combine($d[1], $d[2]);

/* --- 3. Fungsi apa saja yang benar-benar didefinisikan -------------------- */
preg_match_all('/^(?:async\s+)?function\s+([A-Za-z0-9_]+)\s*\(/m', $isi, $f);
$adaFungsi = array_flip($f[1]);

/* --- 4. Bandingkan -------------------------------------------------------- */
$galat = [];

foreach ($tabs as $tab) {
    if (!isset($dispatch[$tab])) {
        $galat[] = "Menu \"$tab\" ada di TABS tapi tidak ada cabangnya di renderContent().";
        continue;
    }
    $fn = $dispatch[$tab];
    if (!isset($adaFungsi[$fn])) {
        $galat[] = "Menu \"$tab\" memanggil $fn(), tapi fungsi itu tidak ada di app.js.";
    }
}

foreach ($dispatch as $tab => $fn) {
    if (!in_array($tab, $tabs, true)) {
        $galat[] = "renderContent() menangani \"$tab\", tapi menunya tidak ada di TABS.";
    }
}

/* --- 5. Laporkan ---------------------------------------------------------- */
echo "Menu di TABS      : " . count($tabs) . ' (' . implode(', ', $tabs) . ")\n";
echo "Cabang di router  : " . count($dispatch) . "\n";
echo "Fungsi render     : " . count(array_intersect($dispatch, array_keys($adaFungsi))) . " ditemukan\n\n";

if ($galat) {
    foreach ($galat as $g) {
        echo "GAGAL: $g\n";
    }
    exit(1);
}

echo "OK — setiap menu punya fungsi penggambarnya.\n";
