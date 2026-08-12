<?php
/**
 * uji_fondasi.php — pemeriksaan cepat lapisan fondasi (Tahap 1).
 *
 * Jalankan: C:\xampp\php\php.exe tools\uji_fondasi.php
 * Aman dijalankan berulang; tidak mengubah data.
 */

declare(strict_types=1);

$lulus = 0;
$gagal = 0;

function cek(string $nama, $aktual, $harap = true): void
{
    global $lulus, $gagal;
    $ok = ($harap === true) ? (bool)$aktual : ($aktual === $harap);
    if ($ok) {
        $lulus++;
        echo "  [OK]    $nama\n";
    } else {
        $gagal++;
        $a = is_scalar($aktual) ? var_export($aktual, true) : gettype($aktual);
        $h = is_scalar($harap) ? var_export($harap, true) : gettype($harap);
        echo "  [GAGAL] $nama  (dapat: $a, harap: $h)\n";
    }
}

echo "=== Lingkungan ===\n";
cek('PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='));
echo "          versi PHP: " . PHP_VERSION . "\n";
cek('ekstensi pdo_mysql', extension_loaded('pdo_mysql'));
cek('ekstensi mbstring', extension_loaded('mbstring'));

echo "\n=== Konfigurasi ===\n";
require_once __DIR__ . '/../includes/helpers.php';
cek('config termuat', defined('DB_NAME'));
cek('lingkungan terdeteksi lokal', APP_ENV, 'lokal');
cek('zona waktu Asia/Jakarta', date_default_timezone_get(), 'Asia/Jakarta');
cek('PAGE_SIZE = 50', PAGE_SIZE, 50);

echo "\n=== Database ===\n";
try {
    $pdo = db();
    cek('koneksi PDO', $pdo instanceof PDO);
    echo "          server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
    // Driver mysql mengembalikan int 0/1, bukan bool — bandingkan longgar.
    cek('prepared statement asli', (int)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 0);

    // Bukti langsung: parameter benar-benar terikat, bukan ditempel ke SQL.
    $injeksi = (int)dbValue('SELECT COUNT(*) FROM master_barang WHERE nama = ?', ['x" OR "1"="1']);
    cek('injeksi SQL lewat parameter tidak jalan', $injeksi, 0);

    $tabel = ['users', 'master_barang', 'import_batch', 'barang_masuk', 'barang_keluar', 'activity_log'];
    foreach ($tabel as $t) {
        cek("tabel $t ada", (bool)dbValue("SHOW TABLES LIKE '$t'"));
    }

    $total = (int)dbValue('SELECT COUNT(*) FROM master_barang');
    cek('master_barang = 1404 baris', $total, 1404);

    $unik = (int)dbValue('SELECT COUNT(DISTINCT barcode) FROM master_barang');
    cek('semua barcode unik', $unik, 1404);

    $kosong = (int)dbValue("SELECT COUNT(*) FROM master_barang WHERE barcode = ''");
    cek('tidak ada barcode kosong', $kosong, 0);

    $generate = (int)dbValue('SELECT COUNT(*) FROM master_barang WHERE barcode_asli = 0');
    cek('359 barcode perlu dilengkapi', $generate, 359);

    cek('zona waktu MySQL = WIB', dbValue("SELECT TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), '%H:%i')"), '07:00');
} catch (Throwable $e) {
    $gagal++;
    echo "  [GAGAL] database: " . $e->getMessage() . "\n";
}

echo "\n=== Autentikasi ===\n";
try {
    $admin = dbOne("SELECT * FROM users WHERE username = 'admin'");
    cek('akun admin ada', $admin !== null);
    if ($admin) {
        cek('password admin123 cocok', password_verify('admin123', $admin['password_hash']));
        cek('password salah ditolak', !password_verify('salah', $admin['password_hash']));
        cek('hash bukan plaintext', strpos($admin['password_hash'], '$2y$') === 0);
        cek('role admin', $admin['role'], 'admin');
    }
} catch (Throwable $e) {
    $gagal++;
    echo "  [GAGAL] auth: " . $e->getMessage() . "\n";
}

echo "\n=== Logika stok ===\n";
$sql = sqlStatusStok();
cek('stok_minimal 0 -> belum_diatur', strpos($sql, "belum_diatur") !== false);
cek('ambang rendah 1.3 dipakai', strpos($sql, '1.3') !== false);

try {
    $ringkas = dbOne('
        SELECT
          SUM(CASE WHEN m.stok_minimal = 0 THEN 1 ELSE 0 END) AS belum_diatur,
          SUM(CASE WHEN m.stok_minimal > 0 AND ' . sqlStokAkhir() . ' <= m.stok_minimal THEN 1 ELSE 0 END) AS kritis
        FROM master_barang m ' . sqlJoinAgregat() . '
        WHERE m.deleted_at IS NULL');
    cek('1404 item belum diatur ambangnya', (int)$ringkas['belum_diatur'], 1404);
    cek('0 item kritis palsu (audit D4)', (int)$ringkas['kritis'], 0);
} catch (Throwable $e) {
    $gagal++;
    echo "  [GAGAL] query stok: " . $e->getMessage() . "\n";
}

echo "\n=== Keamanan ===\n";
cek('polaLike meng-escape wildcard', polaLike('50%'), '%50\\%%');
cek('e() meng-escape HTML', e('<script>'), '&lt;script&gt;');
cek('e() meng-escape kutip', e("a'b"), 'a&#039;b');
cek('ambilInt menolak teks', ambilInt(['x' => 'abc'], 'x', 0), 0);
cek('ambilTanggal menolak format salah', ambilTanggal(['t' => '12-08-2026'], 't'), null);
cek('ambilTanggal menerima ISO', ambilTanggal(['t' => '2026-08-12'], 't'), '2026-08-12');
cek('ambilTanggal menolak 31 Februari', ambilTanggal(['t' => '2026-02-31'], 't'), null);
cek('pilihanValid menolak nilai liar', pilihanValid('DROP TABLE', KET_KELUAR), 'Pesanan MP');

echo "\n" . str_repeat('=', 46) . "\n";
echo "LULUS: $lulus    GAGAL: $gagal\n";
exit($gagal > 0 ? 1 : 0);
