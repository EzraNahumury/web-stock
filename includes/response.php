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
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonError('Format permintaan tidak valid.', 400);
    }
    return $data;
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
        error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        jsonError(
            APP_DEBUG ? $e->getMessage() : 'Terjadi kesalahan di server.',
            500
        );
    });
}
