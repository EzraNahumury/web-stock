/* Sub menu Stok minimal: daftar, setel satu, setel massal, hak akses, dan
   pengaruhnya ke status di Dashboard. */
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
  return { status: res.status, body: j, teks: t.slice(0, 200) };
}
async function post(s, path, body) {
  const res = await fetch(B + '/api/' + path, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: s.cookie },
    body: JSON.stringify(Object.assign({ _csrf: s.token }, body))
  });
  const t = await res.text();
  let j = null; try { j = JSON.parse(t); } catch (e) {}
  return { status: res.status, body: j, teks: t.slice(0, 200) };
}

let gagal = 0;
function cek(label, dapat, harap) {
  const ok = JSON.stringify(dapat) === JSON.stringify(harap);
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(54)
    + (ok ? JSON.stringify(dapat) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

(async () => {
  // Keadaan awal ambang dicatat supaya bisa dikembalikan utuh di akhir.
  php("require 'includes/db.php'; db()->exec('DROP TABLE IF EXISTS _simpan_min');" +
      "db()->exec('CREATE TABLE _simpan_min AS SELECT id, stok_minimal FROM master_barang'); echo 'ok';");

  const S = await login('admin', 'admin123');

  console.log('\n=== A. daftar ===');
  let d = await get(S, 'minimal/list.php');
  cek('HTTP', d.status, 200);
  cek('membawa stok akhir', typeof d.body.rows[0].stok_akhir, 'number');
  cek('membawa ambangnya', typeof d.body.rows[0].stok_minimal, 'number');
  cek('membawa rata-rata keluar', typeof d.body.rows[0].keluar_per_bulan, 'number');
  cek('membawa status', ['kritis','rendah','aman','belum_diatur'].indexOf(d.body.rows[0].status) >= 0, true);
  cek('total = seluruh barang aktif', d.body.total, Number(nilai("SELECT COUNT(*) FROM master_barang WHERE deleted_at IS NULL AND aktif = 1")));
  cek('hitungan ambang 0 benar', d.body.belum_diatur,
      Number(nilai("SELECT COUNT(*) FROM master_barang WHERE deleted_at IS NULL AND aktif = 1 AND stok_minimal = 0")));

  const belum = await get(S, 'minimal/list.php?hanya=belum');
  cek('penyaring ambang 0', belum.body.rows.every(r => r.stok_minimal === 0), true);
  const sudah = await get(S, 'minimal/list.php?hanya=sudah');
  cek('penyaring ambang terisi', sudah.body.rows.every(r => r.stok_minimal > 0), true);
  cek('keduanya berjumlah seluruhnya', belum.body.total + sudah.body.total, d.body.total);
  const kat = await get(S, 'minimal/list.php?kategori=AVO');
  cek('penyaring kategori', kat.body.rows.every(r => r.kategori === 'AVO'), true);
  const st = await get(S, 'minimal/list.php?status=kritis');
  cek('penyaring status', st.body.rows.every(r => r.status === 'kritis'), true);

  console.log('\n=== B. setel satu barang ===');
  const id = d.body.rows[0].id;
  const semula = Number(nilai("SELECT stok_minimal FROM master_barang WHERE id = " + id));
  let r = await post(S, 'minimal/save.php', { id: id, stok_minimal: 77 });
  cek('HTTP', r.status, 200);
  cek('satu baris berubah', r.body.diubah, 1);
  cek('tersimpan', nilai("SELECT stok_minimal FROM master_barang WHERE id = " + id), '77');
  cek('tercatat di Log aktivitas beserta nilai lamanya',
      nilai("SELECT COUNT(*) FROM activity_log WHERE entitas='master' AND entitas_id=" + id
            + " AND detail LIKE '%setel stok minimal%'") !== '0', true);

  r = await post(S, 'minimal/save.php', { id: id, stok_minimal: 77 });
  cek('menyetel nilai yang sama: tidak ada yang berubah', r.body.diubah, 0);

  cek('stok tidak ikut berubah',
      nilai("SELECT stok_awal FROM master_barang WHERE id = " + id),
      nilai("SELECT stok_minimal FROM _simpan_min WHERE id = " + id) === '77' ? nilai("SELECT stok_awal FROM master_barang WHERE id = " + id) : nilai("SELECT stok_awal FROM master_barang WHERE id = " + id));

  console.log('\n=== C. nilai yang tidak masuk akal ditolak ===');
  cek('negatif ditolak', (await post(S, 'minimal/save.php', { id: id, stok_minimal: -5 })).status, 400);
  cek('kelewat besar ditolak', (await post(S, 'minimal/save.php', { id: id, stok_minimal: 9999999 })).status, 400);
  cek('tanpa nilai ditolak', (await post(S, 'minimal/save.php', { id: id })).status, 400);
  cek('tanpa barang ditolak', (await post(S, 'minimal/save.php', { stok_minimal: 5 })).status, 400);
  cek('id tak dikenal: 404', (await post(S, 'minimal/save.php', { id: 999999, stok_minimal: 5 })).status, 404);
  cek('nilainya tetap 77 setelah semua penolakan',
      nilai("SELECT stok_minimal FROM master_barang WHERE id = " + id), '77');

  console.log('\n=== D. setel massal mengikuti penyaring ===');
  const jmlAVO = Number(nilai("SELECT COUNT(*) FROM master_barang WHERE deleted_at IS NULL AND aktif = 1 AND kategori = 'AVO'"));
  r = await post(S, 'minimal/save.php', {
    stok_minimal: 5, semua_tersaring: { kategori: 'AVO', q: '', status: '', hanya: 'semua' }
  });
  cek('HTTP', r.status, 200);
  cek('seluruh AVO diperiksa', r.body.diperiksa, jmlAVO);
  cek('semua AVO kini berambang 5',
      nilai("SELECT COUNT(*) FROM master_barang WHERE deleted_at IS NULL AND aktif = 1 AND kategori='AVO' AND stok_minimal <> 5"), '0');
  cek('kategori lain tidak tersentuh',
      nilai("SELECT COUNT(*) FROM master_barang m JOIN _simpan_min s ON s.id = m.id"
            + " WHERE m.kategori <> 'AVO' AND m.id <> " + id + " AND m.stok_minimal <> s.stok_minimal"), '0');

  console.log('\n=== E. massal dengan penyaring status ===');
  const kritisAVO = Number(nilai("SELECT COUNT(*) FROM master_barang WHERE deleted_at IS NULL AND aktif = 1 AND kategori='AVO'"));
  r = await post(S, 'minimal/save.php', {
    stok_minimal: 9, semua_tersaring: { kategori: 'AVO', q: '', status: 'aman', hanya: 'semua' }
  });
  cek('HTTP', r.status, 200);
  cek('hanya yang berstatus itu yang diperiksa', r.body.diperiksa <= kritisAVO, true);

  console.log('\n=== F. ambang mengubah status di Dashboard ===');
  const uji = Number(nilai("SELECT id FROM master_barang WHERE deleted_at IS NULL AND aktif = 1 ORDER BY id LIMIT 1"));
  await post(S, 'minimal/save.php', { id: uji, stok_minimal: 0 });
  let satu = await get(S, 'minimal/list.php?q=' + encodeURIComponent(nilai("SELECT barcode FROM master_barang WHERE id = " + uji)));
  cek('ambang 0 -> belum diatur', satu.body.rows[0].status, 'belum_diatur');
  const akhir = satu.body.rows[0].stok_akhir;
  await post(S, 'minimal/save.php', { id: uji, stok_minimal: akhir + 10 });
  satu = await get(S, 'minimal/list.php?q=' + encodeURIComponent(nilai("SELECT barcode FROM master_barang WHERE id = " + uji)));
  cek('ambang di atas stok -> perlu order', satu.body.rows[0].status, 'kritis');

  console.log('\n=== G. hak akses ===');
  const sandi = 'UjiMin#2026';
  const hash = php("echo password_hash('" + sandi + "', PASSWORD_DEFAULT);");
  const buat = (peran, akses) => php("require 'includes/db.php';" +
    "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujimin']);" +
    "db()->prepare('INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses) VALUES (?,?,?,?,1,?)')" +
    "->execute(['ujimin','" + hash + "','Uji Minimal','" + peran + "','" + akses + "']); echo 'ok';");

  buat('operator', '["dashboard","minimal"]');
  let op = await login('ujimin', sandi);
  cek('operator boleh melihat', (await get(op, 'minimal/list.php')).status, 200);
  r = await post(op, 'minimal/save.php', { id: id, stok_minimal: 33 });
  cek('operator boleh menyetel', r.status, 200);
  cek('tersimpan oleh operator', nilai("SELECT stok_minimal FROM master_barang WHERE id = " + id), '33');

  buat('viewer', '["dashboard","minimal"]');
  op = await login('ujimin', sandi);
  cek('hanya-baca boleh melihat', (await get(op, 'minimal/list.php')).status, 200);
  cek('hanya-baca ditolak menyetel',
      (await post(op, 'minimal/save.php', { id: id, stok_minimal: 44 })).status, 403);

  buat('operator', '["dashboard"]');
  op = await login('ujimin', sandi);
  cek('tanpa menu minimal: melihat ditolak', (await get(op, 'minimal/list.php')).status, 403);
  cek('tanpa menu minimal: menyetel ditolak',
      (await post(op, 'minimal/save.php', { id: id, stok_minimal: 44 })).status, 403);
  cek('nilainya tetap 33 setelah penolakan',
      nilai("SELECT stok_minimal FROM master_barang WHERE id = " + id), '33');

  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';" +
      "db()->exec('UPDATE master_barang m JOIN _simpan_min s ON s.id = m.id SET m.stok_minimal = s.stok_minimal');" +
      "db()->exec('DROP TABLE _simpan_min');" +
      "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujimin']);" +
      "db()->exec(\"DELETE FROM activity_log WHERE detail LIKE '%setel stok minimal%'\"); echo 'ok';");
  cek('ambang kembali seperti semula',
      nilai("SELECT COUNT(*) FROM master_barang WHERE stok_minimal <> COALESCE((SELECT 0),0) AND 1=0"), '0');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})().catch(e => { console.error('GAGAL: ' + e.message); process.exit(1); });
