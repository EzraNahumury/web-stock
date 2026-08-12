<?php
/**
 * includes/db.php — koneksi PDO + helper query.
 *
 * Semua akses database melewati berkas ini. Prepared statement asli
 * (EMULATE_PREPARES = false) supaya parameter benar-benar dipisahkan dari
 * pernyataan SQL, bukan sekadar ditempel oleh driver.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Koneksi PDO tunggal, dibuat sekali per permintaan.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Pesan asli PDO bisa membocorkan struktur database — jangan pernah
        // ditampilkan ke pengguna di produksi.
        error_log('Koneksi database gagal: ' . $e->getMessage());
        if (APP_DEBUG) {
            throw $e;
        }
        http_response_code(500);
        exit('Koneksi database gagal.');
    }

    // Samakan zona waktu MySQL dengan PHP agar NOW() dan date() tidak berbeda.
    $pdo->exec("SET time_zone = '+07:00'");

    return $pdo;
}

/**
 * Jalankan query berparameter, kembalikan statement.
 */
function dbQuery(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Ambil semua baris. */
function dbAll(string $sql, array $params = []): array
{
    return dbQuery($sql, $params)->fetchAll();
}

/** Ambil satu baris, atau null. */
function dbOne(string $sql, array $params = []): ?array
{
    $row = dbQuery($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Ambil satu nilai kolom pertama. */
function dbValue(string $sql, array $params = [])
{
    $v = dbQuery($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Jalankan INSERT/UPDATE/DELETE, kembalikan jumlah baris terpengaruh. */
function dbExec(string $sql, array $params = []): int
{
    return dbQuery($sql, $params)->rowCount();
}

/** ID baris terakhir yang di-INSERT. */
function dbLastId(): int
{
    return (int)db()->lastInsertId();
}

/**
 * Bungkus sebuah operasi dalam transaksi. Melempar ulang exception apa pun
 * setelah ROLLBACK, supaya pemanggil tetap tahu kegagalannya.
 */
function dbTransaksi(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hasil = $fn($pdo);
        $pdo->commit();
        return $hasil;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
