/* Tiga revisi 24 November:
 *   1. Penyaring tanggal + status di daftar laporan stok opname.
 *   2. Impor Accurate dari CSV/Excel, dicocokkan lewat kode barang.
 *   3. Kolom "Di-packing oleh" pada barang keluar.
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
    + (ok ? JSON.stringify(dapat).slice(0, 70) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

const TANDA = 'UJIN24';

(async () => {
  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  const keluarAwal = nilai("SELECT COALESCE(MAX(id),0) FROM barang_keluar");
  const sesiAwal   = nilai("SELECT COALESCE(MAX(id),0) FROM opname_sesi");

  /* ------------------------------------------------------------------ */
  console.log('\n=== 1. penyaring tanggal + status di daftar laporan opname ===');
  const kat = nilai("SELECT kategori FROM master_barang WHERE deleted_at IS NULL AND kategori <> '' LIMIT 1");
  const buatSesi = async (nama, tanggal) => (await post(S, 'opname/save.php', {
    nama: nama, periode: nama, tanggal: tanggal, kategori: kat, status: 'draft', catatan: TANDA
  })).body.id;

  const sLama = await buatSesi(TANDA + ' LAMA', '2026-09-30');
  const sBaru = await buatSesi(TANDA + ' BARU', '2026-11-24');
  cek('dua sesi dibuat', [typeof sLama, typeof sBaru], ['number', 'number']);

  let d = await get(S, 'opname/list.php?q=' + TANDA);
  cek('tanpa penyaring: keduanya tampil', d.body.total, 2);

  d = await get(S, 'opname/list.php?q=' + TANDA + '&dari=2026-11-01');
  cek('penyaring "dari": hanya yang November', d.body.rows.map(x => x.id), [sBaru]);
  d = await get(S, 'opname/list.php?q=' + TANDA + '&sampai=2026-10-31');
  cek('penyaring "sampai": hanya yang September', d.body.rows.map(x => x.id), [sLama]);
  d = await get(S, 'opname/list.php?q=' + TANDA + '&dari=2026-09-01&sampai=2026-09-30');
  cek('rentang sempit', d.body.rows.map(x => x.id), [sLama]);
  cek('penyaringnya dikembalikan apa adanya', [d.body.dari, d.body.sampai], ['2026-09-01', '2026-09-30']);

  await post(S, 'opname/save.php', {
    id: sLama, nama: TANDA + ' LAMA', periode: TANDA + ' LAMA',
    tanggal: '2026-09-30', status: 'selesai', catatan: TANDA
  });
  d = await get(S, 'opname/list.php?q=' + TANDA + '&status=selesai');
  cek('penyaring status selesai', d.body.rows.map(x => x.id), [sLama]);
  d = await get(S, 'opname/list.php?q=' + TANDA + '&status=draft');
  cek('penyaring status berjalan', d.body.rows.map(x => x.id), [sBaru]);
  d = await get(S, 'opname/list.php?q=' + TANDA + '&status=ngawur');
  cek('status ngawur diabaikan, bukan galat', [d.status, d.body.total, d.body.status], [200, 2, '']);
  d = await get(S, 'opname/list.php?q=' + TANDA + '&dari=bukan-tanggal');
  cek('tanggal ngawur diabaikan', [d.status, d.body.total], [200, 2]);

  /* ------------------------------------------------------------------ */
  console.log('\n=== 2. impor Accurate dicocokkan lewat kode barang ===');
  const item = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "'SELECT id, sku, barcode, nama FROM opname_item WHERE sesi_id = " + sBaru
    + " AND sku <> \\'\\' ORDER BY id LIMIT 1'));"));
  cek('ada baris bersku di sesi uji', item !== null && item.sku !== '', true);

  /* Nama sengaja DIBUAT BERBEDA dari yang tersimpan. Dengan pencocokan nama
     saja ini pasti gagal; lewat kode harus tetap ketemu. Itulah inti
     perbaikannya. */
  let r = await post(S, 'opname/accurate.php', {
    id: sBaru, pratinjau: true,
    rows: [{ kode: item.sku, nama: 'NAMA YANG SAMA SEKALI BERBEDA', qty: 77 }]
  });
  cek('cocok lewat kode meski namanya beda', r.body.cocok, 1);
  cek('dilaporkan cocok lewat kode', r.body.lewat_kode, 1);

  r = await post(S, 'opname/accurate.php', {
    id: sBaru, rows: [{ kode: item.sku, nama: 'NAMA YANG SAMA SEKALI BERBEDA', qty: 77 }]
  });
  cek('tersimpan', nilai('SELECT stok_accurate FROM opname_item WHERE id = ' + item.id), '77');

  console.log('\n--- barcode juga diterima sebagai kode ---');
  r = await post(S, 'opname/accurate.php', {
    id: sBaru, rows: [{ kode: item.barcode, nama: '', qty: 55 }]
  });
  cek('cocok lewat barcode', r.body.lewat_kode, 1);
  cek('tersimpan', nilai('SELECT stok_accurate FROM opname_item WHERE id = ' + item.id), '55');

  console.log('\n--- tanpa kode: nama tetap dipakai (impor PDF) ---');
  r = await post(S, 'opname/accurate.php', {
    id: sBaru, pratinjau: true, rows: [{ nama: item.nama, qty: 9 }]
  });
  cek('cocok lewat nama', r.body.cocok, 1);
  cek('bukan lewat kode', r.body.lewat_kode, 0);

  console.log('\n--- kode tak dikenal jatuh ke nama, bukan langsung gagal ---');
  r = await post(S, 'opname/accurate.php', {
    id: sBaru, pratinjau: true, rows: [{ kode: 'KODE-TIDAK-ADA-XYZ', nama: item.nama, qty: 4 }]
  });
  cek('masih ketemu lewat namanya', r.body.cocok, 1);
  r = await post(S, 'opname/accurate.php', {
    id: sBaru, pratinjau: true, rows: [{ kode: 'KODE-TIDAK-ADA-XYZ', nama: 'JUGA TIDAK ADA', qty: 4 }]
  });
  cek('dua-duanya tak dikenal: dilaporkan tak ketemu', [r.body.cocok, r.body.tak_ketemu.length], [0, 1]);

  console.log('\n--- pembaca CSV di peramban ---');
  /* Dijalankan di Node dengan memuat berkasnya apa adanya, supaya aturannya
     benar-benar yang dipakai layar — bukan salinan yang bisa menyimpang. */
  const fs = require('fs');
  const src = fs.readFileSync('C:/web-stock/assets/js/accurate-csv.js', 'utf8');
  const sandbox = {};
  new Function('window', src)(sandbox);
  const Csv = sandbox.AccurateCsv;

  const csvTeks = 'sep=;\r\n'
    + 'Laporan Kuantitas Barang\r\n'
    + 'Per 24 Nov 2026\r\n'
    + '\r\n'
    + 'No. Barang;Nama Barang;Gudang;Kuantitas\r\n'
    + '"' + item.sku + '";"' + item.nama + '";Utama;1.234\r\n'
    + 'AV-X;"Barang, berkoma";Utama;7\r\n'
    + 'SUBTOTAL;;;\r\n';
  const hasil = Csv.baca(csvTeks);
  cek('kolom kode ketemu', hasil.adaKode, true);
  cek('dua baris data terbaca', hasil.rows.length, 2);
  cek('kode terbaca', hasil.rows[0].kode, item.sku);
  cek('angka bergaya Indonesia: 1.234 = seribu dua ratus tiga puluh empat',
      hasil.rows[0].qty, 1234);
  cek('sel berkutip yang memuat pemisah tetap utuh', hasil.rows[1].nama, 'Barang, berkoma');
  cek('baris subtotal dilewati diam-diam', hasil.dilewati, 1);

  cek('pemisah koma juga dikenali',
      Csv.baca('No. Barang,Nama Barang,Qty\nA1,Satu,5\n').rows[0].qty, 5);
  cek('angka desimal gaya Indonesia', Csv.keAngka('1.234,56'), 1234.56);
  cek('angka desimal gaya Inggris', Csv.keAngka('1,234.56'), 1234.56);
  cek('tanpa pemisah', Csv.keAngka('42'), 42);
  let pesan = '';
  try { Csv.baca('tidak;ada;judulnya\n1;2;3\n'); } catch (e) { pesan = e.message; }
  cek('tanpa baris judul: pesannya menerangkan apa yang kurang',
      pesan.indexOf('judul kolom') >= 0, true);

  /* Baris hasil pembaca dikirim apa adanya ke server — bentuknya memang satu. */
  r = await post(S, 'opname/accurate.php', { id: sBaru, pratinjau: true, rows: hasil.rows });
  cek('hasil pembaca CSV diterima server', r.body.cocok, 1);
  cek('dan cocok lewat kode', r.body.lewat_kode, 1);

  /* ------------------------------------------------------------------ */
  console.log('\n=== 3. kolom "Di-packing oleh" pada barang keluar ===');
  const brg = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "\"SELECT id, barcode, nama FROM master_barang WHERE deleted_at IS NULL"
    + " AND barcode <> '' ORDER BY id LIMIT 1\"));"));
  const stok = () => Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m WHERE m.id = " + brg.id));

  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute([date('Y-m-d')," + brg.id + ",'" + brg.barcode + "','x',20,'" + TANDA + "']); echo 'ok';");

  r = await post(S, 'keluar/create.php', {
    tanggal: '2026-11-24', barcode: brg.barcode, nama: brg.nama, jumlah: 2,
    keterangan: 'Pesanan MP', no_pesanan: TANDA + '-1', packing_oleh: 'NANAY'
  });
  cek('HTTP', r.status, 200);
  cek('tersimpan', nilai("SELECT packing_oleh FROM barang_keluar WHERE no_pesanan = '" + TANDA + "-1'"), 'NANAY');

  await post(S, 'keluar/create.php', {
    tanggal: '2026-11-24', barcode: brg.barcode, nama: brg.nama, jumlah: 1,
    keterangan: 'Pesanan MP', no_pesanan: TANDA + '-2', packing_oleh: 'FAHRUL'
  });

  d = await get(S, 'keluar/list.php?q=' + TANDA);
  cek('ikut dikirim ke layar', d.body.rows.some(x => x.packing_oleh === 'NANAY'), true);
  cek('daftar pem-packing dikirim untuk dropdown',
      ['FAHRUL', 'NANAY'].every(n => (d.body.packing_options || []).indexOf(n) >= 0), true);

  console.log('\n--- penyaring dan pencarian ---');
  d = await get(S, 'keluar/list.php?q=' + TANDA + '&packing=NANAY');
  cek('penyaring pem-packing', d.body.rows.map(x => x.no_pesanan), [TANDA + '-1']);
  cek('penanda penyaringnya dikembalikan', d.body.packing, 'NANAY');
  d = await get(S, 'keluar/list.php?q=FAHRUL');
  cek('kotak pencarian juga menjangkaunya',
      d.body.rows.some(x => x.no_pesanan === TANDA + '-2'), true);
  d = await get(S, 'keluar/list.php?q=' + TANDA + '&packing=TIDAK-ADA');
  cek('penyaring yang tak cocok: kosong', d.body.total, 0);

  console.log('\n--- barang masuk tidak punya kolom ini ---');
  d = await get(S, 'masuk/list.php?packing=NANAY');
  cek('penyaringnya diabaikan, bukan galat', d.status, 200);
  cek('dan daftar pem-packingnya kosong', d.body.packing_options, []);

  console.log('\n--- ikut tercetak di PDF ---');
  let res = await fetch(B + '/api/export/pdf.php?jenis=keluar&q=' + TANDA,
    { headers: { Cookie: S.cookie } });
  let buf = Buffer.from(await res.arrayBuffer());
  const teksPdf = [];
  const re = /\(((?:\\.|[^\\()])*)\)\s*Tj/g;
  let m;
  const mentah = buf.toString('latin1');
  while ((m = re.exec(mentah)) !== null) { teksPdf.push(m[1]); }
  cek('PDF keluar', buf.slice(0, 4).toString(), '%PDF');
  cek('ada judul kolomnya', teksPdf.indexOf('Di-packing oleh') >= 0, true);
  cek('dan namanya tercetak', teksPdf.indexOf('NANAY') >= 0, true);

  res = await fetch(B + '/api/export/pdf.php?jenis=keluar&q=' + TANDA + '&packing=NANAY',
    { headers: { Cookie: S.cookie } });
  buf = Buffer.from(await res.arrayBuffer());
  cek('PDF ikut penyaring pem-packing',
      buf.toString('latin1').indexOf('FAHRUL') === -1, true);

  /* ------------------------------------------------------------------ */
  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec('DELETE FROM opname_item WHERE sesi_id > " + sesiAwal + "');"
    + "db()->exec('DELETE FROM opname_sesi WHERE id > " + sesiAwal + "');"
    + "db()->exec('DELETE FROM barang_keluar WHERE id > " + keluarAwal + "');"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan = '" + TANDA + "'\");"
    + "db()->exec(\"DELETE FROM activity_log WHERE entitas IN ('opname','keluar','masuk')\"); echo 'ok';");
  cek('sesi uji terhapus', nilai("SELECT COUNT(*) FROM opname_sesi WHERE id > " + sesiAwal), '0');
  cek('barang keluar uji terhapus', nilai("SELECT COUNT(*) FROM barang_keluar WHERE id > " + keluarAwal), '0');
  cek('stok kembali seperti semula', stok(), Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m WHERE m.id = " + brg.id)));

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
