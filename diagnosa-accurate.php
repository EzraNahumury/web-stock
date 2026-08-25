<?php
/**
 * diagnosa-accurate.php — alat bantu membaca PDF Accurate.
 *
 * Dipakai hanya bila tombol "PDF Accurate" di Laporan stok opname tidak
 * menemukan barang yang cocok: halaman ini menunjukkan apa yang sebenarnya
 * terbaca dari berkasnya, beserta koordinat potongan teksnya, supaya
 * pembacanya bisa diperbaiki dari data nyata alih-alih ditebak.
 *
 * Berkasnya dibaca sepenuhnya di browser dan tidak pernah diunggah.
 * Halamannya sendiri tetap butuh sesi: tidak ada alasan alat internal
 * bisa dibuka siapa pun yang tahu alamatnya.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
wajibLoginHalaman();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Diagnosa PDF Accurate — Warehouse AVA</title>
<style>
  body{ font-family:system-ui,sans-serif; background:#EEF2F4; color:#101A20;
        margin:0; padding:24px; line-height:1.5; }
  .wrap{ max-width:1100px; margin:0 auto; }
  h1{ font-size:22px; margin:0 0 4px; }
  .sub{ color:#5C6E76; font-size:13px; margin-bottom:20px; }
  .kotak{ background:#fff; border:1px solid #E1E8EA; border-radius:12px;
          padding:16px 18px; margin-bottom:14px; }
  h2{ font-size:15px; margin:0 0 10px; }
  pre{ background:#16242C; color:#DDE7EA; padding:12px; border-radius:8px;
       overflow:auto; font-size:11.5px; line-height:1.45; max-height:460px; margin:0; }
  .ambil{ display:inline-flex; align-items:center; gap:8px; cursor:pointer;
          background:#101A20; color:#fff; border-radius:8px; padding:10px 16px;
          font-size:14px; font-weight:600; }
  .catat{ background:#FBF0DC; border:1px solid #EAC280; color:#8a5a08;
          border-radius:8px; padding:10px 14px; font-size:12.5px; margin-bottom:14px; }
  .salin{ background:#fff; border:1px solid #C9D4D8; border-radius:8px;
          padding:7px 13px; font-size:13px; cursor:pointer; margin-top:10px; }
  .ok{ color:#25725C; font-weight:600; }
  .bad{ color:#B23A2E; font-weight:600; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Diagnosa PDF Accurate</h1>
  <div class="sub">Untuk laporan <b>Kuantitas Barang per Gudang</b>. Dipakai hanya
    kalau tombol “PDF Accurate” di Laporan stok opname tidak menemukan barang yang cocok.</div>

  <div class="catat">
    Berkasnya <b>tidak diunggah ke mana pun</b> — seluruhnya dibaca di browser ini.
    Hasil di bawah memuat nama barang dan angka kuantitas, tapi <b>tidak</b> memuat
    kolom biaya. Periksa dulu sebelum menyalinnya ke orang lain.
  </div>

  <div class="kotak">
    <label class="ambil">Pilih PDF Accurate
      <input type="file" accept="application/pdf,.pdf" id="berkas" style="display:none">
    </label>
    <span id="status" style="margin-left:12px; font-size:13px; color:#5C6E76;"></span>
  </div>

  <div class="kotak">
    <h2>1. Hasil pembacaan</h2>
    <pre id="hasil">Belum ada berkas.</pre>
    <button class="salin" onclick="salin('hasil')">Salin</button>
  </div>

  <div class="kotak">
    <h2>2. Potongan teks mentah beserta koordinatnya</h2>
    <div class="sub" style="margin:0 0 8px;">30 baris pertama. Ini yang dibutuhkan
      untuk memperbaiki pembacanya bila hasil di atas salah.</div>
    <pre id="mentah">Belum ada berkas.</pre>
    <button class="salin" onclick="salin('mentah')">Salin</button>
  </div>

</div>

<script src="assets/vendor/pdf.min.js"></script>
<script src="assets/js/accurate-parser.js"></script>
<script>
function salin(id){
  const t = document.getElementById(id).textContent;
  navigator.clipboard.writeText(t).then(
    () => alert("Tersalin. Tempel ke chat."),
    () => alert("Gagal menyalin — pilih teksnya lalu salin manual.")
  );
}

document.getElementById("berkas").addEventListener("change", async function(ev){
  const f = ev.target.files && ev.target.files[0];
  if(!f) return;
  document.getElementById("status").textContent = "Membaca " + f.name + "…";

  const buf = await f.arrayBuffer();

  /* --- Potongan mentah, sebelum ditafsirkan sama sekali --- */
  try{
    const pdf = await pdfjsLib.getDocument({ data: buf.slice(0) }).promise;
    let keluar = "Halaman: " + pdf.numPages + "\n\n";
    let hitung = 0;

    for(let p = 1; p <= pdf.numPages && hitung < 30; p++){
      const page = await pdf.getPage(p);
      const isi = await page.getTextContent();
      const items = isi.items
        .map(it => ({ text:(it.str||""), x:it.transform[4], y:it.transform[5] }))
        .filter(it => it.text.trim() !== "");
      items.sort((a,b) => (b.y - a.y) || (a.x - b.x));

      let baris = [], yKini = null, kini = [];
      items.forEach(it => {
        if(yKini === null || Math.abs(it.y - yKini) <= 3.5){
          kini.push(it);
          if(yKini === null) yKini = it.y;
        } else { baris.push(kini.sort((a,b)=>a.x-b.x)); kini = [it]; yKini = it.y; }
      });
      if(kini.length) baris.push(kini.sort((a,b)=>a.x-b.x));

      for(let i = 0; i < baris.length && hitung < 30; i++, hitung++){
        keluar += "hal " + p + " baris " + String(i).padStart(3) + " : "
          + baris[i].map(c => "[x=" + c.x.toFixed(1) + " y=" + c.y.toFixed(1)
              + " \"" + c.text + "\"]").join(" ") + "\n";
      }
    }
    document.getElementById("mentah").textContent = keluar;
  }catch(e){
    document.getElementById("mentah").textContent = "Gagal membaca PDF: " + e.message;
  }

  /* --- Hasil parser yang dipakai aplikasi --- */
  try{
    const r = await parsePdfAccurate(buf.slice(0));
    let keluar = "Gudang terbaca : " + JSON.stringify(r.gudang) + "\n";
    keluar += "Baris terbaca  : " + r.rows.length + "\n";
    keluar += "Dilewati       : " + r.dilewati + "\n\n";
    keluar += r.rows.map(x => String(x.qty).padStart(6) + "  " + x.nama).join("\n");
    document.getElementById("hasil").textContent = keluar;
    document.getElementById("status").innerHTML = r.rows.length
      ? '<span class="ok">' + r.rows.length + ' baris terbaca.</span>'
      : '<span class="bad">Tidak ada baris terbaca.</span>';
  }catch(e){
    document.getElementById("hasil").textContent = "Parser gagal: " + e.message;
    document.getElementById("status").innerHTML = '<span class="bad">Parser gagal.</span>';
  }
});
</script>
</body>
</html>
