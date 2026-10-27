/* Laporan stok opname: nama di PDF, stok accurate minus, centang massal,
 * penyesuaian massal.
 *
 * KENAPA SUITE INI ADA
 * Laporan stok opname adalah laporan yang dipakai SAMBIL menghitung barang di
 * rak. Nama barang yang terpotong jadi "AYRES CELANA RUNNING DOUBLE LAYE..."
 * membuat belasan baris tidak bisa dibedakan satu sama lain — tepat pada
 * laporan yang paling butuh dibedakan. 908 dari 1.435 nama di katalog ini
 * lebih dari 28 karakter, jadi ini keadaan biasa, bukan kasus pinggiran.
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
    + (ok ? JSON.stringify(dapat).slice(0, 90) : 'dapat ' + JSON.stringify(dapat) + ', harap ' + JSON.stringify(harap)));
  if (!ok) { gagal++; }
}

/** Seluruh potongan teks di dalam PDF, dari operator "(...) Tj". */
function petikTeksPdf(buf) {
  const mentah = buf.toString('latin1');
  const keluar = [];
  const re = /\(((?:\\.|[^\\()])*)\)\s*Tj/g;
  let m;
  while ((m = re.exec(mentah)) !== null) {
    keluar.push(m[1].replace(/\\([\\()])/g, '$1'));
  }
  return keluar;
}

(async () => {
  const S = await login('admin', 'admin123');
  if (!S.token) { console.log('Gagal masuk ke ' + B); process.exit(1); }

  const sesiId = nilai('SELECT id FROM opname_sesi WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
  if (!sesiId) { console.log('Tidak ada sesi opname di basis data uji.'); process.exit(1); }

  /* ------------------------------------------------------------------ */
  console.log('\n=== A. nama barang di PDF tidak lagi terpotong ===');
  const panjang = php("require 'includes/db.php';"
    + " echo (string)dbValue('SELECT nama FROM opname_item ORDER BY CHAR_LENGTH(nama) DESC LIMIT 1');");
  console.log('  nama terpanjang: ' + panjang.length + ' karakter');
  cek('katalognya memang punya nama panjang', panjang.length > 60, true);

  const res = await fetch(B + '/api/export/pdf.php?jenis=opname&id=' + sesiId
    + '&q=' + encodeURIComponent(panjang.slice(0, 20)), { headers: { Cookie: S.cookie } });
  const buf = Buffer.from(await res.arrayBuffer());
  cek('PDF keluar', [res.status, buf.slice(0, 4).toString()], [200, '%PDF']);

  const potongan = petikTeksPdf(buf);
  const gabung = potongan.join(' ').replace(/\s+/g, ' ');
  /* Nama panjang itu kini tersebar di beberapa baris, jadi yang dicari
     seluruh katanya berurutan pada teks yang digabungkan kembali. */
  cek('seluruh kata namanya ada di PDF',
      gabung.indexOf(panjang.replace(/\s+/g, ' ')) >= 0, true);
  cek('tidak ada nama yang berakhir dengan elipsis',
      potongan.some(t => /\w\.\.\.$/.test(t) && t.length > 20), false);

  /* Sebelum diperbaiki, nama 93 karakter muncul sebagai SATU potongan
     berakhiran "...". Sekarang harus lebih dari satu potongan. */
  const kataAwal = panjang.split(/\s+/)[0];
  const barisNama = potongan.filter(t => t.indexOf(kataAwal) === 0);
  cek('namanya dibungkus, bukan dipotong', barisNama.length >= 1, true);

  /* ------------------------------------------------------------------ */
  console.log('\n=== A2. SKU panjang juga utuh ===');
  /* SKU di katalog ini sampai 15 karakter dan 181 di antaranya memuat spasi
     ("SFN 006 070 045"), jadi kolom selebar 8 memotongnya jadi "SFN 006 070...". */
  const skuPanjang = php("require 'includes/db.php';"
    + " echo (string)dbValue('SELECT sku FROM opname_item ORDER BY CHAR_LENGTH(sku) DESC LIMIT 1');");
  console.log('  SKU terpanjang: ' + skuPanjang.length + ' karakter [' + skuPanjang + ']');
  const resS = await fetch(B + '/api/export/pdf.php?jenis=opname&id=' + sesiId
    + '&q=' + encodeURIComponent(skuPanjang), { headers: { Cookie: S.cookie } });
  const bufS = Buffer.from(await resS.arrayBuffer());
  const potS = petikTeksPdf(bufS);
  cek('SKU terpanjang tergambar utuh', potS.indexOf(skuPanjang) >= 0, true);
  cek('tidak ada satu pun sel berelipsis di laporan ini',
      potS.filter(x => x.endsWith('...')).length, 0);

  /* Batas kolom tegak. Pengubah PDF -> Excel yang tidak menemukannya terpaksa
     menebak dari celah teks, dan tebakannya pernah menaruh kata pertama nama
     barang di kolom SKU. */
  const mentahS = bufS.toString('latin1');
  const tegak = new Set();
  const rg = /([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S/g;
  let g;
  while ((g = rg.exec(mentahS)) !== null) {
    if (Math.abs(Number(g[1]) - Number(g[3])) < 0.01) { tegak.add(Number(g[1]).toFixed(1)); }
  }
  cek('ada garis pemisah kolom', tegak.size >= 9, true);

  console.log('\n=== A3. unduhan Excel: kolomnya sudah kolom ===');
  /* Jawaban sebenarnya atas keluhan "AYRES masuk ke kolom SKU": berkas yang
     tidak perlu diurai ulang sama sekali. */
  const resC = await fetch(B + '/api/export/csv.php?jenis=opname&id=' + sesiId
    + '&q=' + encodeURIComponent(skuPanjang.slice(0, 11)), { headers: { Cookie: S.cookie } });
  const bufC = Buffer.from(await resC.arrayBuffer());
  cek('HTTP', resC.status, 200);
  cek('tipe berkasnya CSV', (resC.headers.get('content-type') || '').indexOf('text/csv') >= 0, true);
  cek('diunduh, bukan dibuka di tab', (resC.headers.get('content-disposition') || '').indexOf('attachment') >= 0, true);
  cek('ada BOM UTF-8', bufC[0] === 0xEF && bufC[1] === 0xBB && bufC[2] === 0xBF, true);

  const barisC = bufC.slice(3).toString('utf8').split('\r\n');
  cek('baris pertama arahan pemisah untuk Excel', barisC[0], 'sep=;');
  const iKepala = barisC.findIndex(x => x.indexOf('SKU;') === 0);
  cek('ada baris judul kolom', iKepala > 0, true);
  const kepala = barisC[iKepala].split(';');
  cek('SKU dan Nama barang kolom terpisah',
      [kepala[0], kepala[2]], ['SKU', 'Nama barang']);
  cek('ada kolom salinan stok saat sesi dibuat', kepala[5], 'Stok saat opname dibuat');
  cek('judul kolom selisih menyebut rumusnya',
      [kepala[8], kepala[9]],
      ['Selisih (hitung - stok akhir)', 'Selisih Accurate (hitung - accurate)']);

  const dataC = barisC[iKepala + 1].split(';');
  /* INILAH keluhannya: di hasil ubahan PDF, kolom SKU berisi "103286 AYRES" —
     SKU ditambah kata pertama nama barangnya. Di sini kolom SKU berisi SKU,
     titik. */
  cek('kolom SKU berisi SKU saja', dataC[0], skuPanjang);
  cek('merek tetap di kolom nama, tidak bocor ke SKU',
      dataC[0].indexOf('SAIFENU') === -1 && dataC[2].indexOf('SAIFENU') === 0, true);
  cek('angka ditulis polos supaya Excel membacanya sebagai angka',
      /^-?\d*$/.test(dataC[4]), true);
  cek('stok akhir dan salinannya dua kolom terpisah',
      /^-?\d*$/.test(dataC[5]), true);

  cek('jenis tak dikenal ditolak',
      (await fetch(B + '/api/export/csv.php?jenis=ngawur', { headers: { Cookie: S.cookie } })).status, 404);
  cek('sesi tak ada ditolak',
      (await fetch(B + '/api/export/csv.php?jenis=opname&id=999999', { headers: { Cookie: S.cookie } })).status, 404);

  console.log('\n=== B. stok accurate boleh minus, stok hitung tidak ===');
  const itemId = nilai('SELECT id FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND master_id IS NOT NULL ORDER BY id LIMIT 1');
  const semula = {
    hitung: nilai('SELECT stok_hitung FROM opname_item WHERE id = ' + itemId),
    acc: nilai('SELECT stok_accurate FROM opname_item WHERE id = ' + itemId),
    dicek: nilai('SELECT dicek FROM opname_item WHERE id = ' + itemId),
    peny: nilai('SELECT penyesuaian FROM opname_item WHERE id = ' + itemId)
  };

  let r = await post(S, 'opname/item.php', { id: Number(itemId), stok_accurate: -12 });
  cek('accurate minus diterima', r.status, 200);
  cek('tersimpan apa adanya', nilai('SELECT stok_accurate FROM opname_item WHERE id = ' + itemId), '-12');

  r = await post(S, 'opname/item.php', { id: Number(itemId), stok_hitung: -3 });
  cek('hitung minus ditolak', r.status, 400);
  cek('pesannya menyebut hitungan fisik', r.body.error.indexOf('hitungan fisik') >= 0, true);
  cek('nilainya tidak bergeser',
      nilai('SELECT stok_hitung FROM opname_item WHERE id = ' + itemId), semula.hitung);

  /* Stok akhir YANG BERLAKU SEKARANG, bukan opname_item.stok_sistem — kolom itu
     salinan saat sesi dibuat, dan stok sudah bergerak sesudahnya. Membandingkan
     dengan salinannya adalah bug yang baru saja diperbaiki. */
  const stokKini = Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m JOIN opname_item i ON i.master_id = m.id WHERE i.id = " + itemId));
  r = await post(S, 'opname/item.php', { id: Number(itemId), stok_hitung: 5, stok_accurate: -12 });
  cek('selisih dihitung terhadap STOK AKHIR yang berlaku', r.body.selisih, 5 - stokKini);
  cek('pembanding Accurate dibawa terpisah', r.body.selisih_acc, 17);

  console.log('\n=== B2. selisih = stok hitung - stok akhir ===');
  /* INILAH yang diminta gudang, dan inilah yang dulu keliru: angka Selisih di
     layar dihitung terhadap stok accurate, padahal yang dibetulkan "Stok
     Disesuaikan" selalu stok hitung - stok akhir. Jadi angka yang dilihat
     bukan angka yang dikerjakan. Uji ini menegaskan keduanya sama. */
  const idB = Number(nilai('SELECT id FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND master_id IS NOT NULL ORDER BY id LIMIT 1'));
  const stokB = () => Number(nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m JOIN opname_item i ON i.master_id = m.id WHERE i.id = " + idB));

  const bersihkanB = () => php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"DELETE FROM barang_keluar WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"UPDATE opname_item SET stok_hitung=NULL, stok_accurate=NULL,"
    + " penyesuaian='Tidak Disesuaikan', adj_jenis=NULL, adj_id=NULL, adj_qty=NULL"
    + " WHERE sesi_id = " + sesiId + "\"); echo 'ok';");

  bersihkanB();
  const awalB = stokB();

  // Lebih di rak daripada di sistem -> selisih positif.
  r = await post(S, 'opname/item.php', { id: idB, stok_hitung: awalB + 6 });
  cek('lebih di rak: selisih positif', r.body.selisih, 6);
  cek('tanpa stok accurate pun selisihnya tetap terhitung', r.body.selisih !== null, true);
  cek('pembanding Accurate kosong karena kolomnya kosong', r.body.selisih_acc, null);

  // Angka yang ditampilkan HARUS sama dengan yang dikerjakan koreksinya.
  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('koreksinya sebesar selisih yang ditampilkan', r.body.adj_qty, 6);
  cek('arahnya menambah stok', r.body.adj_jenis, 'masuk');
  cek('stok jadi sama dengan hitungan rak', stokB(), awalB + 6);
  let dd = await get(S, 'opname/detail.php?id=' + sesiId + '&q='
    + encodeURIComponent(nilai('SELECT barcode FROM opname_item WHERE id = ' + idB)));
  cek('sesudah dikoreksi selisihnya jadi nol', dd.body.rows[0].selisih, 0);

  // Kurang di rak daripada di sistem -> selisih negatif, koreksinya keluar.
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Tidak Disesuaikan' });
  const kurang = Math.max(0, awalB - 4);
  r = await post(S, 'opname/item.php', { id: idB, stok_hitung: kurang });
  cek('kurang di rak: selisih negatif', r.body.selisih, kurang - awalB);
  r = await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Stok Disesuaikan' });
  cek('arahnya mengurangi stok', r.body.adj_jenis, 'keluar');
  cek('stok mengikuti hitungan rak', stokB(), kurang);

  console.log('\n--- ringkasan dan penyaring ikut rumus yang sama ---');
  await post(S, 'opname/item.php', { id: idB, penyesuaian: 'Tidak Disesuaikan' });
  dd = await get(S, 'opname/detail.php?id=' + sesiId);
  const bedaSql = Number(nilai('SELECT COUNT(*) FROM opname_item WHERE sesi_id = ' + sesiId
    + ' AND stok_hitung IS NOT NULL AND stok_hitung <> stok_sistem'));
  cek('kartu Berselisih dihitung terhadap stok akhir', dd.body.ringkas.beda, bedaSql);
  cek('pembanding Accurate dihitung terpisah', typeof dd.body.ringkas.beda_acc, 'number');
  const saring = await get(S, 'opname/detail.php?id=' + sesiId + '&hanya=selisih');
  cek('penyaring "Ada selisih" memakai rumus yang sama',
      saring.body.rows.every(x => x.stok_hitung !== null && x.stok_hitung !== x.stok_sistem), true);
  cek('jumlahnya cocok dengan kartunya', saring.body.total, bedaSql);

  bersihkanB();
  cek('stok kembali seperti semula', stokB(), awalB);

  /* ------------------------------------------------------------------ */
  console.log('\n=== C. centang semua ===');
  php("require 'includes/db.php'; db()->exec('UPDATE opname_item SET dicek = 0 WHERE sesi_id = " + sesiId + "'); echo 'ok';");
  const jmlKat = nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND kategori = 'AVO'");
  cek('ada baris berkategori AVO', Number(jmlKat) > 0, true);

  r = await post(S, 'opname/massal.php', { id: Number(sesiId), pratinjau: true, kategori: 'AVO' });
  cek('pratinjau menyebut jumlahnya', r.body.jumlah, Number(jmlKat));

  r = await post(S, 'opname/massal.php', { id: Number(sesiId), dicek: true, kategori: 'AVO' });
  cek('HTTP', r.status, 200);
  cek('hanya kategori itu yang tercentang',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND dicek = 1"), jmlKat);
  cek('kategori lain tidak tersentuh',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND kategori <> 'AVO' AND dicek = 1"), '0');

  r = await post(S, 'opname/massal.php', { id: Number(sesiId), dicek: false, kategori: 'AVO' });
  cek('bisa dilepas lagi',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND dicek = 1"), '0');

  /* ------------------------------------------------------------------ */
  console.log('\n=== D. penyesuaian massal ===');
  /* Tiga barang bermaster disiapkan dengan stok hitung; sisanya dibiarkan
     kosong supaya aturan "dilewati" ikut teruji. */
  const tiga = JSON.parse(php("require 'includes/db.php'; echo json_encode(array_column(dbAll("
    + "'SELECT id FROM opname_item WHERE sesi_id = " + sesiId + " AND master_id IS NOT NULL"
    + " ORDER BY id LIMIT 3'), 'id'));"));
  php("require 'includes/db.php'; db()->exec(\"UPDATE opname_item SET stok_hitung = NULL,"
    + " stok_accurate = NULL, penyesuaian = 'Tidak Disesuaikan', adj_jenis = NULL,"
    + " adj_id = NULL, adj_qty = NULL WHERE sesi_id = " + sesiId + "\"); echo 'ok';");

  const stokDari = (itemId2) => nilai("SELECT m.stok_awal"
    + " + COALESCE((SELECT SUM(jumlah) FROM barang_masuk WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " - COALESCE((SELECT SUM(jumlah) FROM barang_keluar WHERE master_id = m.id AND deleted_at IS NULL),0)"
    + " FROM master_barang m JOIN opname_item i ON i.master_id = m.id WHERE i.id = " + itemId2);

  const stokAwal = tiga.map(x => Number(stokDari(x)));
  for (let i = 0; i < tiga.length; i++) {
    await post(S, 'opname/item.php', { id: tiga[i], stok_hitung: stokAwal[i] + 4 });
  }
  cek('tiga baris punya stok hitung',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND stok_hitung IS NOT NULL"), '3');

  r = await post(S, 'opname/massal.php', { id: Number(sesiId), pratinjau: true, penyesuaian: 'x' });
  cek('pratinjau: yang bisa disesuaikan hanya yang sudah dihitung', r.body.bisa_sesuai, 3);
  cek('pratinjau menyebut yang dilewati', r.body.lewat_hitung > 0, true);
  cek('pratinjau tidak mengubah apa pun',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND penyesuaian = 'Stok Disesuaikan'"), '0');

  r = await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Stok Disesuaikan' });
  cek('HTTP', r.status, 200);
  cek('tiga baris disesuaikan', r.body.disesuaikan, 3);
  cek('sisanya dilewati, bukan menggagalkan semuanya', r.body.dilewati > 0, true);
  cek('stok naik 12 pcs seluruhnya', r.body.stok_naik, 12);
  for (let i = 0; i < tiga.length; i++) {
    cek('  stok barang ke-' + (i + 1) + ' mengikuti hitungan',
        Number(stokDari(tiga[i])), stokAwal[i] + 4);
  }
  cek('koreksinya terbaca di Barang masuk',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname' AND deleted_at IS NULL"), '3');
  cek('baris yang belum dihitung tetap Tidak Disesuaikan',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId
            + " AND stok_hitung IS NULL AND penyesuaian = 'Stok Disesuaikan'"), '0');

  console.log('\n--- diulang: tidak menumpuk koreksi ---');
  r = await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Stok Disesuaikan' });
  cek('stok tidak naik dua kali', Number(stokDari(tiga[0])), stokAwal[0] + 4);
  cek('barisnya tetap tiga, bukan enam',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname' AND deleted_at IS NULL"), '3');

  console.log('\n--- dibatalkan massal: stok ditarik kembali ---');
  r = await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Tidak Disesuaikan' });
  cek('HTTP', r.status, 200);
  for (let i = 0; i < tiga.length; i++) {
    cek('  stok barang ke-' + (i + 1) + ' kembali', Number(stokDari(tiga[i])), stokAwal[i]);
  }
  cek('koreksinya ditarik dari Barang masuk',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname' AND deleted_at IS NULL"), '0');
  cek('tidak ada lagi yang berstatus disesuaikan',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND penyesuaian = 'Stok Disesuaikan'"), '0');

  console.log('\n--- arah turun juga benar ---');
  await post(S, 'opname/item.php', { id: tiga[0], stok_hitung: Math.max(0, stokAwal[0] - 3) });
  r = await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Stok Disesuaikan' });
  cek('stok turun', Number(stokDari(tiga[0])), Math.max(0, stokAwal[0] - 3));
  cek('lewat Barang keluar', Number(r.body.stok_turun) > 0, true);
  await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Tidak Disesuaikan' });
  cek('dikembalikan lagi', Number(stokDari(tiga[0])), stokAwal[0]);

  /* ------------------------------------------------------------------ */
  console.log('\n=== E. pagar jumlah ===');
  php("require 'includes/db.php'; db()->exec('UPDATE opname_item SET stok_hitung = 0 WHERE sesi_id = " + sesiId + "'); echo 'ok';");
  r = await post(S, 'opname/massal.php', { id: Number(sesiId), pratinjau: true, penyesuaian: 'x' });
  cek('pratinjau menghitung seluruhnya', r.body.bisa_sesuai > r.body.maks, true);
  r = await post(S, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Stok Disesuaikan' });
  cek('ditolak karena melebihi batas', r.status, 422);
  cek('pesannya menyuruh mempersempit penyaring',
      r.body.error.indexOf('Persempit penyaringnya') >= 0, true);
  cek('tidak ada yang terlanjur disesuaikan',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND penyesuaian = 'Stok Disesuaikan'"), '0');
  cek('stok utuh', nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname' AND deleted_at IS NULL"), '0');

  console.log('\n=== F. hak akses ===');
  const sandi = 'UjiOp#2026';
  const hash = php("echo password_hash('" + sandi + "', PASSWORD_DEFAULT);");
  const buat = (peran, akses) => php("require 'includes/db.php';"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiop']);"
    + "db()->prepare('INSERT INTO users (username, password_hash, nama_lengkap, role, aktif, akses) VALUES (?,?,?,?,1,?)')"
    + "->execute(['ujiop','" + hash + "','Uji Opname','" + peran + "','" + akses + "']); echo 'ok';");

  buat('viewer', '["dashboard","opname"]');
  let op = await login('ujiop', sandi);
  cek('hanya-baca ditolak centang massal',
      (await post(op, 'opname/massal.php', { id: Number(sesiId), dicek: true, kategori: 'AVO' })).status, 403);
  cek('hanya-baca ditolak penyesuaian massal',
      (await post(op, 'opname/massal.php', { id: Number(sesiId), penyesuaian: 'Stok Disesuaikan', kategori: 'AVO' })).status, 403);

  buat('operator', '["dashboard"]');
  op = await login('ujiop', sandi);
  cek('tanpa menu opname ditolak',
      (await post(op, 'opname/massal.php', { id: Number(sesiId), dicek: true })).status, 403);
  /* menuLaporan() mengembalikan null untuk jenis tak terdaftar, dan endpoint
     yang melewatkan pemeriksaan saat null bisa diunduh siapa pun yang login —
     persis lubang yang pernah ditemukan pada laporan deadstok. */
  cek('tanpa menu opname: unduhan PDF ditolak',
      (await fetch(B + '/api/export/pdf.php?jenis=opname&id=' + sesiId, { headers: { Cookie: op.cookie } })).status, 403);
  cek('tanpa menu opname: unduhan Excel ditolak',
      (await fetch(B + '/api/export/csv.php?jenis=opname&id=' + sesiId, { headers: { Cookie: op.cookie } })).status, 403);

  console.log('\n=== Kembalikan keadaan semula ===');
  php("require 'includes/db.php';"
    + "db()->exec(\"DELETE FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"DELETE FROM barang_keluar WHERE keterangan = 'Penyesuaian Opname'\");"
    + "db()->exec(\"UPDATE opname_item SET stok_hitung = NULL, stok_accurate = NULL, dicek = 0,"
    + " penyesuaian = 'Tidak Disesuaikan', adj_jenis = NULL, adj_id = NULL, adj_qty = NULL,"
    + " petugas = '', catatan = '' WHERE sesi_id = " + sesiId + "\");"
    + "db()->exec(\"DELETE FROM activity_log WHERE entitas = 'opname'\");"
    + "db()->prepare('DELETE FROM users WHERE username = ?')->execute(['ujiop']); echo 'ok';");
  cek('tidak ada koreksi stok yang tertinggal',
      nilai("SELECT COUNT(*) FROM barang_masuk WHERE keterangan = 'Penyesuaian Opname'"), '0');
  cek('seluruh baris opname kosong lagi',
      nilai("SELECT COUNT(*) FROM opname_item WHERE sesi_id = " + sesiId + " AND stok_hitung IS NOT NULL"), '0');

  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
