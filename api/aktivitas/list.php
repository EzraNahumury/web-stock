<?php
/**
 * GET api/aktivitas/list.php — jejak seluruh aktivitas pengguna.
 *
 * Setiap perubahan data sudah dicatat ke activity_log sejak awal, tapi
 * belum pernah bisa dilihat dari aplikasi. Halaman ini membukanya: siapa
 * melakukan apa, jam berapa, dari alamat IP mana.
 *
 * Aksesnya diberikan per akun lewat menu Pengguna, dan tidak diberikan
 * secara bawaan: log memuat gerak seluruh akun, termasuk penghapusan data
 * dan percobaan masuk yang gagal — bukan bacaan untuk semua orang.
 *
 * Parameter: q, dari, sampai, aksi, entitas, user, page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/aktivitas.php';

pasangPenangananGalatApi();
wajibMetode('GET');
// Bukan wajibAdminApi(): aksesnya kini ditentukan lapisan izin lewat menu
// "aktivitas", supaya admin bisa memberikannya ke akun peninjau tanpa
// mengangkatnya jadi admin.
wajibLoginApi();

$q       = ambilStr($_GET, 'q', 100);
$dari    = trim((string)($_GET['dari'] ?? ''));
$sampai  = trim((string)($_GET['sampai'] ?? ''));
$aksi    = ambilStr($_GET, 'aksi', 50);
$entitas = ambilStr($_GET, 'entitas', 50);
$user    = ambilInt($_GET, 'user', 0);
$page    = ambilHalaman();

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    // Detail disimpan sebagai JSON; dicari sebagai teks biasa supaya nama
    // barang dan no. picking di dalamnya ikut ketemu tanpa fungsi JSON
    // khas satu vendor.
    $where[] = '(a.detail LIKE ? OR u.nama_lengkap LIKE ? OR u.username LIKE ?
                 OR a.aksi LIKE ? OR a.entitas LIKE ? OR a.ip LIKE ?)';
    $pola = polaLike($q);
    for ($i = 0; $i < 6; $i++) {
        $params[] = $pola;
    }
}
// Batas hari dibandingkan sebagai rentang waktu, bukan DATE(created_at),
// supaya indeks pada created_at tetap terpakai.
if ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) {
    $where[] = 'a.created_at >= ?';
    $params[] = $dari . ' 00:00:00';
}
if ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) {
    $where[] = 'a.created_at <= ?';
    $params[] = $sampai . ' 23:59:59';
}
if ($aksi !== '') {
    $where[] = 'a.aksi = ?';
    $params[] = $aksi;
}
if ($entitas !== '') {
    $where[] = 'a.entitas = ?';
    $params[] = $entitas;
}
if ($user > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $user;
}
$sqlWhere = 'WHERE ' . implode(' AND ', $where);

$total  = (int)dbValue(
    "SELECT COUNT(*) FROM activity_log a LEFT JOIN users u ON u.id = a.user_id $sqlWhere",
    $params
);
$meta   = metaPaginasi($total, $page);
$offset = ($meta['page'] - 1) * PAGE_SIZE;

$rows = dbAll(
    "SELECT a.id, a.aksi, a.entitas, a.entitas_id, a.detail, a.ip, a.created_at,
            u.nama_lengkap AS oleh, u.username
       FROM activity_log a
       LEFT JOIN users u ON u.id = a.user_id
     $sqlWhere
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT " . PAGE_SIZE . " OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $label = labelAktivitas($r);
    $r['judul']   = $label['judul'];
    $r['rincian'] = $label['rincian'];
    $r['modul']   = $label['modul'];
    $r['nada']    = $label['nada'];
    // Detail mentah tidak perlu dikirim: sudah diringkas jadi kalimat, dan
    // isinya bisa memuat data yang tidak relevan bagi tampilan.
    unset($r['detail']);
}
unset($r);

/* --- Pilihan filter, dibangun dari isi log yang benar-benar ada -------- */
$opsiAksi = [];
foreach (dbAll('SELECT DISTINCT aksi FROM activity_log ORDER BY aksi') as $o) {
    $opsiAksi[] = [
        'nilai' => $o['aksi'],
        'label' => labelAksi($o['aksi']),
    ];
}
$opsiModul = [];
foreach (dbAll('SELECT DISTINCT entitas FROM activity_log ORDER BY entitas') as $o) {
    $opsiModul[] = ['nilai' => $o['entitas'], 'label' => modulAktivitas($o['entitas'])];
}
$opsiUser = dbAll(
    'SELECT u.id, u.nama_lengkap, u.username
       FROM users u
      WHERE EXISTS (SELECT 1 FROM activity_log a WHERE a.user_id = u.id)
      ORDER BY u.nama_lengkap'
);
foreach ($opsiUser as &$o) {
    $o['id'] = (int)$o['id'];
}
unset($o);

/* --- Ringkasan kecil: selalu hari ini, tidak ikut penyaring ------------ */
$awalHari  = date('Y-m-d') . ' 00:00:00';
$hariIni   = (int)dbValue('SELECT COUNT(*) FROM activity_log WHERE created_at >= ?', [$awalHari]);
$orangHari = (int)dbValue(
    'SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE created_at >= ? AND user_id IS NOT NULL',
    [$awalHari]
);

jsonOk([
    'rows'       => $rows,
    'hari_ini'   => $hariIni,
    'orang_hari' => $orangHari,
    'opsi'  => [
        'aksi'    => $opsiAksi,
        'entitas' => $opsiModul,
        'user'    => $opsiUser,
    ],
] + $meta);
