<?php
/**
 * GET api/sistem/galat.php — galat server terakhir, untuk admin.
 *
 * Saat sebuah permintaan gagal, layar hanya menyebutkan kode galatnya. Di
 * sinilah kode itu dicocokkan dengan pesan aslinya, tanpa perlu membuka error
 * log hosting — yang tidak bisa diakses dari aplikasi.
 *
 * Isinya teknis dan bisa memuat potongan SQL, jadi khusus admin.
 *
 * Parameter: limit (1..100, bawaan 20)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';

pasangPenangananGalatApi();
wajibMetode('GET');
wajibAdminApi();

$limit = ambilInt($_GET, 'limit', 20);
$limit = max(1, min(100, $limit));

// Tabelnya lahir dari migrasi 016. Bila migrasi belum sempat jalan, jangan
// balas 500 — jawab saja daftar kosong beserta alasannya, karena justru
// halaman inilah yang dibuka orang ketika ada yang tidak beres.
$adaTabel = dbOne("SHOW TABLES LIKE 'galat_sistem'") !== null;
if (!$adaTabel) {
    jsonOk([
        'rows'    => [],
        'total'   => 0,
        'catatan' => 'Tabel catatan galat belum ada. Muat ulang halaman sekali '
                   . 'lagi supaya migrasinya dijalankan.',
    ]);
}

$rows = dbAll(
    'SELECT g.id, g.kode, g.endpoint, g.pesan, g.kode_sql, g.berkas, g.baris,
            g.created_at, u.nama_lengkap AS oleh
       FROM galat_sistem g
       LEFT JOIN users u ON u.id = g.user_id
      ORDER BY g.id DESC
      LIMIT ' . $limit
);

foreach ($rows as &$r) {
    $r['id']    = (int)$r['id'];
    $r['baris'] = (int)$r['baris'];
}
unset($r);

jsonOk([
    'rows'  => $rows,
    'total' => (int)dbValue('SELECT COUNT(*) FROM galat_sistem'),
]);
