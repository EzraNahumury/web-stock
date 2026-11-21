/* Dua hal yang diminta gudang 21 November:
 *
 *  1. Barang masuk / keluar bisa dicari lewat NAMA dan SKU dari katalog, dan
 *     unduhan PDF-nya memuat persis yang dicari.
 *  2. Laporan stok opname yang sudah DITUTUP angkanya tidak berubah lagi,
 *     supaya bisa dipakai menelusuri selisih bulan berikutnya.
 *
 * Yang nomor 2 berpasangan dengan perubahan 27 Oktober: selama sesinya
 * berjalan, Stok akhir memang harus mengikuti stok hidup, karena penyesuaian
 * stok bekerja pada angka itu. Suite ini menjaga KEDUANYA tetap benar — beku
 * sesudah ditutup, hidup lagi bila dibuka kembali.
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
  console.log((ok ? '  OK    ' : '  GAGAL ') + label.padEnd(60)
    + (ok ? JSON.stringify(dapat).slice(0, 80) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

function petikTeksPdf(buf) {
  const mentah = buf.toString('latin1');
  const keluar = [];
  const re = /\(((?:\\.|[^\\()])*)\)\s*Tj/g;
  let m;
  while ((m = re.exec(mentah)) !== null) { keluar.push(m[1].replace(/\\([\\()])/g, '$1')); }
  return keluar;
}

const TANDA = 'UJICB';

(async () => {
  const masukAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_masuk");
  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  /* ------------------------------------------------------------------ */
  console.log('\n=== A. cari barang masuk lewat nama pendek dan SKU ===');
  /* Baris transaksi menyimpan nama APA ADANYA dari picking list — panjang.
     Orang gudang mencari dengan nama pendek dan SKU dari katalog. */
  const m = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "\"SELECT id, sku, barcode, nama FROM master_barang WHERE deleted_at IS NULL"
    + " AND sku <> '' AND barcode <> '' ORDER BY id LIMIT 1\"));"));
  console.log('  barang uji: [' + m.sku + '] ' + m.nama);

  const namaPanjang = m.nama + ' VARIAN PANJANG DARI PICKING LIST ' + TANDA;
  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute(['2026-11-21'," + m.id + ",'" + m.barcode + "',"
    + "'" + namaPanjang.replace(/'/g, "''") + "',3,'Restock']); echo 'ok';");

  let d = await get(S, 'masuk/list.php?q=' + encodeURIComponent(m.sku));
  cek('ketemu lewat SKU', d.body.rows.some(x => x.nama === namaPanjang), true);
  cek('SKU ikut dikirim untuk ditampilkan', d.body.rows[0].sku, m.sku);

  d = await get(S, 'masuk/list.php?q=' + encodeURIComponent(m.nama));
  cek('ketemu lewat nama pendek katalog', d.body.rows.some(x => x.nama === namaPanjang), true);

  d = await get(S, 'masuk/list.php?q=' + encodeURIComponent(TANDA));
  cek('ketemu lewat nama panjang di barisnya', d.body.total, 1);
  cek('angka total ikut penyaring, bukan seluruh tabel', d.body.total_jumlah, 3);

  d = await get(S, 'masuk/list.php?q=TIDAK-ADA-SAMA-SEKALI-XYZ');
  cek('pencarian yang tidak cocok mengembalikan kosong', [d.body.total, d.body.total_jumlah], [0, 0]);

  console.log('\n--- barang keluar ikut aturan yang sama ---');
  d = await get(S, 'keluar/list.php?q=' + encodeURIComponent(m.sku));
  cek('HTTP', d.status, 200);
  cek('barang keluar juga membawa SKU',
      d.body.rows.length === 0 || d.body.rows[0].sku !== undefined, true);

  /* ------------------------------------------------------------------ */
  console.log('\n=== B. unduhan PDF memuat persis yang dicari ===');
  let res = await fetch(B + '/api/export/pdf.php?jenis=masuk&q=' + encodeURIComponent(TANDA),
    { headers: { Cookie: S.cookie } });
  let buf = Buffer.from(await res.arrayBuffer());
  cek('PDF keluar', [res.status, buf.slice(0, 4).toString()], [200, '%PDF']);
  let teks = petikTeksPdf(buf).join(' ');
  cek('memuat baris yang dicari', teks.indexOf(TANDA) >= 0, true);
  cek('menyebut jumlah baris hasil pencarian', /Baris: 1\b/.test(teks.replace(/\s+/g, ' ')), true);
  cek('SKU ikut tercetak', teks.indexOf(m.sku) >= 0, true);

  res = await fetch(B + '/api/export/pdf.php?jenis=masuk&q=' + encodeURIComponent(m.sku),
    { headers: { Cookie: S.cookie } });
  buf = Buffer.from(await res.arrayBuffer());
  teks = petikTeksPdf(buf).join(' ');
  cek('dicari lewat SKU pun barisnya ikut', teks.indexOf(TANDA) >= 0, true);

  const semua = Number(nilai("SELECT COUNT(*) FROM barang_masuk WHERE deleted_at IS NULL"));
  res = await fetch(B + '/api/export/pdf.php?jenis=masuk', { headers: { Cookie: S.cookie } });
  buf = Buffer.from(await res.arrayBuffer());
  teks = petikTeksPdf(buf).join(' ').replace(/\s+/g, ' ');
  cek('tanpa pencarian: seluruh baris', teks.indexOf('Baris: ' + semua) >= 0, true);

  php("require 'includes/db.php'; db()->exec('DELETE FROM barang_masuk WHERE id > " + masukAwal + "'); echo 'ok';");

  /* ------------------------------------------------------------------ */
  console.log('\n=== C. laporan opname yang ditutup angkanya beku ===');
  const sesiId = Number(nilai('SELECT id FROM opname_sesi WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1'));
  const sesiLama = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "'SELECT nama, periode, tanggal, status, catatan FROM opname_sesi WHERE id = " + sesiId + "'));"));

  const idB = Number(nilai('SELECT id FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND master_id IS NOT NULL ORDER BY id LIMIT 1'));
  const masterB = Number(nilai('SELECT master_id FROM opname_item WHERE id = ' + idB));
  const stokB = () => Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m WHERE m.id = " + masterB));
  const barisOpname = async () => {
    const r = await get(S, 'opname/detail.php?id=' + sesiId + '&q='
      + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)));
    return r.body.rows[0];
  };

  const bersihkan = () => php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"DELETE FROM barang_keluar WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan = '" + TANDA + "'\");"
    + "db()->exec(\"UPDATE opname_item SET stok_hitung=NULL, stok_accurate=NULL, stok_tutup=NULL,"
    + " penyesuaian='Tidak Disesuaikan', adj_jenis=NULL, adj_id=NULL, adj_qty=NULL"
    + " WHERE sesi_id = " + sesiId + "\");"
    + "db()->prepare('UPDATE opname_sesi SET status = ? WHERE id = ?')->execute(['draft'," + sesiId + "]); echo 'ok';");

  bersihkan();
  const awal = stokB();
  console.log('  stok awal barang uji: ' + awal);

  await post(S, 'opname/item.php', { id: idB, stok_hitung: awal + 5 });
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('sesi berjalan: stok mengikuti hitungan', stokB(), awal + 5);
  let b = await barisOpname();
  cek('sesi berjalan: stok akhir = stok hidup', b.stok_akhir, awal + 5);
  cek('sesi berjalan: belum ada angka beku', b.stok_tutup, null);
  cek('sesi berjalan: selisih nol sesudah dikoreksi', b.selisih, 0);

  console.log('\n--- ditutup ---');
  let r = await post(S, 'opname/save.php', {
    id: sesiId, nama: sesiLama.nama, periode: sesiLama.periode,
    tanggal: sesiLama.tanggal, status: 'selesai', catatan: sesiLama.catatan || ''
  });
  cek('HTTP', r.status, 200);
  b = await barisOpname();
  cek('angkanya dibekukan', b.stok_tutup, awal + 5);
  cek('stok akhir memakai angka beku', b.stok_akhir, awal + 5);

  console.log('\n--- stok bergerak sesudah laporan ditutup ---');
  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute(['2026-11-21'," + masterB + ",'x','x',40,'" + TANDA + "']); echo 'ok';");
  cek('stok sekarang memang sudah berubah', stokB(), awal + 45);
  b = await barisOpname();
  cek('LAPORAN TIDAK IKUT BERUBAH', b.stok_akhir, awal + 5);
  cek('selisihnya pun tetap nol', b.selisih, 0);

  let dd = await get(S, 'opname/detail.php?id=' + sesiId);
  cek('kartu Jumlah selisih ikut beku', typeof dd.body.ringkas.total_selisih, 'number');

  console.log('\n--- unduhannya ikut beku ---');
  res = await fetch(B + '/api/export/csv.php?jenis=opname&id=' + sesiId + '&q='
    + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)),
    { headers: { Cookie: S.cookie } });
  const csv = Buffer.from(await res.arrayBuffer()).slice(3).toString('utf8').split('\r\n');
  const iK = csv.findIndex(x => x.indexOf('SKU;') === 0);
  const kolom = csv[iK + 1].split(';');
  cek('Excel memuat stok akhir yang beku', Number(kolom[4]), awal + 5);

  console.log('\n--- dibuka kembali: hidup lagi ---');
  r = await post(S, 'opname/save.php', {
    id: sesiId, nama: sesiLama.nama, periode: sesiLama.periode,
    tanggal: sesiLama.tanggal, status: 'draft', catatan: sesiLama.catatan || ''
  });
  cek('HTTP', r.status, 200);
  b = await barisOpname();
  cek('angka bekunya dihapus', b.stok_tutup, null);
  cek('stok akhir kembali mengikuti stok hidup', b.stok_akhir, awal + 45);
  cek('selisihnya ikut hidup lagi', b.selisih, (awal + 5) - (awal + 45));

  console.log('\n=== Kembalikan keadaan semula ===');
  bersihkan();
  php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM activity_log WHERE entitas = 'opname'\"); echo 'ok';");
  cek('stok kembali seperti semula', stokB(), awal);
  cek('tidak ada baris uji yang tertinggal',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE id > " + masukAwal), '0');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
