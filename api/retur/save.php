<?php
/**
 * POST api/retur/save.php — catat retur satu pesanan, berisi satu produk atau lebih.
 *
 * Badan permintaan:
 *   { tanggal, no_pesanan,
 *     items: [ { barcode, sku, nama, jumlah, status, keterangan }, ... ] }
 *
 * Bentuk lama — satu produk langsung di badan permintaan, tanpa items — masih
 * diterima dan diperlakukan sebagai daftar berisi satu baris. Keduanya lewat
 * jalur yang SAMA PERSIS; tidak ada kode yang hanya dilalui salah satunya,
 * supaya keduanya tidak pernah bisa berbeda perilaku.
 *
 * SATU PESANAN, BANYAK PRODUK
 * Satu nomor pesanan sering berisi beberapa barang. Tiap barang tetap perlu
 * barisnya sendiri, karena alasan returnya — dan karena itu pengaruhnya ke
 * stok — bisa berbeda-beda: satu produk kembali utuh dan menambah stok,
 * satunya lagi belum bisa diproses. Jadi tanggal dan nomor pesanannya dipakai
 * bersama, sedangkan barang, qty, dan keterangannya milik masing-masing baris.
 *
 * SEMUANYA DALAM SATU TRANSAKSI
 * Seluruh baris retur beserta baris barang masuknya ditulis dalam satu
 * transaksi. Bila produk ketiga gagal, dua yang pertama ikut batal: separuh
 * pesanan yang terlanjur masuk stok lebih berbahaya daripada tidak ada yang
 * masuk sama sekali, sebab selisihnya baru ketahuan saat stok opname.
 *
 * Mencatat retur baru boleh dilakukan operator. MENGUBAH retur yang sudah
 * tersimpan hanya untuk admin: mengubah qty atau keterangannya menggeser stok
 * lewat baris barang masuknya, dan perubahan itu tidak meninggalkan jejak di
 * daftar retur. Pengubahan karena itu selalu satu baris — baris yang sudah
 * tersimpan berdiri sendiri-sendiri, masing-masing dengan barang masuknya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/retur.php';
require_once __DIR__ . '/../../includes/izin.php';

pasangPenangananGalatApi();
wajibMetode('POST');
wajibLoginApi();

/* Batas atas jumlah produk dalam satu kali simpan. Bukan aturan gudang,
 * melainkan pagar transaksi: satu transaksi yang menahan ratusan baris
 * mengunci barang_masuk terlalu lama. Lembar retur terpanjang yang pernah ada
 * berisi belasan produk, jadi angka ini jauh di atas kebutuhan nyata. */
const MAKS_PRODUK_RETUR = 50;

$in = jsonInput();
wajibCsrf($in);

$id = ambilInt($in, 'id', 0);
if ($id > 0 && !bolehUbahCatatan()) {
    jsonError('Mengubah retur yang sudah tersimpan hanya bisa dilakukan admin.', 403);
}

$tanggal   = ambilTanggal($in, 'tanggal');
$noPesanan = ambilStr($in, 'no_pesanan', 100);

if ($tanggal === null) {
    jsonError('Format tanggal tidak valid.');
}

/* --- Daftar produk ------------------------------------------------------- */

/* Bentuk lama dibungkus jadi daftar satu baris, supaya di bawah ini hanya ada
 * satu jalur. $in sendiri yang dipakai sebagai barisnya: kunci yang dibaca
 * hanya barcode/sku/nama/jumlah/status/keterangan, sisanya diabaikan. */
$items = isset($in['items']) ? $in['items'] : null;
if ($items === null) {
    $items = [$in];
}
if (!is_array($items)) {
    jsonError('Daftar produk tidak terbaca.');
}
// Hanya daftar berurut yang diterima; objek bernomor acak bukan daftar produk.
$items = array_values($items);

if (!$items) {
    jsonError('Tambahkan minimal satu produk pada retur ini.');
}
if (count($items) > MAKS_PRODUK_RETUR) {
    jsonError('Maksimal ' . MAKS_PRODUK_RETUR . ' produk dalam satu kali simpan. '
        . 'Simpan sebagian dulu, lalu catat sisanya dengan nomor pesanan yang sama.');
}
if ($id > 0 && count($items) > 1) {
    // Tiap baris retur punya baris barang masuknya sendiri. Mengubah satu
    // baris menjadi beberapa berarti membuat baris baru — itu pencatatan,
    // bukan pengubahan, dan jalurnya memang yang di bawah.
    jsonError('Mengubah retur hanya bisa satu produk. Untuk menambah produk ke '
        . 'pesanan ini, catat returnya sebagai entri baru dengan nomor pesanan yang sama.');
}

$banyak = count($items) > 1;

/** Nama yang bisa dikenali petugas untuk menunjuk satu baris pada pesan galat. */
function labelBarisRetur(array $it, int $nomor): string
{
    foreach (['nama', 'sku', 'barcode'] as $k) {
        $v = ambilStr($it, $k, 255);
        if ($v !== '') {
            return $v;
        }
    }
    return 'Produk #' . $nomor;
}

$pilihanStatus = daftarKeterangan('retur');

$baris      = [];
$peringatan = [];

foreach ($items as $i => $it) {
    $nomor = $i + 1;

    if (!is_array($it)) {
        jsonError('Produk #' . $nomor . ' tidak terbaca.');
    }

    /* Awalan hanya dipasang bila produknya lebih dari satu. Pada satu produk,
     * menyebut namanya di depan pesan hanya mengulang yang sudah terlihat di
     * formnya. */
    $awalan = $banyak ? labelBarisRetur($it, $nomor) . ': ' : '';

    $barcode = ambilStr($it, 'barcode', 50);
    $sku     = ambilStr($it, 'sku', 50);
    $nama    = ambilStr($it, 'nama', 255);
    $jumlah  = ambilInt($it, 'jumlah', 0);
    $status  = pilihanValid(ambilStr($it, 'status', 30), $pilihanStatus);
    $ket     = ambilStr($it, 'keterangan', 255);

    if ($barcode === '' && $sku === '') {
        jsonError($awalan . 'Isi barcode atau SKU barangnya dulu.');
    }
    if ($jumlah <= 0) {
        jsonError($awalan . 'Qty harus lebih dari 0.');
    }

    // Barang dicari lewat barcode dulu, lalu SKU. Lembar retur ditulis per SKU.
    $cari   = cariMasterReturn($barcode, $sku);
    $master = $cari['master'];

    if ($master !== null) {
        // Identitas mengikuti master supaya nama di daftar tetap pendek dan
        // barcode/SKU-nya konsisten dengan katalog.
        $barcode = (string)$master['barcode'];
        $sku     = (string)$master['sku'];
        if ($nama === '') {
            $nama = (string)$master['nama'];
        }
        if ($cari['ganda']) {
            $peringatan[] = $awalan . 'SKU "' . $sku . '" dipakai lebih dari satu barang di master. '
                . 'Yang dipakai: ' . $master['nama'] . '. Isi barcode bila ingin memastikan.';
        }
    } else {
        if ($nama === '') {
            jsonError($awalan . 'Barang tidak ditemukan di master. Isi nama barangnya secara manual.');
        }
        $peringatan[] = $awalan . 'Barang ini belum terdaftar di master. Returnya tetap dicatat, '
            . 'tapi belum mempengaruhi perhitungan stok.';
    }

    $baris[] = [
        'tanggal'    => $tanggal,
        'no_pesanan' => $noPesanan,
        'master_id'  => $master ? (int)$master['id'] : null,
        'barcode'    => $barcode,
        'sku'        => $sku,
        'nama'       => $nama,
        'jumlah'     => $jumlah,
        'status'     => $status,
        'keterangan' => $ket,
    ];
}

/* --- Produk yang sama disebut dua kali ------------------------------------
 *
 * Dua baris berisi barang, keterangan retur, DAN catatan yang sama persis
 * tidak bisa dibedakan satu sama lain — nyaris selalu tombol yang tertekan
 * dua kali, dan akibatnya stok bertambah dua kali lipat. Itu ditolak, dengan
 * petunjuk yang benar: jadikan satu baris, qty-nya dijumlahkan.
 *
 * Yang catatannya BERBEDA tidak ditolak. "2 pcs sobek" dan "1 pcs salah kirim"
 * pada barang yang sama memang dua kejadian, dan menolaknya berarti memaksa
 * petugas menghapus keterangan yang justru berguna. Cukup diberi tahu, supaya
 * kalau memang keliru ketahuan sebelum tersimpan. */
if ($banyak) {
    $terlihat = [];
    foreach ($baris as $i => $b) {
        // Barang yang dikenal master dibandingkan lewat id-nya; yang tidak
        // dikenal lewat teks identitasnya, tanpa beda huruf besar-kecil.
        $barang = $b['master_id'] !== null
            ? 'm' . $b['master_id']
            : 't' . mb_strtolower($b['barcode'] . '|' . $b['sku'] . '|' . $b['nama']);

        $kunciPenuh = $barang . '|' . $b['status'] . '|' . mb_strtolower($b['keterangan']);
        if (isset($terlihat[$kunciPenuh])) {
            jsonError('"' . $b['nama'] . '" disebut dua kali dengan keterangan yang sama persis '
                . '(produk #' . ($terlihat[$kunciPenuh] + 1) . ' dan #' . ($i + 1) . '). '
                . 'Jadikan satu baris saja dan jumlahkan qty-nya, supaya stoknya tidak bertambah dua kali.');
        }
        $terlihat[$kunciPenuh] = $i;

        $kunciBarang = $barang . '|' . $b['status'];
        if (isset($terlihat[$kunciBarang])) {
            $peringatan[] = '"' . $b['nama'] . '" dicatat lebih dari satu baris dengan keterangan retur '
                . 'yang sama. Dicatat apa adanya — pastikan memang dua kejadian yang berbeda.';
        }
        $terlihat[$kunciBarang] = $i;
    }
}

/* --- Ubah satu baris ----------------------------------------------------- */
if ($id > 0) {
    $bersih = $baris[0];

    $lama = dbOne('SELECT * FROM retur WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($lama === null) {
        jsonError('Retur tidak ditemukan.', 404);
    }

    $masukIdLama = $lama['masuk_id'] === null ? null : (int)$lama['masuk_id'];

    dbTransaksi(static function (PDO $pdo) use ($id, $bersih, $masukIdLama) {
        $masukId = sinkronMasukRetur($pdo, $bersih, $masukIdLama);

        $st = $pdo->prepare(
            'UPDATE retur
                SET tanggal = ?, no_pesanan = ?, master_id = ?, barcode = ?, sku = ?,
                    nama = ?, jumlah = ?, status = ?, keterangan = ?, masuk_id = ?
              WHERE id = ?'
        );
        $st->execute([
            $bersih['tanggal'], $bersih['no_pesanan'], $bersih['master_id'],
            $bersih['barcode'], $bersih['sku'], $bersih['nama'], $bersih['jumlah'],
            $bersih['status'], $bersih['keterangan'], $masukId, $id,
        ]);
    });

    catatAktivitas('update', 'retur', $id, [
        'nama'       => $bersih['nama'],
        'barcode'    => $bersih['barcode'],
        'jumlah'     => $bersih['jumlah'],
        'no_pesanan' => $bersih['no_pesanan'],
        'status'     => $bersih['status'],
    ]);

    jsonOk([
        'id'         => $id,
        'ids'        => [$id],
        'peringatan' => $peringatan,
        'pesan'      => 'Retur diperbarui.',
    ]);
}

/* --- Catat baris baru ----------------------------------------------------- */

/* Satu transaksi untuk seluruh pesanan. INSERT-nya disiapkan sekali lalu
 * dipakai ulang; sinkronMasukRetur() membuat baris barang masuk milik tiap
 * baris, jadi tiap produk tetap punya tautan stoknya sendiri. */
$ids = dbTransaksi(static function (PDO $pdo) use ($baris) {
    $st = $pdo->prepare(
        'INSERT INTO retur
            (tanggal, no_pesanan, master_id, barcode, sku, nama, jumlah, status,
             keterangan, masuk_id, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $ids = [];
    foreach ($baris as $b) {
        $masukId = sinkronMasukRetur($pdo, $b, null);
        $st->execute([
            $b['tanggal'], $b['no_pesanan'], $b['master_id'], $b['barcode'],
            $b['sku'], $b['nama'], $b['jumlah'], $b['status'], $b['keterangan'],
            $masukId, userId(),
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
});

/* Satu catatan aktivitas per baris, bukan satu untuk seluruh pesanan. Tiap
 * baris bisa diubah dan dihapus sendiri-sendiri, dan masing-masing menggeser
 * stok sendiri — jejaknya harus bisa ditelusuri per baris juga. Nomor
 * pesanannya ikut tercatat, jadi satu pesanan tetap bisa dirangkai kembali
 * dari log. */
foreach ($baris as $i => $b) {
    catatAktivitas('create', 'retur', $ids[$i], [
        'nama'       => $b['nama'],
        'barcode'    => $b['barcode'],
        'jumlah'     => $b['jumlah'],
        'no_pesanan' => $b['no_pesanan'],
        'status'     => $b['status'],
    ]);
}

/* Hanya barang yang dikenal master yang benar-benar menambah stok; untuk yang
 * tidak dikenal peringatannya sudah dikirim di atas, dan menambahkan "stok
 * bertambah" di sini hanya akan bertentangan dengan peringatan itu. */
$unitKeStok  = 0;
$barisKeStok = 0;
foreach ($baris as $b) {
    if ($b['master_id'] !== null && returMenambahStok($b['status'])) {
        $unitKeStok += $b['jumlah'];
        $barisKeStok++;
    }
}
if ($barisKeStok > 0) {
    $peringatan[] = 'Stok bertambah ' . $unitKeStok . ' pcs'
        . ($banyak ? ' dari ' . $barisKeStok . ' produk' : '')
        . ' lewat barang masuk "' . KET_RETUR_MASUK . '".';
}

jsonOk([
    'id'         => $ids[0],
    'ids'        => $ids,
    'peringatan' => $peringatan,
    'pesan'      => $banyak
        ? count($ids) . ' produk retur dicatat'
            . ($noPesanan !== '' ? ' untuk pesanan ' . $noPesanan : '') . '.'
        : 'Retur dicatat.',
]);
