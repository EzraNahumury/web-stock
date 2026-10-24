/* ==========================================================================
 * tools/uji_html.js — periksa elemen yang benar-benar digambar tiap layar
 *
 * Jalankan:  node tools/uji_html.js
 *
 * BEDANYA DENGAN uji_layar.js
 * uji_layar memastikan tidak ada yang MELEMPAR GALAT. Itu tidak cukup: sebuah
 * penyaring bisa saja hilang sama sekali tanpa galat apa pun, dan layarnya
 * tetap tergambar rapi. Uji ini memeriksa isinya — dropdown penyaringnya ada,
 * kolomnya ada, kalimatnya sudah yang benar.
 *
 * Keduanya pernah gagal menangkap hal yang sama: versi pertama memanggil
 * renderContent(id), padahal fungsi itu tidak menerima argumen dan menggambar
 * tab yang sedang aktif. Keempat belas "layar" yang diuji ternyata dashboard
 * yang sama, empat belas kali. Karena itu di sini tabnya dipindah lewat
 * switchTab(), dan akun tiruannya diberi akses penuh — tanpa itu yang
 * tergambar hanya pesan "tidak punya akses".
 * ========================================================================== */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const akar = require('path').join(__dirname, '..');

/* DOM tiruan yang MENGINGAT elemennya, supaya innerHTML bisa dibaca lagi. */
const daftar = new Map();
function elemen(id) {
  if (daftar.has(id)) { return daftar.get(id); }
  const el = {
    id, innerHTML: '', textContent: '', value: '', checked: false, disabled: false,
    selectedIndex: 0, options: [], href: '', dataset: {},
    style: { display: '', width: '' },
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    setAttribute() {}, getAttribute() { return null; }, removeAttribute() {},
    addEventListener() {}, removeEventListener() {}, appendChild() {}, removeChild() {},
    insertAdjacentHTML() {}, scrollIntoView() {}, focus() {}, blur() {}, click() {},
    closest() { return null; }, reset() {}, remove() {},
    getBoundingClientRect() { return { top: 0, left: 0, width: 100, height: 20 }; },
    querySelector() { return elemen('semu'); }, querySelectorAll() { return []; },
  };
  daftar.set(id, el);
  return el;
}

const JAWABAN = JSON.parse(fs.readFileSync(path.join(akar, 'tools/fixtur/jawaban-layar.json'), 'utf8'));
const takTerjawab = [];
function fetchTiruan(url) {
  const bersih = String(url).replace(/^api\//, '').split('?')[0];
  const d = JAWABAN[bersih];
  if (!d) {
    if (takTerjawab.indexOf(bersih) === -1) { takTerjawab.push(bersih); }
    return Promise.resolve({ status: 500, ok: false, text: () => Promise.resolve('{"ok":false}') });
  }
  return Promise.resolve({
    status: 200, ok: true,
    text: () => Promise.resolve(JSON.stringify(Object.assign({ ok: true }, d))),
  });
}

const ctx = {
  console, setTimeout: (f) => { if (typeof f === 'function') f(); return 0; },
  clearTimeout() {}, setInterval() { return 0; }, clearInterval() {},
  requestAnimationFrame: (f) => { if (typeof f === 'function') f(); return 0; },
  document: {
    body: elemen('body'), documentElement: elemen('html'),
    getElementById: elemen, querySelector() { return elemen('semu'); },
    querySelectorAll() { return []; }, createElement: elemen,
    addEventListener() {}, removeEventListener() {}, createTextNode() { return {}; },
  },
  location: { href: '', hash: '', reload() {} },
  navigator: { userAgent: 'uji', clipboard: { writeText: () => Promise.resolve() } },
  localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
  fetch: fetchTiruan, URLSearchParams,
  FileReader: function () {}, Blob: function () {}, URL: { createObjectURL: () => '' },
  crypto: { subtle: { digest: () => Promise.resolve(new ArrayBuffer(32)) }, getRandomValues: (a) => a },
  Grafik: { angkaNaik() {}, batangBertumpuk() {}, garis() {}, batang() {}, donat() {} },
  pdfjsLib: { getDocument: () => ({ promise: Promise.resolve({ numPages: 0 }) }), GlobalWorkerOptions: {} },
};
ctx.window = ctx; ctx.globalThis = ctx;
vm.createContext(ctx);
/* Akses penuh. Dengan akses kosong, layar justru menggambar pesan "tidak punya
 * akses" — dan ujinya akan lulus tanpa pernah melihat layar yang sebenarnya. */
ctx.window.APP_USER = {
  id: 1, username: 'admin', nama_lengkap: 'Admin', role: 'admin', boleh_tulis: true,
  akses: ['dashboard','masuk','keluar','riwayat','pertukaran','retur','deadstok','opname',
          'master','kategori','minimal','ket_masuk','ket_keluar','ket_retur','pengguna','aktivitas'],
};
ctx.window.CSRF_TOKEN = 'uji';
ctx.window.KATEGORI_OPTIONS = ['FISIO'];
ctx.window.KET_MASUK = ['Restock', 'Barang Baru'];
ctx.window.KET_KELUAR = ['Pesanan MP'];

vm.runInContext(fs.readFileSync(path.join(akar, 'assets/js/api.js'), 'utf8') + ';globalThis.API = API;', ctx);
const DIPAKAI = ['switchTab', 'renderContent', 'renderTransaksiTable', 'refreshRetur',
                 'refreshKeterangan', 'refreshMinimal', 'refreshDeadstok', 'ketJenis',
                 'tambahBarisRetur', 'hapusBarisRetur'];
vm.runInContext(
  fs.readFileSync(path.join(akar, 'assets/js/app.js'), 'utf8')
  + ';(function(){ globalThis.__uji = {}; ' + JSON.stringify(DIPAKAI)
  + '.forEach(function(n){ try { globalThis.__uji[n] = eval(n); } catch(e) {} }); })();', ctx);
const uji = ctx.__uji;

let gagal = 0;
function cek(label, benar) {
  console.log((benar ? '  OK    ' : '  GAGAL ') + label);
  if (!benar) { gagal++; }
}
const isi = (id) => String(elemen(id).innerHTML || '');
/* Layar menulis ke wadah yang berbeda-beda (content, masterIsi, rtHasil, ...),
 * jadi yang dicari seluruh isi DOM tiruan — bukan satu wadah yang ditebak. */
const semua = () => [...daftar.values()].map((e) => String(e.innerHTML || '')).join(' ');

(async () => {
  console.log('\n=== Barang masuk: penyaring keterangan ===');
  uji.switchTab('masuk');
  const htmlMasuk = semua();
  cek('ada dropdown penyaring keterangan', /id="masukFKet"/.test(htmlMasuk));
  cek('ada pilihan "Semua keterangan"', /Semua keterangan/.test(htmlMasuk));
  cek('ada kotak pencarian', /id="masukCari"/.test(htmlMasuk));
  await uji.renderTransaksiTable('masuk');
  cek('dropdown terisi dari server', /Barang Baru/.test(isi('masukFKet')));

  console.log('\n=== Barang keluar: penyaring keterangan ===');
  uji.switchTab('keluar');
  cek('ada dropdown penyaring keterangan', /id="keluarFKet"/.test(semua()));

  console.log('\n=== Retur: kolom Accurate ===');
  uji.switchTab('retur');
  await uji.refreshRetur();
  const htmlRetur = semua();
  cek('ada kolom Accurate', /Accurate/.test(htmlRetur));

  console.log('=== Retur: penanda Accurate tiga keadaan ===');
  const htmlTabel = isi('rtHasil');
  cek('penandanya bisa dipilih, bukan tombol berputar',
      htmlTabel.indexOf('class="sel-tanda') >= 0);
  cek('tiga pilihan: Belum, Sudah, Tidak masuk',
      htmlTabel.indexOf('value="belum"') >= 0
      && htmlTabel.indexOf('value="sudah"') >= 0
      && htmlTabel.indexOf('value="tidak"') >= 0);
  cek('keadaan tiap baris ikut terpilih',
      htmlTabel.indexOf('value="belum" selected') >= 0
      && htmlTabel.indexOf('value="tidak" selected') >= 0);
  cek('"Tidak masuk" punya warna sendiri', htmlTabel.indexOf('sel-tanda abai') >= 0);
  cek('ada penyaring Accurate', /id="rtFAccurate"/.test(htmlRetur));
  cek('penyaringnya memuat ketiganya dan "Semua"',
      /Semua Accurate/.test(htmlRetur) && /Tidak masuk/.test(htmlRetur));
  /* Kartunya tidak boleh menggabungkan "tidak masuk" ke dalam "belum": itu
   * persis masalah yang membuat keadaan ketiga ini dibuat. */
  cek('kartu menyebut yang tidak masuk secara terpisah',
      isi('rtRingkas').indexOf('ditandai tidak masuk') >= 0);

  console.log('=== Retur: satu pesanan, banyak produk ===');
  /* Baris produk pertama digambar renderRetur; keterangan returnya baru terisi
   * sesudah refreshRetur di atas, jadi urutan di sini memang begitu. */
  const hitung = (h, p) => h.split(p).length - 1;
  const satu = isi('rtBarisWadah');
  cek('ada tombol Tambah produk', /id="rtTambah"/.test(htmlRetur) && /Tambah produk/.test(htmlRetur));
  cek('mulai dengan satu baris produk', hitung(satu, 'class="produk-baris"') === 1);
  cek('satu produk tanpa nomor dan tombol hapus', satu.indexOf('produk-kepala') === -1);
  cek('keterangan retur baris pertama terisi', /Sistem Belum Selesai/.test(isi('rtStatus_1')));

  uji.tambahBarisRetur();
  const dua = isi('rtBarisWadah');
  cek('jadi dua baris produk', hitung(dua, 'class="produk-baris"') === 2);
  cek('tiap baris punya kolom SKU sendiri',
      /id="rtSku_1"/.test(dua) && /id="rtSku_2"/.test(dua));
  cek('tiap baris punya keterangan retur sendiri',
      /id="rtStatus_1"/.test(dua) && /id="rtStatus_2"/.test(dua));
  cek('baris bernomor', /Produk 1/.test(dua) && /Produk 2/.test(dua));
  cek('tiap baris bisa dihapus', hitung(dua, 'hapusBarisRetur(') === 2);
  /* oninput + onfocus pada tiap baris */
  cek('pencarian barang per baris', hitung(dua, 'onReturPicker(') === 4);

  uji.hapusBarisRetur(2);
  cek('kembali satu baris sesudah dihapus',
      hitung(isi('rtBarisWadah'), 'class="produk-baris"') === 1);
  uji.hapusBarisRetur(1);
  cek('produk terakhir tidak bisa dihapus',
      hitung(isi('rtBarisWadah'), 'class="produk-baris"') === 1);

  console.log('=== Retur: "stok bertambah" dibaca dari BUKTI, bukan dari keterangannya ===');
  /* Layar dulu menyimpulkannya dari nama keterangannya. Keterangan menyatakan
   * apa yang SEHARUSNYA terjadi, bukan apa yang sudah terjadi — dan ratusan
   * retur digambar "stok bertambah" padahal barang masuknya tidak pernah ada. */
  const tabelRetur = isi('rtHasil');
  cek('baris yang barang masuknya ada: "stok bertambah"',
      tabelRetur.indexOf('stok bertambah') >= 0);
  cek('baris yang belum tersusul: "belum masuk stok"',
      tabelRetur.indexOf('belum masuk stok') >= 0);
  cek('yang belum tersusul tidak digambar hijau seolah beres',
      tabelRetur.indexOf('badge rendah') >= 0);
  cek('ada kartu "Belum tersusul"', isi('rtRingkas').indexOf('Belum tersusul') >= 0);
  /* Kotak peringatannya yang membuat masalahnya bisa diperbaiki sendiri;
   * tanpa itu angkanya hanya berubah tanpa memberi tahu apa yang harus dilakukan. */
  const kotak = isi('rtTautPutus');
  cek('kotak peringatan menyebut jumlah barisnya',
      kotak.indexOf('belum benar-benar masuk stok') >= 0);
  cek('kotak peringatan menerangkan sebabnya',
      kotak.indexOf('dicatat sebelum keterangan itu diubah') >= 0);
  cek('kotak peringatan membawa tombol perbaikannya',
      kotak.indexOf('masukkanSemuaReturKeStok()') >= 0);

  console.log('=== Retur: yang menambah stok dibaca dari daftar, bukan satu nama ===');
  /* Sejak migrasi 022 SELURUH keterangan retur mengembalikan barang ke stok.
   * Layar yang membandingkannya dengan satu nama menggambar baris yang
   * sebenarnya menambah stok seolah-olah belum. */
  const htmlR2 = semua();
  cek('info menyebut seluruh keterangan yang menambah stok',
      htmlR2.indexOf('Sistem Belum Selesai</b>') >= 0);
  cek('kaki kartu tidak memaku satu keterangan',
      htmlR2.indexOf('berketerangan Lengkap') === -1);

  console.log('\n=== Master: Pengaruh ke stok pada keterangan retur ===');
  uji.switchTab('ket_retur');
  const htmlKet = semua();
  cek('ada pilihan Pengaruh ke stok', /id="ketTambahStok"/.test(htmlKet));
  cek('menyebut "Mengembalikan barang ke stok"', /Mengembalikan barang ke stok/.test(htmlKet));
  await uji.refreshKeterangan();
  cek('lencana "Menambah stok" muncul', /Menambah stok/.test(isi('ketHasil')));

  console.log('\n=== Barang deadstok ===');
  uji.switchTab('deadstok');
  await uji.refreshDeadstok();
  const htmlDs = semua();
  cek('ada dua kategori yang diminta gudang',
      /Barang Rusak/.test(htmlDs) && /Barang Belum Siap Jual/.test(htmlDs));
  cek('ada kolom Siap jual di samping Stok',
      htmlDs.indexOf('Siap jual') >= 0 && htmlDs.indexOf('>Stok<') >= 0);
  cek('keadaannya bisa dipilih tiga-tiganya',
      htmlDs.indexOf('value="tertahan"') >= 0
      && htmlDs.indexOf('value="beres"') >= 0
      && htmlDs.indexOf('value="dibuang"') >= 0);
  cek('keadaan tiap baris ikut terpilih',
      htmlDs.indexOf('value="tertahan" selected') >= 0
      && htmlDs.indexOf('value="dibuang" selected') >= 0);
  cek('ada penyaring kategori dan keadaan',
      /id="dsFKategori"/.test(htmlDs) && /id="dsFStatus"/.test(htmlDs));
  /* Inti seluruh menu ini: mencatat deadstok TIDAK mengurangi stok, dan hanya
   * "Dibuang" yang mengurangi. Kalau kalimat itu hilang dari layar, orang akan
   * mengira stoknya sudah turun padahal belum. */
  cek('layar menyatakan stok tidak berkurang',
      htmlDs.indexOf('tidak mengurangi stok') >= 0);
  cek('layar menyebut Dibuang sebagai yang menurunkan stok',
      /Dibuang<\/b>/.test(htmlDs));
  cek('kartu memisahkan kedua kategori',
      isi('dsRingkas').indexOf('Barang Rusak') >= 0
      && isi('dsRingkas').indexOf('Belum Siap Jual') >= 0);

  console.log('\n=== Master: Stok minimal ===');
  uji.switchTab('minimal');
  await uji.refreshMinimal();
  const htmlMin = semua();
  cek('ada kolom Stok minimal', /Stok minimal/.test(htmlMin));
  cek('ada kolom bantu keluar per bulan', htmlMin.indexOf('Keluar/bln') >= 0);
  cek('ambangnya bisa diketik', htmlMin.indexOf('onchange="simpanMinimal(') >= 0);
  cek('ada penyaring ambang masih 0', /Ambangnya masih 0/.test(htmlMin));
  cek('ada tombol setel massal', htmlMin.indexOf('setelMinimalMassal()') >= 0);

  console.log('\n=== Stok opname: keterangan penyesuaian sudah betul ===');
  uji.switchTab('opname');
  const semuaHtml = semua();
  cek('tidak lagi berbunyi "tidak mengubah stok sendiri"', !/tidak .{0,12}mengubah stok sendiri/.test(semuaHtml));

  if (takTerjawab.length) {
    takTerjawab.forEach((e) => { gagal++; console.log('  GAGAL endpoint tanpa jawaban tiruan: ' + e); });
  }
  console.log(gagal ? '\nADA ' + gagal + ' YANG GAGAL' : '\nSEMUA LOLOS');
  process.exit(gagal ? 1 : 0);
})();
