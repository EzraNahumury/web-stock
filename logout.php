<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Dicatat sebelum sesi dihapus — sesudahnya userId() sudah kosong dan
// jejaknya akan tercatat tanpa pemilik.
if (sudahLogin()) {
    catatAktivitas('logout', 'auth', userId(), [
        'username' => (string)($_SESSION['username'] ?? ''),
    ]);
}

logout();
header('Location: login.php');
exit;
