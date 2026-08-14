/**
 * Uji parser terhadap picking list TANPA kolom nomor urut —
 * meniru layout PDF yang dipakai pengguna di lapangan.
 */
import fs from "node:fs";
import path from "node:path";
import { createRequire } from "node:module";
import { webcrypto } from "node:crypto";

const require = createRequire(import.meta.url);
const ROOT = "C:/web-stock";

global.window = global;
if (!globalThis.navigator) {
  Object.defineProperty(global, "navigator", {
    value: { userAgent: "node", platform: "node", language: "id" },
    configurable: true,
  });
}
global.document = {
  currentScript: null,
  createElement: () => ({ style: {}, setAttribute() {}, getContext: () => null }),
  documentElement: { style: {} },
};
if (!globalThis.crypto) {
  Object.defineProperty(global, "crypto", { value: webcrypto, configurable: true });
}
global.location = { href: "file:///", protocol: "file:" };

const pdfjs = require("pdfjs-dist/legacy/build/pdf.js");
global.pdfjsLib = pdfjs;
pdfjs.GlobalWorkerOptions.workerSrc = require.resolve("pdfjs-dist/legacy/build/pdf.worker.js");

const src = fs.readFileSync(path.join(ROOT, "assets/js/pdf-parser.js"), "utf8");
const modul = new Function(src + "\nreturn { parsePdfPickingList };");
const P = modul.call(global);

const data = new Uint8Array(fs.readFileSync(path.join(ROOT, "contoh/picking-tanpa-kolom-no.pdf")));
const hasil = await P.parsePdfPickingList(
  data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength)
);

console.log("=== BARIS TERBACA (" + hasil.rows.length + ") ===");
hasil.rows.forEach((r, i) => {
  console.log(
    "  " + (i + 1) + ". bc=" + String(r.barcode).padEnd(15) +
    " qty=" + String(r.qty).padEnd(4) +
    " sku=" + String(r.sku).padEnd(9) +
    " pesanan=" + String(r.noPesanan).padEnd(26)
  );
  console.log("     nama: " + r.nama);
});

const harap = [
  { barcode: "8190888980296", qty: 29, sku: "100074",
    nama: "Kaos Kaki Futsal Pendek Anti Slip Olahraga Sepak Bola Tebal Sebetis Dewasa...",
    pesanan: "260808AASBC73ZWOML4 (1)" },
  { barcode: "12132458", qty: 17, sku: "100074",
    nama: "Elbowpad Ayres Scudo Deker Penjaga Gawang Dewasa",
    pesanan: "260808AASA4DPDML3TI (1)" },
  { barcode: "8119872809863", qty: 12, sku: "AV-0066",
    nama: "Avo Original Sleeve Sock Sambungan Variant: Putih",
    pesanan: "260808AASBP5OY6QZIU (1)" },
  { barcode: "12132856", qty: 6, sku: "100055",
    nama: "Pelindung Lutut Kiper Knee Pad Dewasa Hitam",
    pesanan: "260808AAR6RCU3WPJNU (1)" },
];

let lulus = 0, gagal = 0;
const cek = (nama, a, b) => {
  if (a === b) { lulus++; console.log("  [OK]    " + nama); }
  else { gagal++; console.log("  [GAGAL] " + nama + "\n            dapat = " + JSON.stringify(a) + "\n            harap = " + JSON.stringify(b)); }
};

console.log("\n=== ASERSI ===");
cek("jumlah baris terpisah", hasil.rows.length, 4);

harap.forEach((h, i) => {
  const r = hasil.rows[i] || {};
  cek("baris" + (i + 1) + ".barcode", r.barcode, h.barcode);
  cek("baris" + (i + 1) + ".qty", r.qty, h.qty);
  cek("baris" + (i + 1) + ".sku", r.sku, h.sku);
  cek("baris" + (i + 1) + ".nama utuh", r.nama, h.nama);
  cek("baris" + (i + 1) + ".pesanan", r.noPesanan, h.pesanan);
});

cek("total qty", hasil.rows.reduce((s, r) => s + r.qty, 0), 64);
cek("tak ada nomor pesanan menumpuk",
    hasil.rows.some(r => (String(r.noPesanan).match(/260808/g) || []).length > 1), false);

console.log("\nLULUS: " + lulus + "   GAGAL: " + gagal);
process.exit(gagal > 0 ? 1 : 0);
