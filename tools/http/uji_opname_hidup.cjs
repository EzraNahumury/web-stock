/* Laporan stok opname: hidup selama berjalan, diam setelah ditandai selesai.
 *
 * ATURAN YANG DIJAGA, dengan kata-kata gudang sendiri:
 *
 *   "selagi kita belum klik tandai selesai, hasil stok akhirnya masih bisa
 *    ke-update, sedangkan jika kita sudah klik maka dia akan tetap, karena
 *    laporannya di periode itu sudah selesai, dan ga bisa berubah-ubah lagi."
 *
 * KENAPA SUITE INI ADA
 * Keadaan ini pernah salah dua kali berturut-turut, dan dua-duanya lolos
 * karena tidak ada satu pun uji yang menjalankan urutan yang paling biasa:
 * buat laporan, lalu catat barang masuk, lalu lihat laporannya.
 *
 * Akibat kesalahannya nyata: barang masuk 2 pcs, dihitung 2 pcs, tetapi
 * laporannya tetap menyebut Stok akhir 0 dan Selisih +2. Petugas yang
 * mempercayai angka itu akan menekan "Stok Disesuaikan" dan menambahkan 2 pcs
 * lagi untuk selisih yang sebenarnya tidak ada.
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
  return { status: res.status, body: j, teks: t.slice(0, 400) };
}

let gagal = 0;
function cek(label, dapat, harap) {
  const ok = JSON.stringify(dapat) === JSON.stringify(harap);
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(62)
    + (ok ? JSON.stringify(dapat) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

const TANDA = 'UJIHDP';

(async () => {
  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  const masukAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_masuk");
  const sesiId = Number(nilai('SELECT id FROM opname_sesi WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1'));
  const sesiLama = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "'SELECT nama, periode, tanggal, status, catatan FROM opname_sesi WHERE id = " + sesiId + "'));"));
  const idB = Number(nilai('SELECT id FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND master_id IS NOT NULL ORDER BY id LIMIT 1'));
  const masterB = Number(nilai('SELECT master_id FROM opname_item WHERE id = ' + idB));
  const barcodeB = nilai('SELECT barcode FROM opname_item WHERE id = ' + idB);

  const stokB = () => Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m WHERE m.id = " + masterB));
  const baris = async () => (await get(S, 'opname/detail.php?id=' + sesiId
    + '&q=' + encodeURIComponent(barcodeB))).body.rows[0];
  const masuk = (qty) => php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute([date('Y-m-d')," + masterB + ",'x','x'," + qty + ",'" + TANDA + "']); echo 'ok';");

  const bersihkan = () => php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan IN ('Penyesuaian Opname','" + TANDA + "')\");"
    + "db()->exec(\"DELETE FROM barang_keluar WHERE keterangan IN ('Penyesuaian Opname','" + TANDA + "')\");"
    + "db()->exec(\"UPDATE opname_item SET stok_hitung=NULL, stok_accurate=NULL, stok_tutup=NULL,"
    + " penyesuaian='Tidak Disesuaikan', adj_jenis=NULL, adj_id=NULL, adj_qty=NULL"
    + " WHERE sesi_id = " + sesiId + "\");"
    + "db()->prepare('UPDATE opname_sesi SET status = ? WHERE id = ?')->execute(['draft'," + sesiId + "]); echo 'ok';");

  bersihkan();
  const awal = stokB();
  console.log('\n  stok barang uji: ' + awal);

  /* ------------------------------------------------------------------ */
  console.log('\n=== A. laporan BERJALAN: stok akhir ikut ter-update ===');
  /* Inilah urutan yang paling biasa di gudang, dan yang dulu tidak pernah
     diuji: laporannya sudah ada, lalu barang masuk dicatat. */
  let b = await baris();
  cek('mula-mula sama dengan stok barang', b.stok_akhir, awal);

  masuk(2);
  cek('stok barang bertambah 2', stokB(), awal + 2);
  b = await baris();
  cek('STOK AKHIR DI LAPORAN IKUT BERUBAH', b.stok_akhir, awal + 2);

  console.log('\n--- dihitung sama banyak: tidak ada selisih ---');
  /* Kasus persis yang dilaporkan: masuk 2, dihitung 2. Kalau stok akhirnya
     tidak ikut, selisihnya terbaca +2 dan petugas akan menambahkan 2 lagi
     untuk selisih yang tidak pernah ada. */
  let r = await post(S, 'opname/item.php', { id: idB, stok_hitung: awal + 2 });
  cek('selisih NOL, bukan +2', r.body.selisih, 0);
  b = await baris();
  cek('layar menunjukkan hal yang sama', b.selisih, 0);
  const dd = await get(S, 'opname/detail.php?id=' + sesiId);
  cek('kartu Berselisih tidak ikut menghitungnya', dd.body.ringkas.beda, 0);
  cek('dan Jumlah selisih nol', dd.body.ringkas.total_selisih, 0);

  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('tidak ada koreksi yang perlu ditulis', r.body.adj_qty, 0);
  cek('stok tidak ikut bertambah lagi', stokB(), awal + 2);
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Tidak Disesuaikan' });

  console.log('\n--- barang keluar juga ikut ---');
  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_keluar (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute([date('Y-m-d')," + masterB + ",'x','x',1,'" + TANDA + "']); echo 'ok';");
  b = await baris();
  cek('stok akhir turun 1', b.stok_akhir, awal + 1);
  cek('selisih ikut menyesuaikan', b.selisih, (awal + 2) - (awal + 1));

  /* ------------------------------------------------------------------ */
  console.log('\n=== B. ditandai selesai: angkanya berhenti bergerak ===');
  const sebelumTutup = (await baris()).stok_akhir;
  r = await post(S, 'opname/save.php', {
    id: sesiId, nama: sesiLama.nama, periode: sesiLama.periode,
    tanggal: sesiLama.tanggal, status: 'selesai', catatan: sesiLama.catatan || ''
  });
  cek('HTTP', r.status, 200);

  masuk(50);
  cek('stok barang memang berubah banyak', stokB(), sebelumTutup + 50);
  b = await baris();
  cek('LAPORAN TIDAK IKUT BERUBAH', b.stok_akhir !== sebelumTutup + 50, true);
  const bekuNya = b.stok_akhir;
  masuk(7);
  b = await baris();
  cek('tetap diam walau stok bergerak lagi', b.stok_akhir, bekuNya);

  console.log('\n--- unduhannya ikut diam ---');
  let res = await fetch(B + '/api/export/csv.php?jenis=opname&id=' + sesiId
    + '&q=' + encodeURIComponent(barcodeB), { headers: { Cookie: S.cookie } });
  const csv = Buffer.from(await res.arrayBuffer()).slice(3).toString('utf8').split('\r\n');
  const iK = csv.findIndex(x => x.indexOf('SKU;') === 0);
  cek('Excel memuat angka yang sama dengan layar', Number(csv[iK + 1].split(';')[4]), bekuNya);

  /* ------------------------------------------------------------------ */
  console.log('\n=== C. dibuka kembali: hidup lagi ===');
  r = await post(S, 'opname/save.php', {
    id: sesiId, nama: sesiLama.nama, periode: sesiLama.periode,
    tanggal: sesiLama.tanggal, status: 'draft', catatan: sesiLama.catatan || ''
  });
  cek('HTTP', r.status, 200);
  b = await baris();
  cek('stok akhir kembali mengikuti stok barang', b.stok_akhir, stokB());
  masuk(3);
  b = await baris();
  cek('dan ikut bergerak lagi', b.stok_akhir, stokB());

  /* ------------------------------------------------------------------ */
  console.log('\n=== Kembalikan keadaan semula ===');
  bersihkan();
  cek('stok kembali seperti semula', stokB(), awal);
  cek('tidak ada baris uji yang tertinggal',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE id > " + masukAwal), '0');
  cek('sesinya kembali berjalan',
      nilai('SELECT status FROM opname_sesi WHERE id = ' + sesiId), 'draft');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
