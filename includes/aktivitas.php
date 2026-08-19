<?php
/**
 * includes/aktivitas.php — penerjemah baris activity_log menjadi kalimat.
 *
 * Tabel activity_log menyimpan aksi dan entitas sebagai kode pendek
 * (create/keluar, import/batch, ...) plus JSON detail. Bentuk itu ringkas
 * untuk disimpan tapi tidak terbaca oleh siapa pun yang membuka halaman
 * riwayat. Penerjemahan ditaruh di satu tempat supaya tampilan layar dan
 * PDF tidak pernah berbeda kata.
 */

declare(strict_types=1);

/** Nama modul per entitas — dipakai sebagai label pengelompokan. */
function modulAktivitas(string $entitas): string
{
    $peta = [
        'auth'      => 'Akun',
        'masuk'     => 'Barang masuk',
        'keluar'    => 'Barang keluar',
        'batch'     => 'Impor PDF',
        'master'    => 'Master barang',
        'kategori'  => 'Kategori',
        'pengguna'  => 'Pengguna',
        'transaksi' => 'Transaksi',
        'laporan'   => 'Laporan',
    ];
    return $peta[$entitas] ?? ucfirst($entitas);
}

/** Nama aksi berdiri sendiri — dipakai untuk isi dropdown penyaring. */
function labelAksi(string $aksi): string
{
    $peta = [
        'login'       => 'Masuk',
        'logout'      => 'Keluar',
        'login_gagal' => 'Gagal masuk',
        'create'      => 'Tambah / input',
        'update'      => 'Ubah',
        'delete'      => 'Hapus',
        'import'      => 'Impor PDF',
        'ekspor'      => 'Unduh laporan',
    ];
    return $peta[$aksi] ?? ucfirst($aksi);
}

/** Judul laporan yang diunduh, dari parameter `jenis` di ekspor PDF. */
function judulLaporan(string $jenis): string
{
    $peta = [
        'dashboard'  => 'Laporan stok barang',
        'masuk'      => 'Laporan barang masuk',
        'keluar'     => 'Laporan barang keluar',
        'riwayat'    => 'Riwayat keluar masuk',
        'pertukaran' => 'Riwayat pertukaran barang',
        'master'     => 'Master barang',
        'aktivitas'  => 'Log aktivitas',
    ];
    return $peta[$jenis] ?? $jenis;
}

/**
 * Ubah satu baris activity_log menjadi kalimat siap tampil.
 *
 * @param  array $r baris mentah (aksi, entitas, detail)
 * @return array{judul:string, rincian:string, modul:string, nada:string}
 */
function labelAktivitas(array $r): array
{
    $aksi    = (string)($r['aksi'] ?? '');
    $entitas = (string)($r['entitas'] ?? '');

    $d = $r['detail'] ?? null;
    if (is_string($d)) {
        $d = json_decode($d, true);
    }
    if (!is_array($d)) {
        $d = [];
    }

    $kunci = $aksi . '/' . $entitas;
    $judul = [
        'login/auth'         => 'Masuk ke sistem',
        'logout/auth'        => 'Keluar dari sistem',
        'login_gagal/auth'   => 'Percobaan masuk gagal',
        'create/masuk'       => 'Input barang masuk',
        'delete/masuk'       => 'Hapus catatan barang masuk',
        'create/keluar'      => 'Input barang keluar',
        'delete/keluar'      => 'Hapus catatan barang keluar',
        'import/batch'       => 'Impor picking list PDF',
        'create/master'      => 'Tambah barang',
        'update/master'      => 'Ubah barang',
        'delete/master'      => 'Hapus barang',
        'create/kategori'    => 'Tambah kategori',
        'update/kategori'    => 'Ubah kategori',
        'delete/kategori'    => 'Hapus kategori',
        'create/pengguna'    => 'Tambah pengguna',
        'update/pengguna'    => 'Ubah pengguna',
        'delete/pengguna'    => 'Hapus pengguna',
        'update/transaksi'   => 'Rapikan nama transaksi',
        'ekspor/laporan'     => 'Unduh laporan PDF',
    ];
    $teks = $judul[$kunci] ?? (ucfirst($aksi) . ' ' . modulAktivitas($entitas));

    // Nada dipakai untuk warna lencana: merah = menghapus/gagal,
    // hijau = menambah, biru = sisanya.
    $nada = 'netral';
    if ($aksi === 'delete' || $aksi === 'login_gagal') {
        $nada = 'bahaya';
    } elseif ($aksi === 'create' || $aksi === 'import') {
        $nada = 'aman';
    }

    /* --- Rincian: bagian yang berbeda tiap baris ------------------------ */
    $bagian = [];

    if (isset($d['nama']) && $d['nama'] !== '') {
        $bagian[] = (string)$d['nama'];
    }
    if (isset($d['barcode']) && $d['barcode'] !== '') {
        $bagian[] = (string)$d['barcode'];
    }
    if (isset($d['jumlah'])) {
        $bagian[] = number_format((int)$d['jumlah'], 0, ',', '.') . ' pcs';
    }
    if (isset($d['username']) && $d['username'] !== '') {
        $bagian[] = (string)$d['username'];
    }
    if (isset($d['role']) && $d['role'] !== '') {
        $bagian[] = 'peran ' . $d['role'];
    }
    if (isset($d['no_picking']) && $d['no_picking'] !== '') {
        $bagian[] = (string)$d['no_picking'];
    }
    if (isset($d['nama_file']) && $d['nama_file'] !== '' && empty($d['no_picking'])) {
        $bagian[] = (string)$d['nama_file'];
    }
    if (isset($d['baris'])) {
        $bagian[] = number_format((int)$d['baris'], 0, ',', '.') . ' baris';
    }
    if (isset($d['jenis']) && $d['jenis'] !== '') {
        $bagian[] = judulLaporan((string)$d['jenis']);
    }
    if (isset($d['diubah'])) {
        $bagian[] = number_format((int)$d['diubah'], 0, ',', '.') . ' catatan disamakan';
    }
    if (isset($d['tanggal']) && $d['tanggal'] !== '') {
        $bagian[] = 'tanggal ' . $d['tanggal'];
    }

    return [
        'judul'   => $teks,
        'rincian' => implode(' · ', $bagian),
        'modul'   => modulAktivitas($entitas),
        'nada'    => $nada,
    ];
}
