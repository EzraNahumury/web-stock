/* Menu Barang deadstok: dua kategori, tiga keadaan, dan satu-satunya jalur
 * yang boleh menggerakkan stok.
 *
 * Yang paling penting diuji: mencatat deadstok TIDAK boleh mengubah stok, dan
 * hanya "dibuang" yang boleh. Kalau yang pertama salah, setiap stok opname akan
 * melaporkan selisih palsu pada barang-barang itu; kalau yang kedua salah,
 * barang yang sudah dibuang tetap terhitung ada.
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
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(58)
    + (ok ? JSON.stringify(dapat) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

/* Barang nyata dari master. */
const A = { sku: 'AO-0001', barcode: '6936047373262', id: 2 };
/* Penanda baris milik suite ini. Angka ringkasan dihitung atas SELURUH hasil
   penyaring, jadi tanpa penanda ia ikut menghitung data nyata dan sisa jalan
   sebelumnya — dan suite jadi lulus-tidaknya bergantung isi basis data. */
const TANDA = 'UJIDS';
const C = { sku: '101066', id: 3 };

const stok = (id) =>
  "SELECT m.stok_awal"
  + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " FROM master_barang m WHERE m.id = " + id;
const kolom = (id, k) => nilai('SELECT ' + k + ' FROM deadstok WHERE id = ' + id);

(async () => {
  const dsAwal = nilai("SELECT COALESCE(MAX(id),0) FROM deadstok");
  const klAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_keluar");

  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  console.log('\n=== A. mencatat deadstok TIDAK mengubah stok ===');
  const stokA0 = Number(nilai(stok(A.id)));
  let r = await post(S, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 4,
    keterangan: TANDA + ' jahitan lepas'
  });
  cek('HTTP', r.status, 200);
  cek('pesannya menyebut kategorinya', r.body.pesan, 'Barang dicatat sebagai Barang Rusak.');
  const id1 = r.body.id;
  cek('tersimpan tertahan', kolom(id1, 'status'), 'tertahan');
  cek('STOK TIDAK BERGERAK', Number(nilai(stok(A.id))), stokA0);
  cek('tidak ada baris barang keluar yang lahir',
      nilai("SELECT COUNT(*) FROM barang_keluar WHERE id > " + klAwal), '0');
  cek('diberi tahu bahwa stok tidak berkurang',
      (r.body.peringatan || []).some(p => p.indexOf('Stok TIDAK berkurang') >= 0), true);

  let d = await get(S, 'deadstok/list.php?q=' + TANDA);
  cek('siap jual = stok - tertahan', d.body.rows[0].siap, stokA0 - 4);
  cek('stok akhirnya ikut dikirim', d.body.rows[0].stok_akhir, stokA0);
  cek('unit tertahan terhitung', d.body.unit_tertahan, 4);
  cek('kategori rusak terhitung', d.body.unit_rusak, 4);
  cek('belum siap jual masih 0', d.body.unit_belum_siap, 0);

  console.log('\n=== B. kategori kedua ===');
  r = await post(S, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'belum_siap', sku: C.sku, jumlah: 2,
    keterangan: TANDA + ' belum dilabeli'
  });
  cek('HTTP', r.status, 200);
  const id2 = r.body.id;
  cek('tersimpan sebagai belum_siap', kolom(id2, 'kategori'), 'belum_siap');
  d = await get(S, 'deadstok/list.php?q=' + TANDA);
  cek('dua kategori terhitung terpisah',
      [d.body.unit_rusak, d.body.unit_belum_siap], [4, 2]);
  cek('daftar kategorinya dikirim server',
      Object.keys(d.body.kategori_options), ['rusak', 'belum_siap']);
  cek('daftar keadaannya dikirim server',
      Object.keys(d.body.status_options), ['tertahan', 'beres', 'dibuang']);

  console.log('\n=== C. kategori ngawur ditolak ===');
  r = await post(S, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'hilang', sku: A.sku, jumlah: 1 });
  cek('ditolak', r.status, 400);
  cek('pesannya menyebut kedua kategorinya',
      r.body.error.indexOf('Barang Rusak') >= 0 && r.body.error.indexOf('Barang Belum Siap Jual') >= 0, true);
  cek('qty 0 ditolak',
      (await post(S, 'deadstok/save.php', { tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 0, keterangan: TANDA })).status, 400);
  cek('tanpa barang ditolak',
      (await post(S, 'deadstok/save.php', { tanggal: '2026-10-22', kategori: 'rusak', jumlah: 1 })).status, 400);

  console.log('\n=== D. beres: stok tetap tidak bergerak ===');
  const sB = Number(nilai(stok(C.id)));
  r = await post(S, 'deadstok/status.php', { id: id2, status: 'beres' });
  cek('HTTP', r.status, 200);
  cek('satu baris berubah', r.body.diubah, 1);
  cek('tersimpan beres', kolom(id2, 'status'), 'beres');
  cek('jejaknya tercatat', kolom(id2, 'selesai_at') !== '', true);
  cek('STOK TIDAK BERGERAK', Number(nilai(stok(C.id))), sB);
  cek('tidak ada baris barang keluar', kolom(id2, 'keluar_id'), '');
  d = await get(S, 'deadstok/list.php?q=' + TANDA);
  cek('tidak lagi menahan', d.body.unit_belum_siap, 0);
  cek('terhitung sebagai sudah siap jual', d.body.unit_beres, 2);

  console.log('\n=== E. dibuang: INI yang mengurangi stok ===');
  const sD = Number(nilai(stok(A.id)));
  r = await post(S, 'deadstok/status.php', { id: id1, status: 'dibuang' });
  cek('HTTP', r.status, 200);
  cek('tersimpan dibuang', kolom(id1, 'status'), 'dibuang');
  cek('STOK BERKURANG 4', Number(nilai(stok(A.id))), sD - 4);
  cek('lahir satu baris barang keluar', kolom(id1, 'keluar_id') !== '', true);
  cek('keterangannya yang terkunci',
      nilai("SELECT keterangan FROM barang_keluar WHERE id = " + kolom(id1, 'keluar_id')), 'Deadstok Dibuang');
  cek('qty-nya sama', nilai("SELECT jumlah FROM barang_keluar WHERE id = " + kolom(id1, 'keluar_id')), '4');
  cek('diberi tahu stok berkurang',
      (r.body.peringatan || []).some(p => p.indexOf('Stok berkurang 4 pcs') >= 0), true);

  console.log('\n=== F. membatalkan pembuangan mengembalikan stok ===');
  const keluarId = kolom(id1, 'keluar_id');
  r = await post(S, 'deadstok/status.php', { id: id1, status: 'tertahan' });
  cek('HTTP', r.status, 200);
  cek('STOK KEMBALI', Number(nilai(stok(A.id))), sD);
  cek('baris barang keluarnya dihapus lunak',
      nilai("SELECT deleted_at IS NOT NULL FROM barang_keluar WHERE id = " + keluarId), '1');
  cek('tautannya DIPERTAHANKAN', kolom(id1, 'keluar_id'), keluarId);
  cek('jejak keputusannya dikosongkan', kolom(id1, 'selesai_at'), '');

  r = await post(S, 'deadstok/status.php', { id: id1, status: 'dibuang' });
  cek('dibuang lagi: baris yang SAMA dipakai ulang', kolom(id1, 'keluar_id'), keluarId);
  cek('tidak beranak baris baru',
      nilai("SELECT COUNT(*) FROM barang_keluar WHERE id > " + klAwal), '1');
  cek('stok turun lagi', Number(nilai(stok(A.id))), sD - 4);

  console.log('\n=== G. tidak boleh membuang melebihi stok ===');
  const tersedia = Number(nilai(stok(C.id)));
  r = await post(S, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'rusak', sku: C.sku, jumlah: tersedia + 5, keterangan: TANDA });
  cek('mencatatnya boleh (barangnya nyata)', r.status, 200);
  const idBanyak = r.body.id;
  cek('tapi diberi peringatan siap jual jadi minus',
      (r.body.peringatan || []).some(p => p.indexOf('siap jual jadi') >= 0), true);
  r = await post(S, 'deadstok/status.php', { id: idBanyak, status: 'dibuang' });
  cek('membuangnya DITOLAK', r.status, 422);
  cek('pesannya menyebut stok tersedia', r.body.error.indexOf('Tersedia ' + tersedia) >= 0, true);
  cek('stok tidak bergeser', Number(nilai(stok(C.id))), tersedia);
  cek('statusnya tetap tertahan', kolom(idBanyak, 'status'), 'tertahan');

  console.log('\n=== H. dua baris barang sama: dijumlahkan dulu ===');
  /* Masing-masing muat, tapi jumlahnya tidak. Diperiksa per barang SEBELUM
     transaksi, bukan per baris di dalamnya — memeriksanya satu per satu akan
     meloloskan baris terakhir dan membuat stok minus.

     Dipakai barang A, bukan C: stok C memang 0, dan dua baris berisi 0 pcs
     tidak pernah sampai ke pemeriksaan ini karena qty-nya sendiri ditolak. */
  await post(S, 'deadstok/status.php', { id: idBanyak, status: 'beres' });
  const sisa = Number(nilai(stok(A.id)));
  cek('barang ujinya memang berstok', sisa > 0, true);
  const a1 = (await post(S, 'deadstok/save.php',
    { tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: sisa, keterangan: TANDA })).body.id;
  const a2 = (await post(S, 'deadstok/save.php',
    { tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: sisa, keterangan: TANDA })).body.id;
  r = await post(S, 'deadstok/status.php', { ids: [a1, a2], status: 'dibuang' });
  cek('ditolak karena totalnya melebihi stok', r.status, 422);
  cek('TIDAK ADA yang terlanjur dibuang',
      nilai("SELECT COUNT(*) FROM deadstok WHERE id IN (" + a1 + "," + a2 + ") AND status = 'dibuang'"), '0');
  cek('stok utuh', Number(nilai(stok(A.id))), sisa);
  r = await post(S, 'deadstok/status.php', { ids: [a1], status: 'dibuang' });
  cek('satu baris saja: boleh', r.status, 200);
  cek('stok turun sampai habis', Number(nilai(stok(A.id))), 0);
  await post(S, 'deadstok/status.php', { ids: [a1], status: 'tertahan' });
  cek('dikembalikan lagi', Number(nilai(stok(A.id))), sisa);

  console.log('\n=== I. barang tak dikenal master ===');
  r = await post(S, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'rusak', sku: 'TIDAK-ADA-XYZ', nama: 'Barang Asing', jumlah: 1, keterangan: TANDA });
  cek('boleh dicatat', r.status, 200);
  const idAsing = r.body.id;
  cek('diberi tahu belum bisa dibuang',
      (r.body.peringatan || []).some(p => p.indexOf('belum bisa ditandai dibuang') >= 0), true);
  r = await post(S, 'deadstok/status.php', { id: idAsing, status: 'dibuang' });
  cek('membuangnya ditolak', r.status, 422);
  cek('pesannya mengarahkan ke Master', r.body.error.indexOf('Master') >= 0, true);
  cek('statusnya tetap', kolom(idAsing, 'status'), 'tertahan');
  cek('ditandai beres: boleh',
      (await post(S, 'deadstok/status.php', { id: idAsing, status: 'beres' })).status, 200);

  console.log('\n=== J. penyaring ===');
  d = await get(S, 'deadstok/list.php?kategori=rusak');
  cek('penyaring kategori', d.body.rows.every(x => x.kategori === 'rusak'), true);
  d = await get(S, 'deadstok/list.php?status=dibuang');
  cek('penyaring keadaan', d.body.rows.every(x => x.status === 'dibuang'), true);
  d = await get(S, 'deadstok/list.php?kategori=ngawur&status=ngawur');
  cek('nilai ngawur diabaikan, bukan galat ENUM',
      [d.status, d.body.kategori, d.body.status], [200, '', '']);
  d = await get(S, 'deadstok/list.php?q=' + TANDA + ' jahitan');
  cek('pencarian menjangkau keterangan', d.body.total >= 1, true);

  console.log('\n=== K. hapus mengembalikan stok ===');
  const stokSebelumHapus = Number(nilai(stok(A.id)));
  cek('baris id1 sedang dibuang', kolom(id1, 'status'), 'dibuang');
  r = await post(S, 'deadstok/delete.php', { id: id1 });
  cek('HTTP', r.status, 200);
  cek('STOK KEMBALI BERTAMBAH', Number(nilai(stok(A.id))), stokSebelumHapus + 4);
  cek('barisnya hilang dari daftar',
      nilai("SELECT COUNT(*) FROM deadstok WHERE id = " + id1 + " AND deleted_at IS NULL"), '0');
  cek('baris barang keluarnya ikut terhapus',
      nilai("SELECT deleted_at IS NOT NULL FROM barang_keluar WHERE id = " + keluarId), '1');
  cek('diberi tahu stok bertambah lagi',
      (r.body.peringatan || []).some(p => p.indexOf('kembali bertambah') >= 0), true);

  console.log('\n=== L. hak akses ===');
  const sandi = 'UjiDs#2026';
  const hash = php("echo password_hash('" + sandi + "', PASSWORD_DEFAULT);");
  const buat = (peran, akses) => php("require 'includes/db.php';"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujids']);"
    + "db()->prepare('INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses) VALUES (?,?,?,?,1,?)')"
    + "->execute(['ujids','" + hash + "','Uji Deadstok','" + peran + "','" + akses + "']); echo 'ok';");

  buat('operator', '["dashboard","deadstok"]');
  let op = await login('ujids', sandi);
  cek('operator boleh melihat', (await get(op, 'deadstok/list.php')).status, 200);
  r = await post(op, 'deadstok/save.php', {
    tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 1, keterangan: TANDA });
  cek('operator boleh mencatat', r.status, 200);
  const idOp = r.body.id;
  cek('operator boleh memindahkan keadaan',
      (await post(op, 'deadstok/status.php', { id: idOp, status: 'beres' })).status, 200);
  cek('operator TIDAK boleh mengubah catatan tersimpan',
      (await post(op, 'deadstok/save.php', { id: idOp, tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 2, keterangan: TANDA })).status, 403);
  cek('operator TIDAK boleh menghapus',
      (await post(op, 'deadstok/delete.php', { id: idOp })).status, 403);

  buat('viewer', '["dashboard","deadstok"]');
  op = await login('ujids', sandi);
  cek('hanya-baca boleh melihat', (await get(op, 'deadstok/list.php')).status, 200);
  cek('hanya-baca ditolak mencatat',
      (await post(op, 'deadstok/save.php', { tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 1, keterangan: TANDA })).status, 403);
  cek('hanya-baca ditolak memindahkan keadaan',
      (await post(op, 'deadstok/status.php', { id: idOp, status: 'dibuang' })).status, 403);

  buat('operator', '["dashboard"]');
  op = await login('ujids', sandi);
  cek('tanpa menu deadstok: melihat ditolak', (await get(op, 'deadstok/list.php')).status, 403);
  cek('tanpa menu deadstok: mencatat ditolak',
      (await post(op, 'deadstok/save.php', { tanggal: '2026-10-22', kategori: 'rusak', sku: A.sku, jumlah: 1, keterangan: TANDA })).status, 403);
  /* menuLaporan() mengembalikan null untuk jenis tak dikenal, dan pemeriksaan
     hak aksesnya ikut dilewati — jadi laporan baru WAJIB terdaftar di sana. */
  const pdfTolak = await fetch(B + '/api/export/pdf.php?jenis=deadstok', { headers: { Cookie: op.cookie } });
  cek('tanpa menu deadstok: unduhan PDF ditolak', pdfTolak.status, 403);

  console.log('\n=== M. unduhan PDF ===');
  for (const qs of ['', '&kategori=rusak', '&kategori=belum_siap', '&status=dibuang',
                    '&status=tertahan', '&kategori=ngawur&status=ngawur', '&q=jahitan']) {
    const res = await fetch(B + '/api/export/pdf.php?jenis=deadstok' + qs, { headers: { Cookie: S.cookie } });
    const buf = Buffer.from(await res.arrayBuffer());
    cek('pdf' + (qs || ' (tanpa penyaring)'), [res.status, buf.slice(0, 4).toString()], [200, '%PDF']);
  }

  console.log('\n=== N. layar lain tidak terganggu ===');
  cek('retur masih jalan', (await get(S, 'retur/list.php')).status, 200);
  cek('barang keluar masih jalan', (await get(S, 'keluar/list.php')).status, 200);
  cek('"Deadstok Dibuang" ada di dropdown keterangan keluar',
      (await get(S, 'keterangan/list.php?jenis=keluar')).body.rows.some(x => x.nama === 'Deadstok Dibuang'), true);
  cek('dan terkunci dari penghapusan',
      nilai("SELECT terkunci FROM keterangan WHERE jenis='keluar' AND nama='Deadstok Dibuang'"), '1');

  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec('DELETE FROM activity_log WHERE entitas = \\'deadstok\\'');"
    + "db()->exec('DELETE FROM deadstok WHERE id > " + dsAwal + "');"
    + "db()->exec('DELETE FROM barang_keluar WHERE id > " + klAwal + "');"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujids']); echo 'ok';");
  cek('deadstok bersih', nilai("SELECT COUNT(*) FROM deadstok WHERE id > " + dsAwal), '0');
  cek('barang keluar bersih', nilai("SELECT COUNT(*) FROM barang_keluar WHERE id > " + klAwal), '0');
  cek('stok produk A kembali seperti semula', Number(nilai(stok(A.id))), stokA0);

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
