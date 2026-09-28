/* ==========================================================================
 * tools/uji_pdf_parser.js — uji pembaca PDF Picking List
 *
 * Jalankan:  node tools/uji_pdf_parser.js
 *
 * Empat berkas picking list nyata pernah gagal diimpor dengan empat sebab
 * berbeda, dan tiga di antaranya gagal dalam diam — angkanya salah, bukan
 * berhenti dengan pesan. Berkas ujinya ada di tools/fixtur/picking-list.json:
 * bukan PDF-nya, melainkan GEOMETRI potongan teksnya (x, y, isi), karena
 * itulah satu-satunya masukan parser. Nomor pesanannya sudah disamarkan —
 * repositori ini publik.
 *
 * Karena fixtur menyimpan geometri, uji ini tidak butuh pdf.js sama sekali:
 * ia memanggil susunBarisDariItem() dan extractPickingListRows() apa adanya
 * dari assets/js/pdf-parser.js.
 *
 * Patokan utamanya angka yang DICETAK di kepala berkas: jumlah unit seluruh
 * baris harus sama dengan "Jumlah produk", dan nomor pesanan yang unik harus
 * sama dengan "Jumlah Pesanan". Dua angka itu ditulis oleh Desty, bukan oleh
 * kita, jadi keduanya pembanding yang tidak bisa disetel agar cocok.
 * ========================================================================== */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const akar = path.join(__dirname, '..');
globalThis.window = globalThis;
vm.runInThisContext(
    fs.readFileSync(path.join(akar, 'assets/js/pdf-parser.js'), 'utf8'),
    { filename: 'pdf-parser.js' }
);

const fixtur = JSON.parse(
    fs.readFileSync(path.join(__dirname, 'fixtur/picking-list.json'), 'utf8')
);

/* Harapan per berkas. Baris yang disebut memakai nomor urut seperti di PDF. */
const harapan = {
    'PICK-014559.pdf': {
        baris: 31,
        // Tiga barang ini tidak mencantumkan digit barcode di PDF; hanya SKU.
        // Dulu ketiganya membuat SELURUH berkas gagal disimpan.
        tanpaBarcode: [{ urut: 14, sku: '103544' }, { urut: 15, sku: '103564' }, { urut: 16, sku: '102398' }]
    },
    'PICK-014337.pdf': { baris: 36, tanpaBarcode: [] },
    // Satu barang saja: nomor urutnya cuma satu, dan dulu kolom "No" tidak
    // dipercaya sebelum ada dua nomor. Akibatnya SKU dan Qty barang itu
    // terbuang, dan hasilnya satu baris berqty 0 yang tidak bisa disimpan.
    'PICK-012828.pdf': {
        baris: 1,
        tanpaBarcode: [],
        spot: [{ urut: 1, barcode: '8119187294859', sku: 'AV-0020', qty: 2 }]
    },
    // Dua barang daftar pesanannya melewati batas halaman, jadi Desty
    // mencetak ulang bloknya di puncak halaman berikutnya. Dulu keduanya
    // terhitung dua kali: 30 baris, 160 unit, padahal kepalanya menyebut 131.
    'PICK-012812.pdf': { baris: 28, tanpaBarcode: [{ urut: 18, sku: '102362' }] }
};

let gagal = 0;

function cek(label, dapat, harap) {
    const lolos = JSON.stringify(dapat) === JSON.stringify(harap);
    if (lolos) {
        console.log('  OK    ' + label.padEnd(44) + JSON.stringify(dapat));
    } else {
        console.log('  GAGAL ' + label.padEnd(44) + 'dapat ' + JSON.stringify(dapat)
            + ', harap ' + JSON.stringify(harap));
        gagal++;
    }
}

function baca(berkas) {
    let lines = [];
    fixtur[berkas].halaman.forEach(function (potongan) {
        lines = lines.concat(susunBarisDariItem(
            potongan.map(function (a) { return { x: a[0], y: a[1], text: a[2] }; })
        ));
    });
    return extractPickingListRows(lines);
}

Object.keys(harapan).forEach(function (berkas) {
    const h = harapan[berkas];
    console.log('\n=== ' + berkas + ' — ' + fixtur[berkas].catatan + ' ===');

    const hasil = baca(berkas);
    const rows  = hasil.rows;

    cek('jumlah baris', rows.length, h.baris);

    /* Jumlah unit harus sama dengan yang dicetak di kepala berkas. */
    const unit = rows.reduce(function (a, r) { return a + (parseInt(r.qty, 10) || 0); }, 0);
    cek('unit = "Jumlah produk" di PDF', unit, parseInt(hasil.header.jumlahProduk, 10));

    /* Nomor pesanan unik harus sama dengan "Jumlah Pesanan". Ini yang
       membuktikan penggabungan blok ulangan tidak membuang nomor pesanan. */
    const pesanan = new Set();
    let gandaDalamBaris = 0;
    rows.forEach(function (r) {
        const dalamBaris = new Set();
        (String(r.noPesanan || '').match(/[A-Z0-9][A-Z0-9-]{8,}/gi) || []).forEach(function (n) {
            if (dalamBaris.has(n)) { gandaDalamBaris++; }
            dalamBaris.add(n);
            pesanan.add(n);
        });
    });
    cek('pesanan unik = "Jumlah Pesanan" di PDF', pesanan.size, parseInt(hasil.header.jumlahPesanan, 10));
    cek('tidak ada nomor pesanan ganda', gandaDalamBaris, 0);

    /* Tidak boleh ada baris berqty 0: baris seperti itu tidak bisa disimpan
       dan memaksa petugas menambal satu per satu. */
    cek('baris berqty 0', rows.filter(function (r) { return !(parseInt(r.qty, 10) > 0); }).length, 0);

    /* Perabot halaman tidak boleh bocor ke kolom nomor pesanan. */
    cek('nomor pesanan bersih dari teks halaman',
        rows.filter(function (r) { return /iambil|atatan|alaman/i.test(String(r.noPesanan || '')); }).length, 0);

    /* Barang tanpa digit barcode di PDF: tetap terbaca, dan SKU-nya utuh —
       SKU itulah satu-satunya yang mengenali barangnya. */
    const kosong = [];
    rows.forEach(function (r, i) { if (!r.barcode) { kosong.push({ urut: i + 1, sku: r.sku }); } });
    cek('baris tanpa barcode (nomor urut + SKU)', kosong, h.tanpaBarcode);

    (h.spot || []).forEach(function (s) {
        const r = rows[s.urut - 1] || {};
        cek('baris ' + s.urut + ' lengkap',
            { barcode: r.barcode, sku: r.sku, qty: r.qty },
            { barcode: s.barcode, sku: s.sku, qty: s.qty });
    });
});

console.log('');
if (gagal === 0) {
    console.log('OK — semua picking list terbaca sesuai angka di kepala berkasnya.');
    process.exit(0);
}
console.log('ADA ' + gagal + ' YANG GAGAL');
process.exit(1);
