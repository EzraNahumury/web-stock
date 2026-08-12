/* ==========================================================================
 * grafik.js — grafik SVG untuk dashboard, tanpa pustaka luar.
 *
 * Semuanya digambar langsung sebagai SVG: aplikasi ini tanpa build step,
 * dan menarik pustaka chart hanya untuk tiga panel tidak sepadan.
 *
 * Aturan yang dipegang:
 *   - satu ukuran dibanding antar kategori -> SATU warna, bukan palet
 *     kategorikal. Warna kategorikal untuk identitas seri yang tumpang
 *     tindih; batang kategori tidak tumpang tindih.
 *   - warna status (kritis/menipis/aman) dicadangkan untuk status, selalu
 *     disertai label — tidak pernah warna saja.
 *   - sumbu dan grid dibuat tipis dan surut; angka memakai warna teks,
 *     bukan warna seri.
 *   - tiap grafik punya lapisan hover: tooltip per batang, crosshair pada
 *     grafik garis.
 * ========================================================================== */

const Grafik = (function(){
  "use strict";

  const NS = "http://www.w3.org/2000/svg";

  /* Warna khusus data. Hijau di sini (#0E8060) lebih pekat daripada hijau
     antarmuka (#25725C): tanda data tipis butuh chroma lebih tinggi agar
     terbaca, dan versi ini lolos pemeriksaan keterbacaan buta warna. */
  const W = {
    kritis:  "#B23A2E",
    rendah:  "#C77F0E",
    aman:    "#0E8060",
    belum:   "#B7C3C8",
    data:    "#2F6B7F",   // batang ukuran tunggal
    dataLo:  "#DCE6EA",
    masuk:   "#0E8060",
    keluar:  "#B23A2E",
    grid:    "#E6ECEE",
    sumbu:   "#C1CCD0",
    teks:    "#5C6E76",
    teksLo:  "#8B9BA3"
  };

  function el(nama, atribut){
    const n = document.createElementNS(NS, nama);
    for(const k in atribut){
      if(atribut[k] !== null && atribut[k] !== undefined) n.setAttribute(k, atribut[k]);
    }
    return n;
  }
  function fmt(n){ return Number(n||0).toLocaleString("id-ID"); }
  function esc(s){ return String(s==null?"":s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }

  function kurangiGerak(){
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  /* ---------------------------------------------------------------- */
  /* Tooltip bersama                                                   */
  /* ---------------------------------------------------------------- */
  let tipEl = null;
  function tip(){
    if(!tipEl){
      tipEl = document.createElement("div");
      tipEl.className = "gtip";
      tipEl.setAttribute("role", "status");
      document.body.appendChild(tipEl);
    }
    return tipEl;
  }
  function tampilTip(html, x, y){
    const t = tip();
    t.innerHTML = html;
    t.classList.add("tampak");
    const r = t.getBoundingClientRect();
    let kiri = x - r.width / 2;
    kiri = Math.max(8, Math.min(kiri, window.innerWidth - r.width - 8));
    let atas = y - r.height - 12;
    if(atas < 8) atas = y + 18;              // balik ke bawah bila mentok
    t.style.left = kiri + "px";
    t.style.top  = atas + "px";
  }
  function sembunyiTip(){
    if(tipEl) tipEl.classList.remove("tampak");
  }
  document.addEventListener("scroll", sembunyiTip, true);

  /* ================================================================== */
  /* 1. Batang bertumpuk — sebaran status                               */
  /*                                                                    */
  /* Dipilih daripada donat: satu bagian menguasai ~75%, dan pada donat  */
  /* tiga bagian sisanya menyusut jadi irisan yang tak terbaca.          */
  /* ================================================================== */
  function statusBar(wadah, data){
    const bagian = [
      { kunci:"kritis",       label:"Perlu order", nilai:data.kritis,       warna:W.kritis },
      { kunci:"rendah",       label:"Menipis",     nilai:data.rendah,       warna:W.rendah },
      { kunci:"aman",         label:"Aman",        nilai:data.aman,         warna:W.aman },
      { kunci:"belum_diatur", label:"Belum diatur",nilai:data.belum_diatur, warna:W.belum }
    ].filter(b => b.nilai > 0);

    const total = bagian.reduce((s,b)=>s+b.nilai, 0);
    if(!total){
      wadah.innerHTML = '<div class="g-kosong">Belum ada barang untuk dihitung.</div>';
      return;
    }

    const T = 30, GAP = 2;
    const svg = el("svg", { viewBox:"0 0 100 " + T, preserveAspectRatio:"none",
                            class:"g-statusbar", role:"img",
                            "aria-label":"Sebaran status stok: " +
                              bagian.map(b => b.label + " " + b.nilai).join(", ") });

    let x = 0;
    bagian.forEach((b, i) => {
      const lebarPenuh = (b.nilai / total) * 100;
      // Sisakan celah 2px antar segmen, tapi jangan sampai segmen tipis hilang.
      const lebar = Math.max(lebarPenuh - (i < bagian.length-1 ? GAP*100/wadah.clientWidth : 0), 0.6);
      const r = el("rect", {
        x: x, y: 0, width: lebar, height: T, rx: 0.6,
        fill: b.warna, class: "g-seg", "data-kunci": b.kunci
      });
      r.style.setProperty("--w", lebar);
      if(!kurangiGerak()){
        r.style.transformOrigin = "left center";
        r.style.animation = "gTumbuhX .55s cubic-bezier(.22,.68,.36,1) both";
        r.style.animationDelay = (i * 70) + "ms";
      }
      const persen = (b.nilai / total * 100);
      r.addEventListener("pointerenter", ev => {
        tampilTip('<b>' + esc(b.label) + '</b><span>' + fmt(b.nilai) + ' item · ' +
                  persen.toFixed(persen < 1 ? 1 : 0) + '%</span>', ev.clientX, ev.clientY);
      });
      r.addEventListener("pointerleave", sembunyiTip);
      svg.appendChild(r);
      x += lebarPenuh;
    });

    // Rincian di bawah batang. Warna tidak pernah berdiri sendiri: tiap
    // baris membawa label, jumlah, persentase, dan arti keadaannya.
    const arti = {
      kritis:       "stok di bawah atau sama dengan ambang minimal",
      rendah:       "stok masih di atas ambang, tapi tinggal sedikit",
      aman:         "stok jauh di atas ambang minimal",
      belum_diatur: "ambang minimalnya masih 0, belum bisa dinilai"
    };
    const rinci = document.createElement("div");
    rinci.className = "g-status-rinci";
    rinci.innerHTML = bagian.map(b => {
      const persen = b.nilai / total * 100;
      return '<div class="g-status-baris">'
        + '<i style="background:' + b.warna + '"></i>'
        + '<span class="g-status-teks">'
          + '<span class="g-status-label">' + esc(b.label) + '</span>'
          + '<span class="g-status-arti">' + esc(arti[b.kunci] || "") + '</span>'
        + '</span>'
        + '<span class="g-status-angka">'
          + '<span class="g-status-jml">' + fmt(b.nilai) + '</span>'
          + '<span class="g-status-pct">' + persen.toFixed(persen < 1 ? 1 : 0) + '%</span>'
        + '</span>'
        + '</div>';
    }).join("");

    wadah.innerHTML = "";
    wadah.appendChild(svg);
    wadah.appendChild(rinci);
  }

  /* ================================================================== */
  /* 2. Batang mendatar — stok per kategori                             */
  /*                                                                    */
  /* Satu ukuran (unit) antar kategori. Satu warna saja; panjang batang  */
  /* yang membawa informasi, bukan rona.                                */
  /* ================================================================== */
  function kategoriBar(wadah, baris, opsi){
    opsi = opsi || {};
    if(!baris || !baris.length){
      wadah.innerHTML = '<div class="g-kosong">Belum ada kategori terisi.</div>';
      return;
    }
    const maks = Math.max.apply(null, baris.map(b => b.unit)) || 1;

    const tbl = document.createElement("div");
    tbl.className = "g-bar-list";

    baris.forEach((b, i) => {
      const pct = Math.max((b.unit / maks) * 100, b.unit > 0 ? 1.5 : 0);
      const row = document.createElement("div");
      row.className = "g-bar-row";
      row.innerHTML =
        '<span class="g-bar-label" title="' + esc(b.kategori) + '">' + esc(b.kategori) + '</span>'
        + '<span class="g-bar-track"><span class="g-bar-fill"></span></span>'
        + '<span class="g-bar-val">' + fmt(b.unit) + '</span>';

      const isi = row.querySelector(".g-bar-fill");
      isi.style.background = W.data;
      if(kurangiGerak()){
        isi.style.width = pct + "%";
      } else {
        isi.style.width = "0%";
        isi.style.transition = "width .7s cubic-bezier(.22,.68,.36,1)";
        setTimeout(()=>{ isi.style.width = pct + "%"; }, 60 + i * 55);
      }

      row.addEventListener("pointerenter", ev => {
        tampilTip('<b>' + esc(b.kategori) + '</b>'
          + '<span>' + fmt(b.unit) + ' unit · ' + fmt(b.sku) + ' SKU</span>'
          + (b.kritis ? '<span class="tip-kritis">' + fmt(b.kritis) + ' perlu order</span>' : ''),
          ev.clientX, ev.clientY);
      });
      row.addEventListener("pointermove", ev => {
        const t = tip();
        if(t.classList.contains("tampak")) tampilTip(t.innerHTML, ev.clientX, ev.clientY);
      });
      row.addEventListener("pointerleave", sembunyiTip);

      if(opsi.onPilih){
        row.classList.add("bisa-klik");
        row.tabIndex = 0;
        row.setAttribute("role", "button");
        row.addEventListener("click", ()=> opsi.onPilih(b.kategori));
        row.addEventListener("keydown", ev => {
          if(ev.key === "Enter" || ev.key === " "){ ev.preventDefault(); opsi.onPilih(b.kategori); }
        });
      }
      tbl.appendChild(row);
    });

    wadah.innerHTML = "";
    wadah.appendChild(tbl);
  }

  /* ================================================================== */
  /* 3. Grafik area — pergerakan masuk vs keluar                        */
  /*                                                                    */
  /* Dua seri pada SATU sumbu (keduanya dalam unit), bukan sumbu ganda.  */
  /* Hijau = masuk, merah = keluar: mengikuti konvensi yang sudah        */
  /* dipakai tabel dashboard, jadi arah aliran terbaca tanpa legenda —   */
  /* tapi legendanya tetap ada.                                          */
  /* ================================================================== */
  function pergerakanArea(wadah, baris, adaData){
    if(!baris || !baris.length){
      wadah.innerHTML = '<div class="g-kosong">Belum ada data pergerakan.</div>';
      return;
    }
    if(!adaData){
      wadah.innerHTML =
        '<div class="g-kosong g-kosong-ajak">'
        + '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">'
        + '<path d="M3 17l5-6 4 4 5-7 4 5"/><line x1="3" y1="21" x2="21" y2="21"/></svg>'
        + '<b>Belum ada pergerakan tercatat</b>'
        + '<span>Grafik ini terisi setelah ada barang masuk atau keluar dicatat.</span>'
        + '</div>';
      return;
    }

    const L = 44, R = 12, A = 12, B = 26;         // margin
    const W_ = 640, H_ = 210;
    const pw = W_ - L - R, ph = H_ - A - B;

    const maks = Math.max(1, Math.max.apply(null, baris.map(d => Math.max(d.masuk, d.keluar))));
    // Bulatkan batas atas ke angka enak supaya label sumbu tidak berantakan.
    const tingkat = Math.pow(10, Math.floor(Math.log10(maks)));
    const atas = Math.ceil(maks / tingkat) * tingkat;

    const px = i => L + (baris.length === 1 ? pw/2 : (i / (baris.length - 1)) * pw);
    const py = v => A + ph - (v / atas) * ph;

    const svg = el("svg", { viewBox:"0 0 " + W_ + " " + H_, class:"g-area",
                            role:"img", "aria-label":"Pergerakan stok harian, masuk dan keluar" });

    const defs = el("defs");
    [["gMasuk", W.masuk], ["gKeluar", W.keluar]].forEach(([id, warna]) => {
      const g = el("linearGradient", { id:id, x1:"0", y1:"0", x2:"0", y2:"1" });
      g.appendChild(el("stop", { offset:"0%",   "stop-color":warna, "stop-opacity":".22" }));
      g.appendChild(el("stop", { offset:"100%", "stop-color":warna, "stop-opacity":"0" }));
      defs.appendChild(g);
    });
    svg.appendChild(defs);

    // Grid mendatar + label sumbu y (surut).
    for(let i = 0; i <= 4; i++){
      const v = (atas / 4) * i, y = py(v);
      svg.appendChild(el("line", { x1:L, y1:y, x2:L+pw, y2:y, stroke:W.grid, "stroke-width":1 }));
      const t = el("text", { x:L-8, y:y+3.5, "text-anchor":"end", fill:W.teksLo,
                             "font-size":"9.5", "font-family":"'IBM Plex Mono',monospace" });
      t.textContent = v >= 1000 ? (v/1000) + "k" : v;
      svg.appendChild(t);
    }

    function jalur(kunci, isiId, warna){
      let d = "", area = "";
      baris.forEach((r, i) => {
        const x = px(i), y = py(r[kunci]);
        d    += (i ? "L" : "M") + x.toFixed(1) + " " + y.toFixed(1);
        area += (i ? "L" : "M") + x.toFixed(1) + " " + y.toFixed(1);
      });
      area += "L" + px(baris.length-1).toFixed(1) + " " + (A+ph) + "L" + px(0).toFixed(1) + " " + (A+ph) + "Z";

      svg.appendChild(el("path", { d:area, fill:"url(#" + isiId + ")" }));
      const garis = el("path", { d:d, fill:"none", stroke:warna, "stroke-width":2,
                                 "stroke-linecap":"round", "stroke-linejoin":"round" });
      if(!kurangiGerak()){
        const p = garis.getTotalLength ? 2000 : 2000;
        garis.style.strokeDasharray = p;
        garis.style.strokeDashoffset = p;
        garis.style.animation = "gGaris 1.1s cubic-bezier(.22,.68,.36,1) forwards";
      }
      svg.appendChild(garis);
    }
    jalur("masuk", "gMasuk", W.masuk);
    jalur("keluar", "gKeluar", W.keluar);

    // Label sumbu x: hanya beberapa, supaya tidak bertabrakan.
    const langkah = Math.max(1, Math.ceil(baris.length / 6));
    baris.forEach((r, i) => {
      if(i % langkah !== 0 && i !== baris.length-1) return;
      const t = el("text", { x:px(i), y:H_-8, "text-anchor":"middle", fill:W.teksLo,
                             "font-size":"9.5", "font-family":"'IBM Plex Mono',monospace" });
      const d = new Date(r.tanggal + "T00:00:00");
      t.textContent = isNaN(d) ? r.tanggal
        : d.toLocaleDateString("id-ID", { day:"2-digit", month:"short" });
      svg.appendChild(t);
    });

    // Lapisan hover: crosshair + penanda + tooltip.
    const garisBidik = el("line", { x1:0, y1:A, x2:0, y2:A+ph, stroke:W.sumbu,
                                    "stroke-width":1, "stroke-dasharray":"3 3", opacity:0 });
    const dotM = el("circle", { r:4.5, fill:W.masuk,  stroke:"#fff", "stroke-width":2, opacity:0 });
    const dotK = el("circle", { r:4.5, fill:W.keluar, stroke:"#fff", "stroke-width":2, opacity:0 });
    svg.appendChild(garisBidik); svg.appendChild(dotM); svg.appendChild(dotK);

    const tangkap = el("rect", { x:L, y:A, width:pw, height:ph, fill:"transparent", style:"cursor:crosshair" });
    tangkap.addEventListener("pointermove", ev => {
      const kotak = svg.getBoundingClientRect();
      const rel = (ev.clientX - kotak.left) / kotak.width * W_;
      let i = Math.round(((rel - L) / pw) * (baris.length - 1));
      i = Math.max(0, Math.min(baris.length - 1, i));
      const r = baris[i], x = px(i);

      garisBidik.setAttribute("x1", x); garisBidik.setAttribute("x2", x);
      garisBidik.setAttribute("opacity", 1);
      dotM.setAttribute("cx", x); dotM.setAttribute("cy", py(r.masuk));  dotM.setAttribute("opacity", 1);
      dotK.setAttribute("cx", x); dotK.setAttribute("cy", py(r.keluar)); dotK.setAttribute("opacity", 1);

      const d = new Date(r.tanggal + "T00:00:00");
      const tgl = isNaN(d) ? r.tanggal : d.toLocaleDateString("id-ID", { day:"2-digit", month:"short", year:"numeric" });
      tampilTip('<b>' + esc(tgl) + '</b>'
        + '<span class="tip-baris"><i style="background:' + W.masuk + '"></i>Masuk <b>' + fmt(r.masuk) + '</b></span>'
        + '<span class="tip-baris"><i style="background:' + W.keluar + '"></i>Keluar <b>' + fmt(r.keluar) + '</b></span>',
        ev.clientX, ev.clientY);
    });
    tangkap.addEventListener("pointerleave", () => {
      garisBidik.setAttribute("opacity", 0);
      dotM.setAttribute("opacity", 0);
      dotK.setAttribute("opacity", 0);
      sembunyiTip();
    });
    svg.appendChild(tangkap);

    const leg = document.createElement("div");
    leg.className = "g-legenda";
    leg.innerHTML =
      '<span class="g-leg"><i style="background:' + W.masuk  + '"></i>Barang masuk</span>' +
      '<span class="g-leg"><i style="background:' + W.keluar + '"></i>Barang keluar</span>';

    wadah.innerHTML = "";
    wadah.appendChild(svg);
    wadah.appendChild(leg);
  }

  /* ================================================================== */
  /* 4. Angka yang menghitung naik                                      */
  /* ================================================================== */
  function angkaNaik(node, sampai, durasi){
    sampai = Number(sampai) || 0;
    if(kurangiGerak() || sampai === 0){
      node.textContent = fmt(sampai);
      return;
    }
    durasi = durasi || 750;
    const mulai = performance.now();
    function langkah(t){
      const p = Math.min((t - mulai) / durasi, 1);
      const e = 1 - Math.pow(1 - p, 3);           // ease-out cubic
      node.textContent = fmt(Math.round(sampai * e));
      if(p < 1) requestAnimationFrame(langkah);
    }
    requestAnimationFrame(langkah);
  }

  return {
    statusBar: statusBar,
    kategoriBar: kategoriBar,
    pergerakanArea: pergerakanArea,
    angkaNaik: angkaNaik,
    warna: W
  };
})();
