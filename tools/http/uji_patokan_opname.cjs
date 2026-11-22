/* SATU PATOKAN untuk seluruh laporan stok opname.
 *
 * Gudang menerangkannya begini:
 *
 *   "stok akhir ini ialah per tanggal laporan stok opname dibuat. contoh
 *    sekarang tgl 22, maka selama periode di range tgl 22 stok barang masuk
 *    dan barang keluar mengikuti stok akhir."
 *
 * Jadi laporan opname adalah potret bertanggal. Patokannya stok pada tanggal
 * laporan itu dibuat — masih berjalan maupun sudah ditutup — dan pergerakan
 * sesudahnya menumpuk di atasnya, bukan menggeser patokannya.
 *
 * Konsekuensinya koreksi "Stok Disesuaikan" ikut berubah: yang ditulis sebesar
 * SELISIHNYA, bukan memaksa stok jadi persis angka hitungan. Tanpa itu, barang
 * yang masuk sesudah penghitungan ikut terhapus — dan angka Selisih di layar
 * tidak sama dengan koreksi yang benar-benar ditulis, yang justru masalah
 * awalnya.
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

const TANDA = 'UJIPTK';

(async () => {
  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  const sesiId = Number(nilai('SELECT id FROM opname_sesi WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1'));
  const sesiLama = JSON.parse(php("require 'includes/db.php'; echo json_encode(dbOne("
    + "'SELECT nama, periode, tanggal, status, catatan FROM opname_sesi WHERE id = " + sesiId + "'));"));
  const idB = Number(nilai('SELECT id FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND master_id IS NOT NULL ORDER BY id LIMIT 1'));
  const masterB = Number(nilai('SELECT master_id FROM opname_item WHERE id = ' + idB));
  const sistemAsli = Number(nilai('SELECT stok_sistem FROM opname_item WHERE id = ' + idB));

  const stokB = () => Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m WHERE m.id = " + masterB));
  const baris = async () => {
    const r = await get(S, 'opname/detail.php?id=' + sesiId + '&q='
      + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)));
    return r.body.rows[0];
  };
  const bersihkan = () => php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan IN ('Penyesuaian Opname','" + TANDA + "')\");"
    + "db()->exec(\"DELETE FROM barang_keluar WHERE keterangan IN ('Penyesuaian Opname','" + TANDA + "')\");"
    + "db()->exec(\"UPDATE opname_item SET stok_hitung=NULL, stok_accurate=NULL, stok_tutup=NULL,"
    + " penyesuaian='Tidak Disesuaikan', adj_jenis=NULL, adj_id=NULL, adj_qty=NULL"
    + " WHERE sesi_id = " + sesiId + "\");"
    + "db()->exec('UPDATE opname_item SET stok_sistem = " + sistemAsli + " WHERE id = " + idB + "');"
    + "db()->prepare('UPDATE opname_sesi SET status = ? WHERE id = ?')->execute(['draft'," + sesiId + "]); echo 'ok';");

  bersihkan();

  /* Laporan dibuat tanggal 22: sistem memegang 38 saat itu. */
  const PATOKAN = 38;
  php("require 'includes/db.php'; db()->exec('UPDATE opname_item SET stok_sistem = "
    + PATOKAN + " WHERE id = " + idB + "'); echo 'ok';");
  const stok0 = stokB();
  console.log('\n  patokan laporan (stok saat dibuat) : ' + PATOKAN);
  console.log('  stok barang yang berlaku sekarang : ' + stok0);

  /* ------------------------------------------------------------------ */
  console.log('\n=== A. Stok akhir = stok saat laporan dibuat ===');
  let b = await baris();
  cek('sesi masih berjalan', (await get(S, 'opname/detail.php?id=' + sesiId)).body.sesi.status, 'draft');
  cek('stok akhir = patokan, bukan stok hari ini', b.stok_akhir, PATOKAN);
  cek('dan memang berbeda dari stok hari ini', b.stok_akhir !== stok0, true);

  console.log('\n--- stok bergerak selama periode berjalan ---');
  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_masuk (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute(['2026-11-23'," + masterB + ",'x','x',4,'" + TANDA + "']); echo 'ok';");
  cek('stok barang bertambah 4', stokB(), stok0 + 4);
  b = await baris();
  cek('PATOKAN TIDAK IKUT BERGERAK', b.stok_akhir, PATOKAN);

  /* ------------------------------------------------------------------ */
  console.log('\n=== B. Selisih dihitung terhadap patokan ===');
  let r = await post(S, 'opname/item.php', { id: idB, stok_hitung: 33 });
  cek('selisih = 33 - 38', r.body.selisih, 33 - PATOKAN);
  b = await baris();
  cek('layar menunjukkan selisih yang sama', b.selisih, 33 - PATOKAN);

  /* ------------------------------------------------------------------ */
  console.log('\n=== C. Koreksi sebesar selisihnya, bukan memaksa jadi 33 ===');
  /* Inilah bagian yang ikut berubah. Dulu stok dipaksa jadi persis 33, dan
     4 pcs yang masuk tanggal 23 ikut lenyap. Sekarang stok digeser -5 saja. */
  const sebelumKoreksi = stokB();
  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('HTTP', r.status, 200);
  cek('KOREKSI = SELISIH YANG DITAMPILKAN', r.body.adj_qty, PATOKAN - 33);
  cek('arahnya keluar', r.body.adj_jenis, 'keluar');
  cek('stok digeser sebesar selisihnya', stokB(), sebelumKoreksi - (PATOKAN - 33));
  cek('BARANG YANG MASUK TGL 23 TIDAK IKUT TERHAPUS', stokB(), stok0 + 4 - (PATOKAN - 33));
  cek('stok TIDAK dipaksa jadi angka hitungan', stokB() !== 33, true);

  b = await baris();
  cek('patokan tetap, jadi selisihnya pun tetap', [b.stok_akhir, b.selisih], [PATOKAN, 33 - PATOKAN]);

  console.log('\n--- ditekan dua kali: tidak menumpuk ---');
  const sesudahSekali = stokB();
  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('stok tidak bergeser lagi', stokB(), sesudahSekali);
  cek('koreksinya tetap satu baris',
      nilai("SELECT COUNT(*) FROM barang_keluar WHERE keterangan='Penyesuaian Opname' AND deleted_at IS NULL"), '1');

  console.log('\n--- dibatalkan: stok kembali ---');
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Tidak Disesuaikan' });
  cek('stok kembali seperti sebelum dikoreksi', stokB(), stok0 + 4);

  /* ------------------------------------------------------------------ */
  console.log('\n=== D. arah sebaliknya ===');
  await post(S, 'opname/item.php', { id: idB, stok_hitung: PATOKAN + 7 });
  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('koreksi masuk sebesar 7', [r.body.adj_jenis, r.body.adj_qty], ['masuk', 7]);
  cek('stok naik 7 dari keadaan sebelumnya', stokB(), stok0 + 4 + 7);
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Tidak Disesuaikan' });
  await post(S, 'opname/item.php', { id: idB, stok_hitung: 33 });

  /* ------------------------------------------------------------------ */
  console.log('\n=== E. ditutup: tidak ada yang berubah ===');
  /* Dengan satu patokan, menutup laporan tidak mengubah angkanya sama sekali —
     itulah untungnya: tidak ada momen di mana angkanya meloncat. */
  const sebelumTutup = await baris();
  r = await post(S, 'opname/save.php', {
    id: sesiId, nama: sesiLama.nama, periode: sesiLama.periode,
    tanggal: sesiLama.tanggal, status: 'selesai', catatan: sesiLama.catatan || ''
  });
  cek('HTTP', r.status, 200);
  const sesudahTutup = await baris();
  cek('stok akhirnya sama sebelum dan sesudah ditutup',
      sesudahTutup.stok_akhir, sebelumTutup.stok_akhir);
  cek('selisihnya pun sama', sesudahTutup.selisih, sebelumTutup.selisih);

  php("require 'includes/db.php';"
    + "db()->prepare('INSERT INTO barang_keluar (tanggal, master_id, barcode, nama, jumlah, keterangan)"
    + " VALUES (?,?,?,?,?,?)')->execute(['2026-11-30'," + masterB + ",'x','x',3,'" + TANDA + "']); echo 'ok';");
  b = await baris();
  cek('stok bergerak lagi, laporan tetap diam', b.stok_akhir, PATOKAN);

  /* ------------------------------------------------------------------ */
  console.log('\n=== F. ringkasan, penyaring, dan unduhan ikut patokan ===');
  const dd = await get(S, 'opname/detail.php?id=' + sesiId);
  cek('kartu Berselisih ikut patokan', dd.body.ringkas.beda,
      Number(nilai('SELECT COUNT(*) FROM opname_item WHERE sesi_id = ' + sesiId
        + ' AND stok_hitung IS NOT NULL AND stok_hitung <> stok_sistem')));
  const saring = await get(S, 'opname/detail.php?id=' + sesiId + '&hanya=selisih');
  cek('penyaring "Ada selisih" ikut patokan',
      saring.body.rows.every(x => x.stok_hitung !== null && x.stok_hitung !== x.stok_sistem), true);

  let res = await fetch(B + '/api/export/csv.php?jenis=opname&id=' + sesiId + '&q='
    + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)),
    { headers: { Cookie: S.cookie } });
  const csv = Buffer.from(await res.arrayBuffer()).slice(3).toString('utf8').split('\r\n');
  const iK = csv.findIndex(x => x.indexOf('SKU;') === 0);
  const kepala = csv[iK].split(';');
  const kolom = csv[iK + 1].split(';');
  cek('Excel menyebut patokannya di judul kolom', kepala[4], 'Stok akhir (saat laporan dibuat)');
  cek('Excel memuat angka patokan', Number(kolom[4]), PATOKAN);
  cek('Excel memuat selisih yang sama', Number(kolom[8]), 33 - PATOKAN);

  res = await fetch(B + '/api/export/pdf.php?jenis=opname&id=' + sesiId + '&q='
    + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)),
    { headers: { Cookie: S.cookie } });
  const buf = Buffer.from(await res.arrayBuffer());
  const teksPdf = [];
  const re = /\(((?:\\.|[^\\()])*)\)\s*Tj/g;
  let m;
  const mentah = buf.toString('latin1');
  while ((m = re.exec(mentah)) !== null) { teksPdf.push(m[1]); }
  cek('PDF keluar', buf.slice(0, 4).toString(), '%PDF');
  cek('PDF memuat angka patokan', teksPdf.indexOf(String(PATOKAN)) >= 0, true);

  /* ------------------------------------------------------------------ */
  console.log('\n=== Kembalikan keadaan semula ===');
  bersihkan();
  cek('stok kembali seperti semula', stokB(), stok0);
  cek('stok_sistem kembali seperti semula',
      Number(nilai('SELECT stok_sistem FROM opname_item WHERE id = ' + idB)), sistemAsli);
  cek('tidak ada koreksi yang tertinggal',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan='Penyesuaian Opname'"), '0');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
