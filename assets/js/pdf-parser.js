/* ==========================================================================
 * pdf-parser.js — Pembaca PDF Picking List
 *
 * DIPINDAHKAN UTUH dari prototipe "aplikasi-gudang (2).html" baris 518-699.
 * Algoritmanya TIDAK diubah sedikit pun: rekonstruksi baris dari koordinat,
 * batas kolom titik-tengah, penyaring baris non-data, penggabungan baris
 * multi-line, dan mode cadangan regex semuanya sama persis.
 *
 * Satu-satunya perubahan: parsePdfPickingList() kini MENGEMBALIKAN hasil
 * alih-alih menulis ke variabel global pdfImport, supaya berkas ini murni
 * berisi logika parsing dan bisa diuji terpisah dari UI.
 *
 * Komentar asli dipertahankan karena mencatat dua bug nyata yang sudah
 * diperbaiki (huruf pertama sel nyasar ke kolom sebelumnya, dan footer
 * halaman yang tersambung ke baris data terakhir). Jangan dirapikan.
 * ========================================================================== */

/**
 * Baca PDF dan kembalikan { header, rows }.
 * @param {ArrayBuffer} arrayBuffer isi berkas PDF
 * @returns {Promise<{header:Object, rows:Array}>}
 */
async function parsePdfPickingList(arrayBuffer){
  if(!window["pdfjsLib"]) throw new Error("PDFJS_TIDAK_DIMUAT");

  const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
  let allLines = [];
  for(let p=1; p<=pdf.numPages; p++){
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    const items = content.items
      .map(it => ({ text:(it.str||""), x:it.transform[4], y:it.transform[5] }))
      .filter(it => it.text.trim());
    items.sort((a,b) => (b.y - a.y) || (a.x - b.x));
    let lines = [], currentY = null, currentLine = [];
    const TOL = 3.5;
    items.forEach(it => {
      if(currentY===null || Math.abs(it.y-currentY) <= TOL){
        currentLine.push(it);
        if(currentY===null) currentY = it.y;
      } else {
        lines.push(currentLine.sort((a,b)=>a.x-b.x));
        currentLine = [it];
        currentY = it.y;
      }
    });
    if(currentLine.length) lines.push(currentLine.sort((a,b)=>a.x-b.x));
    allLines = allLines.concat(lines);
  }
  return extractPickingListRows(allLines);
}

function extractPickingListRows(lines){
  let headerIdx = -1, cols = null;
  for(let i=0;i<lines.length;i++){
    const text = lines[i].map(it=>it.text).join(" ").toLowerCase();
    if(text.includes("barcode") && text.includes("nama") && text.includes("sku") && text.includes("qty")){
      const built = buildColumnsFromHeader(lines[i]);
      if(built){ headerIdx = i; cols = built; break; }
    }
  }
  const header = extractPdfHeaderInfo(lines, headerIdx===-1?lines.length:headerIdx);
  if(headerIdx===-1 || !cols){
    return { header, rows: fallbackRegexParseRows(lines) };
  }
  const rows = [];
  let current = null;
  const penanda = pilihPenandaBaris(lines, headerIdx, cols);
  for(let i=headerIdx+1; i<lines.length; i++){
    if(isNonDataLine(lines[i])) continue; // header berulang / info halaman lain, jangan dianggap data ataupun disambung ke baris berjalan
    const assigned = assignLineToColumns(lines[i], cols);
    const isNewRow = penanda === "no"
      ? /^\d+$/.test((assigned.no || "").trim())
      : tampakBarcode(assigned.barcode);
    if(isNewRow){
      if(current) rows.push(finalizePdfRow(current));
      current = { barcode:assigned.barcode||"", nama:assigned.nama?[assigned.nama]:[], sku:assigned.sku||"", qty:assigned.qty||"", noPesanan:assigned.noPesanan||"" };
    } else if(current){
      if(assigned.barcode) current.barcode += assigned.barcode;
      if(assigned.nama) current.nama.push(assigned.nama);
      if(assigned.sku) current.sku += (" "+assigned.sku);
      if(assigned.qty) current.qty += assigned.qty;
      if(assigned.noPesanan) current.noPesanan += (" "+assigned.noPesanan);
    }
  }
  if(current) rows.push(finalizePdfRow(current));
  return { header, rows: rows.filter(r => r.barcode || r.nama) };
}

/**
 * Apakah isi sel ini tampak seperti barcode?
 *
 * Dipakai sebagai penanda awal baris pada picking list yang tidak punya
 * kolom nomor urut. Barcode di data nyata berupa 8-14 digit (12132458,
 * 8190888980296) atau kode alfanumerik (SFN004073043), jadi syaratnya:
 * cukup panjang dan didominasi angka. Sengaja longgar terhadap spasi,
 * karena pdf.js kerap memecah satu barcode menjadi beberapa potongan.
 */
function tampakBarcode(s){
  const t = String(s || "").replace(/\s+/g, "");
  if(t.length < 6) return false;
  const digit = (t.match(/\d/g) || []).length;
  return digit >= 4;
}

/**
 * Tentukan apa yang menandai awal sebuah baris barang.
 *
 * Parser semula selalu memakai kolom "No" — baris baru dimulai ketika sel
 * No berisi bilangan bulat murni. Picking list yang TIDAK punya kolom
 * nomor urut membuat aturan itu tidak pernah terpenuhi: seluruh halaman
 * menyatu menjadi satu baris, dan baris pertama hilang sama sekali karena
 * belum ada baris berjalan untuk menampungnya.
 *
 * Keputusannya kini diambil dari isi berkas: kolom "No" dipakai hanya bila
 * benar-benar berisi nomor urut. Selain itu, awal baris ditandai kolom
 * Barcode yang terisi — setiap barang punya tepat satu barcode, sedangkan
 * baris sambungan nama tidak punya.
 *
 * @return {"no"|"barcode"}
 */
function pilihPenandaBaris(lines, headerIdx, cols){
  if(!cols.some(c => c.key === "no")) return "barcode";

  let berangka = 0, diperiksa = 0;
  for(let i = headerIdx + 1; i < lines.length && diperiksa < 60; i++){
    if(isNonDataLine(lines[i])) continue;
    const a = assignLineToColumns(lines[i], cols);
    diperiksa++;
    if(/^\d+$/.test((a.no || "").trim())) berangka++;
  }
  // Butuh minimal dua nomor urut sebelum mempercayai kolom itu; satu
  // kecocokan kebetulan tidak cukup.
  return berangka >= 2 ? "no" : "barcode";
}

// Baris yang BUKAN baris data barang: header tabel yang tercetak ulang di
// tiap halaman PDF, atau info ringkasan (Tanggal Cetak, Dicetak Oleh,
// Jumlah Pesanan, Jumlah Produk, No Pick, nomor halaman). Baris seperti ini
// dulu ikut tergabung ke kolom Barcode/SKU baris terakhir sehingga isinya
// jadi campur aduk (mis. "12132458Jumlah", "SKU 100074").
function isNonDataLine(items){
  const text = items.map(it=>it.text).join(" ").trim();
  const t = text.toLowerCase();
  if(t.includes("barcode") && t.includes("nama") && t.includes("sku") && t.includes("qty")) return true;
  if(/dicetak\s*oleh/i.test(text)) return true;
  if(/tanggal\s*cetak/i.test(text)) return true;
  if(/jumlah\s*pesanan/i.test(text)) return true;
  if(/jumlah\s*produk/i.test(text)) return true;
  if(/\bpick-[\w-]+/i.test(text) && !/^\d/.test(text)) return true;
  if(/no\.?\s*pick/i.test(text)) return true;
  if(/^halaman\b/i.test(t) || /^page\b/i.test(t) || /^\d+\s*\/\s*\d+$/.test(t)) return true;
  return false;
}

function buildColumnsFromHeader(items){
  const colDefs = [];
  items.forEach((it, i) => {
    const t = it.text.trim().toLowerCase().replace(/\.$/,"");
    // pdf.js kerap memecah judul "No.Pesanan" menjadi "No." dan "Pesanan".
    // Tanpa penjagaan ini, potongan "No." terdaftar sebagai kolom nomor
    // urut di posisi paling kanan — menambah batas kolom palsu yang
    // menggeser pembagian sel di seluruh tabel.
    const berikutnya = ((items[i+1] && items[i+1].text) || "").trim().toLowerCase();
    if(t==="no" && berikutnya.indexOf("pesanan") === 0) return;

    if(t==="no") colDefs.push({ key:"no", x:it.x });
    else if(t.includes("barcode")) colDefs.push({ key:"barcode", x:it.x });
    else if(t.includes("nama")) colDefs.push({ key:"nama", x:it.x });
    else if(t==="sku") colDefs.push({ key:"sku", x:it.x });
    else if(t.includes("qty")) colDefs.push({ key:"qty", x:it.x });
    else if(t.includes("pesanan")) colDefs.push({ key:"noPesanan", x:it.x });
  });
  const keys = colDefs.map(c=>c.key);
  if(!keys.includes("barcode") || !keys.includes("nama") || !keys.includes("sku") || !keys.includes("qty")) return null;
  const seen = {};
  const dedup = colDefs.filter(c => seen[c.key] ? false : (seen[c.key]=true));
  dedup.sort((a,b)=>a.x-b.x);
  return dedup;
}

function assignLineToColumns(items, cols){
  // Batas tiap kolom = titik tengah antara header kolom ini dan header
  // sebelumnya (bukan x header + toleransi kecil). Isi baris jarang persis
  // rata kiri dengan teks headernya, jadi toleransi tetap (+2) membuat
  // huruf pertama sebuah sel sering "kepotong" dan nyasar ke kolom
  // sebelumnya (mis. "Kanti Slip..." kehilangan "Ka" dan masuk ke kolom
  // Barcode). Titik tengah antar kolom jauh lebih toleran terhadap itu.
  const bounds = cols.map((c, idx) => idx===0 ? -Infinity : (cols[idx-1].x + c.x) / 2);
  const result = {};
  items.forEach(it => {
    let bestIdx = 0;
    for(let i=0;i<cols.length;i++){ if(it.x >= bounds[i]) bestIdx = i; }
    const key = cols[bestIdx].key;
    result[key] = result[key] ? (result[key] + " " + it.text.trim()) : it.text.trim();
  });
  return result;
}

/**
 * Sambung pecahan nama yang terbagi antar baris di PDF.
 *
 * Picking list marketplace memotong nama pada lebar karakter tetap, bukan
 * pada batas kata, sehingga satu kata bisa terbelah dua baris:
 *
 *     "Kaos Kaki Futsal Pendek A"
 *     "nti Slip Olahraga Sepak Bol"
 *     "a Tebal Sebetis Dewasa..."
 *
 * Menyambungnya dengan spasi menghasilkan "Pendek A nti Slip ... Bol a
 * Tebal" — nama jadi sulit dibaca dan tidak cocok saat dicari di master.
 *
 * Penentunya huruf pertama pecahan berikutnya: huruf KECIL berarti kata
 * yang sama terpotong, jadi disambung tanpa spasi. Huruf besar, angka,
 * atau tanda baca berarti kata baru, jadi tetap diberi spasi. Nama produk
 * ditulis Kapital Di Awal Kata, sehingga sambungan berhuruf kecil hampir
 * selalu berarti potongan kata.
 */
function gabungPecahanNama(bagian){
  let hasil = "";
  bagian.forEach(function(p){
    const potong = String(p == null ? "" : p).trim();
    if(!potong) return;
    if(!hasil){ hasil = potong; return; }
    const akhir = hasil.slice(-1);
    const awal  = potong.charAt(0);
    const terpotong = /[A-Za-z0-9]/.test(akhir) && /[a-z]/.test(awal);
    hasil += (terpotong ? "" : " ") + potong;
  });
  return hasil;
}

function finalizePdfRow(r){
  const nama = gabungPecahanNama(r.nama).replace(/\s+/g," ").trim();
  return {
    barcode: (r.barcode||"").replace(/\s+/g,"").trim(),
    nama: nama,
    sku: (r.sku||"").trim(),
    qty: parseInt((r.qty||"").replace(/[^\d]/g,""),10) || 0,
    noPesanan: (r.noPesanan||"").trim(),
    keterangan: "Pesanan MP"
  };
}

function extractPdfHeaderInfo(lines, uptoIdx){
  const preText = lines.slice(0, uptoIdx).map(l => l.map(it=>it.text).join(" ")).join(" ");
  const get = (re) => { const m = preText.match(re); return m ? m[1].trim() : ""; };
  return {
    noPicking: get(/(PICK-[\w-]+)/i),
    tanggalCetak: get(/Tanggal Cetak:?\s*([\d\/\-]+)/i),
    dicetakOleh: get(/Dicetak Oleh:?\s*([A-Za-z0-9 _-]+?)(?:\s+Picking|$)/i),
    jumlahPesanan: get(/Jumlah Pesanan:?\s*(\d+)/i),
    jumlahProduk: get(/Jumlah [Pp]roduk:?\s*(\d+)/i)
  };
}

function fallbackRegexParseRows(lines){
  // Fallback when the table header/columns can't be detected: split the raw
  // text by long digit runs (likely barcodes) so the admin can still review
  // and complete each row manually.
  const fullText = lines.map(l => l.map(it=>it.text).join(" ")).join(" \n ");
  const matches = [...fullText.matchAll(/\b(\d{8,14})\b/g)];
  const rows = [];
  for(let i=0;i<matches.length;i++){
    const start = matches[i].index;
    const end = i+1<matches.length ? matches[i+1].index : Math.min(fullText.length, start + 200);
    const chunk = fullText.slice(start, end).replace(/\s+/g," ").trim();
    const barcode = matches[i][1];
    const rest = chunk.slice(barcode.length).trim();
    const qtyMatch = rest.match(/\b(\d{1,4})\b(?!.*\d{1,4}\b)/);
    rows.push({
      barcode: barcode,
      nama: rest.replace(/\d{1,4}$/,"").trim().slice(0,120),
      sku: "",
      qty: qtyMatch ? parseInt(qtyMatch[1],10) : 0,
      noPesanan: "",
      keterangan: "Pesanan MP"
    });
  }
  return rows;
}

/**
 * Hitung SHA-256 isi PDF untuk deteksi impor ganda (audit D5).
 * Dihitung dari ArrayBuffer yang sudah ada di tangan — PDF tidak perlu
 * diunggah ke server sama sekali.
 */
async function hitungHashPdf(arrayBuffer){
  try{
    const buf = await crypto.subtle.digest("SHA-256", arrayBuffer);
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2,"0")).join("");
  }catch(e){
    return ""; // crypto.subtle butuh HTTPS atau localhost; bukan galat fatal
  }
}
