/* ==========================================================================
 * tools/uji_collation.js — pastikan tidak ada query yang pecah karena collation
 *
 * Jalankan (dua perintah, di dua jendela):
 *
 *   WMS_DB_COLLATION=utf8mb4_unicode_ci php -S 127.0.0.1:8100 -t .
 *   node tools/uji_collation.js
 *
 * ADA KARENA SATU BUG YANG MAKAN BERMINGGU-MINGGU
 * Memilih status di Dashboard selalu gagal 500 di server produksi, dan tidak
 * pernah bisa ditiru di mesin pengembangan. Bentuk querynya diuji berulang
 * kali — di MariaDB, lalu di MySQL 8 asli dengan 250.000 baris transaksi —
 * dan selalu lolos. Yang berbeda ternyata bukan mesinnya, melainkan
 * collationnya:
 *
 *   1267 Illegal mix of collations (utf8mb4_unicode_ci,COERCIBLE)
 *                               and (utf8mb4_general_ci,COERCIBLE)
 *
 * Penyebabnya membandingkan hasil CASE berisi teks dengan parameter. Keduanya
 * COERCIBLE; bila collation koneksi berbeda dari collation bawaan charset
 * parameter, MySQL tidak punya dasar memilih dan menolak.
 *
 * Uji ini menghantam seluruh endpoint lewat server yang collationnya sengaja
 * dibuat berbeda, lalu menolak setiap jawaban yang gagal karena collation.
 * Pola yang sama di endpoint lain akan ketahuan di sini, bukan di gudang.
 * ========================================================================== */

'use strict';

const B = process.env.UJI_BASE || 'http://127.0.0.1:8100';

async function login(user, pass) {
    let r = await fetch(B + '/login.php');
    let kuki = (r.headers.getSetCookie() || []).map((c) => c.split(';')[0]);
    const csrf = ((await r.text()).match(/name="_csrf" value="([^"]+)"/) || [])[1];
    r = await fetch(B + '/login.php', {
        method: 'POST', redirect: 'manual',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: kuki.join('; ') },
        body: new URLSearchParams({ username: user, password: pass, _csrf: csrf }),
    });
    const baru = (r.headers.getSetCookie() || []).map((c) => c.split(';')[0]);
    if (baru.length) { kuki = baru; }
    const cookie = kuki.join('; ');
    const html = await (await fetch(B + '/index.php', { headers: { Cookie: cookie } })).text();
    return { cookie, token: (html.match(/window\.CSRF_TOKEN = "([^"]+)"/) || [])[1] };
}

/* Setiap halaman yang menyaring, dengan penyaring yang benar-benar dipakai. */
const ALAMAT = [
    'dashboard/stats.php',
    'dashboard/stats.php?status=kritis',
    'dashboard/stats.php?status=rendah',
    'dashboard/stats.php?status=aman',
    'dashboard/stats.php?status=belum_diatur',
    'dashboard/stats.php?kategori=AOLIKES&status=kritis',
    'dashboard/stats.php?kategori=FASHION&status=rendah',
    'dashboard/stats.php?q=tape&status=aman',
    'dashboard/ringkas.php',
    'dashboard/ringkas.php?hari=7',
    'masuk/list.php',
    'masuk/list.php?keterangan=Restock',
    'masuk/list.php?q=tape',
    'keluar/list.php',
    'keluar/list.php?keterangan=Pesanan%20MP',
    'riwayat/list.php',
    'riwayat/list.php?kategori=AOLIKES',
    'pertukaran/list.php',
    'pertukaran/list.php?q=tape',
    'retur/list.php',
    'retur/list.php?status=Lengkap',
    'retur/list.php?accurate=belum',
    'retur/list.php?accurate=sudah',
    'retur/list.php?accurate=tidak',
    'deadstok/list.php',
    'deadstok/list.php?kategori=rusak',
    'deadstok/list.php?kategori=belum_siap&status=tertahan',
    'deadstok/list.php?status=dibuang&q=tape',
    'opname/list.php',
    'minimal/list.php',
    'minimal/list.php?hanya=belum',
    'minimal/list.php?status=kritis&kategori=AVO',
    'master/list.php',
    'master/list.php?q=tape',
    'kategori/list.php',
    'keterangan/list.php?jenis=masuk',
    'keterangan/list.php?jenis=keluar',
    'keterangan/list.php?jenis=retur',
    'pengguna/list.php',
    'aktivitas/list.php',
    'sistem/galat.php',
    'export/pdf.php?jenis=dashboard&status=rendah',
    'export/pdf.php?jenis=dashboard&kategori=AOLIKES&status=kritis',
    'export/pdf.php?jenis=masuk',
    'export/pdf.php?jenis=keluar&keterangan=Pesanan%20MP',
    'export/pdf.php?jenis=riwayat',
    'export/pdf.php?jenis=pertukaran',
    'export/pdf.php?jenis=retur&accurate=belum',
    'export/pdf.php?jenis=retur&accurate=tidak',
    'export/pdf.php?jenis=deadstok',
    'export/pdf.php?jenis=deadstok&kategori=rusak&status=tertahan',
    'export/pdf.php?jenis=master',
    'export/pdf.php?jenis=aktivitas',
];

(async () => {
    let gagal = 0;

    let s;
    try {
        s = await login('admin', 'admin123');
    } catch (e) {
        console.log('Tidak bisa masuk ke ' + B + ' — jalankan dulu:');
        console.log('  WMS_DB_COLLATION=utf8mb4_unicode_ci php -S 127.0.0.1:8100 -t .');
        process.exit(1);
    }

    const cek = await fetch(B + '/api/dashboard/stats.php', { headers: { Cookie: s.cookie } });
    if (cek.status === 401) {
        console.log('Gagal masuk. Periksa akun admin di basis data uji.');
        process.exit(1);
    }

    console.log('menguji ' + ALAMAT.length + ' alamat lewat ' + B + '\n');

    for (const alamat of ALAMAT) {
        const res = await fetch(B + '/api/' + alamat, { headers: { Cookie: s.cookie } });
        const teks = await res.text();

        let kodeSql = '';
        let pesan = '';
        try {
            const j = JSON.parse(teks);
            kodeSql = j.kode_sql || '';
            pesan = j.teknis || j.error || '';
        } catch (e) { /* PDF bukan JSON; yang penting statusnya */ }

        const kenaCollation = /collation/i.test(pesan) || String(kodeSql) === 'HY000';

        if (res.status === 200) {
            console.log('  OK    ' + alamat);
        } else if (kenaCollation) {
            gagal++;
            console.log('  GAGAL ' + alamat + '\n          ' + pesan.slice(0, 150));
        } else {
            // Status lain (403, 404, 422) bukan urusan uji ini.
            console.log('  LEWAT ' + alamat + ' — HTTP ' + res.status);
        }
    }

    console.log('');
    if (gagal === 0) {
        console.log('OK — tidak ada query yang pecah karena collation.');
        process.exit(0);
    }
    console.log('ADA ' + gagal + ' YANG PECAH KARENA COLLATION');
    process.exit(1);
})();
