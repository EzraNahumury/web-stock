/**
 * uji_parser_desty.mjs — uji parser memakai koordinat NYATA dari picking list
 * Desty "PICK-012812 (PACK BAYU).pdf".
 *
 * Baris di bawah disalin apa adanya dari keluaran diagnosa-pdf.html, termasuk
 * posisi x-nya. Jadi ini menguji layout sungguhan, bukan PDF tiruan:
 * nomor urut dan Qty berada di baris teks TERPISAH dari nama produk dan SKU.
 *
 * Jalankan: node tools\uji_parser_desty.mjs
 */
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\//, "")), "..");

// Muat parser apa adanya, tanpa pdf.js — hanya bagian yang bekerja atas baris.
const src = fs.readFileSync(path.join(ROOT, "assets/js/pdf-parser.js"), "utf8");
const P = new Function(
  src + "\nreturn { extractPickingListRows, isNonDataLine, hitungAwalBaris, pilihPenandaBaris };"
).call({});

/** Bangun satu baris dari pasangan [x, teks]. */
const B = (...sel) => sel.map(([x, text]) => ({ text, x, y: 0 }));

const lines = [
  B([504, "Picking List"]),
  B([140, "Jumlah Pesanan: 100"], [260, "Tanggal Cetak: 09-08-2026"]),
  B([514, "PICK-012812"]),
  B([140, "Jumlah produk: 131"], [260, "Dicetak Oleh:GUDANG AVA"]),
  B([487, "Master Warehouse"]),
  B([15, "No"], [39, "Barcode"], [186, "Nama Produk"], [371, "SKU"], [419, "Qty"], [444, "No.Pesanan"]),

  // --- produk 1 ---
  B([238, "Kaos Kaki Futsal Pendek A"], [371, "AV-0063"], [462, "260808AASBC73ZWOML4 (1)"]),
  B([15, "1"], [419, "29"]),
  B([238, "nti Slip Olahraga Sepak Bol"]),
  B([462, "260808AASA4DPDML3TI (1)"]),
  B([238, "a Tebal Sebetis Dewasa..."]),
  B([74, "8190888980296"], [462, "260808AASBP5OY6QZIU (1)"]),
  B([238, "Variant: Putih"]),
  B([462, "260808AAR6RCU3WPJNU (1)"]),
  B([462, "260808AASAO6RFRQNEA (1)"]),

  // --- batas halaman: perabot halaman yang harus diabaikan ---
  B([300, "Halaman: 1"]),
  B([504, "Picking List"]),
  B([487, "Master Warehouse"]),
  B([300, "Halaman sebelumnya"]),

  // --- produk 2 ---
  B([238, "Pelindung Lutut Kiper Knee"], [371, "100074"], [462, "260808AAR6IANY3ZMEA (1)"]),
  B([15, "2"], [419, "17"]),
  B([238, "pad Ayres Scudo Deker Pe"]),
  B([462, "260808AASA3DMDZZCMU (1)"]),
  B([238, "njaga Gawang Futsal S..."]),
  B([74, "12132458"], [462, "260808AASDMAHTKCZEY (1)"]),
  B([238, "Variant: Putih Sepasang"]),
];

const hasil = P.extractPickingListRows(lines);

console.log("=== HASIL ===");
console.log("header:", JSON.stringify(hasil.header));
console.log("jumlah baris:", hasil.rows.length);
hasil.rows.forEach((r, i) => {
  console.log(`  ${i + 1}. barcode=${r.barcode}  qty=${r.qty}  sku=${r.sku}`);
  console.log(`     nama    = ${r.nama}`);
  console.log(`     pesanan = ${r.noPesanan}`);
});

let lulus = 0, gagal = 0;
const cek = (nama, a, b) => {
  if (a === b) { lulus++; console.log("  [OK]    " + nama); }
  else {
    gagal++;
    console.log("  [GAGAL] " + nama + "\n            dapat = " + JSON.stringify(a) +
                "\n            harap = " + JSON.stringify(b));
  }
};

console.log("\n=== ASERSI ===");
cek("jumlah baris", hasil.rows.length, 2);

const r1 = hasil.rows[0] || {};
cek("1. barcode", r1.barcode, "8190888980296");
cek("1. qty", r1.qty, 29);
cek("1. sku", r1.sku, "AV-0063");
cek("1. nama utuh (fragmen pertama tidak hilang)", r1.nama,
    "Kaos Kaki Futsal Pendek Anti Slip Olahraga Sepak Bola Tebal Sebetis Dewasa... Variant: Putih");
cek("1. pesanan diawali yang pertama di PDF",
    String(r1.noPesanan).startsWith("260808AASBC73ZWOML4 (1)"), true);
cek("1. pesanan berisi 5 nomor",
    (String(r1.noPesanan).match(/260808/g) || []).length, 5);
cek("1. tidak kebocoran produk berikutnya",
    /Pelindung Lutut/.test(String(r1.nama)), false);
cek("1. tidak kebocoran perabot halaman",
    /Halaman|Picking List|Master Warehouse/i.test(String(r1.noPesanan)), false);

const r2 = hasil.rows[1] || {};
cek("2. barcode", r2.barcode, "12132458");
cek("2. qty", r2.qty, 17);
cek("2. sku", r2.sku, "100074");
// "Knee" + "pad" tersambung menjadi "Kneepad" — itu memang nama produknya
// ("Kneepad Ayres Scudo Deker"), bukan dua kata yang keliru dilekatkan.
cek("2. nama utuh", r2.nama,
    "Pelindung Lutut Kiper Kneepad Ayres Scudo Deker Penjaga Gawang Futsal S... Variant: Putih Sepasang");
cek("2. pesanan diawali yang pertama",
    String(r2.noPesanan).startsWith("260808AAR6IANY3ZMEA (1)"), true);

cek("dicetakOleh bersih", hasil.header.dicetakOleh, "GUDANG AVA");
cek("noPicking", hasil.header.noPicking, "PICK-012812");
cek("tanggalCetak", hasil.header.tanggalCetak, "09-08-2026");

console.log("\nLULUS: " + lulus + "   GAGAL: " + gagal);
process.exit(gagal > 0 ? 1 : 0);
