/* ==========================================================================
 * app.js — antarmuka Papan Kendali Gudang
 *
 * Diturunkan dari prototipe "aplikasi-gudang (2).html". Struktur render,
 * markup, dan tampilannya dipertahankan sama; yang berubah hanya sumber
 * datanya: dari variabel global menjadi endpoint API (lihat api.js).
 *
 * Pola render parsial prototipe DIPERTAHANKAN: saat memfilter, hanya wadah
 * hasil yang diganti, bukan seluruh #content — kalau seluruh #content
 * diganti, fokus kotak pencarian hilang setiap ketikan.
 * ========================================================================== */

/* ---------------------------------------------------------------- */
/* State                                                             */
/* ---------------------------------------------------------------- */
let tab = "dashboard";

let dashFilters   = { q:"", kategori:"Semua", status:"semua", page:1 };
let masterFilters = { q:"", page:1 };
let trxFilters    = { masuk:{ q:"", dari:"", sampai:"", page:1 },
                      keluar:{ q:"", dari:"", sampai:"", page:1 } };

let kategoriOptions = [];
let editingMasterId = null;

let pdfImport = { status:"idle", header:null, rows:[], fileName:"", fileHash:"",
                  tanggal:"", cocok:{}, duplikat:null };

if(window["pdfjsLib"]){
  pdfjsLib.GlobalWorkerOptions.workerSrc = "assets/vendor/pdf.worker.min.js";
}

/* ---------------------------------------------------------------- */
/* Helpers — sama seperti prototipe                                  */
/* ---------------------------------------------------------------- */
function todayISO(){
  // Pakai waktu lokal, bukan toISOString() yang berbasis UTC — di WIB
  // toISOString() sebelum jam 07:00 menghasilkan tanggal kemarin.
  const d = new Date();
  const p = n => String(n).padStart(2,"0");
  return d.getFullYear() + "-" + p(d.getMonth()+1) + "-" + p(d.getDate());
}
function fmtDate(iso){
  if(!iso) return "-";
  const d = new Date(iso+"T00:00:00");
  if(isNaN(d)) return iso;
  return d.toLocaleDateString("id-ID",{day:"2-digit",month:"short",year:"numeric"});
}
function fmtNum(n){ return Number(n||0).toLocaleString("id-ID"); }
function esc(s){ return String(s==null?"":s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }
function $(id){ return document.getElementById(id); }

function svgIcon(name){
  const icons = {
    search:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    alert:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    trash:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>',
    plus:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    edit:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
    check:'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
    x:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    download:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
  };
  return icons[name] || "";
}

function toast(msg, jenis){
  const w = $("toastWrap");
  const div = document.createElement("div");
  div.className = "toast" + (jenis === "err" ? " err" : "");
  div.textContent = msg;
  w.appendChild(div);
  setTimeout(()=> { if(div.parentNode) w.removeChild(div); }, jenis === "err" ? 4200 : 2200);
}

function setSaveStatus(state, pesan){
  const el = $("saveStatus");
  if(!el) return;
  if(state === "saving") el.innerHTML = "Menyimpan…";
  else if(state === "error") el.innerHTML = esc(pesan || "Gagal menyimpan — coba lagi.");
  else el.innerHTML = svgIcon("check") + " Tersimpan di server";
}

/** Tampilkan galat API secara seragam, termasuk daftar detailnya. */
function tampilGalat(e){
  console.error(e);
  let pesan = e && e.message ? e.message : "Terjadi kesalahan.";
  if(e && e.detail && e.detail.length){
    pesan += " — " + e.detail.slice(0,3).join(" | ");
    if(e.detail.length > 3) pesan += " (+" + (e.detail.length-3) + " lainnya)";
  }
  toast(pesan, "err");
  setSaveStatus("error", "Gagal menyimpan");
}

/** Tunda pemanggilan agar tiap ketikan tidak langsung memanggil server. */
function debounce(fn, ms){
  let t = null;
  return function(){
    const args = arguments, self = this;
    clearTimeout(t);
    t = setTimeout(()=> fn.apply(self, args), ms || 300);
  };
}

/* ---------------------------------------------------------------- */
/* Dialog konfirmasi (audit F2)                                      */
/* ---------------------------------------------------------------- */
function konfirmasi(judul, pesan, labelYa){
  return new Promise(resolve => {
    const bg = document.createElement("div");
    bg.className = "modal-bg";
    bg.innerHTML =
      '<div class="modal" role="dialog" aria-modal="true">'
      + '<h3>' + esc(judul) + '</h3>'
      + '<p>' + esc(pesan) + '</p>'
      + '<div class="modal-act">'
      + '<button type="button" class="btn ghost" data-act="batal">Batal</button>'
      + '<button type="button" class="btn danger-btn" data-act="ya">' + esc(labelYa || "Hapus") + '</button>'
      + '</div></div>';

    function tutup(hasil){
      document.removeEventListener("keydown", onKey);
      if(bg.parentNode) bg.parentNode.removeChild(bg);
      resolve(hasil);
    }
    function onKey(ev){ if(ev.key === "Escape") tutup(false); }

    bg.addEventListener("click", ev => {
      const act = ev.target.getAttribute && ev.target.getAttribute("data-act");
      if(act === "ya") tutup(true);
      else if(act === "batal" || ev.target === bg) tutup(false);
    });
    document.addEventListener("keydown", onKey);
    document.body.appendChild(bg);
    const tombol = bg.querySelector('[data-act="ya"]');
    if(tombol) tombol.focus();
  });
}

/**
 * Modal isi bebas (bukan konfirmasi). Mengembalikan objek dengan .tutup()
 * dan .isi(html) supaya isinya bisa diganti setelah data tiba.
 */
function modalKonten(lebar){
  const bg = document.createElement("div");
  bg.className = "modal-bg";
  bg.innerHTML = '<div class="modal' + (lebar ? ' lebar' : '') + '" role="dialog" aria-modal="true"></div>';
  const kotak = bg.firstChild;

  function tutup(){
    document.removeEventListener("keydown", onKey);
    if(bg.parentNode) bg.parentNode.removeChild(bg);
  }
  function onKey(ev){ if(ev.key === "Escape") tutup(); }

  bg.addEventListener("click", ev => {
    if(ev.target === bg) tutup();
    const act = ev.target.closest && ev.target.closest('[data-act="tutup"]');
    if(act) tutup();
  });
  document.addEventListener("keydown", onKey);
  document.body.appendChild(bg);

  return {
    tutup: tutup,
    isi: function(html){ kotak.innerHTML = html; },
    el: kotak
  };
}

/* ---------------------------------------------------------------- */
/* Popup riwayat transaksi per barang                                */
/* ---------------------------------------------------------------- */
let riwayatState = { masterId:0, jenis:"masuk", page:1, modal:null };

async function bukaRiwayat(masterId, jenis, nama){
  riwayatState = { masterId: masterId, jenis: jenis, page: 1, modal: modalKonten(true) };
  riwayatState.modal.isi(
    '<div class="modal-head"><h3>' + esc(nama || "Riwayat") + '</h3>'
    + '<button type="button" class="icon-btn" data-act="tutup" aria-label="Tutup">' + svgIcon("x") + '</button></div>'
    + '<div class="modal-kosong">Memuat riwayat…</div>'
  );
  await muatRiwayat();
}

function riwayatGoPage(p){
  riwayatState.page = p;
  muatRiwayat();
}

async function muatRiwayat(){
  const st = riwayatState;
  if(!st.modal) return;

  let d;
  try{
    d = await API.get("master/riwayat.php", {
      master_id: st.masterId, jenis: st.jenis, page: st.page
    });
  }catch(e){
    st.modal.isi(
      '<div class="modal-head"><h3>Gagal memuat</h3>'
      + '<button type="button" class="icon-btn" data-act="tutup" aria-label="Tutup">' + svgIcon("x") + '</button></div>'
      + '<p>' + esc(e.message || "Terjadi kesalahan.") + '</p>'
      + '<div class="modal-act"><button type="button" class="btn ghost" data-act="tutup">Tutup</button></div>'
    );
    return;
  }

  const isKel  = d.jenis === "keluar";
  const judul  = isKel ? "Riwayat barang keluar" : "Riwayat barang masuk";
  const tanda  = isKel ? "−" : "+";
  const kelas  = isKel ? "keluar" : "masuk";

  let html = '<div class="modal-head"><div>'
    + '<h3>' + esc(judul) + '</h3>'
    + '<div style="font-size:13.5px; font-weight:600; margin-top:2px;">' + esc(d.item.nama) + '</div>'
    + '</div>'
    + '<button type="button" class="icon-btn" data-act="tutup" aria-label="Tutup">' + svgIcon("x") + '</button>'
    + '</div>';

  html += '<div class="modal-sub">' + esc(d.item.sku || "-") + ' · ' + esc(d.item.barcode) + '</div>';

  html += '<div class="chip-row">'
    + '<span class="chip">Total ' + esc(isKel ? "keluar" : "masuk") + ': <b>' + tanda + fmtNum(d.total_jumlah) + '</b> pcs</span>'
    + '<span class="chip"><b>' + fmtNum(d.total) + '</b> catatan</span>'
    + '<span class="chip">Stok awal: <b>' + fmtNum(d.item.stok_awal) + '</b></span>'
    + (d.item.kategori ? '<span class="chip">' + esc(d.item.kategori) + '</span>' : '')
    + '</div>';

  if(d.per_keterangan && d.per_keterangan.length > 1){
    html += '<div class="chip-row">'
      + d.per_keterangan.map(k =>
          '<span class="chip">' + esc(k.keterangan) + ': <b>' + fmtNum(k.unit) + '</b> pcs (' + fmtNum(k.jml) + 'x)</span>'
        ).join("")
      + '</div>';
  }

  if(!d.rows.length){
    html += '<div class="modal-kosong">Belum ada catatan barang '
      + esc(isKel ? "keluar" : "masuk") + ' untuk barang ini.</div>';
  } else {
    const kolom = ["Tanggal", "Jumlah", "Keterangan"]
      .concat(isKel ? ["No. Pesanan", "Asal"] : [])
      .concat(["Dicatat oleh"]);

    html += '<div class="modal-scroll"><table><thead><tr>'
      + kolom.map((h,i)=>'<th'+(i===1?' class="num"':'')+'>'+esc(h)+'</th>').join("")
      + '</tr></thead><tbody>'
      + d.rows.map(r => {
          let asal = "Input manual";
          if(isKel && r.batch_id){
            asal = "Impor PDF" + (r.no_picking ? " · " + esc(r.no_picking) : (r.nama_file ? " · " + esc(r.nama_file) : ""));
          }
          return '<tr>'
            + '<td style="white-space:nowrap">' + fmtDate(r.tanggal) + '</td>'
            + '<td class="num" style="font-weight:700; color:var(--' + (isKel ? 'danger' : 'safe') + ')">'
              + tanda + fmtNum(r.jumlah) + '</td>'
            + '<td style="color:var(--slate)">' + esc(r.keterangan) + '</td>'
            + (isKel ? '<td class="mono" style="font-size:11.5px; color:var(--slate)">' + esc(r.no_pesanan || "-") + '</td>' : '')
            + (isKel ? '<td style="font-size:11.5px; color:var(--slate)">' + asal + '</td>' : '')
            + '<td style="font-size:11.5px; color:var(--slate)">' + esc(r.oleh || "-") + '</td>'
            + '</tr>';
        }).join("")
      + '</tbody></table></div>';

    if(d.total_pages > 1){
      html += '<div class="pagination" style="padding:10px 2px 0">'
        + '<span>halaman ' + d.page + ' dari ' + d.total_pages + '</span>'
        + '<span style="display:flex; gap:6px;">'
          + '<button class="btn ghost" ' + (d.page<=1?'disabled':'') + ' onclick="riwayatGoPage(' + (d.page-1) + ')">Sebelumnya</button>'
          + '<button class="btn ghost" ' + (d.page>=d.total_pages?'disabled':'') + ' onclick="riwayatGoPage(' + (d.page+1) + ')">Berikutnya</button>'
        + '</span></div>';
    }
  }

  html += '<div class="modal-act" style="margin-top:14px;">'
    + '<button type="button" class="btn ghost" data-act="tutup">Tutup</button>'
    + '</div>';

  riwayatState.modal.isi(html);
}

/* ---------------------------------------------------------------- */
/* Header / tabs                                                     */
/* ---------------------------------------------------------------- */
const TABS = [
  { id:"dashboard", label:"Dashboard stok", sub:"Ringkasan stok seluruh barang",
    ikon:'<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>' },
  { id:"masuk", label:"Barang masuk", sub:"Catat dan telusuri barang yang diterima",
    ikon:'<path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M3 17v2a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2"/>' },
  { id:"keluar", label:"Barang keluar", sub:"Impor picking list PDF atau catat manual",
    ikon:'<path d="M12 21V9"/><polyline points="7 14 12 9 17 14"/><path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"/>' },
  { id:"master", label:"Master barang", sub:"Kelola katalog, barcode, dan ambang stok",
    ikon:'<path d="M4 7h16M4 12h16M4 17h10"/>' },
];

/** Jumlah kecil di sisi menu — diisi setelah dashboard dimuat. */
let navHitung = { perluOrder:0 };

function renderNav(){
  const nav = $("sisiNav");
  if(!nav) return;
  nav.innerHTML = '<div class="nav-judul">Menu</div>' + TABS.map(t => {
    const isi = (t.ikon.indexOf("fill") === -1 && t.id !== "dashboard")
      ? ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
      : ' fill="currentColor"';
    let lencana = "";
    if(t.id === "dashboard" && navHitung.perluOrder > 0){
      lencana = '<span class="nav-jml perhatian">' + fmtNum(navHitung.perluOrder) + '</span>';
    }
    return '<button type="button" class="nav-btn' + (tab===t.id ? ' aktif' : '') + '"'
      + ' data-tab="' + t.id + '"' + (tab===t.id ? ' aria-current="page"' : '') + '>'
      + '<svg width="17" height="17" viewBox="0 0 24 24"' + isi + '>' + t.ikon + '</svg>'
      + esc(t.label) + lencana + '</button>';
  }).join("");
}

function judulHalaman(){
  const t = TABS.find(x => x.id === tab);
  if(!t) return;
  const h = $("judulHalaman"), p = $("subJudulHalaman");
  if(h) h.textContent = t.label;
  if(p) p.textContent = t.sub;
  document.title = t.label + " — " + (window.APP_USER ? "Papan Kendali Gudang" : "Gudang");
}

function switchTab(id){
  if(tab === id) return;
  tab = id;
  renderNav();
  judulHalaman();
  renderContent();
  tutupSisi();
}

/* --- Sidebar di layar sempit --- */
function bukaSisi(){
  const s = $("sisi"), t = $("sisiTirai");
  if(s) s.classList.add("buka");
  if(t) t.hidden = false;
}
function tutupSisi(){
  const s = $("sisi"), t = $("sisiTirai");
  if(s) s.classList.remove("buka");
  if(t) t.hidden = true;
}

function paginationBar(total, page, totalPages, fnName){
  return '<div class="pagination">'
    + '<span>' + fmtNum(total) + ' data · halaman ' + page + ' dari ' + totalPages + '</span>'
    + '<span style="display:flex; gap:6px;">'
      + '<button class="btn ghost" ' + (page<=1?'disabled':'') + ' onclick="'+fnName+'('+(page-1)+')">Sebelumnya</button>'
      + '<button class="btn ghost" ' + (page>=totalPages?'disabled':'') + ' onclick="'+fnName+'('+(page+1)+')">Berikutnya</button>'
    + '</span></div>';
}

/** Kartu statistik. Angka diberi data-nilai supaya bisa dihitung naik. */
function statCard(o){
  const ikon = {
    sku:    '<path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
    unit:   '<path d="M3 9h18M3 15h18"/><rect x="3" y="4" width="18" height="16" rx="2"/>',
    alert:  '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    kosong: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    tag:    '<path d="M20.6 13.4L12 22l-9-9V4a1 1 0 0 1 1-1h9l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>'
  }[o.ikon] || "";

  return '<div class="stat-card' + (o.klik ? ' bisa-klik' : '') + '"'
    + (o.klik ? ' data-statklik="' + o.klik + '" tabindex="0" role="button"' : '')
    + '>'
    + '<div class="stat-atas">'
      + '<span class="stat-ikon ' + (o.nada || '') + '">'
        + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ikon + '</svg>'
      + '</span>'
      + '<span class="stat-label">' + esc(o.label) + '</span>'
    + '</div>'
    + '<div class="stat-value' + (o.tone ? ' ' + o.tone : '') + '" data-nilai="' + o.nilai + '">0</div>'
    + (o.kaki ? '<div class="stat-kaki">' + esc(o.kaki) + '</div>' : '')
    + '</div>';
}

/* ---------------------------------------------------------------- */
/* Dashboard                                                          */
/* ---------------------------------------------------------------- */
let ringkasHari = 30;

function renderDashboard(){
  let html = "";
  html += '<div id="dashBanner"></div>';
  html += '<div class="stat-row" id="dashStats"></div>';

  // --- Panel visual ---
  html += '<div class="panel-grid dua masuk-tahap" style="--d:60ms">'
    + '<div class="panel">'
      + '<div class="panel-head"><div>'
        + '<h2>Pergerakan stok</h2>'
        + '<p>Unit masuk dan keluar per hari</p></div>'
        + '<div class="panel-aksi" id="segHari">'
          + [7,30,90].map(h => '<button type="button" class="seg-btn'+(h===ringkasHari?' aktif':'')+'" data-hari="'+h+'">'+h+'h</button>').join("")
        + '</div>'
      + '</div>'
      + '<div id="gPergerakan" class="panel-isi"><div class="g-kosong">Memuat…</div></div>'
    + '</div>'
    + '<div class="panel">'
      + '<div class="panel-head"><div>'
        + '<h2>Paling perlu diorder</h2>'
        + '<p>Diurutkan menurut kekurangan terhadap ambang</p></div></div>'
      + '<div id="gPerluOrder" class="panel-isi"><div class="g-kosong">Memuat…</div></div>'
    + '</div>'
  + '</div>';

  html += '<div class="panel-grid dua-rata masuk-tahap" style="--d:120ms">'
    + '<div class="panel">'
      + '<div class="panel-head"><div>'
        + '<h2>Status stok</h2>'
        + '<p>Sebaran seluruh SKU menurut ambang minimalnya</p></div></div>'
      + '<div id="gStatus" class="panel-isi"><div class="g-kosong">Memuat…</div></div>'
    + '</div>'
    + '<div class="panel">'
      + '<div class="panel-head"><div>'
        + '<h2>Stok per kategori</h2>'
        + '<p>Klik untuk menyaring tabel di bawah</p></div></div>'
      + '<div id="gKategori" class="panel-isi"><div class="g-kosong">Memuat…</div></div>'
    + '</div>'
  + '</div>';

  html += '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search") + '<input type="text" id="dashSearch" placeholder="Cari nama, SKU, atau barcode…" oninput="onDashSearchInput()"></div>'
    + '<select id="dashKategori" onchange="onDashFilterChange()"><option>Semua</option></select>'
    + '<select id="dashStatus" onchange="onDashFilterChange()">'
      + '<option value="semua">Semua status</option>'
      + '<option value="kritis">Perlu order</option>'
      + '<option value="rendah">Menipis</option>'
      + '<option value="aman">Aman</option>'
      + '<option value="belum_diatur">Belum diatur</option>'
    + '</select>'
    + '<a class="btn ghost" href="api/export/csv.php?jenis=dashboard">' + svgIcon("download") + 'Ekspor CSV</a>'
    + '</div>';
  html += '<div id="dashResults" class="masuk-tahap" style="--d:180ms"></div>';
  $("content").innerHTML = html;
  $("dashSearch").value = dashFilters.q;
  $("dashStatus").value = dashFilters.status;

  const seg = $("segHari");
  if(seg){
    seg.addEventListener("click", function(e){
      const b = e.target.closest("[data-hari]");
      if(!b) return;
      ringkasHari = Number(b.getAttribute("data-hari"));
      seg.querySelectorAll(".seg-btn").forEach(x =>
        x.classList.toggle("aktif", Number(x.getAttribute("data-hari")) === ringkasHari));
      muatRingkas();
    });
  }

  refreshDashboard();
  muatRingkas();
}

/** Muat data panel visual (grafik, perlu order). Terpisah dari tabel
 *  supaya memfilter tabel tidak ikut menggambar ulang grafik. */
async function muatRingkas(){
  let d;
  try{
    d = await API.get("dashboard/ringkas.php", { hari: ringkasHari });
  }catch(e){
    console.error(e);
    return;
  }
  if(tab !== "dashboard") return;

  const gs = $("gStatus");
  if(gs) Grafik.statusBar(gs, d.status);

  const gk = $("gKategori");
  if(gk) Grafik.kategoriBar(gk, d.kategori, {
    onPilih: function(kat){
      dashFilters.kategori = kat;
      dashFilters.page = 1;
      const sel = $("dashKategori");
      if(sel) sel.value = kat;
      refreshDashboard();
      const hasil = $("dashResults");
      if(hasil) hasil.scrollIntoView({ behavior:"smooth", block:"start" });
      toast("Disaring: kategori " + kat);
    }
  });

  const gp = $("gPergerakan");
  if(gp) Grafik.pergerakanArea(gp, d.pergerakan, d.ada_pergerakan);

  const go = $("gPerluOrder");
  if(go) renderPerluOrder(go, d.perlu_order);
}

function renderPerluOrder(wadah, baris){
  if(!baris || !baris.length){
    wadah.innerHTML = '<div class="g-kosong g-kosong-ajak">'
      + '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">'
      + '<polyline points="20 6 9 17 4 12"/></svg>'
      + '<b>Tidak ada yang perlu diorder</b>'
      + '<span>Semua barang yang ambangnya sudah diatur masih di atas batas minimal.</span></div>';
    return;
  }
  wadah.innerHTML = '<div class="order-list">' + baris.map((b, i) =>
    '<div class="order-item">'
    + '<span class="order-rank">' + (i+1) + '</span>'
    + '<span class="order-teks">'
      + '<span class="order-nama">' + esc(b.nama) + '</span>'
      + '<span class="order-sub">' + esc(b.barcode) + ' · sisa ' + fmtNum(b.stok_akhir)
        + ' dari min ' + fmtNum(b.stok_minimal) + '</span>'
    + '</span>'
    + '<span class="order-angka">'
      + '<span class="order-kurang">' + fmtNum(b.kurang) + '</span>'
      + '<span class="order-kurang-lbl">kurang</span>'
    + '</span>'
    + '</div>'
  ).join("") + '</div>';
}

const onDashSearchInput = debounce(function(){
  dashFilters.q = $("dashSearch").value;
  dashFilters.page = 1;
  refreshDashboard();
}, 300);

function onDashFilterChange(){
  dashFilters.q = $("dashSearch").value;
  dashFilters.kategori = $("dashKategori").value;
  dashFilters.status = $("dashStatus").value;
  dashFilters.page = 1;
  refreshDashboard();
}
function dashGoPage(p){ dashFilters.page = p; refreshDashboard(); }
function setDashStatusFilter(){
  dashFilters.status = dashFilters.status === "kritis" ? "semua" : "kritis";
  dashFilters.page = 1;
  const sel = $("dashStatus");
  if(sel) sel.value = dashFilters.status;
  refreshDashboard();
}

/**
 * Sel angka MASUK / KELUAR yang bisa diklik untuk membuka riwayatnya.
 * Nama barang diselipkan lewat atribut data-, bukan diinterpolasi ke dalam
 * string JavaScript di atribut onclick — nama barang mengandung tanda kutip
 * dan karakter lain yang akan memecah atribut bila ditempel begitu saja.
 */
function selRiwayat(s, jenis){
  const nilai = jenis === "masuk" ? s.masuk_total : s.keluar_total;
  const tanda = jenis === "masuk" ? "+" : "-";
  const teks  = tanda + fmtNum(nilai);
  const judul = jenis === "masuk"
    ? "Lihat riwayat barang masuk untuk " + s.nama
    : "Lihat riwayat barang keluar untuk " + s.nama;
  return '<button type="button" class="num-link" data-riwayat="' + jenis + '"'
    + ' data-id="' + s.id + '" data-nama="' + esc(s.nama) + '"'
    + ' title="' + esc(judul) + '">' + teks + '</button>';
}

async function refreshDashboard(){
  const hasil = $("dashResults");
  if(hasil && !hasil.innerHTML) hasil.innerHTML = '<div class="info-box">Memuat…</div>';

  let data;
  try{
    data = await API.dashboard({
      q: dashFilters.q, kategori: dashFilters.kategori,
      status: dashFilters.status, page: dashFilters.page
    });
  }catch(e){ tampilGalat(e); return; }

  const r = data.ringkasan;

  // Dropdown kategori diisi sekali dari data server.
  const selKat = $("dashKategori");
  if(selKat && kategoriOptions.join("|") !== data.kategori.join("|")){
    kategoriOptions = data.kategori;
    const nilaiLama = dashFilters.kategori;
    selKat.innerHTML = ['Semua'].concat(kategoriOptions).map(k=>'<option value="'+esc(k)+'">'+esc(k)+'</option>').join("");
    selKat.value = nilaiLama;
  }

  const stats = $("dashStats");
  if(stats){
    stats.innerHTML =
        statCard({ label:"Total SKU", nilai:r.total_sku, ikon:"sku", nada:"biru",
                   kaki:fmtNum(r.jml_kategori) + " kategori" })
      + statCard({ label:"Total stok akhir", nilai:r.total_stok, ikon:"unit", nada:"biru",
                   kaki:"unit di gudang" })
      + statCard({ label:"Perlu order", nilai:r.perlu_order, ikon:"alert",
                   nada:r.perlu_order ? "danger" : "safe",
                   tone:r.perlu_order ? "danger" : "safe",
                   kaki:fmtNum(r.kritis) + " kritis · " + fmtNum(r.rendah) + " menipis",
                   klik:"kritis" })
      + statCard({ label:"Belum diatur", nilai:r.belum_diatur, ikon:"kosong", nada:"",
                   tone:"muted", kaki:"ambang minimal masih 0", klik:"belum_diatur" });

    // Angka menghitung naik, bukan langsung muncul — mengarahkan mata ke
    // besaran yang berubah setiap kali data dimuat ulang.
    stats.querySelectorAll(".stat-value[data-nilai]").forEach(n =>
      Grafik.angkaNaik(n, Number(n.getAttribute("data-nilai"))));
  }

  // Lencana jumlah di menu sisi.
  if(navHitung.perluOrder !== r.perlu_order){
    navHitung.perluOrder = r.perlu_order;
    renderNav();
  }

  const bannerEl = $("dashBanner");
  if(bannerEl){
    bannerEl.innerHTML = r.perlu_order ? (
      '<button class="banner-alert" onclick="setDashStatusFilter()">' + svgIcon("alert")
      + '<span>' + r.perlu_order + ' item stoknya di bawah atau mendekati batas minimal — perlu segera diorder.</span></button>'
    ) : "";
  }

  let rows = data.rows.map(s => {
    const denom = Math.max(s.stok_minimal*2, s.stok_akhir, 1);
    const pct = Math.min(100, Math.max(0, (s.stok_akhir/denom)*100));
    const markPct = Math.min(100, (s.stok_minimal/denom)*100);
    const color = s.status==="kritis" ? "var(--danger)" : (s.status==="rendah" ? "var(--amber)" : (s.status==="aman" ? "var(--safe)" : "var(--lineStrong)"));
    const labelStatus = s.status==="kritis" ? "Perlu order"
                      : s.status==="rendah" ? "Menipis"
                      : s.status==="aman"   ? "Aman" : "Belum diatur";
    return '<tr>'
      + '<td><div class="item-name">'+esc(s.nama)+'</div><div class="item-sub">'+esc(s.sku||"-")+' · '+esc(s.barcode)
        + (s.barcode_asli===0 ? '<span class="flag-gen">BARCODE SEMENTARA</span>' : '') + '</div></td>'
      + '<td>'+esc(s.kategori||'-')+'</td>'
      + '<td class="num">'+fmtNum(s.stok_awal)+'</td>'
      + '<td class="num" style="color:var(--safe)">'   + selRiwayat(s, "masuk")  + '</td>'
      + '<td class="num" style="color:var(--danger)">' + selRiwayat(s, "keluar") + '</td>'
      + '<td class="num"><div class="stok-akhir-num">'+fmtNum(s.stok_akhir)+'</div><div class="gauge"><div class="gauge-fill" style="width:'+pct+'%; background:'+color+'"></div><div class="gauge-mark" style="left:'+markPct+'%"></div></div></td>'
      + '<td class="num">'+fmtNum(s.stok_minimal)+'</td>'
      + '<td class="num"><span class="badge '+s.status+'">'+(s.status==="kritis"?svgIcon("alert"):"")+labelStatus+'</span></td>'
      + '</tr>';
  }).join("");
  if(data.rows.length===0) rows = '<tr class="empty-row"><td colspan="8">Tidak ada barang yang cocok dengan pencarian.</td></tr>';

  hasil.innerHTML =
    '<div class="table-card"><table style="min-width:820px"><thead><tr>'
    + ["Barang","Kategori","Awal","Masuk","Keluar","Akhir","Min.","Status"].map((h,i)=>'<th'+(i>=2&&i<=6?' class="num"':'')+'>'+h+'</th>').join("")
    + '</tr></thead><tbody>' + rows + '</tbody></table>'
    + paginationBar(data.total, data.page, data.total_pages, "dashGoPage")
    + '</div>';
}

/* ---------------------------------------------------------------- */
/* Transaksi (Barang Masuk / Keluar)                                 */
/* ---------------------------------------------------------------- */
// Disuntikkan index.php dari config/config.php — satu sumber kebenaran.
// Nilai cadangan hanya dipakai bila halaman dibuka tanpa suntikan server.
const KET_MASUK  = window.KET_MASUK  || ["Barang Baru","Restock","Retur Masuk","Lainnya"];
const KET_KELUAR = window.KET_KELUAR || ["Pesanan MP","Retur","Rusak / Reject","Lainnya"];

function renderTransaksiTab(kind){
  const ketOptions = kind==="masuk" ? KET_MASUK : KET_KELUAR;
  const accent = kind==="masuk" ? "safe" : "danger-btn";
  const label = kind==="masuk" ? "Catat barang masuk" : "Catat barang keluar";

  let html = "";
  if(kind === "keluar"){
    html += '<div class="pdf-import-card" id="pdfImportCard">'
      + '<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">'
      + '<div><div style="font-weight:700; font-size:15px;">Impor dari PDF Picking List</div>'
      + '<div style="font-size:12.5px; color:var(--slate); max-width:520px;">Upload PDF picking list dari sistem pesanan. Sistem akan membaca datanya dulu untuk dicek admin gudang — bila sudah sesuai, tekan Konfirmasi untuk menyimpannya sebagai barang keluar.</div></div>'
      + '<label class="file-btn">' + svgIcon("plus") + 'Pilih file PDF'
      + '<input type="file" accept="application/pdf,.pdf" id="pdfFileInput" style="display:none" onchange="handlePdfUpload(event)"></label>'
      + '</div>'
      + '<div id="pdfImportStatus"></div>'
      + '<div id="pdfReviewArea"></div>'
      + '</div>'
      + '<div style="font-size:11.5px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:var(--slate); margin:4px 0 10px;">Atau input manual</div>';
  }

  html += '<form class="form-card" onsubmit="submitTransaksi(event,\''+kind+'\')">';
  html += '<div class="form-grid">';
  html += '<div><label class="field-label">Tanggal</label><input type="date" id="'+kind+'Tanggal" value="'+todayISO()+'"></div>';
  html += '<div class="span2 picker-wrap"><label class="field-label">Cari barang (nama / barcode)</label>'
    + '<input type="text" id="'+kind+'Picker" placeholder="Ketik nama atau barcode…" autocomplete="off" oninput="onPickerInput(\''+kind+'\')" onfocus="onPickerInput(\''+kind+'\')">'
    + '<div id="'+kind+'PickerList" class="picker-list" style="display:none"></div></div>';
  html += '<div><label class="field-label">Kode barcode</label><input type="text" id="'+kind+'Barcode" class="mono" placeholder="Contoh: 12132522"></div>';
  html += '<div><label class="field-label">Nama barang</label><input type="text" id="'+kind+'Nama" placeholder="Nama barang"></div>';
  html += '<div><label class="field-label">Jumlah</label><input type="number" min="1" id="'+kind+'Jumlah" placeholder="0"></div>';
  if(kind === "keluar"){
    html += '<div><label class="field-label">No. Pesanan</label><input type="text" id="keluarNoPesanan" placeholder="Opsional"></div>';
  }
  html += '<div><label class="field-label">Keterangan</label><select id="'+kind+'Ket">' + ketOptions.map(k=>'<option>'+esc(k)+'</option>').join("") + '</select></div>';
  html += '</div>';
  html += '<button type="submit" class="btn ' + accent + '" id="'+kind+'Submit">' + svgIcon("plus") + label + '</button>';
  html += '</form>';

  html += '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search") + '<input type="text" id="'+kind+'Cari" placeholder="Cari nama, barcode' + (kind==="keluar"?", no. pesanan":"") + '…" oninput="onTrxSearchInput(\''+kind+'\')"></div>'
    + '<div class="daterange">Dari <input type="date" id="'+kind+'Dari" onchange="onTrxFilterChange(\''+kind+'\')">'
    + ' s/d <input type="date" id="'+kind+'Sampai" onchange="onTrxFilterChange(\''+kind+'\')"></div>'
    + '<a class="btn ghost" id="'+kind+'Export" href="api/export/csv.php?jenis='+kind+'">' + svgIcon("download") + 'Ekspor CSV</a>'
    + '</div>';
  html += '<div id="'+kind+'Table"></div>';

  $("content").innerHTML = html;
  const f = trxFilters[kind];
  $(kind+"Cari").value   = f.q;
  $(kind+"Dari").value   = f.dari;
  $(kind+"Sampai").value = f.sampai;

  renderTransaksiTable(kind);
  if(kind === "keluar"){
    renderPdfImportStatus();
    renderPdfReview();
  }
}

const onTrxSearchInput = debounce(function(kind){
  trxFilters[kind].q = $(kind+"Cari").value;
  trxFilters[kind].page = 1;
  renderTransaksiTable(kind);
}, 300);

function onTrxFilterChange(kind){
  const f = trxFilters[kind];
  f.q      = $(kind+"Cari").value;
  f.dari   = $(kind+"Dari").value;
  f.sampai = $(kind+"Sampai").value;
  f.page   = 1;
  const ex = $(kind+"Export");
  if(ex) ex.href = "api/export/csv.php?jenis="+kind+"&dari="+encodeURIComponent(f.dari)+"&sampai="+encodeURIComponent(f.sampai);
  renderTransaksiTable(kind);
}
function trxGoPageMasuk(p){ trxFilters.masuk.page = p; renderTransaksiTable("masuk"); }
function trxGoPageKeluar(p){ trxFilters.keluar.page = p; renderTransaksiTable("keluar"); }

/* --- Picker autocomplete --- */
const onPickerInput = debounce(async function(kind){
  const el = $(kind+"Picker");
  if(!el) return;
  const q = el.value.trim();
  const listEl = $(kind+"PickerList");
  if(!listEl) return;
  if(!q){ listEl.style.display="none"; listEl.innerHTML=""; return; }

  let data;
  try{ data = await API.masterPick(q); }
  catch(e){ return; }

  // Pengguna mungkin sudah mengubah ketikannya saat respons tiba.
  if(el.value.trim() !== q) return;

  if(data.rows.length===0){
    listEl.innerHTML = '<div class="picker-item">Tidak ditemukan — isi manual di bawah.</div>';
    listEl.style.display="block";
    return;
  }
  listEl.innerHTML = data.rows.map(m =>
    '<div class="picker-item" onclick="pickMasterItem(\''+kind+'\',\''+esc(m.barcode).replace(/'/g,"\\'")+'\',\''+esc(m.nama).replace(/'/g,"\\'")+'\')">'
    + '<div>'+esc(m.nama)+'</div><div class="sub">'+esc(m.sku||"-")+' · '+esc(m.barcode)+'</div></div>'
  ).join("");
  listEl.style.display = "block";
}, 250);

function pickMasterItem(kind, barcode, nama){
  $(kind+"Barcode").value = barcode;
  $(kind+"Nama").value = nama;
  $(kind+"Picker").value = nama;
  $(kind+"PickerList").style.display = "none";
}

/* --- Simpan transaksi --- */
async function submitTransaksi(e, kind){
  e.preventDefault();
  const tombol = $(kind+"Submit");
  const body = {
    tanggal:    $(kind+"Tanggal").value || todayISO(),
    barcode:    $(kind+"Barcode").value.trim(),
    nama:       $(kind+"Nama").value.trim(),
    jumlah:     Number($(kind+"Jumlah").value),
    keterangan: $(kind+"Ket").value
  };
  if(kind === "keluar"){
    const np = $("keluarNoPesanan");
    body.no_pesanan = np ? np.value.trim() : "";
  }
  if(!body.barcode || !body.nama || !body.jumlah){
    toast("Lengkapi barcode, nama, dan jumlah dulu.", "err");
    return;
  }

  if(tombol) tombol.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.trxCreate(kind, body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    (res.peringatan || []).forEach(p => toast(p, "err"));

    $(kind+"Barcode").value=""; $(kind+"Nama").value="";
    $(kind+"Jumlah").value="";  $(kind+"Picker").value="";
    $(kind+"Ket").selectedIndex = 0;              // audit F5
    if(kind === "keluar" && $("keluarNoPesanan")) $("keluarNoPesanan").value = "";

    trxFilters[kind].page = 1;
    renderTransaksiTable(kind);
  }catch(err){
    tampilGalat(err);
  }finally{
    if(tombol) tombol.disabled = false;
  }
}

async function renderTransaksiTable(kind){
  const wadah = $(kind+"Table");
  if(!wadah) return;
  const f = trxFilters[kind];

  let data;
  try{
    data = await API.trxList(kind, { q:f.q, dari:f.dari, sampai:f.sampai, page:f.page });
  }catch(e){ tampilGalat(e); return; }

  const jumlahLabel = kind==="masuk" ? "Masuk" : "Keluar";
  const showPesanan = kind === "keluar";

  let body = data.rows.map(r =>
    '<tr>'
    + '<td style="white-space:nowrap">'+fmtDate(r.tanggal)+'</td>'
    + '<td style="font-weight:500">'+esc(r.nama)+(r.master_id===null?'<span class="flag-gen">TAK DIKENAL</span>':'')+'</td>'
    + '<td class="mono" style="font-size:11.5px; color:var(--slate)">'+esc(r.barcode)+'</td>'
    + '<td style="font-weight:600">'+fmtNum(r.jumlah)+'</td>'
    + '<td style="color:var(--slate)">'+esc(r.keterangan)+'</td>'
    + (showPesanan ? '<td class="mono" style="font-size:11.5px; color:var(--slate)">'+esc(r.no_pesanan || "-")+'</td>' : '')
    + '<td style="color:var(--slate); font-size:11.5px">'+esc(r.oleh || "-")+'</td>'
    + '<td class="num"><button class="icon-btn" onclick="deleteTransaksi(\''+kind+'\','+r.id+')" aria-label="Hapus">'+svgIcon("trash")+'</button></td>'
    + '</tr>'
  ).join("");

  const kolomJml = showPesanan ? 8 : 7;
  if(data.rows.length===0) body = '<tr class="empty-row"><td colspan="'+kolomJml+'">Belum ada catatan.</td></tr>';

  const headers = ["Tanggal","Barang","Barcode",jumlahLabel,"Keterangan"]
    .concat(showPesanan?["No. Pesanan"]:[]).concat(["Oleh",""]);

  wadah.innerHTML = '<div class="table-card"><table style="min-width:720px"><thead><tr>'
    + headers.map(h=>'<th>'+h+'</th>').join("")
    + '</tr></thead><tbody>'+body+'</tbody></table>'
    + '<div class="pagination"><span>' + fmtNum(data.total) + ' catatan · total ' + jumlahLabel.toLowerCase()
      + ' ' + fmtNum(data.total_jumlah) + ' pcs · halaman ' + data.page + ' dari ' + data.total_pages + '</span>'
    + '<span style="display:flex; gap:6px;">'
      + '<button class="btn ghost" ' + (data.page<=1?'disabled':'') + ' onclick="trxGoPage'+(kind==="masuk"?"Masuk":"Keluar")+'('+(data.page-1)+')">Sebelumnya</button>'
      + '<button class="btn ghost" ' + (data.page>=data.total_pages?'disabled':'') + ' onclick="trxGoPage'+(kind==="masuk"?"Masuk":"Keluar")+'('+(data.page+1)+')">Berikutnya</button>'
    + '</span></div>'
    + '</div>';
}

async function deleteTransaksi(kind, id){
  const ok = await konfirmasi(
    "Hapus catatan ini?",
    "Catatan transaksi akan dihapus dan perhitungan stok ikut berubah. Data masih bisa dipulihkan lewat database bila perlu.",
    "Hapus catatan"
  );
  if(!ok) return;
  setSaveStatus("saving");
  try{
    const res = await API.trxDelete(kind, id);
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    renderTransaksiTable(kind);
  }catch(e){ tampilGalat(e); }
}

/* ---------------------------------------------------------------- */
/* Impor Barang Keluar dari PDF Picking List                         */
/* ---------------------------------------------------------------- */
function handlePdfUpload(event){
  const file = event.target.files && event.target.files[0];
  if(!file) return;
  const isPdf = file.type === "application/pdf" || /\.pdf$/i.test(file.name);
  if(!isPdf){
    toast("File harus berformat PDF.", "err");
    event.target.value = "";
    return;
  }
  const reader = new FileReader();
  reader.onload = function(e){ prosesPdf(e.target.result, file.name); };
  reader.onerror = function(){
    pdfImport = { status:"error", header:null, rows:[], fileName:file.name, fileHash:"", tanggal:"", cocok:{}, duplikat:null };
    renderPdfImportStatus(); renderPdfReview();
  };
  pdfImport = { status:"parsing", header:null, rows:[], fileName:file.name, fileHash:"", tanggal:"", cocok:{}, duplikat:null };
  renderPdfImportStatus(); renderPdfReview();
  reader.readAsArrayBuffer(file);
}

async function prosesPdf(arrayBuffer, fileName){
  if(!window["pdfjsLib"]){
    pdfImport = { status:"error", header:null, rows:[], fileName, fileHash:"", tanggal:"", cocok:{}, duplikat:null };
    renderPdfImportStatus(); renderPdfReview();
    toast("Pembaca PDF gagal dimuat.", "err");
    return;
  }
  try{
    // Hash dihitung dari salinan, karena pdf.js memindahkan (detach) buffer.
    const hash = await hitungHashPdf(arrayBuffer.slice(0));
    const parsed = await parsePdfPickingList(arrayBuffer);

    // Tanggal awal diambil dari tanggal cetak PDF bila ada (audit D6),
    // bukan selalu hari ini seperti prototipe.
    let tanggal = todayISO();
    const tc = (parsed.header && parsed.header.tanggalCetak) || "";
    const m = tc.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if(m){
      tanggal = m[3] + "-" + String(m[2]).padStart(2,"0") + "-" + String(m[1]).padStart(2,"0");
    }

    pdfImport = {
      status: parsed.rows.length ? "ready" : "empty",
      header: parsed.header, rows: parsed.rows,
      fileName, fileHash: hash, tanggal, cocok:{}, duplikat:null
    };

    if(parsed.rows.length){
      await cekBarcodeMassal();
      await cekDuplikatImpor();
    }
  }catch(err){
    console.error(err);
    pdfImport = { status:"error", header:null, rows:[], fileName, fileHash:"", tanggal:"", cocok:{}, duplikat:null };
  }
  renderPdfImportStatus();
  renderPdfReview();
}

/** Cek semua barcode sekaligus, bukan satu permintaan per baris. */
async function cekBarcodeMassal(){
  const daftar = pdfImport.rows.map(r => r.barcode).filter(b => b);
  if(!daftar.length){ pdfImport.cocok = {}; return; }
  try{
    const res = await API.post("master/cek_barcode.php", { barcodes: daftar });
    pdfImport.cocok = res.ditemukan || {};
  }catch(e){ pdfImport.cocok = {}; }
}

async function cekDuplikatImpor(){
  try{
    const res = await API.importCek({
      no_picking: (pdfImport.header && pdfImport.header.noPicking) || "",
      file_hash:  pdfImport.fileHash
    });
    pdfImport.duplikat = res.duplikat ? res : null;
  }catch(e){ pdfImport.duplikat = null; }
}

function renderPdfImportStatus(){
  const el = $("pdfImportStatus");
  const card = $("pdfImportCard");
  if(!el) return;
  if(pdfImport.status==="parsing") el.innerHTML = '<div style="padding:10px 0; color:var(--slate); font-size:13px;">Membaca file PDF ('+esc(pdfImport.fileName)+')…</div>';
  else if(pdfImport.status==="error") el.innerHTML = '<div style="padding:10px 0; color:var(--danger); font-size:13px;">Gagal membaca PDF ini. Pastikan filenya adalah Picking List yang valid, atau isi manual di bawah.</div>';
  else if(pdfImport.status==="empty") el.innerHTML = '<div style="padding:10px 0; color:var(--amber); font-size:13px;">Tidak ada baris barang yang terbaca dari PDF ini. Coba file lain, atau isi manual di bawah.</div>';
  else el.innerHTML = "";
  if(card) card.classList.toggle("has-review", pdfImport.status==="ready");
}

function pdfRowStatusBadge(r){
  if(!r.barcode) return '<span class="badge kritis">'+svgIcon("alert")+'Barcode kosong</span>';
  const found = Object.prototype.hasOwnProperty.call(pdfImport.cocok, r.barcode);
  return found ? '<span class="badge aman">'+svgIcon("check")+'Cocok master</span>'
               : '<span class="badge rendah">'+svgIcon("alert")+'Tak dikenal</span>';
}

function pdfReviewRowHtml(r, idx){
  return '<tr>'
    + '<td><input type="text" class="mono" style="width:130px" value="'+esc(r.barcode)+'" oninput="updatePdfRow('+idx+',\'barcode\',this.value)"></td>'
    + '<td><input type="text" style="min-width:220px" value="'+esc(r.nama)+'" oninput="updatePdfRow('+idx+',\'nama\',this.value)"></td>'
    + '<td><input type="text" style="width:90px" value="'+esc(r.sku)+'" oninput="updatePdfRow('+idx+',\'sku\',this.value)"></td>'
    + '<td class="num"><input type="number" min="0" style="width:70px; text-align:right" value="'+(r.qty||0)+'" oninput="updatePdfRow('+idx+',\'qty\',this.value)"></td>'
    + '<td><input type="text" style="width:140px" value="'+esc(r.noPesanan)+'" oninput="updatePdfRow('+idx+',\'noPesanan\',this.value)"></td>'
    + '<td><select onchange="updatePdfRow('+idx+',\'keterangan\',this.value)">' + KET_KELUAR.map(k=>'<option'+(r.keterangan===k?' selected':'')+'>'+esc(k)+'</option>').join("") + '</select></td>'
    + '<td id="pdfRowStatus'+idx+'">'+pdfRowStatusBadge(r)+'</td>'
    + '<td><button type="button" class="icon-btn" onclick="removePdfReviewRow('+idx+')" aria-label="Hapus baris">'+svgIcon("trash")+'</button></td>'
    + '</tr>';
}

function renderPdfReview(){
  const el = $("pdfReviewArea");
  if(!el) return;
  if(pdfImport.status !== "ready" || !pdfImport.rows.length){ el.innerHTML = ""; return; }

  const h = pdfImport.header || {};
  let html = "";

  if(pdfImport.duplikat){
    const b = pdfImport.duplikat.batch || {};
    html += '<div class="warn-box">' + esc(pdfImport.duplikat.alasan || "Picking list ini sudah pernah diimpor.")
      + ' Sebelumnya: ' + esc(b.jumlah_baris || 0) + ' baris pada ' + esc(b.created_at || "-")
      + ' oleh ' + esc(b.diimpor_oleh || "-") + '.'
      + ' Menyimpannya lagi akan memotong stok untuk kedua kalinya.</div>';
  }

  html += '<div class="pdf-meta">';
  if(h.noPicking) html += '<span><b>No Pick:</b> '+esc(h.noPicking)+'</span>';
  if(h.tanggalCetak) html += '<span><b>Tanggal cetak:</b> '+esc(h.tanggalCetak)+'</span>';
  if(h.dicetakOleh) html += '<span><b>Dicetak oleh:</b> '+esc(h.dicetakOleh)+'</span>';
  if(h.jumlahPesanan) html += '<span><b>Jumlah Pesanan:</b> '+esc(h.jumlahPesanan)+'</span>';
  html += '<span><b>'+pdfImport.rows.length+'</b> baris barang terbaca</span>';
  html += '</div>';

  const jmlTakDikenal = pdfImport.rows.filter(r => r.barcode && !Object.prototype.hasOwnProperty.call(pdfImport.cocok, r.barcode)).length;
  const jmlKosong = pdfImport.rows.filter(r => !r.barcode).length;
  if(jmlTakDikenal || jmlKosong){
    html += '<div class="info-box">'
      + (jmlKosong ? '<b>'+jmlKosong+'</b> baris barcodenya kosong dan wajib dilengkapi. ' : '')
      + (jmlTakDikenal ? '<b>'+jmlTakDikenal+'</b> baris barcodenya belum terdaftar di master — tetap bisa disimpan, tapi belum mempengaruhi perhitungan stok.' : '')
      + '</div>';
  }

  html += '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">'
    + '<label class="field-label" style="margin:0">Tanggal transaksi</label>'
    + '<input type="date" style="width:auto" value="'+esc(pdfImport.tanggal)+'" onchange="pdfImport.tanggal=this.value">'
    + '<span style="font-size:12px; color:var(--slate)">Diisi dari tanggal cetak PDF bila terbaca.</span>'
    + '</div>';

  html += '<div style="font-size:12.5px; color:var(--slate); margin-bottom:8px;">Cek data di bawah ini sudah sesuai fisik barang atau belum. Ubah bila perlu, lalu tekan <strong>Konfirmasi &amp; Simpan</strong> untuk mencatatnya sebagai barang keluar.</div>';
  html += '<div class="table-card" style="overflow:auto"><table style="min-width:900px"><thead><tr>'
    + ["Barcode","Nama barang","SKU","Qty","No. Pesanan","Keterangan","Status",""].map(h2=>'<th'+(h2==="Qty"?' class="num"':'')+'>'+h2+'</th>').join("")
    + '</tr></thead><tbody id="pdfReviewBody">'
    + pdfImport.rows.map((r,idx)=>pdfReviewRowHtml(r,idx)).join("")
    + '</tbody></table></div>';
  html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; flex-wrap:wrap; gap:8px;">'
    + '<button type="button" class="btn ghost" onclick="addPdfReviewRow()">'+svgIcon("plus")+'Tambah baris</button>'
    + '<div style="display:flex; gap:8px; flex-wrap:wrap;">'
      + '<button type="button" class="btn ghost" onclick="cancelPdfReview()">'+svgIcon("x")+'Batalkan impor</button>'
      + '<button type="button" class="btn safe" id="pdfConfirmBtn" onclick="confirmPdfReview()">'+svgIcon("check")+'Konfirmasi &amp; Simpan Semua</button>'
    + '</div></div>';
  el.innerHTML = html;
}

let cekBarcodeTertunda = null;
function updatePdfRow(idx, field, value){
  const r = pdfImport.rows[idx];
  if(!r) return;
  r[field] = field==="qty" ? (parseInt(value,10)||0) : value;
  if(field==="barcode"){
    const statusEl = $("pdfRowStatus"+idx);
    if(statusEl) statusEl.innerHTML = pdfRowStatusBadge(r);
    // Barcode baru mungkin belum ada di peta cocok — periksa ulang, ditunda
    // supaya mengetik tidak memicu permintaan tiap huruf.
    clearTimeout(cekBarcodeTertunda);
    cekBarcodeTertunda = setTimeout(async ()=>{
      await cekBarcodeMassal();
      pdfImport.rows.forEach((row,i)=>{
        const el2 = $("pdfRowStatus"+i);
        if(el2) el2.innerHTML = pdfRowStatusBadge(row);
      });
    }, 500);
  }
}

function addPdfReviewRow(){
  if(pdfImport.status !== "ready"){ pdfImport.status = "ready"; pdfImport.header = pdfImport.header || {}; }
  if(!pdfImport.tanggal) pdfImport.tanggal = todayISO();
  pdfImport.rows.push({ barcode:"", nama:"", sku:"", qty:0, noPesanan:"", keterangan:"Pesanan MP" });
  renderPdfReview();
}

function removePdfReviewRow(idx){
  pdfImport.rows.splice(idx,1);
  if(!pdfImport.rows.length){ pdfImport.status = "empty"; }
  renderPdfImportStatus();
  renderPdfReview();
}

function cancelPdfReview(){
  pdfImport = { status:"idle", header:null, rows:[], fileName:"", fileHash:"", tanggal:"", cocok:{}, duplikat:null };
  const fi = $("pdfFileInput");
  if(fi) fi.value = "";
  renderPdfImportStatus();
  renderPdfReview();
}

async function confirmPdfReview(){
  const rows = pdfImport.rows;
  if(!rows.length){ toast("Tidak ada data untuk disimpan.", "err"); return; }

  const invalid = rows.some(r => !r.barcode || !r.qty);
  if(invalid){ toast("Lengkapi Barcode dan Qty di semua baris sebelum konfirmasi.", "err"); return; }

  if(pdfImport.duplikat){
    const ok = await konfirmasi(
      "Impor ulang picking list ini?",
      (pdfImport.duplikat.alasan || "") + " Menyimpannya lagi akan memotong stok untuk kedua kalinya.",
      "Ya, impor lagi"
    );
    if(!ok) return;
  }

  const tombol = $("pdfConfirmBtn");
  if(tombol) tombol.disabled = true;
  setSaveStatus("saving");

  try{
    const res = await API.importSave({
      header:  pdfImport.header || {},
      rows:    rows,
      fileName: pdfImport.fileName,
      fileHash: pdfImport.fileHash,
      tanggal:  pdfImport.tanggal || todayISO(),
      abaikanDuplikat: !!pdfImport.duplikat
    });

    setSaveStatus("ok");
    toast(res.pesan || (res.tersimpan + " barang keluar berhasil disimpan."));
    (res.peringatan || []).forEach(p => toast(p, "err"));

    cancelPdfReview();
    trxFilters.keluar.page = 1;
    renderTransaksiTable("keluar");
  }catch(err){
    tampilGalat(err);
  }finally{
    if(tombol) tombol.disabled = false;
  }
}

/* ---------------------------------------------------------------- */
/* Master barang                                                      */
/* ---------------------------------------------------------------- */
function renderMaster(){
  const daftarKategori = window.KATEGORI_OPTIONS || [];
  const opsiKategori = '<option value="">— Tanpa kategori —</option>'
    + daftarKategori.map(k=>'<option value="'+esc(k)+'">'+esc(k)+'</option>').join("");

  let html = '<form class="form-card" id="masterForm" onsubmit="submitMaster(event)">';
  html += '<div class="form-grid">';
  html += '<div><label class="field-label">SKU</label><input type="text" id="mSku" class="mono"></div>';
  html += '<div><label class="field-label">Kode barcode</label><input type="text" id="mBarcode" class="mono"></div>';
  html += '<div class="span2"><label class="field-label">Nama barang</label><input type="text" id="mNama"></div>';
  html += '<div><label class="field-label">Stok awal</label><input type="number" min="0" id="mStokAwal" value="0"></div>';
  html += '<div><label class="field-label">Stok minimal</label><input type="number" min="0" id="mStokMinimal" value="0"></div>';
  html += '<div><label class="field-label">Kategori</label><select id="mKategori">' + opsiKategori + '</select></div>';
  html += '</div>';
  html += '<div style="display:flex; gap:8px;"><button type="submit" class="btn" id="mSubmitBtn">'+svgIcon("plus")+'Tambah barang</button>'
    + '<button type="button" class="btn ghost" id="mCancelBtn" style="display:none" onclick="cancelEditMaster()">'+svgIcon("x")+'Batal</button></div>';
  html += '</form>';
  html += '<div class="toolbar"><div class="search-wrap">'+svgIcon("search")+'<input type="text" id="masterSearch" placeholder="Cari SKU, barcode, atau nama…" oninput="onMasterSearchInput()"></div>'
    + '<a class="btn ghost" href="api/export/csv.php?jenis=master">'+svgIcon("download")+'Ekspor CSV</a></div>';
  html += '<div id="masterResults"></div>';
  $("content").innerHTML = html;
  $("masterSearch").value = masterFilters.q;
  refreshMasterTable();
}

const onMasterSearchInput = debounce(function(){
  masterFilters.q = $("masterSearch").value;
  masterFilters.page = 1;
  refreshMasterTable();
}, 300);

function masterGoPage(p){ masterFilters.page = p; refreshMasterTable(); }

async function refreshMasterTable(){
  const wadah = $("masterResults");
  if(!wadah) return;

  let data;
  try{
    data = await API.masterList({ q: masterFilters.q, page: masterFilters.page });
  }catch(e){ tampilGalat(e); return; }

  let rows = data.rows.map(m =>
    '<tr>'
    + '<td class="mono" style="font-size:11.5px">'+esc(m.sku||"-")+'</td>'
    + '<td class="mono" style="font-size:11.5px; color:var(--slate)">'+esc(m.barcode)
      + (m.barcode_asli===0 ? '<span class="flag-gen">SEMENTARA</span>' : '') + '</td>'
    + '<td style="font-weight:500">'+esc(m.nama)+'</td>'
    + '<td>'+fmtNum(m.stok_awal)+'</td>'
    + '<td>'+fmtNum(m.stok_minimal)+'</td>'
    + '<td style="color:var(--slate)">'+esc(m.kategori||'-')+'</td>'
    + '<td class="num" style="white-space:nowrap"><button class="icon-btn" onclick="editMaster('+m.id+')" aria-label="Ubah">'+svgIcon("edit")+'</button>'
      + '<button class="icon-btn" onclick="deleteMaster('+m.id+')" aria-label="Hapus">'+svgIcon("trash")+'</button></td>'
    + '</tr>'
  ).join("");
  if(data.rows.length===0) rows = '<tr class="empty-row"><td colspan="7">Tidak ada barang yang cocok.</td></tr>';

  wadah.innerHTML = '<div class="table-card"><table style="min-width:720px"><thead><tr>'
    + ["SKU","Barcode","Nama barang","Stok awal","Stok minimal","Kategori",""].map(h=>'<th>'+h+'</th>').join("")
    + '</tr></thead><tbody>'+rows+'</tbody></table>'
    + paginationBar(data.total, data.page, data.total_pages, "masterGoPage")
    + '</div>';
}

async function submitMaster(e){
  e.preventDefault();
  const body = {
    id:           editingMasterId || 0,
    sku:          $("mSku").value.trim(),
    barcode:      $("mBarcode").value.trim(),
    nama:         $("mNama").value.trim(),
    stok_awal:    Number($("mStokAwal").value)||0,
    stok_minimal: Number($("mStokMinimal").value)||0,
    kategori:     $("mKategori").value
  };
  if(!body.barcode || !body.nama){
    toast("Lengkapi barcode dan nama barang dulu.", "err");
    return;
  }
  const tombol = $("mSubmitBtn");
  if(tombol) tombol.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.masterSave(body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    resetMasterForm();
    refreshMasterTable();
  }catch(err){
    tampilGalat(err);
  }finally{
    if(tombol) tombol.disabled = false;
  }
}

async function editMaster(id){
  let data;
  try{
    // Ambil dari halaman yang sedang tampil; bila tidak ada, cari lewat API.
    data = await API.masterList({ q: masterFilters.q, page: masterFilters.page });
  }catch(e){ tampilGalat(e); return; }

  const m = data.rows.find(x => x.id === id);
  if(!m){ toast("Barang tidak ditemukan.", "err"); return; }

  editingMasterId = id;
  $("mSku").value = m.sku; $("mBarcode").value = m.barcode; $("mNama").value = m.nama;
  $("mStokAwal").value = m.stok_awal; $("mStokMinimal").value = m.stok_minimal;
  $("mKategori").value = m.kategori || "";
  $("mSubmitBtn").innerHTML = svgIcon("edit") + "Simpan perubahan";
  $("mCancelBtn").style.display = "inline-flex";
  $("masterForm").scrollIntoView({behavior:"smooth", block:"center"});
}

function cancelEditMaster(){ resetMasterForm(); }

function resetMasterForm(){
  editingMasterId = null;
  const f = ["mSku","mBarcode","mNama"];
  f.forEach(id => { if($(id)) $(id).value = ""; });
  if($("mStokAwal")) $("mStokAwal").value = "0";
  if($("mStokMinimal")) $("mStokMinimal").value = "0";
  if($("mKategori")) $("mKategori").value = "";
  if($("mSubmitBtn")) $("mSubmitBtn").innerHTML = svgIcon("plus") + "Tambah barang";
  if($("mCancelBtn")) $("mCancelBtn").style.display = "none";
}

async function deleteMaster(id){
  const ok = await konfirmasi(
    "Hapus barang ini dari master?",
    "Barang akan disembunyikan dari daftar. Riwayat transaksinya tetap tersimpan dan tidak ikut terhapus.",
    "Hapus barang"
  );
  if(!ok) return;
  setSaveStatus("saving");
  try{
    const res = await API.masterDel(id);
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    refreshMasterTable();
  }catch(e){ tampilGalat(e); }
}

/* ---------------------------------------------------------------- */
/* Router & init                                                      */
/* ---------------------------------------------------------------- */
function renderContent(){
  if(tab==="dashboard") renderDashboard();
  else if(tab==="masuk") renderTransaksiTab("masuk");
  else if(tab==="keluar") renderTransaksiTab("keluar");
  else if(tab==="master") renderMaster();
}

function init(){
  renderNav();
  judulHalaman();
  renderContent();
  setSaveStatus("ok");

  const buka  = $("sisiBuka");
  const tutup = $("sisiTutup");
  const tirai = $("sisiTirai");
  if(buka)  buka.addEventListener("click", bukaSisi);
  if(tutup) tutup.addEventListener("click", tutupSisi);
  if(tirai) tirai.addEventListener("click", tutupSisi);

  document.addEventListener("keydown", function(e){
    if(e.key === "Escape") tutupSisi();
  });
}

document.addEventListener("click", function(e){
  if(!e.target.closest(".picker-wrap")){
    document.querySelectorAll(".picker-list").forEach(el => el.style.display = "none");
  }

  // Menu sisi.
  const nav = e.target.closest && e.target.closest("[data-tab]");
  if(nav){ switchTab(nav.getAttribute("data-tab")); return; }

  // Kartu statistik yang menyaring tabel.
  const kartu = e.target.closest && e.target.closest("[data-statklik]");
  if(kartu){
    const s = kartu.getAttribute("data-statklik");
    dashFilters.status = dashFilters.status === s ? "semua" : s;
    dashFilters.page = 1;
    const sel = $("dashStatus");
    if(sel) sel.value = dashFilters.status;
    refreshDashboard();
    const hasil = $("dashResults");
    if(hasil) hasil.scrollIntoView({ behavior:"smooth", block:"start" });
    return;
  }

  // Angka MASUK / KELUAR di dashboard -> popup riwayat.
  // Didelegasikan dari document supaya tetap bekerja setelah tabel
  // dirender ulang oleh filter atau paginasi.
  const tombol = e.target.closest && e.target.closest("[data-riwayat]");
  if(tombol){
    bukaRiwayat(
      Number(tombol.getAttribute("data-id")),
      tombol.getAttribute("data-riwayat"),
      tombol.getAttribute("data-nama")
    );
  }
});

init();
