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
  akses: ['dashboard','masuk','keluar','riwayat','pertukaran','retur','opname',
          'master','kategori','ket_masuk','ket_keluar','ket_retur','pengguna','aktivitas'],
};
ctx.window.CSRF_TOKEN = 'uji';
ctx.window.KATEGORI_OPTIONS = ['FISIO'];
ctx.window.KET_MASUK = ['Restock', 'Barang Baru'];
ctx.window.KET_KELUAR = ['Pesanan MP'];

vm.runInContext(fs.readFileSync(path.join(akar, 'assets/js/api.js'), 'utf8') + ';globalThis.API = API;', ctx);
const DIPAKAI = ['switchTab', 'renderContent', 'renderTransaksiTable', 'refreshRetur', 'refreshKeterangan', 'ketJenis'];
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

  console.log('\n=== Master: Pengaruh ke stok pada keterangan retur ===');
  uji.switchTab('ket_retur');
  const htmlKet = semua();
  cek('ada pilihan Pengaruh ke stok', /id="ketTambahStok"/.test(htmlKet));
  cek('menyebut "Mengembalikan barang ke stok"', /Mengembalikan barang ke stok/.test(htmlKet));
  await uji.refreshKeterangan();
  cek('lencana "Menambah stok" muncul', /Menambah stok/.test(isi('ketHasil')));

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
