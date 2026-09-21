<?php
/**
 * includes/izin.php — hak akses per akun.
 *
 * DUA HAL YANG DIATUR
 *   1. Menu apa saja yang boleh dibuka akun ini.
 *   2. Boleh menulis, atau hanya melihat.
 *
 * MENGAPA DI SERVER, BUKAN DI LAYAR
 * Menyembunyikan menu dan tombol hanya merapikan tampilan. Permintaan bisa
 * dikirim langsung ke endpoint-nya tanpa melewati antarmuka, jadi satu-satunya
 * tempat pembatasan ini berarti adalah di sini. Layar mengikuti, bukan
 * sebaliknya.
 *
 * DIBACA DARI DATABASE TIAP PERMINTAAN, BUKAN DARI SESI
 * Peran dan daftar aksesnya dibaca ulang dari tabel users setiap permintaan.
 * Kalau dibaca dari isi sesi, akun yang aksesnya baru dicabut — atau yang
 * baru dinonaktifkan — tetap bisa bekerja sampai ia logout sendiri.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Menu yang boleh diberikan ke akun non-admin, beserta labelnya.
 *
 * Menu "pengguna" sengaja tidak ada di sini: akun non-admin yang bisa
 * mengelola pengguna dapat mengangkat dirinya sendiri jadi admin.
 */
function menuIzin(): array
{
    return [
        'dashboard'  => 'Dashboard stok',
        'masuk'      => 'Barang masuk',
        'keluar'     => 'Barang keluar',
        'riwayat'    => 'Riwayat',
        'pertukaran' => 'Pertukaran barang',
        'retur'      => 'Retur',
        'opname'     => 'Laporan stok opname',
        'master'     => 'Barang',
        'kategori'   => 'Kategori',
        'ket_masuk'  => 'Keterangan barang masuk',
        'ket_keluar' => 'Keterangan barang keluar',
        'aktivitas'  => 'Log aktivitas',
    ];
}

/**
 * Kelompok tiap menu, sama dengan pengelompokan di sidebar.
 *
 * Dikirim ke layar supaya daftar centang hak akses bisa dikelompokkan
 * seperti menunya. Tanpa itu, "Barang" terbaca seolah mewakili seluruh
 * grup Master, padahal Kategori dan kedua Keterangan adalah menu terpisah
 * yang hanya tinggal di grup yang sama — mencabut "Barang" tidak membuat
 * tombol Master hilang, dan itu tampak seperti izin yang tidak bekerja.
 */
function menuGrup(): array
{
    return [
        'dashboard'  => 'Operasional',
        'masuk'      => 'Operasional',
        'keluar'     => 'Operasional',
        'riwayat'    => 'Operasional',
        'pertukaran' => 'Operasional',
        'retur'      => 'Operasional',
        'opname'     => 'Operasional',
        'master'     => 'Master',
        'kategori'   => 'Master',
        'ket_masuk'  => 'Master',
        'ket_keluar' => 'Master',
        'aktivitas'  => 'Sistem',
    ];
}

/**
 * Menu yang didapat akun non-admin bila hak aksesnya belum pernah diatur.
 *
 * Sama persis dengan jangkauan operator sebelum fitur ini ada: seluruh menu
 * kecuali Log aktivitas, yang dulu memang admin saja. Akun lama tidak boleh
 * mendadak kehilangan akses karena pembaruan, dan juga tidak boleh
 * mendadak mendapat akses yang dulu tidak dimilikinya.
 */
function menuBawaan(): array
{
    $semua = array_keys(menuIzin());
    return array_values(array_diff($semua, ['aktivitas']));
}

/** Menu yang selamanya hanya untuk admin. */
function menuAdminSaja(): array
{
    return ['pengguna'];
}

/** Peran yang bisa dipilih saat membuat akun. */
function peranIzin(): array
{
    return [
        'admin'    => 'Admin',
        'operator' => 'Operator',
        'viewer'   => 'Hanya baca',
    ];
}

/**
 * Baris users milik pemakai yang sedang masuk, dibaca dari database.
 *
 * Di-cache per permintaan supaya satu permintaan tidak menembak tabel yang
 * sama berkali-kali, tapi tidak pernah dibawa antar permintaan.
 */
function userDb(): ?array
{
    static $cache = null;
    static $sudah = false;

    if ($sudah) {
        return $cache;
    }
    $sudah = true;

    $id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($id <= 0) {
        $cache = null;
        return $cache;
    }
    $cache = dbOne(
        'SELECT id, username, nama_lengkap, role, akses, aktif FROM users WHERE id = ?',
        [$id]
    );
    return $cache;
}

/** Daftar menu yang boleh dibuka akun ini. */
function aksesSaya(): array
{
    $u = userDb();
    if ($u === null) {
        return [];
    }

    $semua = array_keys(menuIzin());

    if ($u['role'] === 'admin') {
        return array_merge($semua, menuAdminSaja());
    }

    $akses = ($u['akses'] === null || $u['akses'] === '') ? null : json_decode($u['akses'], true);
    if (!is_array($akses) || !$akses) {
        return menuBawaan();
    }

    // Hanya id yang dikenal yang dihitung, supaya isi kolom yang rusak atau
    // menu yang sudah dihapus tidak pernah membuka apa pun.
    return array_values(array_intersect($akses, $semua));
}

/** Boleh membuka menu ini? */
function bolehMenu(string $menu): bool
{
    return in_array($menu, aksesSaya(), true);
}

/** Boleh mengubah data? Peran "viewer" tidak, di menu mana pun. */
function bolehTulis(): bool
{
    $u = userDb();
    return $u !== null && $u['role'] !== 'viewer';
}

/**
 * Boleh menghapus catatan? Hanya admin.
 *
 * Dipisah dari bolehTulis() karena operator memang perlu mencatat dan
 * mengubah, tapi penghapusan menggeser stok dan menghilangkan barisnya dari
 * seluruh layar — kekeliruannya baru ketahuan saat stok opname.
 */
function bolehHapus(): bool
{
    // Perannya dibaca dari userDb(), bukan lewat adalahAdmin() di auth.php:
    // berkas ini juga dipakai tools/uji_menu.php yang tidak memuat auth.
    $u = userDb();
    return $u !== null && $u['role'] === 'admin';
}

/**
 * Peta endpoint API -> [menu yang dibutuhkan, apakah menulis, apakah menghapus].
 *
 * Ditulis lengkap dan eksplisit, bukan ditebak dari nama folder atau metode
 * HTTP. Sebagian endpoint POST sebenarnya hanya membaca (cek_barcode), dan
 * sebagian endpoint di folder master dipakai oleh form di menu lain —
 * menebaknya akan salah persis di tempat-tempat itu.
 *
 * menu null = boleh diakses siapa pun yang sudah masuk. Dipakai untuk
 * pencarian barang yang dibutuhkan form di hampir semua menu.
 *
 * Kolom ketiga menandai endpoint yang MENGHAPUS catatan. Penghapusan hanya
 * untuk admin, di menu mana pun — ditaruh di sini sebagai satu aturan, bukan
 * ditempel wajibAdminApi() di tiap berkas, supaya endpoint hapus yang baru
 * tidak bisa lolos hanya karena penulisnya lupa.
 *
 * Alasannya operasional: menghapus barang masuk atau keluar langsung
 * menggeser stok, dan yang terhapus tidak kelihatan lagi di layar mana pun.
 * Salah hapus oleh petugas gudang baru ketahuan saat stok opname.
 */
function petaEndpoint(): array
{
    return [
        'dashboard/stats.php'     => ['dashboard',  false],
        'dashboard/ringkas.php'   => ['dashboard',  false],

        'masuk/list.php'          => ['masuk',      false],
        'masuk/create.php'        => ['masuk',      true],
        'masuk/delete.php'        => ['masuk',      true, true],

        'keluar/list.php'         => ['keluar',     false],
        'keluar/create.php'       => ['keluar',     true],
        'keluar/delete.php'       => ['keluar',     true, true],

        // Impor PDF picking list adalah bagian dari menu Barang keluar.
        'import/check.php'        => ['keluar',     false],
        'import/commit.php'       => ['keluar',     true],

        'riwayat/list.php'        => ['riwayat',    false],
        'pertukaran/list.php'     => ['pertukaran', false],

        'retur/list.php'          => ['retur',      false],
        'retur/save.php'          => ['retur',      true],
        'retur/delete.php'        => ['retur',      true, true],

        'opname/list.php'         => ['opname',     false],
        'opname/detail.php'       => ['opname',     false],
        'opname/save.php'         => ['opname',     true],
        'opname/item.php'         => ['opname',     true],
        'opname/massal.php'       => ['opname',     true],
        'opname/accurate.php'     => ['opname',     true],
        'opname/delete.php'       => ['opname',     true, true],

        // Pencarian barang dipakai form Barang masuk, Barang keluar, dan
        // Retur; popup riwayat dibuka dari Dashboard.
        'master/list.php'         => [null,         false],
        'master/cek_barcode.php'  => [null,         false],
        'master/riwayat.php'      => [null,         false],
        'master/save.php'         => ['master',     true],
        'master/delete.php'       => ['master',     true, true],
        'master/samakan_nama.php' => ['master',     true],

        'kategori/list.php'       => [null,         false],
        'kategori/save.php'       => ['kategori',   true],
        'kategori/delete.php'     => ['kategori',   true, true],

        // Satu berkas melayani dua menu; arah mana yang dibuka ditentukan
        // parameter jenis-nya.
        'keterangan/list.php'     => ['@keterangan', false],
        'keterangan/save.php'     => ['@keterangan', true],
        'keterangan/delete.php'   => ['@keterangan', true, true],

        'aktivitas/list.php'      => ['aktivitas',  false],

        'pengguna/list.php'       => ['pengguna',   false],
        'pengguna/save.php'       => ['pengguna',   true],
        'pengguna/delete.php'     => ['pengguna',   true, true],

        // Menu yang dibutuhkan ditentukan dari parameter jenis-nya.
        'export/pdf.php'          => ['@ekspor',    false],
    ];
}

/** Jenis laporan PDF -> menu asalnya. */
function menuLaporan(string $jenis): ?string
{
    $peta = [
        'dashboard'  => 'dashboard',
        'masuk'      => 'masuk',
        'keluar'     => 'keluar',
        'riwayat'    => 'riwayat',
        'pertukaran' => 'pertukaran',
        'retur'      => 'retur',
        'opname'     => 'opname',
        'master'     => 'master',
        'aktivitas'  => 'aktivitas',
    ];
    return isset($peta[$jenis]) ? $peta[$jenis] : null;
}

/**
 * Jalur endpoint yang sedang dijalankan, relatif terhadap folder api/.
 * Mengembalikan null bila permintaannya bukan dari sana.
 */
function jalurEndpoint(): ?string
{
    $skrip = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';
    if ($skrip === '') {
        return null;
    }
    $skrip = str_replace('\\', '/', (string)realpath($skrip));
    $akar  = str_replace('\\', '/', (string)realpath(dirname(__DIR__) . '/api'));
    if ($akar === '' || strpos($skrip, $akar . '/') !== 0) {
        return null;
    }
    return substr($skrip, strlen($akar) + 1);
}

/**
 * Periksa izin untuk permintaan API yang sedang berjalan.
 *
 * Dipanggil dari wajibLoginApi(), jadi berlaku untuk seluruh endpoint tanpa
 * perlu diingat satu per satu. Endpoint yang tidak terdaftar di peta ditolak:
 * lupa mendaftarkan endpoint baru harus berakhir dengan penolakan yang
 * kelihatan, bukan dengan celah yang diam.
 */
function periksaIzinApi(): void
{
    require_once __DIR__ . '/response.php';

    $u = userDb();
    if ($u === null || (int)$u['aktif'] !== 1) {
        // Akun terhapus atau dinonaktifkan saat sesinya masih hidup.
        $_SESSION = [];
        jsonError('Akun ini sudah tidak aktif. Hubungi admin.', 401);
    }

    $rel = jalurEndpoint();
    if ($rel === null) {
        return;   // bukan endpoint di bawah api/
    }

    $peta = petaEndpoint();
    if (!isset($peta[$rel])) {
        jsonError('Endpoint ini belum terdaftar di daftar izin.', 500);
    }

    $menu  = $peta[$rel][0];
    $tulis = $peta[$rel][1];
    $hapus = isset($peta[$rel][2]) ? (bool)$peta[$rel][2] : false;

    if ($menu === '@ekspor') {
        $jenis = isset($_GET['jenis']) ? (string)$_GET['jenis'] : '';
        $menu  = menuLaporan($jenis);
        if ($menu === null) {
            return;   // jenis tak dikenal; endpoint-nya sendiri yang menolak
        }
    }

    if ($menu === '@keterangan') {
        // Arah dibaca dari query untuk GET dan dari body untuk POST, karena
        // form mengirimnya sebagai JSON.
        $jenis = isset($_GET['jenis']) ? (string)$_GET['jenis'] : '';
        if ($jenis === '') {
            $body  = jsonInput();
            $jenis = isset($body['jenis']) ? (string)$body['jenis'] : '';
        }
        $menu = $jenis === 'keluar' ? 'ket_keluar' : 'ket_masuk';
    }

    if ($menu !== null && !bolehMenu($menu)) {
        jsonError('Akun ini tidak punya akses ke menu tersebut.', 403);
    }
    if ($tulis && !bolehTulis()) {
        jsonError('Akun ini hanya bisa melihat, tidak bisa mengubah data.', 403);
    }
    if ($hapus && !bolehHapus()) {
        jsonError('Menghapus catatan hanya bisa dilakukan admin.', 403);
    }
}
