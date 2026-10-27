/* Periksa SETIAP sel di SETIAP laporan PDF: adakah yang masih terpotong?
 *
 * ADA KARENA LEBAR KOLOM TIDAK BOLEH DIKIRA-KIRA
 * Gudang melaporkan nama barang terpotong di laporan stok opname. Setelah
 * diperbaiki dan diperiksa dengan alat ini, ternyata EMPAT laporan lain juga
 * terpotong — master, riwayat, dashboard, dan log aktivitas — dengan 1.164 sel
 * yang isinya hilang, dan tidak satu pun pernah dilaporkan. Yang terpotong
 * memang tidak terlihat sebagai galat; ia terlihat seperti laporan biasa.
 *
 * Elipsis "..." hanya ditulis oleh potong() di includes/pdf.php, jadi
 * kehadirannya di akhir sebuah potongan teks berarti ada isi yang tidak muat.
 *
 * Aturan yang dijaga: kolom teks dibungkus ke beberapa baris, kolom angka
 * tidak. Panjang isi di katalog ini tidak bisa ditebak — barcode 16 karakter,
 * SKU berspasi 15 karakter, nama barang sampai 93 karakter.
 */
const { execFileSync } = require('child_process');
const B = process.env.UJI_BASE || 'http://127.0.0.1:8099';

function php(kode) {
  return execFileSync('php', ['-r', kode], { cwd: 'C:/web-stock', encoding: 'utf8' }).trim();
}

async function login() {
  let r = await fetch(B + '/login.php');
  let kuki = (r.headers.getSetCookie() || []).map(c => c.split(';')[0]);
  const csrf = ((await r.text()).match(/name="_csrf" value="([^"]+)"/) || [])[1];
  r = await fetch(B + '/login.php', {
    method: 'POST', redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: kuki.join('; ') },
    body: new URLSearchParams({ username: 'admin', password: 'admin123', _csrf: csrf })
  });
  const baru = (r.headers.getSetCookie() || []).map(c => c.split(';')[0]);
  if (baru.length) { kuki = baru; }
  return kuki.join('; ');
}

(async () => {
  const C = await login();
  const sesi = php("require 'includes/db.php'; echo dbValue('SELECT id FROM opname_sesi WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');");

  const alamat = [
    'jenis=opname&id=' + sesi,
    'jenis=opname&id=' + sesi + '&kategori=SAIFENU',
    'jenis=master',
    'jenis=masuk',
    'jenis=keluar',
    'jenis=riwayat',
    'jenis=retur',
    'jenis=deadstok',
    'jenis=pertukaran',
    'jenis=dashboard',
    'jenis=aktivitas',
  ];

  let total = 0;
  for (const a of alamat) {
    const res = await fetch(B + '/api/export/pdf.php?' + a, { headers: { Cookie: C } });
    const buf = Buffer.from(await res.arrayBuffer());
    if (buf.slice(0, 4).toString() !== '%PDF') {
      console.log('  GAGAL ' + a + ' — bukan PDF (HTTP ' + res.status + ')');
      total++;
      continue;
    }
    const t = buf.toString('latin1');
    const out = [];
    const re = /\(((?:\\.|[^\\()])*)\)\s*Tj/g;
    let m;
    while ((m = re.exec(t)) !== null) { out.push(m[1]); }

    /* Baris keterangan di kepala laporan memuat "..." secara sah? Tidak —
       satu-satunya penulis "..." adalah potong(). Tapi isi data bisa saja
       memang berakhir titik tiga; itu tidak ada di katalog ini. */
    const potong = out.filter(x => x.endsWith('...'));
    const tanda = potong.length ? 'TERPOTONG ' + potong.length : 'bersih';
    console.log('  ' + (potong.length ? 'GAGAL ' : 'OK    ') + a.padEnd(34) + tanda);
    if (potong.length) {
      [...new Set(potong)].slice(0, 6).forEach(x => console.log('          [' + x + ']'));
      total += potong.length;
    }
  }

  console.log();
  console.log(total ? 'ADA ' + total + ' SEL TERPOTONG' : 'OK — tidak ada sel yang terpotong di laporan mana pun.');
  process.exit(total ? 1 : 0);
})();
