/* Penanda Accurate tiga keadaan: belum / sudah / tidak masuk.
 *
 * Yang paling penting diuji di sini: "tidak masuk" TIDAK boleh terhitung
 * sebagai "belum", dan "Tandai Accurate" massal tidak boleh menyapu baris yang
 * sudah diputuskan tidak masuk. Dua-duanya akan membatalkan keputusan orang
 * secara diam-diam, dan baru ketahuan saat angkanya tidak cocok dengan
 * Accurate.
 */
const { execFileSync } = require('child_process');
const B = process.env.UJI_BASE || 'http://127.0.0.1:8099';

function php(kode) {
  return execFileSync('php', ['-r', kode], { cwd: 'C:/web-stock', encoding: 'utf8' }).trim();
}
const nilai = (sql) => php("require 'includes/db.php'; echo (string)dbValue(\"" + sql + "\");");

async function login(user, pass) {
  let r = await fetch(B + '/login.php');
  let kuki = (r.headers.getSetCookie() || []).map(c => c.split(';')[0]);
  const csrf = ((await r.text()).match(/name="_csrf" value="([^"]+)"/) || [])[1];
  r = await fetch(B + '/login.php', {
    method: 'POST', redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: kuki.join('; ') },
    body: new URLSearchParams({ username: user, password: pass, _csrf: csrf })
  });
  const baru = (r.headers.getSetCookie() || []).map(c => c.split(';')[0]);
  if (baru.length) { kuki = baru; }
  const cookie = kuki.join('; ');
  const html = await (await fetch(B + '/index.php', { headers: { Cookie: cookie } })).text();
  return { cookie, token: (html.match(/window\.CSRF_TOKEN = "([^"]+)"/) || [])[1] };
}
async function get(s, path) {
  const res = await fetch(B + '/api/' + path, { headers: { Cookie: s.cookie } });
  const t = await res.text();
  let j = null; try { j = JSON.parse(t); } catch (e) {}
  return { status: res.status, body: j, teks: t.slice(0, 300) };
}
async function post(s, path, body) {
  const res = await fetch(B + '/api/' + path, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: s.cookie },
    body: JSON.stringify(Object.assign({ _csrf: s.token }, body))
  });
  const t = await res.text();
  let j = null; try { j = JSON.parse(t); } catch (e) {}
  return { status: res.status, body: j, teks: t.slice(0, 300) };
}

let gagal = 0;
function cek(label, dapat, harap) {
  const ok = JSON.stringify(dapat) === JSON.stringify(harap);
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(56)
    + (ok ? JSON.stringify(dapat) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

const A = { sku: 'AO-0001' };
const C = { sku: '101066' };
const D = { sku: '102382' };
const PESANAN = 'UJI-ACC';
const kolom = (id, k) => nilai('SELECT ' + k + ' FROM retur WHERE id = ' + id);

(async () => {
  const returAwal = nilai("SELECT COALESCE(MAX(id),0) FROM retur");
  const masukAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_masuk");

  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  console.log('\n=== Siapkan tiga baris retur ===');
  let r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-22', no_pesanan: PESANAN,
    items: [
      { sku: A.sku, jumlah: 1, status: 'Lengkap', keterangan: 'satu' },
      { sku: C.sku, jumlah: 1, status: 'Lengkap', keterangan: 'dua' },
      { sku: D.sku, jumlah: 1, status: 'Lengkap', keterangan: 'tiga' }
    ]
  });
  cek('tiga baris tercatat', r.status, 200);
  const ids = r.body.ids;
  cek('bawaannya belum semua', nilai("SELECT COUNT(*) FROM retur WHERE id IN (" + ids.join(',') + ") AND accurate = 0"), '3');

  /* ---------------------------------------------------------------- */
  console.log('\n=== A. keadaan ketiga: tidak masuk ===');
  r = await post(S, 'retur/accurate.php', { id: ids[0], tanda: 'tidak' });
  cek('HTTP', r.status, 200);
  cek('satu baris berubah', r.body.diubah, 1);
  cek('tandanya dikembalikan', r.body.tanda, 'tidak');
  cek('pesannya menyebut labelnya', r.body.pesan, '1 retur ditandai "Tidak masuk".');
  cek('tersimpan sebagai 2', kolom(ids[0], 'accurate'), '2');
  cek('jejak waktunya ikut tercatat', kolom(ids[0], 'accurate_at') !== '', true);
  cek('pelakunya ikut tercatat', kolom(ids[0], 'accurate_user') !== '', true);

  r = await post(S, 'retur/accurate.php', { id: ids[0], tanda: 'tidak' });
  cek('menandai ulang: tidak ada yang berubah', r.body.diubah, 0);

  r = await post(S, 'retur/accurate.php', { id: ids[0], tanda: 'belum' });
  cek('kembali ke belum', kolom(ids[0], 'accurate'), '0');
  cek('jejaknya ikut dikosongkan', kolom(ids[0], 'accurate_at'), '');
  cek('pelakunya ikut dikosongkan', kolom(ids[0], 'accurate_user'), '');

  console.log('\n=== B. bentuk lama { sudah } masih jalan ===');
  r = await post(S, 'retur/accurate.php', { id: ids[1], sudah: true });
  cek('HTTP', r.status, 200);
  cek('tersimpan sebagai 1', kolom(ids[1], 'accurate'), '1');
  cek('masih membawa sudah=true untuk pemanggil lama', r.body.sudah, true);
  cek('tanda ikut dikirim', r.body.tanda, 'sudah');
  r = await post(S, 'retur/accurate.php', { id: ids[1], sudah: false });
  cek('sudah:false -> belum', kolom(ids[1], 'accurate'), '0');

  console.log('\n=== C. tanda ngawur ditolak ===');
  r = await post(S, 'retur/accurate.php', { id: ids[1], tanda: 'mungkin' });
  cek('ditolak', r.status, 400);
  cek('pesannya menyebut ketiga pilihannya',
      r.body.error.indexOf('belum, sudah, atau tidak') >= 0, true);
  cek('nilainya tidak bergeser', kolom(ids[1], 'accurate'), '0');
  cek('tanda angka mentah juga ditolak',
      (await post(S, 'retur/accurate.php', { id: ids[1], tanda: '2' })).status, 400);
  cek('masih 0', kolom(ids[1], 'accurate'), '0');

  /* ---------------------------------------------------------------- */
  console.log('\n=== D. "tidak masuk" bukan "belum" ===');
  await post(S, 'retur/accurate.php', { id: ids[0], tanda: 'tidak' });
  await post(S, 'retur/accurate.php', { id: ids[1], tanda: 'sudah' });
  // ids[2] dibiarkan belum.

  let d = await get(S, 'retur/list.php?q=' + PESANAN);
  cek('HTTP', d.status, 200);
  cek('tiga baris', d.body.total, 3);
  cek('yang belum dihitung hanya yang benar-benar belum', d.body.belum_accurate, 1);
  cek('yang tidak masuk dihitung terpisah', d.body.tidak_accurate, 1);

  console.log('\n=== E. penyaring ===');
  d = await get(S, 'retur/list.php?q=' + PESANAN + '&accurate=tidak');
  cek('penyaring tidak: satu baris', d.body.total, 1);
  cek('yang keluar memang yang bertanda 2', d.body.rows[0].accurate, 2);
  cek('penanda terbawa di jawaban', d.body.accurate, 'tidak');
  d = await get(S, 'retur/list.php?q=' + PESANAN + '&accurate=sudah');
  cek('penyaring sudah: satu baris', d.body.total, 1);
  cek('bertanda 1', d.body.rows[0].accurate, 1);
  d = await get(S, 'retur/list.php?q=' + PESANAN + '&accurate=belum');
  cek('penyaring belum: satu baris', d.body.total, 1);
  cek('bertanda 0', d.body.rows[0].accurate, 0);
  cek('"belum" tidak menyeret yang tidak masuk',
      d.body.rows.every(x => x.accurate === 0), true);
  d = await get(S, 'retur/list.php?q=' + PESANAN + '&accurate=ngawur');
  cek('penyaring ngawur diabaikan, bukan galat', [d.status, d.body.total, d.body.accurate], [200, 3, '']);

  /* ---------------------------------------------------------------- */
  console.log('\n=== F. tandai massal tidak menyapu "tidak masuk" ===');
  const sebelum = kolom(ids[0], 'accurate');
  r = await post(S, 'retur/accurate.php', { ids: [ids[2]], tanda: 'sudah' });
  cek('yang belum ditandai sudah', r.body.diubah, 1);
  cek('yang tidak masuk tetap 2', kolom(ids[0], 'accurate'), sebelum);
  /* Kalau layar mengirim seluruh baris (termasuk yang bertanda 2), endpoint
     memang akan mengubahnya — karena itu penyaringannya ada di layar dan
     diuji di tools/uji_html.js. Di sini yang dipastikan: endpoint hanya
     menyentuh id yang benar-benar dikirim. */
  cek('endpoint hanya menyentuh id yang dikirim',
      nilai("SELECT COUNT(*) FROM retur WHERE id IN (" + ids.join(',') + ") AND accurate = 1"), '2');

  console.log('\n=== G. banyak id sekaligus ===');
  /* Disamakan dulu ke "belum". Tanpa ini sebagian baris sudah bernilai 2 dari
     bagian F, dan klausa `accurate <> ?` melewatinya — jadi yang terhitung
     cuma selisihnya, dan jalur massalnya tidak benar-benar teruji. */
  await post(S, 'retur/accurate.php', { ids: ids, tanda: 'belum' });
  cek('disamakan ke belum', nilai("SELECT COUNT(*) FROM retur WHERE id IN (" + ids.join(',') + ") AND accurate = 0"), '3');
  r = await post(S, 'retur/accurate.php', { ids: ids, tanda: 'tidak' });
  cek('tiga baris jadi tidak masuk', r.body.diubah, 3);
  r = await post(S, 'retur/accurate.php', { ids: ids, tanda: 'tidak' });
  cek('diulang: tidak ada yang berubah lagi', r.body.diubah, 0);
  cek('semuanya 2', nilai("SELECT COUNT(*) FROM retur WHERE id IN (" + ids.join(',') + ") AND accurate = 2"), '3');
  d = await get(S, 'retur/list.php?q=' + PESANAN);
  cek('tidak ada lagi yang belum', d.body.belum_accurate, 0);
  cek('ketiganya tidak masuk', d.body.tidak_accurate, 3);

  console.log('\n=== H. stok tidak tersentuh sama sekali ===');
  const stokSql = "SELECT COALESCE(SUM(jumlah),0) FROM barang_masuk WHERE id > " + masukAwal + " AND deleted_at IS NULL";
  cek('barang masuk dari retur tetap 3 pcs', nilai(stokSql), '3');

  console.log('\n=== I. hak akses ===');
  const sandi = 'UjiAcc#2026';
  const hash = php("echo password_hash('" + sandi + "', PASSWORD_DEFAULT);");
  const buat = (peran, akses) => php("require 'includes/db.php';"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiacc']);"
    + "db()->prepare('INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses) VALUES (?,?,?,?,1,?)')"
    + "->execute(['ujiacc','" + hash + "','Uji Accurate','" + peran + "','" + akses + "']); echo 'ok';");

  buat('operator', '["dashboard","retur"]');
  let op = await login('ujiacc', sandi);
  r = await post(op, 'retur/accurate.php', { id: ids[0], tanda: 'belum' });
  cek('operator boleh menandai', r.status, 200);
  cek('tersimpan oleh operator', kolom(ids[0], 'accurate'), '0');

  buat('viewer', '["dashboard","retur"]');
  op = await login('ujiacc', sandi);
  cek('hanya-baca ditolak',
      (await post(op, 'retur/accurate.php', { id: ids[0], tanda: 'tidak' })).status, 403);
  cek('nilainya tetap 0', kolom(ids[0], 'accurate'), '0');

  console.log('\n=== J. unduhan PDF menerima keadaan ketiga ===');
  for (const a of ['', 'belum', 'sudah', 'tidak', 'ngawur']) {
    const res = await fetch(B + '/api/export/pdf.php?jenis=retur&accurate=' + a,
      { headers: { Cookie: S.cookie } });
    const buf = Buffer.from(await res.arrayBuffer());
    cek('accurate=' + (a || '(kosong)'),
        [res.status, buf.slice(0, 4).toString()], [200, '%PDF']);
  }

  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec('DELETE FROM activity_log WHERE entitas = \\'retur\\' AND entitas_id > " + returAwal + "');"
    + "db()->exec('DELETE FROM retur WHERE id > " + returAwal + "');"
    + "db()->exec('DELETE FROM barang_masuk WHERE id > " + masukAwal + "');"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiacc']); echo 'ok';");
  cek('retur bersih', nilai("SELECT COUNT(*) FROM retur WHERE id > " + returAwal), '0');
  cek('barang masuk bersih', nilai("SELECT COUNT(*) FROM barang_masuk WHERE id > " + masukAwal), '0');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
