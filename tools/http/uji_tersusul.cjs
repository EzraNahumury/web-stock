/* Retur yang keterangannya mengembalikan barang ke stok, tapi barang masuknya
 * tidak pernah dibuat.
 *
 * KENAPA SUITE INI ADA
 * Layar Retur dulu menyimpulkan "stok bertambah" dari NAMA KETERANGANNYA.
 * Keterangan menyatakan apa yang SEHARUSNYA terjadi, bukan apa yang sudah
 * terjadi. Retur yang dicatat sebelum keterangannya diubah tidak pernah punya
 * baris barang masuk, tapi tetap digambar "stok bertambah" dan ikut dihitung
 * di kartu "Sudah masuk stok" — 398 pcs di server, sementara Barang masuk
 * kosong dan stok tidak pernah naik.
 *
 * Yang diuji di sini: kartu dan lencananya membaca BUKTI (ada tidaknya baris
 * barang masuk), bukan dugaan.
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
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(60)
    + (ok ? JSON.stringify(dapat) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

const A = { sku: 'AO-0001', id: 2 };
const P1 = 'UJITSL-A';   // dicatat saat keterangannya belum menambah stok
const P2 = 'UJITSL-B';   // dicatat saat keterangannya sudah menambah stok
const stok = (id) =>
  "SELECT m.stok_awal"
  + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " FROM master_barang m WHERE m.id = " + id;
const setKet = (nama, v) => php("require 'includes/db.php'; db()->exec(\"UPDATE keterangan SET tambah_stok="
  + v + " WHERE jenis='retur' AND nama='" + nama + "'\"); echo 'ok';");

(async () => {
  const returAwal = nilai("SELECT COALESCE(MAX(id),0) FROM retur");
  const masukAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_masuk");

  php("require 'includes/db.php'; db()->exec('DROP TABLE IF EXISTS _simpan_ket3');"
    + "db()->exec(\"CREATE TABLE _simpan_ket3 AS SELECT id, tambah_stok FROM keterangan WHERE jenis='retur'\"); echo 'ok';");

  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  /* ------------------------------------------------------------------ */
  console.log('\n=== A. retur dicatat saat keterangannya BELUM menambah stok ===');
  setKet('Sistem Belum Selesai', 0);
  const stok0 = Number(nilai(stok(A.id)));

  let r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-08', no_pesanan: P1, sku: A.sku, jumlah: 7,
    status: 'Sistem Belum Selesai', keterangan: 'UJITSL'
  });
  cek('tercatat', r.status, 200);
  const id1 = r.body.id;
  cek('tanpa baris barang masuk', nilai('SELECT masuk_id FROM retur WHERE id=' + id1), '');
  cek('stok tidak bergerak', Number(nilai(stok(A.id))), stok0);

  let d = await get(S, 'retur/list.php?q=' + P1);
  cek('kartu sudah-masuk-stok nol', d.body.unit_ke_stok, 0);
  cek('terhitung tertahan', d.body.unit_tertahan, 7);
  cek('belum ada yang perlu disusulkan', d.body.baris_belum_tersusul, 0);
  cek('baris ditandai belum punya barang masuk', d.body.rows[0].masuk_aktif, 0);

  /* ------------------------------------------------------------------ */
  console.log('\n=== B. keterangannya diubah lewat SQL mentah (seperti migrasi 022) ===');
  setKet('Sistem Belum Selesai', 1);

  d = await get(S, 'retur/list.php?q=' + P1);
  cek('KARTU TIDAK BERBOHONG: belum terhitung masuk stok', d.body.unit_ke_stok, 0);
  cek('muncul sebagai belum tersusul', d.body.unit_belum_tersusul, 7);
  cek('barisnya dihitung', d.body.baris_belum_tersusul, 1);
  cek('tidak lagi disebut tertahan', d.body.unit_tertahan, 0);
  cek('baris ditandai perlu disusulkan', d.body.rows[0].belum_tersusul, 1);
  cek('baris tahu keterangannya menyuruh menambah stok', d.body.rows[0].ket_tambah_stok, 1);
  cek('tapi barang masuknya tetap tidak ada', d.body.rows[0].masuk_aktif, 0);
  cek('stok memang belum bergerak', Number(nilai(stok(A.id))), stok0);
  cek('dan Barang masuk memang kosong',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE id > " + masukAwal), '0');

  /* ------------------------------------------------------------------ */
  console.log('\n=== C. retur baru pada keterangan yang sama: langsung masuk ===');
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-08', no_pesanan: P2, sku: A.sku, jumlah: 3,
    status: 'Sistem Belum Selesai', keterangan: 'UJITSL'
  });
  cek('tercatat', r.status, 200);
  const id2 = r.body.id;
  cek('punya baris barang masuk', nilai('SELECT masuk_id FROM retur WHERE id=' + id2) !== '', true);
  cek('stok naik 3', Number(nilai(stok(A.id))), stok0 + 3);

  d = await get(S, 'retur/list.php?q=' + P2);
  cek('terhitung sudah masuk stok', d.body.unit_ke_stok, 3);
  cek('bukan belum tersusul', d.body.unit_belum_tersusul, 0);
  cek('barisnya ditandai punya barang masuk', d.body.rows[0].masuk_aktif, 1);

  console.log('\n--- keduanya bersama: angkanya terpisah, bukan tercampur ---');
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('total unit', d.body.total_unit, 10);
  cek('sudah masuk stok', d.body.unit_ke_stok, 3);
  cek('belum tersusul', d.body.unit_belum_tersusul, 7);
  cek('tertahan', d.body.unit_tertahan, 0);
  cek('ketiganya berjumlah total',
      d.body.unit_ke_stok + d.body.unit_belum_tersusul + d.body.unit_tertahan, d.body.total_unit);

  /* ------------------------------------------------------------------ */
  console.log('\n=== D. "Masukkan semua ke stok" menyusulkannya ===');
  r = await post(S, 'retur/sinkron_stok.php', { semua_keterangan: true });
  cek('HTTP', r.status, 200);
  cek('satu baris disusulkan', r.body.ditambah, 1);
  cek('STOK NAIK 7', Number(nilai(stok(A.id))), stok0 + 10);
  cek('barang masuknya sekarang ada', nilai('SELECT masuk_id FROM retur WHERE id=' + id1) !== '', true);

  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('kartu sudah-masuk-stok jadi benar', d.body.unit_ke_stok, 10);
  cek('tidak ada lagi yang perlu disusulkan', d.body.baris_belum_tersusul, 0);
  cek('aman diulang: tidak ada yang berubah lagi',
      (await post(S, 'retur/sinkron_stok.php', {})).body.ditambah, 0);
  cek('dan stoknya tidak naik dua kali', Number(nilai(stok(A.id))), stok0 + 10);

  /* ------------------------------------------------------------------ */
  console.log('\n=== E. kebalikannya: barisnya ada padahal keterangannya melarang ===');
  setKet('Sistem Belum Selesai', 0);
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('terbaca sebagai kelebihan', d.body.baris_kelebihan, 2);
  cek('bukan sebagai belum tersusul', d.body.baris_belum_tersusul, 0);
  cek('stoknya memang masih naik', Number(nilai(stok(A.id))), stok0 + 10);
  r = await post(S, 'retur/sinkron_stok.php', {});
  cek('tombol yang sama menariknya kembali', r.body.ditarik, 2);
  cek('STOK KEMBALI', Number(nilai(stok(A.id))), stok0);
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('tidak ada lagi kelebihan', d.body.baris_kelebihan, 0);
  cek('semuanya terhitung tertahan', d.body.unit_tertahan, 10);

  /* ------------------------------------------------------------------ */
  console.log('\n=== F. baris barang masuk yang dihapus tidak lagi dihitung ===');
  setKet('Sistem Belum Selesai', 1);
  await post(S, 'retur/sinkron_stok.php', {});
  const masukId = nilai('SELECT masuk_id FROM retur WHERE id=' + id2);
  cek('sudah masuk stok lagi', (await get(S, 'retur/list.php?q=UJITSL')).body.unit_ke_stok, 10);
  php("require 'includes/db.php'; db()->exec('UPDATE barang_masuk SET deleted_at = NOW() WHERE id = "
    + masukId + "'); echo 'ok';");
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('barisnya tidak lagi terhitung masuk stok', d.body.unit_ke_stok, 7);
  cek('dan langsung tampak perlu disusulkan', d.body.unit_belum_tersusul, 3);
  cek('masuk_id masih tersimpan untuk dipakai ulang',
      nilai('SELECT masuk_id FROM retur WHERE id=' + id2), masukId);

  /* ------------------------------------------------------------------ */
  console.log('\n=== G. mengubah centang lewat Master menyusulkan sendiri ===');
  /* Jalur inilah yang membedakan Master -> Keterangan retur dari migrasi 022:
     mencentangnya di layar memanggil sinkronMasukRetur(), SQL mentah tidak.
     Dulu diuji suite tersendiri yang hilang bersama folder temp; dipindah ke
     sini supaya ikut terjaga di repo. */
  const ketId = nilai("SELECT id FROM keterangan WHERE jenis='retur' AND nama='Sistem Belum Selesai'");
  php("require 'includes/db.php'; db()->exec('UPDATE barang_masuk SET deleted_at = NULL WHERE id = "
    + masukId + "'); echo 'ok';");
  setKet('Sistem Belum Selesai', 0);
  await post(S, 'retur/sinkron_stok.php', {});
  cek('mulai dari keadaan tertahan', (await get(S, 'retur/list.php?q=UJITSL')).body.unit_tertahan, 10);
  cek('stok kembali ke titik awal', Number(nilai(stok(A.id))), stok0);

  r = await post(S, 'keterangan/save.php', {
    id: Number(ketId), jenis: 'retur', nama: 'Sistem Belum Selesai',
    catatan: 'Retur tercatat tapi belum menambah stok', urutan: 20,
    aktif: true, tambah_stok: true
  });
  cek('HTTP', r.status, 200);
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('retur lama ikut disusulkan tanpa menekan tombol apa pun', d.body.unit_ke_stok, 10);
  cek('tidak ada yang tertinggal', d.body.baris_belum_tersusul, 0);
  cek('stok benar-benar naik', Number(nilai(stok(A.id))), stok0 + 10);

  r = await post(S, 'keterangan/save.php', {
    id: Number(ketId), jenis: 'retur', nama: 'Sistem Belum Selesai',
    catatan: 'Retur tercatat tapi belum menambah stok', urutan: 20,
    aktif: true, tambah_stok: false
  });
  cek('dicabut lagi: ditarik dari stok', Number(nilai(stok(A.id))), stok0);
  d = await get(S, 'retur/list.php?q=UJITSL');
  cek('tidak terbaca sebagai kelebihan', d.body.baris_kelebihan, 0);
  cek('kembali terhitung tertahan', d.body.unit_tertahan, 10);

  console.log('\n=== H. penyaring tidak mengubah artinya ===');
  d = await get(S, 'retur/list.php?q=UJITSL&status=' + encodeURIComponent('Sistem Belum Selesai'));
  cek('disaring per keterangan tetap konsisten',
      d.body.unit_ke_stok + d.body.unit_belum_tersusul + d.body.unit_tertahan, d.body.total_unit);
  d = await get(S, 'retur/list.php?q=UJITSL&dari=2026-10-08&sampai=2026-10-08');
  cek('disaring per tanggal tetap konsisten',
      d.body.unit_ke_stok + d.body.unit_belum_tersusul + d.body.unit_tertahan, d.body.total_unit);

  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec('DELETE FROM activity_log WHERE entitas = \\'retur\\' AND entitas_id > " + returAwal + "');"
    + "db()->exec('DELETE FROM retur WHERE id > " + returAwal + "');"
    + "db()->exec('DELETE FROM barang_masuk WHERE id > " + masukAwal + "');"
    + "db()->exec('UPDATE keterangan k JOIN _simpan_ket3 s ON s.id = k.id SET k.tambah_stok = s.tambah_stok');"
    + "db()->exec('DROP TABLE _simpan_ket3'); echo 'ok';");
  cek('retur bersih', nilai("SELECT COUNT(*) FROM retur WHERE id > " + returAwal), '0');
  cek('stok kembali seperti semula', Number(nilai(stok(A.id))), stok0);

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
