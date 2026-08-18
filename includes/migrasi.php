<?php
/**
 * includes/migrasi.php — penerap migrasi database otomatis.
 *
 * Berkas di folder sql/ dijalankan berurutan menurut namanya, dan yang
 * sudah pernah dijalankan dicatat di tabel `migrasi` sehingga tidak
 * terulang. Tujuannya: deploy cukup git push, tanpa langkah manual di
 * phpMyAdmin yang mudah terlewat.
 *
 * BAHAYA YANG DIJAGA
 * Migrasi lama bersifat merusak bila diulang: 001 diawali DROP TABLE untuk
 * enam tabel, 002 diawali DELETE FROM master_barang. Menjalankannya di
 * database yang sudah berisi data akan menghapus seluruhnya.
 *
 * Karena itu tiap berkas menyatakan sendiri syarat lewatnya, lewat komentar
 * di dalam berkas:
 *
 *   -- @lewati-jika-tabel: master_barang     lewati bila tabel itu sudah ada
 *   -- @lewati-jika-terisi: master_barang    lewati bila tabel itu sudah berisi
 *
 * Berkas yang dilewati tetap tercatat sebagai sudah diterapkan, jadi
 * database lama otomatis ter-"baseline" pada pemeriksaan pertama dan hanya
 * migrasi yang benar-benar baru yang dijalankan.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Terapkan migrasi yang belum pernah dijalankan.
 *
 * @param  bool  $paksa lewati cache per-permintaan (dipakai CLI)
 * @return array{diterapkan:string[], dilewati:string[], galat:?string}
 */
function jalankanMigrasi(bool $paksa = false): array
{
    static $hasil = null;
    if ($hasil !== null && !$paksa) {
        return $hasil;
    }
    $hasil = ['diterapkan' => [], 'dilewati' => [], 'galat' => null];

    $folder = dirname(__DIR__) . '/sql';
    if (!is_dir($folder)) {
        return $hasil;
    }

    try {
        $pdo = db();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrasi (
                berkas     VARCHAR(190) NOT NULL PRIMARY KEY,
                dilewati   TINYINT(1)   NOT NULL DEFAULT 0,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $berkas = glob($folder . '/*.sql') ?: [];
        sort($berkas, SORT_STRING);
        if (!$berkas) {
            return $hasil;
        }

        $sudah = [];
        foreach (dbAll('SELECT berkas FROM migrasi') as $r) {
            $sudah[$r['berkas']] = true;
        }

        $tertunda = [];
        foreach ($berkas as $b) {
            if (!isset($sudah[basename($b)])) {
                $tertunda[] = $b;
            }
        }
        if (!$tertunda) {
            return $hasil;
        }

        // Kunci agar dua permintaan bersamaan tidak menjalankan migrasi yang
        // sama dua kali. Bila kunci tidak didapat, permintaan ini menyerah —
        // permintaan lain sedang mengerjakannya.
        $dapat = (int)dbValue("SELECT GET_LOCK('gudang_migrasi', 10)");
        if ($dapat !== 1) {
            return $hasil;
        }

        try {
            // Baca ulang: bisa jadi proses lain sudah menyelesaikannya.
            $sudah = [];
            foreach (dbAll('SELECT berkas FROM migrasi') as $r) {
                $sudah[$r['berkas']] = true;
            }

            $stCatat = $pdo->prepare('INSERT INTO migrasi (berkas, dilewati) VALUES (?, ?)');

            foreach ($tertunda as $jalur) {
                $nama = basename($jalur);
                if (isset($sudah[$nama])) {
                    continue;
                }

                $isi = file_get_contents($jalur);
                if ($isi === false) {
                    continue;
                }

                if (migrasiPerluDilewati($isi)) {
                    $stCatat->execute([$nama, 1]);
                    $hasil['dilewati'][] = $nama;
                    continue;
                }

                foreach (pecahSql($isi) as $perintah) {
                    $pdo->exec($perintah);
                }
                $stCatat->execute([$nama, 0]);
                $hasil['diterapkan'][] = $nama;
            }
        } finally {
            $pdo->exec("DO RELEASE_LOCK('gudang_migrasi')");
        }
    } catch (Throwable $e) {
        $hasil['galat'] = $e->getMessage();
        error_log('Migrasi gagal: ' . $e->getMessage());
    }

    return $hasil;
}

/**
 * Apakah berkas ini harus dilewati karena databasenya sudah pada keadaan
 * yang dituju? Syaratnya dibaca dari komentar di dalam berkas itu sendiri.
 */
function migrasiPerluDilewati(string $isi): bool
{
    if (preg_match('/--\s*@lewati-jika-tabel:\s*([A-Za-z0-9_]+)/', $isi, $m)) {
        if (tabelAda($m[1])) {
            return true;
        }
    }
    if (preg_match('/--\s*@lewati-jika-terisi:\s*([A-Za-z0-9_]+)/', $isi, $m)) {
        $tabel = $m[1];
        if (tabelAda($tabel)) {
            if ((int)dbValue('SELECT COUNT(*) FROM `' . $tabel . '`') > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Apakah tabel ini ada di database yang sedang dipakai?
 *
 * Memakai information_schema, bukan SHOW TABLES LIKE ?: pernyataan SHOW
 * tidak menerima parameter terikat, sehingga versi itu selalu gagal dengan
 * galat sintaks 1064.
 */
function tabelAda(string $tabel): bool
{
    return (int)dbValue(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$tabel]
    ) > 0;
}

/**
 * Pecah satu berkas SQL menjadi perintah-perintah terpisah.
 *
 * Titik koma di dalam string, tanda kutip balik, atau komentar tidak boleh
 * dianggap pemisah — berkas seed berisi nama barang yang memuat tanda petik
 * dan koma, jadi pemecahan naif akan merusaknya.
 *
 * @return string[]
 */
function pecahSql(string $sql): array
{
    $perintah = [];
    $kini = '';
    $n = strlen($sql);
    $dalam = null;      // ' " atau backtick
    $komentar = null;   // 'baris' atau 'blok'

    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        $berikut = $i + 1 < $n ? $sql[$i + 1] : '';

        if ($komentar === 'baris') {
            if ($c === "\n") {
                $komentar = null;
                $kini .= $c;
            }
            continue;
        }
        if ($komentar === 'blok') {
            if ($c === '*' && $berikut === '/') {
                $komentar = null;
                $i++;
            }
            continue;
        }

        if ($dalam === null) {
            // "--" hanya memulai komentar bila diikuti spasi atau akhir baris.
            if ($c === '-' && $berikut === '-' && (($i + 2 >= $n) || preg_match('/\s/', $sql[$i + 2]))) {
                $komentar = 'baris';
                continue;
            }
            if ($c === '#') {
                $komentar = 'baris';
                continue;
            }
            if ($c === '/' && $berikut === '*') {
                $komentar = 'blok';
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $dalam = $c;
                $kini .= $c;
                continue;
            }
            if ($c === ';') {
                $potong = trim($kini);
                if ($potong !== '') {
                    $perintah[] = $potong;
                }
                $kini = '';
                continue;
            }
            $kini .= $c;
            continue;
        }

        // Di dalam string / identifier.
        $kini .= $c;
        if ($c === '\\' && $dalam !== '`') {
            // Escape backslash: karakter berikutnya ikut apa adanya.
            if ($berikut !== '') {
                $kini .= $berikut;
                $i++;
            }
            continue;
        }
        if ($c === $dalam) {
            // Kutip ganda berurutan berarti kutip harfiah, bukan penutup.
            if ($berikut === $dalam) {
                $kini .= $berikut;
                $i++;
                continue;
            }
            $dalam = null;
        }
    }

    $potong = trim($kini);
    if ($potong !== '') {
        $perintah[] = $potong;
    }
    return $perintah;
}
