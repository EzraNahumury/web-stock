/* Retur satu pesanan berisi beberapa produk.
 *
 * Yang paling penting di sini BUKAN jalur yang berhasil, melainkan jalur yang
 * gagal: kalau produk ketiga ditolak, dua yang pertama tidak boleh tersisa di
 * basis data, dan stok tidak boleh terlanjur bertambah. Separuh pesanan yang
 * masuk stok baru ketahuan saat stok opname.
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

/* Barang nyata dari master; returnya harus benar-benar menggeser stok. */
const A = { sku: 'AO-0001',  barcode: '6936047373262', id: 2 };
const C = { sku: '101066',   barcode: '6936047375471', id: 3 };
const D = { sku: '102382',   barcode: 'INT-102382',    id: 4 };

const barisRetur = (p) => "SELECT COUNT(*) FROM retur WHERE deleted_at IS NULL AND no_pesanan = '" + p + "'";
const masukRetur = (p) =>
  "SELECT COUNT(*) FROM barang_masuk bm JOIN retur r ON r.masuk_id = bm.id"
  + " WHERE r.no_pesanan = '" + p + "' AND bm.deleted_at IS NULL";
const stok = (id) =>
  "SELECT m.stok_awal"
  + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
  + " FROM master_barang m WHERE m.id = " + id;

(async () => {
  /* Suite ini menegaskan bahwa SELURUH keterangan retur mengembalikan barang ke
     stok — keadaan produksi sejak migrasi 022. Keadaan itu dipasang di sini,
     bukan diandaikan: uji lain (uji_revisi2, uji_sinkron) sengaja menyetelnya
     ke 0 untuk menguji retur yang tertahan, dan suite yang lulus-tidaknya
     bergantung pada urutan jalan lebih buruk daripada tidak ada suite sama
     sekali. Keadaan semula disimpan dan dikembalikan di akhir. */
  php("require 'includes/db.php'; db()->exec('DROP TABLE IF EXISTS _simpan_ket');"
    + "db()->exec(\"CREATE TABLE _simpan_ket AS SELECT id, tambah_stok FROM keterangan WHERE jenis='retur'\");"
    + "db()->exec(\"UPDATE keterangan SET tambah_stok=1 WHERE jenis='retur' AND aktif=1 AND deleted_at IS NULL\"); echo 'ok';");

  const returAwal = nilai("SELECT COALESCE(MAX(id),0) FROM retur");
  const masukAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_masuk");
  console.log('retur id awal = ' + returAwal + ', barang_masuk id awal = ' + masukAwal);

  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  /* --------------------------------------------------------------------- */
  console.log('\n=== A. bentuk lama (satu produk, tanpa items) tetap jalan ===');
  const stokA0 = Number(nilai(stok(A.id)));
  let r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-LAMA', sku: A.sku, jumlah: 3,
    status: 'Lengkap', keterangan: 'bentuk lama'
  });
  cek('HTTP', r.status, 200);
  cek('pesannya tidak berubah', r.body.pesan, 'Retur dicatat.');
  cek('membawa id tunggal', typeof r.body.id, 'number');
  cek('membawa ids juga', r.body.ids.length, 1);
  cek('satu baris tersimpan', nilai(barisRetur('UJI-LAMA')), '1');
  cek('stok bertambah 3', Number(nilai(stok(A.id))), stokA0 + 3);
  cek('peringatan stok tanpa embel-embel jumlah produk',
      (r.body.peringatan || []).some(p => p === 'Stok bertambah 3 pcs lewat barang masuk "Retur Masuk".'), true);

  /* --------------------------------------------------------------------- */
  console.log('\n=== B. satu pesanan, tiga produk ===');
  const s1 = Number(nilai(stok(A.id))), s2 = Number(nilai(stok(C.id))), s3 = Number(nilai(stok(D.id)));
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-MULTI',
    items: [
      { sku: A.sku, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' },
      { sku: C.sku, jumlah: 5, status: 'Lengkap', keterangan: 'salah warna' },
      { sku: D.sku, jumlah: 1, status: 'Sistem Belum Selesai', keterangan: 'belum diproses' }
    ]
  });
  cek('HTTP', r.status, 200);
  cek('tiga id kembali', r.body.ids.length, 3);
  cek('pesannya menyebut jumlah dan pesanannya', r.body.pesan, '3 produk retur dicatat untuk pesanan UJI-MULTI.');
  cek('tiga baris retur tersimpan', nilai(barisRetur('UJI-MULTI')), '3');
  cek('ketiganya satu nomor pesanan',
      nilai("SELECT COUNT(DISTINCT no_pesanan) FROM retur WHERE id > " + returAwal + " AND no_pesanan = 'UJI-MULTI'"), '1');
  cek('tiap baris punya barang masuknya sendiri', nilai(masukRetur('UJI-MULTI')), '3');
  cek('stok produk 1 naik 2', Number(nilai(stok(A.id))), s1 + 2);
  cek('stok produk 2 naik 5', Number(nilai(stok(C.id))), s2 + 5);
  cek('stok produk 3 naik 1 juga (semua keterangan menambah stok)', Number(nilai(stok(D.id))), s3 + 1);
  cek('ringkasan stok digabung jadi satu baris',
      (r.body.peringatan || []).filter(p => p.indexOf('Stok bertambah') === 0),
      ['Stok bertambah 8 pcs dari 3 produk lewat barang masuk "Retur Masuk".']);
  cek('tiga catatan aktivitas, satu per baris',
      nilai("SELECT COUNT(*) FROM activity_log WHERE entitas = 'retur' AND aksi = 'create'"
            + " AND detail LIKE '%UJI-MULTI%'"), '3');

  /* --------------------------------------------------------------------- */
  console.log('\n=== C. satu produk gagal, SELURUHNYA batal ===');
  const b1 = Number(nilai(stok(A.id))), b2 = Number(nilai(stok(C.id)));
  const returSebelum = nilai("SELECT COUNT(*) FROM retur");
  const masukSebelum = nilai("SELECT COUNT(*) FROM barang_masuk");

  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-BATAL',
    items: [
      { sku: A.sku, jumlah: 4, status: 'Lengkap' },
      { sku: C.sku, jumlah: 4, status: 'Lengkap' },
      { sku: D.sku, jumlah: 0, status: 'Lengkap' }        // qty 0 -> ditolak
    ]
  });
  cek('ditolak', r.status, 400);
  cek('pesannya menunjuk produk yang salah',
      r.body.error.indexOf('Qty harus lebih dari 0') >= 0 && r.body.error.indexOf(D.sku) >= 0, true);
  cek('tidak ada baris retur yang tersisa', nilai(barisRetur('UJI-BATAL')), '0');
  cek('jumlah retur tidak berubah', nilai("SELECT COUNT(*) FROM retur"), returSebelum);
  cek('jumlah barang masuk tidak berubah', nilai("SELECT COUNT(*) FROM barang_masuk"), masukSebelum);
  cek('stok produk 1 tidak bergeser', Number(nilai(stok(A.id))), b1);
  cek('stok produk 2 tidak bergeser', Number(nilai(stok(C.id))), b2);

  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-BATAL2',
    items: [
      { sku: A.sku, jumlah: 4, status: 'Lengkap' },
      { sku: 'TIDAK-ADA-DI-MASTER', jumlah: 1, status: 'Lengkap' }   // tanpa nama
    ]
  });
  cek('barang asing tanpa nama ditolak', r.status, 400);
  cek('pesannya menunjuk barisnya', r.body.error.indexOf('TIDAK-ADA-DI-MASTER:') === 0, true);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-BATAL2')), '0');
  cek('stok tetap', Number(nilai(stok(A.id))), b1);

  /* --------------------------------------------------------------------- */
  console.log('\n=== D. produk kembar ===');
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-KEMBAR',
    items: [
      { sku: A.sku, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' },
      { sku: A.sku, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' }
    ]
  });
  cek('sama persis ditolak', r.status, 400);
  cek('pesannya menyuruh menjumlahkan qty', r.body.error.indexOf('jumlahkan qty') >= 0, true);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-KEMBAR')), '0');

  /* Barcode dan SKU barang yang sama harus terbaca sebagai barang yang sama —
   * kalau tidak, stok tetap bisa bertambah dua kali lewat pintu lain. */
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-KEMBAR2',
    items: [
      { sku: A.sku, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' },
      { barcode: A.barcode, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' }
    ]
  });
  cek('kembar lewat barcode vs SKU juga ditolak', r.status, 400);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-KEMBAR2')), '0');

  const k1 = Number(nilai(stok(A.id)));
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-DUAKALI',
    items: [
      { sku: A.sku, jumlah: 2, status: 'Lengkap', keterangan: 'sobek' },
      { sku: A.sku, jumlah: 1, status: 'Lengkap', keterangan: 'salah kirim' }
    ]
  });
  cek('catatan berbeda: diterima', r.status, 200);
  cek('diberi peringatan',
      (r.body.peringatan || []).some(p => p.indexOf('lebih dari satu baris') >= 0), true);
  cek('dua baris tersimpan', nilai(barisRetur('UJI-DUAKALI')), '2');
  cek('stok naik 3 seluruhnya', Number(nilai(stok(A.id))), k1 + 3);

  const k2 = Number(nilai(stok(C.id)));
  r = await post(S, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-BEDAKET',
    items: [
      { sku: C.sku, jumlah: 2, status: 'Lengkap', keterangan: 'x' },
      { sku: C.sku, jumlah: 1, status: 'Sistem Belum Selesai', keterangan: 'x' }
    ]
  });
  cek('keterangan retur berbeda: diterima tanpa peringatan kembar', r.status, 200);
  cek('tanpa peringatan kembar',
      (r.body.peringatan || []).some(p => p.indexOf('lebih dari satu baris') >= 0), false);
  cek('stok naik 3', Number(nilai(stok(C.id))), k2 + 3);

  /* --------------------------------------------------------------------- */
  console.log('\n=== E. batas dan bentuk yang salah ===');
  cek('items kosong ditolak',
      (await post(S, 'retur/save.php', { tanggal: '2026-10-18', no_pesanan: 'UJI-X', items: [] })).status, 400);
  const banyak = [];
  for (let i = 0; i < 51; i++) { banyak.push({ sku: A.sku, jumlah: 1, status: 'Lengkap', keterangan: 'ke-' + i }); }
  r = await post(S, 'retur/save.php', { tanggal: '2026-10-18', no_pesanan: 'UJI-BANYAK', items: banyak });
  cek('51 produk ditolak', r.status, 400);
  cek('pesannya menyebut batasnya', r.body.error.indexOf('Maksimal 50 produk') >= 0, true);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-BANYAK')), '0');
  cek('items bukan daftar ditolak',
      (await post(S, 'retur/save.php', { tanggal: '2026-10-18', items: 'bukan daftar' })).status, 400);
  cek('tanggal ngawur ditolak',
      (await post(S, 'retur/save.php', { tanggal: '18-10-2026', items: [{ sku: A.sku, jumlah: 1 }] })).status, 400);

  /* --------------------------------------------------------------------- */
  console.log('\n=== F. mengubah tetap satu baris ===');
  const idUbah = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbValue(\""
    + "SELECT id FROM retur WHERE no_pesanan = 'UJI-LAMA' AND deleted_at IS NULL LIMIT 1\"));"));
  r = await post(S, 'retur/save.php', {
    id: idUbah, tanggal: '2026-10-18', no_pesanan: 'UJI-LAMA',
    items: [{ sku: A.sku, jumlah: 9, status: 'Lengkap' }]
  });
  cek('satu produk: boleh', r.status, 200);
  cek('tersimpan', nilai("SELECT jumlah FROM retur WHERE id = " + idUbah), '9');
  cek('barang masuknya ikut', nilai("SELECT bm.jumlah FROM barang_masuk bm JOIN retur r ON r.masuk_id = bm.id WHERE r.id = " + idUbah), '9');

  r = await post(S, 'retur/save.php', {
    id: idUbah, tanggal: '2026-10-18', no_pesanan: 'UJI-LAMA',
    items: [{ sku: A.sku, jumlah: 1, status: 'Lengkap' }, { sku: C.sku, jumlah: 1, status: 'Lengkap' }]
  });
  cek('dua produk saat mengubah: ditolak', r.status, 400);
  cek('pesannya mengarahkan ke entri baru', r.body.error.indexOf('entri baru') >= 0, true);
  cek('qty tidak ikut berubah', nilai("SELECT jumlah FROM retur WHERE id = " + idUbah), '9');

  /* --------------------------------------------------------------------- */
  console.log('\n=== G. hak akses ===');
  const sandi = 'UjiRet#2026';
  const hash = php("echo password_hash('" + sandi + "', PASSWORD_DEFAULT);");
  const buat = (peran, akses) => php("require 'includes/db.php';" +
    "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiret']);" +
    "db()->prepare('INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses) VALUES (?,?,?,?,1,?)')" +
    "->execute(['ujiret','" + hash + "','Uji Retur','" + peran + "','" + akses + "']); echo 'ok';");

  buat('operator', '["dashboard","retur"]');
  let op = await login('ujiret', sandi);
  r = await post(op, 'retur/save.php', {
    tanggal: '2026-10-18', no_pesanan: 'UJI-OP',
    items: [{ sku: A.sku, jumlah: 1, status: 'Lengkap' }, { sku: C.sku, jumlah: 1, status: 'Lengkap' }]
  });
  cek('operator boleh mencatat banyak produk', r.status, 200);
  cek('tersimpan dua baris', nilai(barisRetur('UJI-OP')), '2');
  cek('operator tetap tidak boleh mengubah',
      (await post(op, 'retur/save.php', { id: idUbah, tanggal: '2026-10-18', items: [{ sku: A.sku, jumlah: 2 }] })).status, 403);

  buat('viewer', '["dashboard","retur"]');
  op = await login('ujiret', sandi);
  cek('hanya-baca ditolak',
      (await post(op, 'retur/save.php', { tanggal: '2026-10-18', no_pesanan: 'UJI-RO', items: [{ sku: A.sku, jumlah: 1 }] })).status, 403);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-RO')), '0');

  buat('operator', '["dashboard"]');
  op = await login('ujiret', sandi);
  cek('tanpa menu retur ditolak',
      (await post(op, 'retur/save.php', { tanggal: '2026-10-18', no_pesanan: 'UJI-NOMENU', items: [{ sku: A.sku, jumlah: 1 }] })).status, 403);
  cek('tidak ada yang tersimpan', nilai(barisRetur('UJI-NOMENU')), '0');

  /* --------------------------------------------------------------------- */
  console.log('\n=== H. daftar retur membaca pesanan banyak produk ===');
  const d = await get(S, 'retur/list.php?q=UJI-MULTI');
  cek('HTTP', d.status, 200);
  cek('tiga baris tampil', d.body.total, 3);
  cek('seluruh keterangan terhitung masuk stok', d.body.unit_ke_stok, 8);
  cek('tidak ada yang tertahan', d.body.unit_tertahan, 0);
  cek('daftar keterangan penambah stok dikirim', d.body.status_masuk_list.length >= 1, true);

  /* --------------------------------------------------------------------- */
  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec('DELETE FROM activity_log WHERE entitas = \\'retur\\' AND entitas_id > " + returAwal + "');"
    + "db()->exec('DELETE FROM retur WHERE id > " + returAwal + "');"
    + "db()->exec('DELETE FROM barang_masuk WHERE id > " + masukAwal + "');"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiret']); echo 'ok';");
  php("require 'includes/db.php';"
    + "db()->exec('UPDATE keterangan k JOIN _simpan_ket s ON s.id = k.id SET k.tambah_stok = s.tambah_stok');"
    + "db()->exec('DROP TABLE _simpan_ket'); echo 'ok';");
  cek('retur kembali kosong seperti semula',
      nilai("SELECT COUNT(*) FROM retur WHERE id > " + returAwal), '0');
  cek('barang masuk kembali seperti semula',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE id > " + masukAwal), '0');
  cek('stok produk 1 kembali seperti semula', Number(nilai(stok(A.id))), stokA0);

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
