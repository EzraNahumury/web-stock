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
    allLines = allLines.concat(susunBarisDariItem(items));
  }
  return extractPickingListRows(allLines);
}

/**
 * Susun potongan teks satu halaman menjadi baris, berdasarkan koordinatnya.
 *
 * pdf.js memberi potongan teks lepas, bukan baris. Yang berselisih tinggi
 * kurang dari TOL dianggap satu baris, lalu diurutkan dari kiri ke kanan.
 *
 * Terpisah dari parsePdfPickingList supaya bisa diuji tanpa pdf.js: lihat
 * tools/uji_pdf_parser.js, yang menjalankannya atas geometri berkas nyata.
 *
 * @param {Array<{text:string,x:number,y:number}>} items
 * @returns {Array<Array<{text:string,x:number,y:number}>>}
 */
function susunBarisDariItem(items){
  const TOL = 3.5;
  items.sort((a,b) => (b.y - a.y) || (a.x - b.x));
  const lines = [];
  let currentY = null, currentLine = [];
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
  return lines;
}

/** Nomor urut baris, hanya bila kolom No benar-benar berisi bilangan. */
/**
 * Apakah baris ini perabot halaman — kaki, kepala, atau penunjuk sambungan?
 *
 * Dipakai untuk mengenali pergantian halaman. Bedanya dengan isNonDataLine:
 * yang ini khusus penanda batas halaman, bukan seluruh baris bukan-data.
 */
function perabotHalaman(items){
  return /halaman/i.test(items.map(it => it.text).join(" "));
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
  let ulangan = false;        // sedang di dalam blok barang yang dicetak ulang
  const penanda = pilihPenandaBaris(lines, headerIdx, cols);
  const tanda = hitungAwalBaris(lines, headerIdx, cols, penanda);
  for(let i=headerIdx+1; i<lines.length; i++){
    if(isNonDataLine(lines[i])) continue; // header berulang / info halaman lain, jangan dianggap data ataupun disambung ke baris berjalan
    const assigned = assignLineToColumns(lines[i], cols);

    // Blok barang yang dicetak ulang di puncak halaman berikutnya.
    //
    // Bila daftar nomor pesanan satu barang tidak habis dalam satu halaman,
    // Desty mencetak ULANG seluruh blok barangnya di puncak halaman
    // berikutnya — nomor urut, nama, SKU, barcode dan Qty yang sama — lalu
    // meneruskan nomor pesanan yang tersisa. Yang baru di situ hanya nomor
    // pesanannya. Tanpa penjagaan ini blok ulangan menjadi baris kedua:
    // qty-nya dihitung dua kali dan stoknya terpotong dua kali. Pada satu
    // picking list nyata dua barang terulang seperti ini dan totalnya
    // menjadi 160, padahal kepala berkasnya menyebut 131.
    if(tanda.ulangan.has(i)){
      ulangan = true;
      continue;
    }

    if(tanda.awal.has(i)){
      if(current) rows.push(finalizePdfRow(current));
      current = { barcode:assigned.barcode||"", nama:assigned.nama?[assigned.nama]:[], sku:assigned.sku||"", qty:assigned.qty||"", noPesanan:assigned.noPesanan||"" };
      ulangan = false;
      continue;
    }

    if(!current) continue;

    if(ulangan){
      // Dari blok ulangan hanya nomor pesanannya yang diambil. Kolom lain
      // cuma dilengkapi bila masih kosong — itu terjadi bila halaman
      // sebelumnya terpotong sebelum baris barcodenya. Yang sudah terisi
      // tidak disambung: isinya sama, dan menyambungnya menghasilkan qty
      // "1212" atau nama yang tertulis dua kali.
      if(assigned.noPesanan) current.noPesanan += (" "+assigned.noPesanan);
      if(!current.barcode && assigned.barcode)  current.barcode = assigned.barcode;
      if(!current.sku && assigned.sku)          current.sku     = assigned.sku;
      if(!current.qty && assigned.qty)          current.qty     = assigned.qty;
      if(!current.nama.length && assigned.nama) current.nama.push(assigned.nama);
      continue;
    }

    if(assigned.barcode) current.barcode += assigned.barcode;
    if(assigned.nama) current.nama.push(assigned.nama);
    if(assigned.sku) current.sku += (" "+assigned.sku);
    if(assigned.qty) current.qty += assigned.qty;
    if(assigned.noPesanan) current.noPesanan += (" "+assigned.noPesanan);
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

  let berangka = 0, diperiksa = 0, pertama = "";
  for(let i = headerIdx + 1; i < lines.length && diperiksa < 60; i++){
    if(isNonDataLine(lines[i])) continue;
    const a = assignLineToColumns(lines[i], cols);
    diperiksa++;
    const nilai = (a.no || "").trim();
    if(/^\d+$/.test(nilai)){
      berangka++;
      if(pertama === "") pertama = nilai;
    }
  }
  // Butuh minimal dua nomor urut sebelum mempercayai kolom itu; satu
  // kecocokan kebetulan tidak cukup.
  //
  // Kecualinya: bila nomor satu-satunya itu "1". Picking list yang memuat
  // SATU barang memang hanya punya satu nomor urut, dan menolaknya membuat
  // seluruh berkas dibaca dengan penanda barcode. Pada layout Desty barcode
  // berada di baris teks tersendiri, di bawah nama dan SKU, sehingga barang
  // dimulai terlambat dan kolom SKU, Qty serta nomor pesanannya terbuang —
  // hasilnya satu baris berqty 0 yang tidak bisa disimpan. Nomor urut yang
  // kebetulan berbunyi tepat "1" jauh lebih jarang daripada picking list
  // berisi satu barang.
  return (berangka >= 2 || (berangka === 1 && pertama === "1")) ? "no" : "barcode";
}

/**
 * Tentukan pada indeks baris mana setiap barang dimulai.
 *
 * Penanda baris (nomor urut, atau barcode) TIDAK selalu berada di baris teks
 * pertama sebuah barang. Pada picking list Desty, pdf.js membaca satu baris
 * tabel sebagai dua baris teks terpisah, karena nomor urut dan Qty dicetak
 * pada garis dasar yang berbeda dari nama produk:
 *
 *     baris N   : [x=238] Kaos Kaki Futsal Pendek A  [x=371] AV-0063  [x=462] 260808...
 *     baris N+1 : [x=15]  1                          [x=419] 29
 *
 * Memulai barang tepat di baris penanda membuat baris N terbuang: ia tiba
 * saat belum ada barang berjalan, sehingga nama fragmen pertama, SKU, dan
 * nomor pesanan pertama hilang — dan untuk barang berikutnya, baris N-nya
 * justru nyangkut ke barang sebelumnya.
 *
 * Karena itu awal barang digeser satu baris ke belakang bila baris tepat
 * sebelum penanda membawa isi kolom Nama atau SKU. Baris sambungan yang
 * hanya berisi nomor pesanan tidak memenuhi syarat itu, jadi ekor daftar
 * pesanan barang sebelumnya tetap utuh.
 *
 * Baris penanda yang, ditelusuri ke belakang, langsung bertemu perabot
 * halaman adalah blok barang yang dicetak ulang di puncak halaman berikutnya.
 * Isinya ada di halaman sebelumnya, jadi ia bukan awal barang baru — dan
 * menoleh lebih jauh ke belakang justru merampas baris sambungan terakhir
 * halaman sebelumnya, yang dulu memecah satu barang menjadi dua.
 *
 * @return {{awal: Set<number>, ulangan: Set<number>}} indeks awal setiap
 *         barang, dan indeks baris penanda blok yang dicetak ulang
 */
function hitungAwalBaris(lines, headerIdx, cols, penanda){
  const awal = new Set();
  const ulangan = new Set();

  const adalahPenanda = (a) => penanda === "no"
    ? /^\d+$/.test((a.no || "").trim())
    : tampakBarcode(a.barcode);

  const punyaIsi = (a) => ((a.nama || "").trim() !== "") || ((a.sku || "").trim() !== "");

  for(let i=headerIdx+1; i<lines.length; i++){
    if(isNonDataLine(lines[i])) continue;
    const a = assignLineToColumns(lines[i], cols);
    if(!adalahPenanda(a)) continue;

    let mulai = i;

    // Hanya menoleh ke belakang bila baris penanda ini TIDAK membawa isi
    // barangnya sendiri. Pada layout biasa, baris penanda sudah memuat nama
    // dan SKU sekaligus — menoleh ke belakang di situ justru merampas baris
    // sambungan nama milik barang sebelumnya (mis. "EDISI KHUSUS" terlepas
    // dari "FINGERTAPE HIJAU MUDA"). Pada layout Desty, baris penanda hanya
    // berisi nomor urut dan Qty, jadi isinya memang ada di baris sebelumnya.
    if(!punyaIsi(a)){
      let blokUlangan = false;
      for(let j=i-1; j>headerIdx; j--){
        if(isNonDataLine(lines[j])){
          // Perabot halaman lebih dulu ditemui daripada isi: blok barangnya
          // dicetak ulang di puncak halaman ini.
          if(perabotHalaman(lines[j])){ blokUlangan = true; break; }
          continue;
        }
        const b = assignLineToColumns(lines[j], cols);
        // Jangan mengambil baris yang sudah menjadi awal barang lain.
        if(punyaIsi(b) && !adalahPenanda(b) && !awal.has(j)) mulai = j;
        break;
      }
      if(blokUlangan){
        ulangan.add(i);
        continue;
      }
    }
    awal.add(mulai);
  }
  return { awal: awal, ulangan: ulangan };
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
  // Blok tanda tangan di kaki halaman terakhir. "Diambil oleh" dicetak tepat
  // di kolom No. Pesanan, jadi tanpa aturan ini ia tersambung ke baris barang
  // terakhir dan ikut tersimpan sebagai nomor pesanan.
  if(/diambil\s*oleh/i.test(text)) return true;
  if(/catatan\s*pengambilan/i.test(text)) return true;
  if(/tanggal\s*cetak/i.test(text)) return true;
  if(/jumlah\s*pesanan/i.test(text)) return true;
  if(/jumlah\s*produk/i.test(text)) return true;
  if(/\bpick-[\w-]+/i.test(text) && !/^\d/.test(text)) return true;
  if(/no\.?\s*pick/i.test(text)) return true;
  if(/^halaman\b/i.test(t) || /^page\b/i.test(t) || /^\d+\s*\/\s*\d+$/.test(t)) return true;

  // Perabot halaman pada picking list berhalaman banyak. Dicocokkan di mana
  // pun dalam baris, bukan hanya di awal: pada batas halaman, pdf.js
  // menggabungkan penomoran halaman dengan judul halaman berikutnya dalam
  // satu baris, sehingga aturan "^halaman" di atas tidak menangkapnya dan
  // teksnya bocor ke kolom No. Pesanan barang terakhir.
  if(/halaman\s*:/i.test(text)) return true;
  if(/halaman\s+(berikutnya|sebelumnya)/i.test(text)) return true;
  if(/^picking\s+list\b/i.test(t)) return true;
  if(/^master\s+warehouse$/i.test(t)) return true;
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
    // Berhenti juga di "Master" dan "Halaman": pada berkas nyata teks
    // sesudahnya ikut tertangkap, menghasilkan "GUDANG AVA Master Warehouse".
    dicetakOleh: get(/Dicetak Oleh:?\s*([A-Za-z0-9 _-]+?)(?:\s+(?:Picking|Master|Halaman|Jumlah)|$)/i),
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
