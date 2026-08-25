/* ==========================================================================
 * accurate-parser.js — Pembaca PDF "Kuantitas Barang per Gudang" dari Accurate
 *
 * Dipakai kolom Stok accurate di Laporan stok opname. Yang diambil hanya dua
 * hal per baris: nama barang, dan kuantitas pada kolom gudang PERTAMA.
 *
 * MENGAPA ANGKA PERTAMA
 * Laporannya punya dua kelompok kolom berjudul sama: satu untuk gudang yang
 * dipilih (mis. "GUDANG UTAMA") dan satu lagi "Total Nama Gudang". Keduanya
 * memakai sub-judul "Kuantitas" dan "Total Biaya". Kelompok gudangnya selalu
 * di kiri, jadi angka PERTAMA pada tiap baris data adalah kuantitas yang
 * dicari. Nama gudangnya ikut dikembalikan supaya bisa ditunjukkan ke
 * pemakai sebelum datanya dipakai.
 *
 * Baris disusun ulang dari koordinat y, sama seperti pdf-parser.js — PDF
 * tidak menyimpan tabel, hanya potongan teks beserta letaknya. Tapi
 * pemotongan kolomnya TIDAK memakai posisi judul: judul kolom laporan ini
 * bertingkat dua dan keterangan cabangnya bisa sebaris dengan isi lain,
 * yang membuat batas kolom berbasis judul meleset. Bentuk barisnya sendiri
 * sudah cukup: nama di kiri sebagai teks, lalu angka-angka; yang pertama
 * adalah Kuantitas kelompok kolom paling kiri.
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
  // Hanya digit, titik, koma, dan tanda minus yang dianggap angka. Nama
  // seperti "CONE KERUCUT 22CM" tidak lolos karena memuat huruf.
  if(!/^-?[\d.,]+$/.test(t)) return null;
  if(!/\d/.test(t)) return null;
  const bersih = t.replace(/\./g, "").replace(",", ".");
  const n = parseFloat(bersih);
  return isNaN(n) ? null : n;
}

/**
 * Baris yang jelas bukan data barang: judul laporan, judul kolom,
 * keterangan cetak, nomor halaman, dan baris total di kaki tabel.
 *
 * Diperiksa terhadap SELURUH teks barisnya, bukan bagian namanya saja.
 * Judul kolom dua tingkat ("Nama Barang / GUDANG UTAMA / Total Nama Gudang"
 * lalu "Kuantitas / Total Biaya / …") harus tersaring di sini, kalau tidak
 * potongannya akan menempel di depan nama barang pertama.
 */
function bukanBarisDataAccurate(teks){
  const t = teks.trim();
  if(t === "") return true;
  return /(^|\s)(nama\s*barang|kuantitas|total\s*biaya|total\s*nama\s*gudang)(\s|$)/i.test(t)
      || /^(per\s*tgl|cabang\s*:|gudang\s*:|halaman|page)/i.test(t)
      || /kuantitas\s+barang\s+per\s+gudang/i.test(t)
      || /^total(\s|:|$)/i.test(t);
}

/**
 * Susun baris data dari kumpulan potongan teks.
 *
 * TIDAK memakai posisi judul kolom untuk memotong baris. Versi pertama
 * melakukan itu dan gagal pada berkas Accurate yang sebenarnya: judul
 * kolomnya bertingkat dua dan keterangan cabangnya sebaris dengan isi lain,
 * sehingga batas kolomnya salah dan hampir semua baris terbuang.
 *
 * Bentuk barisnya sendiri sudah cukup menentukan: nama barang selalu di
 * kiri sebagai teks, disusul angka-angka. Yang pertama dari angka-angka itu
 * adalah Kuantitas kelompok kolom paling kiri — yaitu gudang yang dipilih,
 * bukan kolom "Total Nama Gudang" yang ada di kanannya.
 */
function ambilBarisAccurate(baris){
  /* --- Nama gudang, hanya untuk ditunjukkan ke pemakai ------------------ */
  let gudang = "";
  for(let i = 0; i < baris.length && i < 40; i++){
    const teks = baris[i].map(c => c.text).join(" ");
    const m = teks.match(/Gudang\s*:\s*(.+)$/i);
    if(m){
      // Buang potongan angka yang kebetulan sebaris dengan keterangan itu.
      gudang = m[1].split(/\s+/).filter(k => angkaAccurate(k) === null).join(" ").trim();
      if(gudang !== "") break;
    }
  }

  /* --- Baris data -------------------------------------------------------- */
  const rows = [];
  let sisaNama = "";
  let dilewati = 0;
  let adaJudul = false;

  for(let i = 0; i < baris.length; i++){
    const sel = baris[i];
    const teksPenuh = sel.map(c => c.text).join(" ").replace(/\s+/g, " ").trim();

    if(bukanBarisDataAccurate(teksPenuh)){
      // Nama yang menggantung dibuang: judul halaman berikutnya berarti
      // baris sebelumnya memang sudah selesai.
      sisaNama = "";
      if(/kuantitas/i.test(teksPenuh)) adaJudul = true;
      continue;
    }

    // Pisahkan bagian nama (teks di kiri) dari angka-angka di kanannya.
    let bagianNama = [];
    const angka = [];
    let sudahAngka = false;

    for(let j = 0; j < sel.length; j++){
      const n = angkaAccurate(sel[j].text);
      if(!sudahAngka && n === null){
        const t = sel[j].text.trim();
        if(t !== "") bagianNama.push(t);
      } else if(n !== null){
        sudahAngka = true;
        angka.push(n);
      }
      // Teks bukan angka SESUDAH angka pertama diabaikan: di laporan ini
      // tidak ada kolom teks di kanan, jadi itu pasti sisa hiasan tabel.
    }

    const nama = bagianNama.join(" ").replace(/\s+/g, " ").trim();

    if(!angka.length){
      // Baris tanpa angka: nama yang turun ke baris berikutnya.
      if(nama !== "") sisaNama = (sisaNama ? sisaNama + " " : "") + nama;
      continue;
    }

    const namaPenuh = ((sisaNama ? sisaNama + " " : "") + nama).replace(/\s+/g, " ").trim();
    sisaNama = "";

    if(namaPenuh === ""){
      dilewati++;      // ada angkanya tapi tanpa nama — tidak bisa dicocokkan
      continue;
    }
    rows.push({ nama: namaPenuh, qty: Math.round(angka[0]) });
  }

  if(!rows.length && !adaJudul){
    throw new Error("JUDUL_KOLOM_TIDAK_DITEMUKAN");
  }

  return { gudang: gudang, rows: rows, dilewati: dilewati };
}
