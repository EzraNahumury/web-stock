<?php
/**
 * includes/response.php — keluaran JSON seragam untuk seluruh endpoint API.
 *
 * Bentuk respons selalu sama:
 *   sukses : { "ok": true,  ...data }
 *   gagal  : { "ok": false, "error": "pesan untuk pengguna" }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function jsonResponse(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonOk(array $data = []): void
{
    jsonResponse(array_merge(['ok' => true], $data));
}

function jsonError(string $pesan, int $status = 400, array $extra = []): void
{
    jsonResponse(array_merge(['ok' => false, 'error' => $pesan], $extra), $status);
}

/**
 * Baca body JSON dari permintaan POST.
 */
function jsonInput(): array
{
    // Di-cache karena kini dibaca lebih dari sekali dalam satu permintaan:
    // lapisan izin perlu melihat body untuk tahu menu mana yang dituju,
    // lalu endpoint-nya membacanya lagi. php://input memang bisa dibaca
    // ulang, tapi mengurainya dua kali tidak ada gunanya.
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $cache = [];
        return $cache;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonError('Format permintaan tidak valid.', 400);
    }
    $cache = $data;
    return $cache;
}

/**
 * Pastikan metode HTTP sesuai. Menolak GET yang mencoba mengubah data.
 */
function wajibMetode(string $metode): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($metode)) {
        jsonError('Metode tidak diizinkan.', 405);
    }
}

/**
 * Pasang penangan galat global agar kegagalan tak terduga tetap keluar
 * sebagai JSON, bukan halaman HTML galat PHP yang merusak parsing di klien.
 */
function pasangPenangananGalatApi(): void
{
    set_exception_handler(static function (Throwable $e): void {
        $kode = catatGalatSistem($e);
        error_log('API error [' . $kode . '] ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());

        // Kode galat ikut disebut ke layar. Pesan aslinya tetap disembunyikan —
        // isinya bisa memuat struktur basis data — tapi kodenya membuat
        // tangkapan layar dari gudang bisa dicocokkan dengan barisnya di menu
        // Log aktivitas, tanpa perlu membuka error log hosting.
        // Kodenya disebut di kedua keadaan, supaya pesan di layar sama
        // bentuknya saat dikembangkan maupun di produksi.
        jsonError(
            (APP_DEBUG ? $e->getMessage() : 'Terjadi kesalahan di server.')
                . ' Kode galat: ' . $kode,
            500,
            ['kode_galat' => $kode]
        );
    });
}

/**
 * Catat satu galat ke tabel galat_sistem, kembalikan kode pendeknya.
 *
 * Pencatatannya sendiri tidak boleh ikut menggagalkan permintaan: kalau
 * basis datanya justru yang sedang bermasalah, penyimpanan ini akan gagal
 * juga, dan yang penting tetap jawaban JSON untuk klien. Karena itu seluruh
 * isinya dibungkus try/catch dan kegagalannya diabaikan — error log PHP
 * sudah menerima salinannya lewat pemanggil.
 */
function catatGalatSistem(Throwable $e): string
{
    try {
        $kode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable $acak) {
        $kode = strtoupper(substr(md5((string)microtime(true)), 0, 6));
    }

    try {
        require_once __DIR__ . '/db.php';

        // Endpoint cukup bagian setelah /api/, supaya tidak memuat jalur
        // berkas di server.
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
        $jalur = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $pos  = strpos($jalur, '/api/');
        $endpoint = $pos === false ? $jalur : substr($jalur, $pos + 5);

        // Parameter penyaring ikut dicatat. Tanpa itu, laporan dari gudang
        // hanya menyebut endpointnya, dan kombinasi yang membuatnya gagal —
        // kategori mana, status apa — harus ditebak lagi.
        $kueri = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($kueri !== '') {
            $endpoint .= '?' . $kueri;
        }

        $sqlstate = '';
        if ($e instanceof PDOException && isset($e->errorInfo[0])) {
            $sqlstate = (string)$e->errorInfo[0];
        }

        dbExec(
            'INSERT INTO galat_sistem (kode, endpoint, pesan, kode_sql, berkas, baris, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $kode,
                mb_substr($endpoint, 0, 255),
                mb_substr($e->getMessage(), 0, 500),
                mb_substr($sqlstate, 0, 10),
                mb_substr(basename($e->getFile()), 0, 200),
                $e->getLine(),
                function_exists('userId') ? userId() : null,
            ]
        );

        // Yang lama dibuang sendiri, sekali-sekali saja supaya tidak menambah
        // kerja pada tiap galat.
        if (random_int(1, 20) === 1) {
            dbExec('DELETE FROM galat_sistem WHERE created_at < (NOW() - INTERVAL 30 DAY)');
        }
    } catch (Throwable $abai) {
        // Diabaikan dengan sengaja; lihat penjelasan di atas.
    }

    return $kode;
}
