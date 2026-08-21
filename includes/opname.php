<?php
/**
 * includes/opname.php — penyaring baris stok opname, dipakai bersama.
 *
 * Layar isi sesi, pengisian massal, dan ekspor PDF harus menyaring baris
 * dengan aturan yang sama persis. Kalau tidak, tombol "isi untuk semua
 * baris" bisa mengenai baris yang tidak sedang terlihat — jenis kesalahan
 * yang baru ketahuan setelah ribuan baris terlanjur tertimpa.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Bangun WHERE untuk baris opname pada satu sesi.
 *
 * @param  array $src  sumber penyaring ($_GET atau body JSON)
 * @param  int   $sesiId
 * @return array{where:string, params:array, hanya:string, kategori:string, q:string}
 */
function filterOpnameItem(array $src, int $sesiId): array
{
    $q        = ambilStr($src, 'q', 100);
    $kategori = ambilStr($src, 'kategori', 30);
    $hanya    = pilihanValid(ambilStr($src, 'hanya', 20), ['semua', 'selisih', 'belum', 'disesuaikan']);

    $where  = ['i.sesi_id = ?'];
    $params = [$sesiId];

    if ($q !== '') {
        $where[] = '(i.nama LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)';
        $pola = polaLike($q);
        array_push($params, $pola, $pola, $pola);
    }
    if ($kategori !== '' && $kategori !== 'Semua') {
        $where[] = 'i.kategori = ?';
        $params[] = $kategori;
    }

    if ($hanya === 'selisih') {
        $where[] = 'i.stok_hitung IS NOT NULL AND i.stok_accurate IS NOT NULL AND i.stok_hitung <> i.stok_accurate';
    } elseif ($hanya === 'belum') {
        $where[] = 'i.stok_hitung IS NULL';
    } elseif ($hanya === 'disesuaikan') {
        $where[] = 'i.penyesuaian = ?';
        $params[] = PENYESUAIAN_DISESUAIKAN;
    }

    return [
        'where'    => 'WHERE ' . implode(' AND ', $where),
        'params'   => $params,
        'hanya'    => $hanya,
        'kategori' => $kategori,
        'q'        => $q,
    ];
}
