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
 * Berapa kolom angka yang dipunyai laporan ini, dibaca dari judul kolomnya.
 *
 * Laporan aslinya punya empat: Kuantitas + Total Biaya untuk gudang yang
 * dipilih, lalu sepasang lagi untuk "Total Nama Gudang". Angkanya dipakai
 * sebagai batas saat sebuah baris harus dipotong dari teksnya sendiri —
 * lihat ambilDariTeks().
 */
function jumlahKolomAngka(baris){
  for(let i = 0; i < baris.length; i++){
    const teks = baris[i].map(c => c.text).join(" ");
    if(!/kuantitas/i.test(teks)) continue;
    const k = (teks.match(/kuantitas/gi) || []).length;
    const b = (teks.match(/total\s*biaya/gi) || []).length;
    if(k + b >= 2) return k + b;
  }
  return 4;   // bentuk baku laporan ini
}

/**
 * Potong satu baris dari teksnya, bukan dari sel-selnya.
 *
 * Dipakai bila pdf.js mengirim seluruh baris sebagai SATU potongan teks —
 * hal yang bergantung pada cara PDF-nya dibuat, dan yang membuat pemotongan
 * per sel tidak menemukan angka sama sekali.
 *
 * Angkanya diambil dari BELAKANG sebanyak jumlah kolom angka, bukan dari
 * depan. Nama barang boleh berakhir dengan angka — "ADIDAS BOLA IMPOR EURO
 * 2024 SIZE 5" — dan menghitung dari depan akan memakan "5" sebagai
 * kuantitas. Dari belakang, empat angka terakhirlah kolomnya, dan sisanya
 * utuh sebagai nama.
 *
 * @returns {?{nama:string, qty:number}}
 */
function ambilDariTeks(teks, jumlahKolom){
  const kata = teks.trim().split(/\s+/);
  const angkaBelakang = [];

  for(let i = kata.length - 1; i >= 0 && angkaBelakang.length < jumlahKolom; i--){
    const n = angkaAccurate(kata[i]);
    if(n === null) break;
    angkaBelakang.unshift({ n: n, idx: i });
  }

  // Satu angka saja terlalu lemah: nama yang kebetulan berakhir angka akan
  // terbaca sebagai baris data palsu.
  if(angkaBelakang.length < 2) return null;

  const nama = kata.slice(0, angkaBelakang[0].idx).join(" ").trim();
  if(nama === "") return null;

  return { nama: nama, qty: Math.round(angkaBelakang[0].n) };
}

/**
 * Susun baris data dari kumpulan potongan teks.
 *
 * TIDAK memakai posisi judul kolom untuk memotong baris. Versi pertama
 * melakukan itu dan gagal pada berkas Accurate yang sebenarnya: judul
 * kolomnya bertingkat dua dan keterangan cabangnya sebaris dengan isi lain,
 * sehingga batas kolomnya salah dan hampir semua baris terbuang.
 *
 * Ada dua cara membaca satu baris, dicoba berurutan:
 *   1. lewat sel — nama di kiri sebagai teks, lalu angka-angka; yang
 *      pertama adalah Kuantitas kelompok kolom paling kiri;
 *   2. lewat teksnya sendiri, bila sel-selnya tidak memuat angka sama
 *      sekali karena seluruh baris datang sebagai satu potongan teks.
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

  const jumlahKolom = jumlahKolomAngka(baris);

  /* --- Baris data -------------------------------------------------------- */
  const rows = [];
  let sisaNama = "";
  let dilewati = 0;
  let adaJudul = false;

  for(let i = 0; i < baris.length; i++){
    const sel = baris[i];
    const teksPenuh = sel.map(c => c.text).join(" ").replace(/\s+/g, " ").trim();

    // Judul kelompok kolom kadang berdiri sendiri satu baris. Kalau
    // dibiarkan, namanya akan menempel di depan barang berikutnya —
    // persis yang membuat satu-satunya baris terbaca bernama "GUDANG UTAMA".
    const judulKelompok = gudang !== "" && teksPenuh.toUpperCase() === gudang.toUpperCase();

    if(judulKelompok || bukanBarisDataAccurate(teksPenuh)){
      sisaNama = "";
      if(/kuantitas/i.test(teksPenuh)) adaJudul = true;
      continue;
    }

    /* --- Cara 1: lewat sel --- */
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
    }

    if(angka.length){
      const nama = bagianNama.join(" ").replace(/\s+/g, " ").trim();
      const namaPenuh = ((sisaNama ? sisaNama + " " : "") + nama).replace(/\s+/g, " ").trim();
      sisaNama = "";
      if(namaPenuh === ""){
        dilewati++;
        continue;
      }
      rows.push({ nama: namaPenuh, qty: Math.round(angka[0]) });
      continue;
    }

    /* --- Cara 2: seluruh baris ternyata satu potongan teks --- */
    const dariTeks = ambilDariTeks(teksPenuh, jumlahKolom);
    if(dariTeks !== null){
      const namaPenuh = ((sisaNama ? sisaNama + " " : "") + dariTeks.nama)
        .replace(/\s+/g, " ").trim();
      sisaNama = "";
      rows.push({ nama: namaPenuh, qty: dariTeks.qty });
      continue;
    }

    // Tidak ada angka sama sekali: nama yang turun ke baris berikutnya.
    if(teksPenuh !== "") sisaNama = (sisaNama ? sisaNama + " " : "") + teksPenuh;
  }

  if(!rows.length && !adaJudul){
    throw new Error("JUDUL_KOLOM_TIDAK_DITEMUKAN");
  }

  return { gudang: gudang, rows: rows, dilewati: dilewati };
}
