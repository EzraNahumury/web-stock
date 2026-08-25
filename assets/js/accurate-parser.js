/* ==========================================================================
 * accurate-parser.js — Pembaca PDF "Kuantitas Barang per Gudang" dari Accurate
 *
 * Dipakai kolom Stok accurate di Laporan stok opname. Yang diambil hanya dua
 * hal per baris: nama barang, dan kuantitas pada kolom gudang PERTAMA.
 *
 * MENGAPA KOLOM PERTAMA
 * Laporannya punya dua kelompok kolom berjudul sama: satu untuk gudang yang
 * dipilih (mis. "GUDANG UTAMA") dan satu lagi "Total Nama Gudang". Keduanya
 * memakai sub-judul "Kuantitas" dan "Total Biaya", jadi mencocokkan lewat
 * teks sub-judul saja akan ambigu. Kelompok gudangnya selalu di kiri, jadi
 * kemunculan "Kuantitas" paling kiri yang dipakai — dan nama gudangnya ikut
 * dikembalikan supaya bisa ditunjukkan ke pemakai sebelum data dipakai.
 *
 * Rekonstruksi barisnya memakai cara yang sama dengan pdf-parser.js:
 * kelompokkan item teks menurut koordinat y, lalu petakan ke kolom memakai
 * batas titik-tengah antar posisi judul kolom. PDF tidak menyimpan tabel,
 * hanya potongan teks beserta letaknya.
 * ========================================================================== */

/**
 * Baca PDF Accurate dan kembalikan { gudang, rows, dilewati }.
 * @param {ArrayBuffer} arrayBuffer isi berkas PDF
 * @returns {Promise<{gudang:string, rows:Array<{nama:string, qty:number}>, dilewati:number}>}
 */
async function parsePdfAccurate(arrayBuffer){
  if(!window["pdfjsLib"]) throw new Error("PDFJS_TIDAK_DIMUAT");

  const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
  let semuaBaris = [];

  for(let p = 1; p <= pdf.numPages; p++){
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    const items = content.items
      .map(it => ({ text:(it.str || ""), x:it.transform[4], y:it.transform[5] }))
      .filter(it => it.text.trim() !== "");
    items.sort((a, b) => (b.y - a.y) || (a.x - b.x));

    let baris = [], yKini = null, kini = [];
    const TOL = 3.5;
    items.forEach(it => {
      if(yKini === null || Math.abs(it.y - yKini) <= TOL){
        kini.push(it);
        if(yKini === null) yKini = it.y;
      } else {
        baris.push(kini.sort((a, b) => a.x - b.x));
        kini = [it];
        yKini = it.y;
      }
    });
    if(kini.length) baris.push(kini.sort((a, b) => a.x - b.x));
    semuaBaris = semuaBaris.concat(baris);
  }

  return ambilBarisAccurate(semuaBaris);
}

/** Ubah "69.871,8" atau "1.234" menjadi angka. Kembalikan null bila bukan angka. */
function angkaAccurate(teks){
  const t = String(teks || "").trim();
  if(t === "") return null;
  // Hanya digit, titik, koma, dan tanda minus yang dianggap angka.
  if(!/^-?[\d.,]+$/.test(t)) return null;
  const bersih = t.replace(/\./g, "").replace(",", ".");
  const n = parseFloat(bersih);
  return isNaN(n) ? null : n;
}

/** Baris yang jelas bukan data: judul, keterangan cetak, nomor halaman. */
function bukanBarisData(teks){
  const t = teks.trim();
  if(t === "") return true;
  return /^(per tgl|cabang\s*:|halaman|page|total\s*$|kuantitas barang per gudang)/i.test(t);
}

function ambilBarisAccurate(baris){
  /* --- 1. Cari baris judul kolom ---------------------------------------- */
  let idxJudul = -1, selKuantitas = [];
  for(let i = 0; i < baris.length; i++){
    const kuant = baris[i].filter(c => /^kuantitas$/i.test(c.text.trim()));
    if(kuant.length){
      idxJudul = i;
      selKuantitas = kuant;
      break;
    }
  }
  if(idxJudul === -1){
    throw new Error("JUDUL_KOLOM_TIDAK_DITEMUKAN");
  }

  /* --- 2. Cari posisi kolom nama ---------------------------------------- */
  // "Nama Barang" bisa berada di baris judul yang sama, atau satu baris di
  // atasnya bila judulnya bertingkat dua.
  let xNama = null;
  for(let i = idxJudul; i >= Math.max(0, idxJudul - 3) && xNama === null; i--){
    const sel = baris[i].find(c => /nama\s*barang/i.test(c.text));
    if(sel) xNama = sel.x;
  }
  if(xNama === null){
    // Cadangan: kolom nama selalu paling kiri di laporan ini.
    xNama = Math.min.apply(null, baris[idxJudul].map(c => c.x)) - 1;
  }

  /* --- 3. Nama gudang, untuk ditunjukkan ke pemakai ---------------------- */
  let gudang = "";
  for(let i = 0; i < Math.min(baris.length, idxJudul + 1); i++){
    const teks = baris[i].map(c => c.text).join(" ");
    const m = teks.match(/Gudang\s*:\s*([^,]+?)\s*$/i);
    if(m){ gudang = m[1].trim(); }
  }
  if(gudang === ""){
    // Judul kelompok kolom kiri, bila keterangan cabangnya tidak ada.
    const atas = baris[Math.max(0, idxJudul - 1)] || [];
    const kiri = atas.filter(c => c.x > xNama + 10);
    if(kiri.length) gudang = kiri[0].text.trim();
  }

  /* --- 4. Batas kolom --------------------------------------------------- */
  // Kolom kuantitas yang dipakai adalah yang paling kiri; batas kanannya
  // adalah titik tengah menuju judul kolom berikutnya.
  const xKuantitas = selKuantitas[0].x;
  const semuaJudul = baris[idxJudul].map(c => c.x).sort((a, b) => a - b);
  const berikut = semuaJudul.find(x => x > xKuantitas + 1);

  // Angka ditulis rata kanan, jadi x-nya tidak sama dengan x judulnya.
  // Batas kiri diambil dari titik tengah antara kolom nama dan kolom
  // kuantitas; batas kanan dari titik tengah menuju kolom sesudahnya.
  const batasKiri  = (xNama + xKuantitas) / 2;
  const batasKanan = berikut !== undefined ? (xKuantitas + berikut) / 2 : Infinity;

  /* --- 5. Baca baris data ----------------------------------------------- */
  const rows = [];
  let sisaNama = "";
  let dilewati = 0;

  for(let i = idxJudul + 1; i < baris.length; i++){
    const sel = baris[i];
    const bagianNama = sel.filter(c => c.x < batasKiri).map(c => c.text.trim()).filter(Boolean);
    const nama = bagianNama.join(" ").replace(/\s+/g, " ").trim();

    if(bukanBarisData(nama) && bagianNama.length){
      // Judul halaman berikutnya atau keterangan cetak — buang sisa nama
      // yang menggantung supaya tidak menempel ke baris data setelahnya.
      sisaNama = "";
      continue;
    }

    // Kuantitas: sel yang jatuh di rentang kolom paling kiri.
    let qty = null;
    for(let j = 0; j < sel.length; j++){
      const c = sel[j];
      if(c.x >= batasKiri && c.x < batasKanan){
        const n = angkaAccurate(c.text);
        if(n !== null){ qty = n; break; }
      }
    }

    if(qty === null){
      // Baris tanpa angka: kemungkinan nama yang turun ke baris berikutnya.
      if(nama !== "") sisaNama = (sisaNama ? sisaNama + " " : "") + nama;
      continue;
    }

    const namaPenuh = ((sisaNama ? sisaNama + " " : "") + nama).replace(/\s+/g, " ").trim();
    sisaNama = "";

    if(namaPenuh === ""){
      dilewati++;      // ada angkanya tapi tidak ada namanya — tidak bisa dicocokkan
      continue;
    }
    rows.push({ nama: namaPenuh, qty: Math.round(qty) });
  }

  return { gudang: gudang, rows: rows, dilewati: dilewati };
}
