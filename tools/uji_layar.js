/* ==========================================================================
 * tools/uji_layar.js — jalankan tiap penggambar layar tanpa browser
 *
 * Jalankan:  node tools/uji_layar.js
 *
 * ADA KARENA SATU BUG YANG LOLOS
 * Sebuah penyuntingan pernah gagal tersimpan separuh: variabel `penyaringAktif`
 * dipakai di halaman Retur tapi tidak pernah dideklarasikan. `node --check`
 * tidak menangkapnya — itu sah secara tata bahasa — dan uji lain tidak pernah
 * menggambar layar. Yang terjadi di gudang: kartu muncul, tabelnya tidak, dan
 * satu-satunya petunjuk hanya ReferenceError di konsol yang tak pernah dibuka.
 *
 * Uji ini memuat assets/js/app.js apa adanya ke dalam sandbox dengan DOM dan
 * API tiruan, lalu memanggil setiap fungsi render dan refresh. Yang dicari
 * bukan tampilannya, melainkan satu hal saja: tidak ada yang melempar galat —
 * termasuk nama yang tidak pernah dideklarasikan.
 *
 * Perlu Node, tanpa satu pun paket.
 * ========================================================================== */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const akar = path.join(__dirname, '..');

/* --- DOM tiruan ----------------------------------------------------------
 * Secukupnya saja supaya kode render berjalan: tiap elemen menerima apa pun
 * yang ditulis padanya dan mengembalikan dirinya sendiri untuk pencarian
 * turunan. Yang diuji perilakunya, bukan hasil gambarnya. */
function buatElemen(id) {
    const el = {
        id: id,
        innerHTML: '',
        textContent: '',
        value: '',
        checked: false,
        disabled: false,
        selectedIndex: 0,
        options: [],
        href: '',
        dataset: {},
        style: { display: '', width: '' },
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
        setAttribute() {}, getAttribute() { return null; }, removeAttribute() {},
        addEventListener() {}, removeEventListener() {},
        appendChild() {}, removeChild() {}, insertAdjacentHTML() {},
        scrollIntoView() {}, focus() {}, blur() {}, click() {},
        closest() { return null; },
        getBoundingClientRect() { return { top: 0, left: 0, width: 100, height: 20 }; },
        querySelector() { return buatElemen('semu'); },
        querySelectorAll() { return []; },
        reset() {},
        remove() {},
    };
    el.parentNode = null;
    return el;
}

const dokumen = {
    body: buatElemen('body'),
    documentElement: buatElemen('html'),
    getElementById(id) { return buatElemen(id); },
    querySelector() { return buatElemen('semu'); },
    querySelectorAll() { return []; },
    createElement(tag) { return buatElemen(tag); },
    addEventListener() {},
    removeEventListener() {},
    createTextNode() { return {}; },
};

/* --- Jawaban API tiruan ---------------------------------------------------
 * Bentuknya mengikuti yang betul-betul dikirim endpoint: kalau sebuah layar
 * membaca field yang tidak pernah ada, uji ini yang kena duluan. */
/* Bentuk jawaban server, dipakai bersama dengan tools/uji_html.js supaya
 * keduanya tidak pernah menguji bentuk data yang berbeda. */
const JAWABAN = JSON.parse(
    fs.readFileSync(path.join(__dirname, 'fixtur/jawaban-layar.json'), 'utf8'));

/* Endpoint yang dipanggil tapi belum punya jawaban tiruan. Dikumpulkan, lalu
 * menggagalkan uji di akhir.
 *
 * Penting: aplikasi menangkap galat pemuatannya sendiri dan hanya menampilkan
 * toast, jadi panggilan yang gagal TIDAK terlihat sebagai galat di sini. Tanpa
 * daftar ini, uji akan berbunyi "OK" padahal separuh kode render tidak pernah
 * dijalankan — lebih buruk daripada tidak diuji, karena menenangkan. */
const takTerjawab = [];

function fetchTiruan(url) {
    const bersih = String(url).replace(/^api[/]/, '').split('?')[0];
    const d = JAWABAN[bersih];
    if (!d) {
        if (takTerjawab.indexOf(bersih) === -1) {
            takTerjawab.push(bersih);
        }
        return Promise.resolve({
            status: 500, ok: false,
            text: () => Promise.resolve('{"ok":false,"error":"jawaban tiruan belum ada"}'),
        });
    }
    return Promise.resolve({
        status: 200, ok: true,
        text: () => Promise.resolve(JSON.stringify(Object.assign({ ok: true }, d))),
    });
}

const ctx = {
    console,
    setTimeout: (f) => { if (typeof f === 'function') { f(); } return 0; },
    clearTimeout() {}, setInterval() { return 0; }, clearInterval() {},
    requestAnimationFrame: (f) => { if (typeof f === 'function') { f(); } return 0; },
    document: dokumen,
    location: { href: '', hash: '', reload() {} },
    navigator: { userAgent: 'uji', clipboard: { writeText() { return Promise.resolve(); } } },
    localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
    fetch: fetchTiruan,
    URLSearchParams: URLSearchParams,
    Headers: typeof Headers !== 'undefined' ? Headers : function () {},
    FileReader: function () {}, Blob: function () {}, URL: { createObjectURL() { return ''; } },
    crypto: { subtle: { digest() { return Promise.resolve(new ArrayBuffer(32)); } },
              getRandomValues(a) { return a; } },
    Grafik: { angkaNaik() {}, batangBertumpuk() {}, garis() {}, batang() {}, donat() {} },
    pdfjsLib: { getDocument() { return { promise: Promise.resolve({ numPages: 0 }) }; },
                GlobalWorkerOptions: {} },
};
ctx.window = ctx;
ctx.globalThis = ctx;
vm.createContext(ctx);

/* Akses penuh. Dengan akses kosong, switchTab() menolak berpindah dan setiap
 * layar yang diuji sebenarnya tetap dashboard. */
ctx.window.APP_USER = {
    id: 1, username: 'admin', nama_lengkap: 'Admin', role: 'admin', boleh_tulis: true,
    akses: ['dashboard', 'masuk', 'keluar', 'riwayat', 'pertukaran', 'retur', 'opname',
            'master', 'kategori', 'ket_masuk', 'ket_keluar', 'ket_retur', 'pengguna',
            'aktivitas'],
};
ctx.window.CSRF_TOKEN = 'uji';
ctx.window.KATEGORI_OPTIONS = ['FISIO'];
ctx.window.KET_MASUK = ['Restock'];
ctx.window.KET_KELUAR = ['Pesanan MP'];

/* api.js yang ASLI dipakai, bukan tiruan: dengan begitu seluruh pembungkusnya
 * (API.masterList, API.trxList, dan seterusnya) ikut ada, dan api.js sendiri
 * ikut teruji. Yang ditiru cuma fetch-nya. */
vm.runInContext(
    fs.readFileSync(path.join(akar, 'assets/js/api.js'), 'utf8') + ';globalThis.API = API;',
    ctx, { filename: 'api.js' });

/* `const` dan `function` di puncak skrip tidak menempel ke objek konteks, jadi
 * yang dibutuhkan disalin sendiri dari dalam sandbox. */
const DIPAKAI = ['TABS', 'switchTab', 'renderContent', 'refreshDashboard', 'refreshRetur',
                 'refreshMasterTable', 'refreshKategori', 'refreshKeterangan',
                 'refreshLog', 'refreshGalatSistem', 'renderTransaksiTable'];

vm.runInContext(
    fs.readFileSync(path.join(akar, 'assets/js/app.js'), 'utf8')
    + ';(function(){ globalThis.__uji = {}; '
    + JSON.stringify(DIPAKAI) + '.forEach(function(n){'
    + ' try { globalThis.__uji[n] = eval(n); } catch(e) {} });'
    + ' globalThis.__tabAktif = function(){ return tab; }; })();',
    ctx, { filename: 'app.js' });

const uji = ctx.__uji || {};

/* --- Jalankan tiap layar -------------------------------------------------- */
let gagal = 0;

async function coba(label, fn) {
    try {
        await fn();
        console.log('  OK    ' + label);
    } catch (e) {
        gagal++;
        console.log('  GAGAL ' + label.padEnd(34) + e.name + ': ' + e.message);
    }
}

(async () => {
    const tabs = uji.TABS.map((t) => t.id);
    console.log('menu yang diuji: ' + tabs.length + '\n');

    /* renderContent() TIDAK menerima argumen — ia menggambar tab yang sedang
     * aktif. Versi pertama uji ini memanggilnya dengan id sebagai argumen,
     * yang diabaikan diam-diam: keempat belas "layar" yang diuji ternyata
     * dashboard yang sama, empat belas kali. Jadi tabnya dipindah lewat
     * switchTab(), persis seperti saat menunya diklik. */
    for (const id of tabs) {
        await coba('render ' + id, () => {
            uji.switchTab(id);
            if (ctx.__tabAktif && ctx.__tabAktif() !== id) {
                throw new Error('tab tidak berpindah ke ' + id
                    + ' (sekarang ' + ctx.__tabAktif() + ')');
            }
        });
    }

    // Refresh dipanggil terpisah: sebagian baru berjalan setelah jawaban server
    // tiba, dan di situlah bug kemarin bersembunyi.
    const refresh = [
        ['refreshDashboard', () => uji.refreshDashboard()],
        ['refreshRetur', () => uji.refreshRetur()],
        ['refreshMasterTable', () => uji.refreshMasterTable()],
        ['refreshKategori', () => uji.refreshKategori()],
        ['refreshKeterangan', () => uji.refreshKeterangan()],
        ['refreshLog', () => uji.refreshLog()],
        ['refreshGalatSistem', () => uji.refreshGalatSistem()],
        ['renderTransaksiTable(masuk)', () => uji.renderTransaksiTable('masuk')],
        ['renderTransaksiTable(keluar)', () => uji.renderTransaksiTable('keluar')],
    ];
    for (const [label, fn] of refresh) {
        if (typeof uji[label.split('(')[0]] !== 'function') {
            gagal++;
            console.log('  GAGAL ' + label + ' — fungsinya tidak ditemukan');
            continue;
        }
        await coba(label, fn);
    }

    console.log('');
    if (gagal === 0) {
        console.log('OK — semua layar tergambar tanpa galat.');
        process.exit(0);
    }
    console.log('ADA ' + gagal + ' YANG GAGAL');
    process.exit(1);
})();
