<?php
/**
 * includes/riwayat.php — rekap pergerakan stok per barang.
 *
 * Sebelumnya halaman Riwayat menampilkan satu baris per transaksi. Bentuk
 * itu tidak bisa menjawab pertanyaan yang sebenarnya dipakai gudang:
 * "barang ini bulan lalu mulai dari berapa, masuk berapa, keluar berapa,
 * sisa berapa". Satu barang bisa punya puluhan baris pesanan dalam sehari,
 * dan barang yang tidak bergerak sama sekali tidak pernah muncul.
 *
 * Sekarang dasarnya master_barang, bukan tabel transaksi: seluruh barang
 * pada kategori yang dipilih selalu tampil, bergerak maupun tidak.
 *
 *   stok awal   = stok_awal master + seluruh mutasi SEBELUM tanggal "dari"
 *   stok masuk  = jumlah masuk di dalam rentang
 *   stok keluar = jumlah keluar di dalam rentang
 *   stok akhir  = stok awal + masuk - keluar
 *
 * Bila "dari" kosong, tidak ada apa pun sebelum rentang, jadi stok awalnya
 * adalah stok awal master itu sendiri.
 *
 * Dipakai bersama oleh api/riwayat/list.php dan ekspor PDF supaya angka di
 * layar dan di berkas cetak tidak mungkin berbeda.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Baca dan bersihkan penyaring riwayat dari $_GET. */
function filterRiwayat(array $src): array
{
    $dari   = trim((string)($src['dari'] ?? ''));
    $sampai = trim((string)($src['sampai'] ?? ''));

    return [
        'q'        => ambilStr($src, 'q', 100),
        'kategori' => ambilStr($src, 'kategori', 30),
        'dari'     => ($dari !== '' && ambilTanggal(['d' => $dari], 'd') !== null) ? $dari : '',
        'sampai'   => ($sampai !== '' && ambilTanggal(['d' => $sampai], 'd') !== null) ? $sampai : '',
    ];
}

/**
 * Bangun bagian FROM + WHERE beserta parameternya.
 *
 * Urutan parameter mengikuti urutan kemunculan di SQL: subquery pada JOIN
 * lebih dulu, baru syarat WHERE. Salah urut di sini akan menghasilkan angka
 * yang tampak masuk akal tapi salah, jadi keduanya dibangun berdampingan.
 *
 * @return array{sql:string, params:array}
 */
function kueriRiwayat(array $f): array
{
    $params = [];

    // Satu subquery agregat per arah per jangkauan waktu.
    $agregat = static function (string $tabel, string $alias, array $syarat, array &$params) {
        $where = ['deleted_at IS NULL', 'master_id IS NOT NULL'];
        foreach ($syarat as $s) {
            $where[]  = $s[0];
            $params[] = $s[1];
        }
        return ' LEFT JOIN (SELECT master_id, SUM(jumlah) AS total FROM ' . $tabel
             . ' WHERE ' . implode(' AND ', $where)
             . ' GROUP BY master_id) ' . $alias . ' ON ' . $alias . '.master_id = m.id';
    };

    $join = '';

    // --- Mutasi sebelum rentang: hanya perlu bila ada tanggal mulai -------
    if ($f['dari'] !== '') {
        $join .= $agregat('barang_masuk',  'sm', [['tanggal < ?', $f['dari']]], $params);
        $join .= $agregat('barang_keluar', 'sk', [['tanggal < ?', $f['dari']]], $params);
    }

    // --- Mutasi di dalam rentang ------------------------------------------
    $syarat = [];
    if ($f['dari'] !== '') {
        $syarat[] = ['tanggal >= ?', $f['dari']];
    }
    if ($f['sampai'] !== '') {
        $syarat[] = ['tanggal <= ?', $f['sampai']];
    }
    $join .= $agregat('barang_masuk',  'pm', $syarat, $params);
    $join .= $agregat('barang_keluar', 'pk', $syarat, $params);

    $where = ['m.deleted_at IS NULL'];
    if ($f['q'] !== '') {
        $where[] = '(m.nama LIKE ? OR m.sku LIKE ? OR m.barcode LIKE ?)';
        $pola = polaLike($f['q']);
        array_push($params, $pola, $pola, $pola);
    }
    if ($f['kategori'] !== '' && $f['kategori'] !== 'Semua') {
        $where[] = 'm.kategori = ?';
        $params[] = $f['kategori'];
    }

    return [
        'sql'    => ' FROM master_barang m' . $join . ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/** Ekspresi stok awal periode — dipakai di beberapa tempat, jadi disatukan. */
function sqlAwalPeriode(array $f): string
{
    return $f['dari'] !== ''
        ? '(m.stok_awal + COALESCE(sm.total,0) - COALESCE(sk.total,0))'
        : 'm.stok_awal';
}

/**
 * Ambil baris rekap. $limit null = seluruhnya (dipakai ekspor PDF).
 */
function barisRiwayat(array $f, ?int $limit = null, int $offset = 0): array
{
    $k     = kueriRiwayat($f);
    $awal  = sqlAwalPeriode($f);
    $batas = $limit === null ? '' : ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $rows = dbAll(
        "SELECT m.id AS master_id, m.sku, m.barcode, m.nama, m.kategori,
                $awal AS stok_awal,
                COALESCE(pm.total,0) AS masuk,
                COALESCE(pk.total,0) AS keluar,
                $awal + COALESCE(pm.total,0) - COALESCE(pk.total,0) AS stok_akhir"
        . $k['sql'] . ' ORDER BY m.nama, m.id' . $batas,
        $k['params']
    );

    foreach ($rows as &$r) {
        $r['master_id']  = (int)$r['master_id'];
        $r['stok_awal']  = (int)$r['stok_awal'];
        $r['masuk']      = (int)$r['masuk'];
        $r['keluar']     = (int)$r['keluar'];
        $r['stok_akhir'] = (int)$r['stok_akhir'];
    }
    unset($r);

    return $rows;
}

/** Jumlah barang yang lolos penyaring. */
function jumlahRiwayat(array $f): int
{
    $k = kueriRiwayat($f);
    return (int)dbValue('SELECT COUNT(*)' . $k['sql'], $k['params']);
}

/** Total seluruh baris terpilih, bukan hanya halaman yang tampil. */
function totalRiwayat(array $f): array
{
    $k    = kueriRiwayat($f);
    $awal = sqlAwalPeriode($f);

    $r = dbOne(
        "SELECT COALESCE(SUM($awal),0) AS awal,
                COALESCE(SUM(COALESCE(pm.total,0)),0) AS masuk,
                COALESCE(SUM(COALESCE(pk.total,0)),0) AS keluar,
                COALESCE(SUM($awal + COALESCE(pm.total,0) - COALESCE(pk.total,0)),0) AS akhir"
        . $k['sql'],
        $k['params']
    );

    return [
        'awal'   => (int)($r['awal'] ?? 0),
        'masuk'  => (int)($r['masuk'] ?? 0),
        'keluar' => (int)($r['keluar'] ?? 0),
        'akhir'  => (int)($r['akhir'] ?? 0),
    ];
}
