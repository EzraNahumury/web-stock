/* ==========================================================================
 * app.js — antarmuka Warehouse AVA
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
                  tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };

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
    tukar:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
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
  { id:"dashboard", label:"Dashboard stok", sub:"Ringkasan stok seluruh barang", grup:"Operasional",
    ikon:'<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>', isi:true },
  { id:"masuk", label:"Barang masuk", sub:"Catat dan telusuri barang yang diterima", grup:"Operasional",
    ikon:'<path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M3 17v2a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2"/>' },
  { id:"keluar", label:"Barang keluar", sub:"Impor picking list PDF atau catat manual", grup:"Operasional",
    ikon:'<path d="M12 21V9"/><polyline points="7 14 12 9 17 14"/><path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"/>' },
  { id:"riwayat", label:"Riwayat", sub:"Stok awal, masuk, keluar, dan akhir per barang", grup:"Operasional",
    ikon:'<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>' },

  { id:"pertukaran", label:"Pertukaran barang", sub:"Produk yang ditukar saat meninjau impor PDF", grup:"Operasional",
    ikon:'<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>' },
  { id:"retur", label:"Retur", sub:"Barang kembali dari pembeli", grup:"Operasional",
    ikon:'<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>' },
  { id:"opname", label:"Laporan stok opname", sub:"Stok sistem dibanding hitungan fisik dan Accurate", grup:"Operasional",
    ikon:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>' },

  { id:"master", label:"Barang", sub:"Kelola katalog, barcode, dan ambang stok", grup:"Master",
    ikon:'<path d="M20.6 13.4L12 22l-9-9V4a1 1 0 0 1 1-1h9l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>' },
  { id:"kategori", label:"Kategori", sub:"Kelola daftar kategori barang", grup:"Master",
    ikon:'<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="7" cy="6" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="17" cy="18" r="1.6" fill="currentColor" stroke="none"/>' },
  { id:"pengguna", label:"Pengguna", sub:"Kelola akun yang bisa masuk ke aplikasi", grup:"Master",
    adminSaja:true,
    ikon:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>' },

  { id:"aktivitas", label:"Log aktivitas", sub:"Jejak siapa melakukan apa, lengkap dengan jamnya", grup:"Sistem",
    adminSaja:true,
    ikon:'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>' },
];

/** Peran pengguna yang sedang masuk. */
function peranSaya(){
  return (window.APP_USER && window.APP_USER.role) || "operator";
}
function sayaAdmin(){ return peranSaya() === "admin"; }

/** Menu yang boleh dilihat pengguna ini. */
function tabTerlihat(){
  return TABS.filter(t => !t.adminSaja || sayaAdmin());
}

/** Jumlah kecil di sisi menu — diisi setelah dashboard dimuat. */
let navHitung = { perluOrder:0 };

function renderNav(){
  const nav = $("sisiNav");
  if(!nav) return;

  let html = "";
  let grupTerakhir = null;

  tabTerlihat().forEach(t => {
    if(t.grup !== grupTerakhir){
      html += '<div class="nav-judul">' + esc(t.grup) + '</div>';
      grupTerakhir = t.grup;
    }
    // Ikon dashboard digambar dengan fill; sisanya dengan stroke.
    const gaya = t.id === "dashboard"
      ? ' fill="currentColor"'
      : ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

    let lencana = "";
    if(t.id === "dashboard" && navHitung.perluOrder > 0){
      lencana = '<span class="nav-jml perhatian">' + fmtNum(navHitung.perluOrder) + '</span>';
    }
    html += '<button type="button" class="nav-btn' + (tab===t.id ? ' aktif' : '') + '"'
      + ' data-tab="' + t.id + '"' + (tab===t.id ? ' aria-current="page"' : '') + '>'
      + '<svg width="17" height="17" viewBox="0 0 24 24"' + gaya + '>' + t.ikon + '</svg>'
      + esc(t.label) + lencana + '</button>';
  });

  nav.innerHTML = html;
}

function judulHalaman(){
  const t = TABS.find(x => x.id === tab);
  if(!t) return;
  const h = $("judulHalaman"), p = $("subJudulHalaman");
  if(h) h.textContent = t.label;
  if(p) p.textContent = t.sub;
  document.title = t.label + " — " + (window.APP_NAMA || "Warehouse AVA");
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
    + '<a class="btn ghost" id="dashExport" href="api/export/pdf.php?jenis=dashboard">' + svgIcon("download") + 'Unduh PDF</a>'
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

  // Tautan unduh mengikuti penyaringan yang sedang aktif, supaya yang
  // tercetak sama persis dengan yang sedang dilihat di layar.
  const unduh = $("dashExport");
  if(unduh){
    unduh.href = "api/export/pdf.php?jenis=dashboard"
      + "&q=" + encodeURIComponent(dashFilters.q)
      + "&kategori=" + encodeURIComponent(dashFilters.kategori)
      + "&status=" + encodeURIComponent(dashFilters.status);
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
    + (sayaAdmin()
        ? '<button type="button" class="btn ghost" onclick="rapikanNama()" title="Ganti nama panjang dari PDF dengan nama pendek di master barang">'
          + svgIcon("tukar") + 'Rapikan nama</button>'
        : '')
    + '<a class="btn ghost" id="'+kind+'Export" href="api/export/pdf.php?jenis='+kind+'">' + svgIcon("download") + 'Unduh PDF</a>'
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
  if(ex) ex.href = "api/export/pdf.php?jenis="+kind+"&q="+encodeURIComponent(f.q)
      + "&dari="+encodeURIComponent(f.dari)+"&sampai="+encodeURIComponent(f.sampai);
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
    pdfImport = { status:"error", header:null, rows:[], fileName:file.name, fileHash:"", tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };
    renderPdfImportStatus(); renderPdfReview();
  };
  pdfImport = { status:"parsing", header:null, rows:[], fileName:file.name, fileHash:"", tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };
  renderPdfImportStatus(); renderPdfReview();
  reader.readAsArrayBuffer(file);
}

async function prosesPdf(arrayBuffer, fileName){
  if(!window["pdfjsLib"]){
    pdfImport = { status:"error", header:null, rows:[], fileName, fileHash:"", tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };
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
      fileName, fileHash: hash, tanggal, cocok:{}, cocokSku:{}, duplikat:null
    };

    if(parsed.rows.length){
      await cekBarcodeMassal();
      samakanDenganMaster();
      await cekDuplikatImpor();
    }
  }catch(err){
    console.error(err);
    pdfImport = { status:"error", header:null, rows:[], fileName, fileHash:"", tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };
  }
  renderPdfImportStatus();
  renderPdfReview();
}

/** Cek semua barcode dan SKU sekaligus, bukan satu permintaan per baris. */
async function cekBarcodeMassal(){
  const barcodes = pdfImport.rows.map(r => r.barcode).filter(b => b);
  const skus     = pdfImport.rows.map(r => r.sku).filter(b => b);
  if(!barcodes.length && !skus.length){
    pdfImport.cocok = {}; pdfImport.cocokSku = {}; return;
  }
  try{
    const res = await API.post("master/cek_barcode.php", { barcodes: barcodes, skus: skus });
    pdfImport.cocok    = res.ditemukan || {};
    pdfImport.cocokSku = res.ditemukan_sku || {};
  }catch(e){ pdfImport.cocok = {}; pdfImport.cocokSku = {}; }
}

/**
 * Ganti nama dan SKU hasil baca PDF dengan yang tercatat di master.
 *
 * Picking list marketplace memakai judul etalase yang panjang, penuh kata
 * kunci pencarian ("Kaos Kaki Futsal Pendek Anti Slip Olahraga Sepak Bola
 * Tebal Sebetis Dewasa... Variant: Putih"). Yang dipakai gudang adalah nama
 * pendek di master. Karena barcodenya sudah cocok, produknya sudah pasti
 * sama — jadi nama master yang dipakai.
 *
 * Setelah itu keadaan tiap baris dipotret sebagai `asli`. Potret inilah
 * pembanding untuk mendeteksi pertukaran: perubahan barcode atau SKU SETELAH
 * titik ini berarti admin sengaja menukar produknya.
 */
function samakanDenganMaster(){
  pdfImport.rows.forEach(r => {
    const m = r.barcode ? pdfImport.cocok[r.barcode] : null;
    if(m && typeof m === "object"){
      r.nama = m.nama;
      r.sku  = m.sku || "";
    }
    r.pilih = true;                       // semua baris tercentang secara bawaan
    r.asli  = { barcode: r.barcode, nama: r.nama, sku: r.sku };
  });
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

/** Apakah baris ini produknya sudah ditukar dari hasil baca PDF? */
function barisDitukar(r){
  if(!r || !r.asli) return false;
  return (r.asli.barcode || "") !== (r.barcode || "")
      || (r.asli.sku || "")     !== (r.sku || "");
}

/** Sel status + ikon pertukaran, dirender ulang sendiri saat baris berubah. */
function selStatusPdf(r, idx){
  let html = pdfRowStatusBadge(r);
  if(barisDitukar(r)){
    html += '<button type="button" class="icon-btn tukar" data-tukar="' + idx + '"'
      + ' title="Produk ditukar - klik untuk melihat perbandingannya"'
      + ' aria-label="Lihat detail pertukaran">' + svgIcon("tukar") + '</button>';
  }
  return html;
}

function pdfReviewRowHtml(r, idx){
  // Nama barang dan No. Pesanan memakai <textarea>, bukan <input>: teks yang
  // lebih panjang dari kolomnya turun ke baris berikutnya, tidak terpotong.
  // Tingginya menyesuaikan isi lewat tumbuhkanArea().
  const dicentang = r.pilih !== false;
  return '<tr class="' + (dicentang ? "" : "tak-dipilih") + '">'
    + '<td><input type="text" class="mono" value="'+esc(r.barcode)+'" oninput="updatePdfRow('+idx+',\'barcode\',this.value)"></td>'
    + '<td><textarea rows="1" oninput="updatePdfRow('+idx+',\'nama\',this.value)">'+esc(r.nama)+'</textarea></td>'
    + '<td><input type="text" class="mono" value="'+esc(r.sku)+'" oninput="updatePdfRow('+idx+',\'sku\',this.value)"></td>'
    + '<td class="num"><input type="number" min="0" style="text-align:right" value="'+(r.qty||0)+'" oninput="updatePdfRow('+idx+',\'qty\',this.value)"></td>'
    + '<td><textarea rows="1" class="mono" oninput="updatePdfRow('+idx+',\'noPesanan\',this.value)">'+esc(r.noPesanan)+'</textarea></td>'
    + '<td><select onchange="updatePdfRow('+idx+',\'keterangan\',this.value)">' + KET_KELUAR.map(k=>'<option'+(r.keterangan===k?' selected':'')+'>'+esc(k)+'</option>').join("") + '</select></td>'
    + '<td id="pdfRowStatus'+idx+'">' + selStatusPdf(r, idx) + '</td>'
    + '<td class="aksi-baris">'
      + '<label class="centang" title="Centang untuk ikut disimpan">'
        + '<input type="checkbox" ' + (dicentang ? "checked" : "") + ' data-pilih="' + idx + '"'
        + ' aria-label="Ikutkan baris ini saat menyimpan"></label>'
      + '<button type="button" class="icon-btn bahaya" onclick="removePdfReviewRow('+idx+')" aria-label="Hapus baris">'+svgIcon("trash")+'</button>'
    + '</td>'
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
  html += '<div class="pilih-bar">'
    + '<label class="centang"><input type="checkbox" id="pdfPilihSemua" checked'
      + ' aria-label="Centang semua baris"> Centang semua</label>'
    + '<span id="pdfRingkasPilih"></span>'
    + '</div>';
  html += '<div class="table-card" style="overflow:auto"><table class="pdf-review"><thead><tr>'
    + ["Barcode","Nama barang","SKU","Qty","No. Pesanan","Keterangan","Status","Pilih"].map(h2=>'<th'+(h2==="Qty"?' class="num"':'')+'>'+h2+'</th>').join("")
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

  // Tinggi textarea baru bisa dihitung setelah elemennya masuk ke halaman.
  tumbuhkanSemuaArea();
  ringkasPilihan();
}

/**
 * Samakan tinggi textarea dengan isinya, supaya teks yang membungkus ke
 * baris kedua tetap terlihat seluruhnya tanpa perlu digulir.
 */
function tumbuhkanArea(el){
  if(!el) return;
  el.style.height = "auto";
  el.style.height = (el.scrollHeight + 2) + "px";
}

/** Terapkan ke seluruh textarea di tabel review. */
function tumbuhkanSemuaArea(){
  const body = $("pdfReviewBody");
  if(!body) return;
  body.querySelectorAll("textarea").forEach(tumbuhkanArea);
}

// Enter di dalam textarea akan menyisipkan baris baru — tidak diinginkan
// untuk nama barang maupun nomor pesanan. Tekanan Enter diabaikan, dan
// tinggi disesuaikan ulang setiap kali isinya berubah.
document.addEventListener("keydown", function(e){
  if(e.key === "Enter" && e.target.matches && e.target.matches(".pdf-review textarea")){
    e.preventDefault();
  }
});
document.addEventListener("input", function(e){
  if(e.target.matches && e.target.matches(".pdf-review textarea")){
    tumbuhkanArea(e.target);
  }
});

let cekBarcodeTertunda = null;
function updatePdfRow(idx, field, value){
  const r = pdfImport.rows[idx];
  if(!r) return;
  r[field] = field==="qty" ? (parseInt(value,10)||0) : value;

  // Barcode DAN SKU sama-sama menentukan produk mana yang stoknya dipotong,
  // jadi keduanya memicu pencarian ulang ke master.
  if(field==="barcode" || field==="sku"){
    segarkanSelStatus(idx);
    clearTimeout(cekBarcodeTertunda);
    cekBarcodeTertunda = setTimeout(async ()=>{
      await cekBarcodeMassal();
      ikutkanDataMaster(idx, field);
      pdfImport.rows.forEach((row,i)=> segarkanSelStatus(i));
    }, 500);
  }
}

/** Gambar ulang sel Status satu baris saja, tanpa menyentuh isian lain. */
function segarkanSelStatus(idx){
  const el = $("pdfRowStatus"+idx);
  const r  = pdfImport.rows[idx];
  if(el && r) el.innerHTML = selStatusPdf(r, idx);
}

/**
 * Setelah barcode atau SKU sebuah baris diganti, ikutkan data master.
 *
 * Barcode adalah kunci yang menentukan barang mana yang stoknya berkurang.
 * Membiarkan nama lama menempel pada barcode baru membuat catatan barang
 * keluar menyebut produk yang berbeda dari yang benar-benar dipotong.
 *
 * @param {string} asal kolom yang barusan diubah: "barcode" atau "sku"
 */
function ikutkanDataMaster(idx, asal){
  const r = pdfImport.rows[idx];
  if(!r) return;

  let m = null;
  if(asal === "sku" && r.sku){
    m = pdfImport.cocokSku[r.sku];
    // SKU tidak dijamin unik di master; beri tahu agar admin memastikan.
    if(m && m.ganda) toast("SKU " + r.sku + " dipakai lebih dari satu barang. Pakai barcode bila ragu.", "err");
    if(m && typeof m === "object") r.barcode = m.barcode || r.barcode;
  } else if(r.barcode){
    m = pdfImport.cocok[r.barcode];
  }
  if(!m || typeof m !== "object") return;   // tidak dikenal, biarkan apa adanya

  const namaLama = r.nama;
  r.nama = m.nama;
  r.sku  = m.sku || "";

  // Perbarui isi kolomnya langsung, bukan menggambar ulang seluruh tabel -
  // menggambar ulang akan merebut fokus dari kolom yang sedang diketik.
  const baris = document.querySelectorAll("#pdfReviewBody tr")[idx];
  if(baris){
    const inputBc  = baris.querySelector("td:nth-child(1) input");
    const areaNama = baris.querySelector("td:nth-child(2) textarea");
    const inputSku = baris.querySelector("td:nth-child(3) input");
    if(inputBc && document.activeElement !== inputBc)   inputBc.value  = r.barcode;
    if(areaNama){ areaNama.value = r.nama; tumbuhkanArea(areaNama); }
    if(inputSku && document.activeElement !== inputSku) inputSku.value = r.sku;
  }
  segarkanSelStatus(idx);

  if(namaLama !== r.nama) toast("Produk disesuaikan: " + r.nama);
}


/* ---------------------------------------------------------------- */
/* Centang baris & dialog pertukaran                                 */
/* ---------------------------------------------------------------- */

/** Baris baru ikut tercentang, dan punya potret asal seperti baris dari PDF. */
function siapkanBarisBaru(r){
  if(r.pilih === undefined) r.pilih = true;
  if(!r.asli) r.asli = { barcode:r.barcode || "", nama:r.nama || "", sku:r.sku || "" };
  return r;
}

function setPilihSemua(nilai){
  pdfImport.rows.forEach(r => { r.pilih = !!nilai; });
  renderPdfReview();
}

/** Ringkasan berapa baris yang akan ikut tersimpan. */
function ringkasPilihan(){
  const total = pdfImport.rows.length;
  const dipilih = pdfImport.rows.filter(r => r.pilih !== false).length;
  const el = $("pdfRingkasPilih");
  if(el){
    el.innerHTML = '<b>' + fmtNum(dipilih) + '</b> dari ' + fmtNum(total)
      + ' baris akan disimpan.'
      + (dipilih < total ? ' Baris yang tidak dicentang dilewati.' : '');
  }
  const kotakSemua = $("pdfPilihSemua");
  if(kotakSemua){
    kotakSemua.checked = dipilih === total && total > 0;
    kotakSemua.indeterminate = dipilih > 0 && dipilih < total;
  }
}

/** Dialog perbandingan produk lama vs produk baru. */
function bukaDialogTukar(idx){
  const r = pdfImport.rows[idx];
  if(!r || !r.asli) return;

  const sisi = (judul, barcode, nama, sku, warna) =>
    '<div class="tukar-sisi ' + warna + '">'
    + '<div class="tukar-judul">' + esc(judul) + '</div>'
    + '<div class="tukar-nama">' + esc(nama || "(tanpa nama)") + '</div>'
    + '<div class="tukar-kode">Barcode: <b>' + esc(barcode || "-") + '</b></div>'
    + '<div class="tukar-kode">SKU: <b>' + esc(sku || "-") + '</b></div>'
    + '</div>';

  const beda = [];
  if((r.asli.barcode || "") !== (r.barcode || "")) beda.push("barcode");
  if((r.asli.sku || "") !== (r.sku || "")) beda.push("SKU");

  const m = modalKonten(true);
  m.isi(
    '<div class="modal-head"><div>'
    + '<h3>Pertukaran produk</h3>'
    + '<div style="font-size:12.5px; color:var(--slate); margin-top:2px;">'
    + 'Yang diubah: ' + esc(beda.join(" dan ")) + '. Stok yang dipotong berpindah ke produk baru.</div>'
    + '</div>'
    + '<button type="button" class="icon-btn" data-act="tutup" aria-label="Tutup">' + svgIcon("x") + '</button>'
    + '</div>'
    + '<div class="tukar-banding">'
      + sisi("Produk dari PDF", r.asli.barcode, r.asli.nama, r.asli.sku, "lama")
      + '<div class="tukar-panah">' + svgIcon("tukar") + '</div>'
      + sisi("Produk pengganti", r.barcode, r.nama, r.sku, "baru")
    + '</div>'
    + '<div class="info-box" style="margin-top:14px; margin-bottom:0;">'
    + 'Qty <b>' + fmtNum(r.qty || 0) + '</b> akan dipotong dari produk pengganti. '
    + 'Pertukaran ini tercatat di menu <b>Pertukaran barang</b> setelah disimpan.</div>'
    + '<div class="modal-act" style="margin-top:16px;">'
      + '<button type="button" class="btn ghost" data-act="batalkan-tukar">Kembalikan ke produk PDF</button>'
      + '<button type="button" class="btn" data-act="tutup">Tutup</button>'
    + '</div>'
  );

  m.el.addEventListener("click", ev => {
    if(ev.target.closest('[data-act="batalkan-tukar"]')){
      r.barcode = r.asli.barcode;
      r.nama    = r.asli.nama;
      r.sku     = r.asli.sku;
      m.tutup();
      renderPdfReview();
      toast("Dikembalikan ke produk asli dari PDF.");
    }
  });
}

function addPdfReviewRow(){
  if(pdfImport.status !== "ready"){ pdfImport.status = "ready"; pdfImport.header = pdfImport.header || {}; }
  if(!pdfImport.tanggal) pdfImport.tanggal = todayISO();
  pdfImport.rows.push(siapkanBarisBaru({ barcode:"", nama:"", sku:"", qty:0, noPesanan:"", keterangan:"Pesanan MP" }));
  renderPdfReview();
}

function removePdfReviewRow(idx){
  pdfImport.rows.splice(idx,1);
  if(!pdfImport.rows.length){ pdfImport.status = "empty"; }
  renderPdfImportStatus();
  renderPdfReview();
}

function cancelPdfReview(){
  pdfImport = { status:"idle", header:null, rows:[], fileName:"", fileHash:"", tanggal:"", cocok:{}, cocokSku:{}, duplikat:null };
  const fi = $("pdfFileInput");
  if(fi) fi.value = "";
  renderPdfImportStatus();
  renderPdfReview();
}

async function confirmPdfReview(){
  const rows = pdfImport.rows;
  if(!rows.length){ toast("Tidak ada data untuk disimpan.", "err"); return; }

  // Hanya baris tercentang yang divalidasi dan disimpan.
  const dipilih = rows.filter(r => r.pilih !== false);
  if(!dipilih.length){ toast("Centang minimal satu baris untuk disimpan.", "err"); return; }

  const invalid = dipilih.some(r => !r.barcode || !r.qty);
  if(invalid){ toast("Lengkapi Barcode dan Qty di semua baris yang dicentang.", "err"); return; }

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
    + '<a class="btn ghost" id="masterExport" href="api/export/pdf.php?jenis=master">'+svgIcon("download")+'Unduh PDF</a></div>';
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

  const unduhM = $("masterExport");
  if(unduhM) unduhM.href = "api/export/pdf.php?jenis=master&q=" + encodeURIComponent(masterFilters.q);

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


/**
 * Samakan nama pada catatan transaksi dengan nama di master barang.
 *
 * Catatan yang dibuat sebelum perbaikan "nama ikut master" masih memakai
 * judul etalase marketplace dari picking list — panjang dan sulit dibaca.
 * Karena barcodenya sudah cocok master, produknya sudah pasti sama.
 *
 * Dihitung dulu dan ditunjukkan contohnya sebelum apa pun diubah: ini
 * menulis ulang banyak baris sekaligus, jadi admin harus tahu persis apa
 * yang akan terjadi.
 */
async function rapikanNama(){
  let pra;
  try{
    pra = await API.post("master/samakan_nama.php", { pratinjau:true });
  }catch(e){ tampilGalat(e); return; }

  if(!pra.jumlah){
    toast("Semua nama sudah sama dengan master. Tidak ada yang perlu dirapikan.");
    return;
  }

  const contoh = (pra.contoh || []).map(c =>
    '<div class="rapikan-contoh">'
    + '<div class="rapikan-lama">' + esc(c.nama_lama) + '</div>'
    + '<div class="rapikan-baru">' + svgIcon("tukar") + esc(c.nama_baru) + '</div>'
    + '</div>'
  ).join("");

  const ok = await konfirmasiIsi(
    "Rapikan " + fmtNum(pra.jumlah) + " nama catatan?",
    '<p>Nama panjang dari PDF akan diganti dengan nama pendek yang tercatat di '
    + '<b>Master barang</b>. Barcode, jumlah, tanggal, dan nomor pesanan tidak berubah.</p>'
    + '<div class="rapikan-daftar">' + contoh + '</div>'
    + '<div class="info-box" style="margin:12px 0 0;">Baris yang barcodenya belum terdaftar '
    + 'di master dibiarkan apa adanya — tidak ada nama master untuk menggantinya.</div>',
    "Rapikan sekarang"
  );
  if(!ok) return;

  setSaveStatus("saving");
  try{
    const res = await API.post("master/samakan_nama.php", {});
    setSaveStatus("ok");
    toast(res.pesan || "Nama dirapikan.");
    if(tab === "masuk" || tab === "keluar") renderTransaksiTable(tab);
    else if(tab === "riwayat") refreshRiwayat();
  }catch(e){ tampilGalat(e); }
}

/** Dialog konfirmasi dengan isi HTML, bukan sekadar satu kalimat. */
function konfirmasiIsi(judul, isiHtml, labelYa){
  return new Promise(resolve => {
    const m = modalKonten(true);
    let selesai = false;
    m.isi(
      '<div class="modal-head"><h3>' + esc(judul) + '</h3>'
      + '<button type="button" class="icon-btn" data-act="tutup" aria-label="Tutup">' + svgIcon("x") + '</button></div>'
      + '<div style="font-size:13px; color:var(--slate); line-height:1.55;">' + isiHtml + '</div>'
      + '<div class="modal-act" style="margin-top:16px;">'
        + '<button type="button" class="btn ghost" data-act="tutup">Batal</button>'
        + '<button type="button" class="btn" data-act="ya">' + esc(labelYa || "Lanjutkan") + '</button>'
      + '</div>'
    );
    m.el.addEventListener("click", ev => {
      if(ev.target.closest('[data-act="ya"]')){ selesai = true; m.tutup(); resolve(true); }
      else if(ev.target.closest('[data-act="tutup"]')){ selesai = true; m.tutup(); resolve(false); }
    });
    // Tutup lewat Escape atau klik latar juga dihitung sebagai batal.
    const amati = setInterval(() => {
      if(!document.body.contains(m.el)){
        clearInterval(amati);
        if(!selesai) resolve(false);
      }
    }, 200);
  });
}

/* ================================================================== */
/* Riwayat — gabungan barang masuk dan keluar                         */
/* ================================================================== */
let riwayatFilter = { q:"", dari:"", sampai:"", kategori:"Semua", page:1 };

function renderRiwayat(){
  let html = '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search")
      + '<input type="text" id="rwCari" placeholder="Cari nama barang, SKU, atau barcode&hellip;" oninput="onRiwayatCari()"></div>'
    + '<select id="rwKategori" onchange="onRiwayatFilter()"><option value="Semua">Semua kategori</option></select>'
    + '<div class="daterange">Dari <input type="date" id="rwDari" onchange="onRiwayatFilter()">'
      + ' s/d <input type="date" id="rwSampai" onchange="onRiwayatFilter()"></div>'
    + '<button type="button" class="btn ghost" onclick="resetRiwayat()">' + svgIcon("x") + 'Reset</button>'
    + '<a class="btn ghost" id="rwUnduh" href="api/export/pdf.php?jenis=riwayat">' + svgIcon("download") + 'Unduh PDF</a>'
    + '</div>'
    + '<div class="stat-row" id="rwRingkas"></div>'
    + '<div id="rwHasil"></div>';

  $("content").innerHTML = html;
  $("rwCari").value   = riwayatFilter.q;
  $("rwDari").value   = riwayatFilter.dari;
  $("rwSampai").value = riwayatFilter.sampai;
  refreshRiwayat();
}

const onRiwayatCari = debounce(function(){
  riwayatFilter.q = $("rwCari").value;
  riwayatFilter.page = 1;
  refreshRiwayat();
}, 300);

function onRiwayatFilter(){
  riwayatFilter.q        = $("rwCari").value;
  riwayatFilter.kategori = $("rwKategori").value;
  riwayatFilter.dari     = $("rwDari").value;
  riwayatFilter.sampai   = $("rwSampai").value;
  riwayatFilter.page     = 1;
  refreshRiwayat();
}

function resetRiwayat(){
  riwayatFilter = { q:"", dari:"", sampai:"", kategori:"Semua", page:1 };
  $("rwCari").value = ""; $("rwKategori").value = "Semua";
  $("rwDari").value = ""; $("rwSampai").value = "";
  refreshRiwayat();
}

function riwayatGoPage(p){ riwayatFilter.page = p; refreshRiwayat(); }

async function refreshRiwayat(){
  const wadah = $("rwHasil");
  if(!wadah) return;

  let d;
  try{
    d = await API.get("riwayat/list.php", {
      q: riwayatFilter.q, kategori: riwayatFilter.kategori,
      dari: riwayatFilter.dari, sampai: riwayatFilter.sampai,
      page: riwayatFilter.page
    });
  }catch(e){ tampilGalat(e); return; }

  // Dropdown kategori diisi dari server, sama sumbernya dengan dashboard.
  const selKat = $("rwKategori");
  if(selKat && d.kategori_options){
    const isi = ['Semua'].concat(d.kategori_options)
      .map(k => '<option value="' + esc(k) + '">' + esc(k === "Semua" ? "Semua kategori" : k) + '</option>').join("");
    if(selKat.innerHTML !== isi){
      selKat.innerHTML = isi;
      selKat.value = riwayatFilter.kategori;
    }
  }

  // Tautan unduh mengikuti penyaringan yang sedang aktif.
  const unduh = $("rwUnduh");
  if(unduh){
    unduh.href = "api/export/pdf.php?jenis=riwayat"
      + "&q=" + encodeURIComponent(riwayatFilter.q)
      + "&kategori=" + encodeURIComponent(riwayatFilter.kategori)
      + "&dari=" + encodeURIComponent(riwayatFilter.dari)
      + "&sampai=" + encodeURIComponent(riwayatFilter.sampai);
  }

  const ringkas = $("rwRingkas");
  if(ringkas){
    ringkas.innerHTML =
        statCard({ label:"Barang", nilai:d.total, ikon:"sku", nada:"biru",
                   kaki:riwayatFilter.kategori && riwayatFilter.kategori !== "Semua"
                        ? "kategori " + riwayatFilter.kategori : "seluruh kategori" })
      + statCard({ label:"Stok awal", nilai:d.total_awal, ikon:"unit", nada:"",
                   kaki:riwayatFilter.dari ? "posisi sebelum " + fmtDate(riwayatFilter.dari) : "stok awal master" })
      + statCard({ label:"Stok masuk", nilai:d.total_masuk, ikon:"tag", nada:"safe", tone:"safe",
                   kaki:"unit diterima" })
      + statCard({ label:"Stok keluar", nilai:d.total_keluar, ikon:"alert", nada:"danger", tone:"danger",
                   kaki:"unit dikeluarkan" })
      + statCard({ label:"Stok akhir", nilai:d.total_akhir, ikon:"unit", nada:"biru",
                   kaki:"posisi setelah periode" });
    ringkas.querySelectorAll(".stat-value[data-nilai]").forEach(n =>
      Grafik.angkaNaik(n, Number(n.getAttribute("data-nilai"))));
  }

  let baris = d.rows.map(r =>
    '<tr>'
    + '<td class="mono" style="font-size:11px; color:var(--slateLo)">' + esc(r.sku || "-") + '</td>'
    + '<td><div class="item-name">' + esc(r.nama) + '</div>'
      + '<div class="item-sub">' + esc(r.barcode) + '</div></td>'
    + '<td>' + (r.kategori
        ? '<span class="badge netral">' + esc(r.kategori) + '</span>'
        : '<span style="color:var(--slateLo); font-size:11px">-</span>') + '</td>'
    + '<td class="num">' + fmtNum(r.stok_awal) + '</td>'
    + '<td class="num" style="font-weight:700; color:var(--safe)">'
      + (r.masuk > 0 ? "+" + fmtNum(r.masuk) : '<span style="color:var(--lineStrong); font-weight:400">—</span>') + '</td>'
    + '<td class="num" style="font-weight:700; color:var(--danger)">'
      + (r.keluar > 0 ? "-" + fmtNum(r.keluar) : '<span style="color:var(--lineStrong); font-weight:400">—</span>') + '</td>'
    + '<td class="num"><span class="stok-akhir-num">' + fmtNum(r.stok_akhir) + '</span></td>'
    + '</tr>'
  ).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="7">'
      + 'Tidak ada barang pada penyaring ini.</td></tr>';
  }

  wadah.innerHTML =
    '<div class="info-box"><b>Stok awal</b> adalah posisi barang sebelum tanggal mulai; '
    + '<b>stok akhir</b> posisinya setelah seluruh pergerakan pada rentang itu dihitung. '
    + 'Barang yang tidak bergerak tetap ditampilkan — kalau disembunyikan, laporan per '
    + 'kategori jadi tidak lengkap.</div>'
    + '<div class="table-card"><table style="min-width:900px"><thead><tr>'
    + ["SKU","Barang","Kategori","Stok awal","Stok masuk","Stok keluar","Stok akhir"]
        .map((h,i)=>'<th'+(i>=3?' class="num"':'')+'>'+h+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + paginationBar(d.total, d.page, d.total_pages, "riwayatGoPage")
    + '</div>';
}

/* ================================================================== */
/* Pertukaran barang                                                  */
/* ================================================================== */
let tukarFilter = { q:"", dari:"", sampai:"", page:1 };

function renderPertukaran(){
  $("content").innerHTML =
    '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search")
      + '<input type="text" id="tkCari" placeholder="Cari barcode, nama produk, atau no. pesanan…" oninput="onTukarCari()"></div>'
    + '<div class="daterange">Dari <input type="date" id="tkDari" onchange="onTukarFilter()">'
      + ' s/d <input type="date" id="tkSampai" onchange="onTukarFilter()"></div>'
    + '<button type="button" class="btn ghost" onclick="resetTukar()">' + svgIcon("x") + 'Reset</button>'
    + '<a class="btn ghost" id="tkUnduh" href="api/export/pdf.php?jenis=pertukaran">' + svgIcon("download") + 'Unduh PDF</a>'
    + '</div>'
    + '<div class="stat-row" id="tkRingkas"></div>'
    + '<div id="tkHasil"></div>';
  $("tkCari").value   = tukarFilter.q;
  $("tkDari").value   = tukarFilter.dari;
  $("tkSampai").value = tukarFilter.sampai;
  refreshPertukaran();
}

const onTukarCari = debounce(function(){
  tukarFilter.q = $("tkCari").value;
  tukarFilter.page = 1;
  refreshPertukaran();
}, 300);

function onTukarFilter(){
  tukarFilter.q      = $("tkCari").value;
  tukarFilter.dari   = $("tkDari").value;
  tukarFilter.sampai = $("tkSampai").value;
  tukarFilter.page   = 1;
  refreshPertukaran();
}

function resetTukar(){
  tukarFilter = { q:"", dari:"", sampai:"", page:1 };
  $("tkCari").value = ""; $("tkDari").value = ""; $("tkSampai").value = "";
  refreshPertukaran();
}

function tukarGoPage(p){ tukarFilter.page = p; refreshPertukaran(); }

async function refreshPertukaran(){
  const wadah = $("tkHasil");
  if(!wadah) return;

  let d;
  try{
    d = await API.get("pertukaran/list.php", {
      q: tukarFilter.q, dari: tukarFilter.dari,
      sampai: tukarFilter.sampai, page: tukarFilter.page
    });
  }catch(e){ tampilGalat(e); return; }

  const unduh = $("tkUnduh");
  if(unduh){
    unduh.href = "api/export/pdf.php?jenis=pertukaran"
      + "&q=" + encodeURIComponent(tukarFilter.q)
      + "&dari=" + encodeURIComponent(tukarFilter.dari)
      + "&sampai=" + encodeURIComponent(tukarFilter.sampai);
  }

  const ringkas = $("tkRingkas");
  if(ringkas){
    ringkas.innerHTML =
        statCard({ label:"Pertukaran", nilai:d.total, ikon:"tag", nada:"amber",
                   kaki:"baris yang ditukar" })
      + statCard({ label:"Unit berpindah", nilai:d.total_unit, ikon:"unit", nada:"biru",
                   kaki:"pcs pindah produk" });
    ringkas.querySelectorAll(".stat-value[data-nilai]").forEach(n =>
      Grafik.angkaNaik(n, Number(n.getAttribute("data-nilai"))));
  }

  const labelAlasan = { barcode:"Barcode", sku:"SKU", keduanya:"Barcode & SKU" };

  let baris = d.rows.map(r =>
    '<tr>'
    + '<td style="white-space:nowrap">' + fmtDate(r.tanggal) + '</td>'
    + '<td><div class="item-name" style="color:var(--danger)">' + esc(r.nama_lama || "(tanpa nama)") + '</div>'
      + '<div class="item-sub">' + esc(r.barcode_lama || "-")
      + (r.sku_lama ? ' · ' + esc(r.sku_lama) : '') + '</div></td>'
    + '<td class="num" style="color:var(--slateLo)">' + svgIcon("tukar") + '</td>'
    + '<td><div class="item-name" style="color:var(--safe)">' + esc(r.nama_baru || "(tanpa nama)") + '</div>'
      + '<div class="item-sub">' + esc(r.barcode_baru || "-")
      + (r.sku_baru ? ' · ' + esc(r.sku_baru) : '') + '</div></td>'
    + '<td class="num" style="font-weight:700">' + fmtNum(r.jumlah) + '</td>'
    + '<td><span class="badge rendah">' + esc(labelAlasan[r.alasan] || r.alasan) + '</span></td>'
    + '<td class="mono" style="font-size:11px; color:var(--slateLo)">' + esc(r.no_pesanan || "-") + '</td>'
    + '<td style="font-size:11.5px; color:var(--slateLo)">' + esc(r.oleh || "-") + '</td>'
    + '</tr>'
  ).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="8">'
      + 'Belum ada pertukaran produk pada rentang ini.</td></tr>';
  }

  wadah.innerHTML =
    '<div class="info-box">Tercatat di sini ketika admin mengganti barcode atau SKU sebuah baris '
    + 'saat meninjau impor PDF, sehingga stok yang dipotong berpindah ke produk lain.</div>'
    + '<div class="table-card"><table style="min-width:940px"><thead><tr>'
    + ["Tanggal","Produk dari PDF","","Produk pengganti","Qty","Yang diubah","No. Pesanan","Oleh"]
        .map((h,i)=>'<th'+(i===4?' class="num"':'')+'>'+esc(h)+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + paginationBar(d.total, d.page, d.total_pages, "tukarGoPage")
    + '</div>';
}

/* ================================================================== */
/* Retur barang                                                       */
/* ================================================================== */
let returFilter = { q:"", dari:"", sampai:"", status:"", page:1 };
let editReturId = null;
let returStatusOptions = [];
let returStatusMasuk = "Lengkap";   // ditimpa oleh jawaban server

function renderRetur(){
  let html = '<form class="form-card" id="rtForm" onsubmit="submitRetur(event)">'
    + '<div class="form-grid">'
    + '<div><label class="field-label" for="rtTanggal">Tanggal</label>'
      + '<input type="date" id="rtTanggal" value="' + todayISO() + '"></div>'
    + '<div class="span2"><label class="field-label" for="rtNoPesanan">No. pesanan</label>'
      + '<input type="text" id="rtNoPesanan" maxlength="100" placeholder="Contoh: 260504HBRHB311"></div>'
    + '<div class="span2 picker-wrap"><label class="field-label" for="rtPicker">Cari barang (nama / SKU / barcode)</label>'
      + '<input type="text" id="rtPicker" placeholder="Ketik nama, SKU, atau barcode&hellip;" autocomplete="off"'
        + ' oninput="onReturPicker()" onfocus="onReturPicker()">'
      + '<div id="rtPickerList" class="picker-list" style="display:none"></div></div>'
    + '<div><label class="field-label" for="rtSku">SKU</label>'
      + '<input type="text" id="rtSku" class="mono" maxlength="50" placeholder="Contoh: AV-0063"></div>'
    + '<div><label class="field-label" for="rtBarcode">Barcode</label>'
      + '<input type="text" id="rtBarcode" class="mono" maxlength="50" placeholder="Opsional"></div>'
    + '<div class="span2"><label class="field-label" for="rtNama">Nama produk</label>'
      + '<input type="text" id="rtNama" maxlength="255" placeholder="Terisi sendiri bila barangnya dikenal"></div>'
    + '<div><label class="field-label" for="rtJumlah">Qty</label>'
      + '<input type="number" id="rtJumlah" min="1" value="1"></div>'
    + '<div><label class="field-label" for="rtStatus">Keterangan retur</label>'
      + '<select id="rtStatus"></select></div>'
    + '<div class="span2"><label class="field-label" for="rtKet">Ket.</label>'
      + '<input type="text" id="rtKet" maxlength="255" placeholder="Contoh: tidak bisa di proses"></div>'
    + '</div>'
    + '<div style="display:flex; gap:8px;">'
      + '<button type="submit" class="btn" id="rtSubmit">' + svgIcon("plus") + 'Catat retur</button>'
      + '<button type="button" class="btn ghost" id="rtBatal" style="display:none" onclick="batalEditRetur()">'
        + svgIcon("x") + 'Batal</button>'
    + '</div></form>'

    + '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search")
      + '<input type="text" id="rtCari" placeholder="Cari no. pesanan, SKU, atau nama produk&hellip;" oninput="onReturCari()"></div>'
    + '<select id="rtFStatus" onchange="onReturFilter()"><option value="">Semua keterangan</option></select>'
    + '<div class="daterange">Dari <input type="date" id="rtDari" onchange="onReturFilter()">'
      + ' s/d <input type="date" id="rtSampai" onchange="onReturFilter()"></div>'
    + '<button type="button" class="btn ghost" onclick="resetRetur()">' + svgIcon("x") + 'Reset</button>'
    + '<a class="btn ghost" id="rtUnduh" href="api/export/pdf.php?jenis=retur">' + svgIcon("download") + 'Unduh PDF</a>'
    + '</div>'
    + '<div class="stat-row" id="rtRingkas"></div>'
    + '<div id="rtHasil"></div>';

  $("content").innerHTML = html;
  $("rtCari").value   = returFilter.q;
  $("rtDari").value   = returFilter.dari;
  $("rtSampai").value = returFilter.sampai;
  refreshRetur();
}

const onReturPicker = debounce(async function(){
  const el = $("rtPicker"), listEl = $("rtPickerList");
  if(!el || !listEl) return;
  const q = el.value.trim();
  if(!q){ listEl.style.display = "none"; listEl.innerHTML = ""; return; }

  let data;
  try{ data = await API.masterPick(q); }catch(e){ return; }
  if(el.value.trim() !== q) return;   // ketikan sudah berubah saat respons tiba

  if(!data.rows.length){
    listEl.innerHTML = '<div class="picker-item">Tidak ditemukan — isi manual di bawah.</div>';
    listEl.style.display = "block";
    return;
  }
  listEl.innerHTML = data.rows.map(m =>
    '<div class="picker-item" data-bc="' + esc(m.barcode) + '" data-sku="' + esc(m.sku || "") + '"'
    + ' data-nama="' + esc(m.nama) + '" onclick="pilihBarangRetur(this)">'
    + '<div>' + esc(m.nama) + '</div>'
    + '<div class="sub">' + esc(m.sku || "-") + ' · ' + esc(m.barcode) + '</div></div>'
  ).join("");
  listEl.style.display = "block";
}, 250);

function pilihBarangRetur(el){
  $("rtBarcode").value = el.getAttribute("data-bc") || "";
  $("rtSku").value     = el.getAttribute("data-sku") || "";
  $("rtNama").value    = el.getAttribute("data-nama") || "";
  $("rtPicker").value  = el.getAttribute("data-nama") || "";
  $("rtPickerList").style.display = "none";
}

const onReturCari = debounce(function(){
  returFilter.q = $("rtCari").value;
  returFilter.page = 1;
  refreshRetur();
}, 300);

function onReturFilter(){
  returFilter.q      = $("rtCari").value;
  returFilter.status = $("rtFStatus").value;
  returFilter.dari   = $("rtDari").value;
  returFilter.sampai = $("rtSampai").value;
  returFilter.page   = 1;
  refreshRetur();
}

function resetRetur(){
  returFilter = { q:"", dari:"", sampai:"", status:"", page:1 };
  $("rtCari").value = ""; $("rtFStatus").value = "";
  $("rtDari").value = ""; $("rtSampai").value = "";
  refreshRetur();
}

function returGoPage(p){ returFilter.page = p; refreshRetur(); }

async function refreshRetur(){
  const wadah = $("rtHasil");
  if(!wadah) return;

  let d;
  try{
    d = await API.get("retur/list.php", {
      q: returFilter.q, status: returFilter.status,
      dari: returFilter.dari, sampai: returFilter.sampai, page: returFilter.page
    });
  }catch(e){ tampilGalat(e); return; }

  // Pilihan status datang dari server supaya layar dan validasi tak pernah beda.
  if(d.status_masuk) returStatusMasuk = d.status_masuk;
  if(d.status_options && d.status_options.length){
    returStatusOptions = d.status_options;
    const sel = $("rtStatus");
    if(sel && !sel.options.length){
      sel.innerHTML = returStatusOptions.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join("");
    }
    const fs = $("rtFStatus");
    if(fs && fs.options.length <= 1){
      fs.innerHTML = '<option value="">Semua keterangan</option>'
        + returStatusOptions.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join("");
      fs.value = returFilter.status;
    }
  }

  const unduh = $("rtUnduh");
  if(unduh){
    unduh.href = "api/export/pdf.php?jenis=retur"
      + "&q=" + encodeURIComponent(returFilter.q)
      + "&status=" + encodeURIComponent(returFilter.status)
      + "&dari=" + encodeURIComponent(returFilter.dari)
      + "&sampai=" + encodeURIComponent(returFilter.sampai);
  }

  const ringkas = $("rtRingkas");
  if(ringkas){
    ringkas.innerHTML =
        statCard({ label:"Retur", nilai:d.total, ikon:"tag", nada:"biru", kaki:"baris tercatat" })
      + statCard({ label:"Unit diretur", nilai:d.total_unit, ikon:"unit", nada:"", kaki:"pcs dikembalikan" })
      + statCard({ label:"Masuk stok", nilai:d.unit_ke_stok, ikon:"sku", nada:"safe", tone:"safe",
                   kaki:"sudah lengkap" })
      + statCard({ label:"Tertahan", nilai:d.unit_tertahan, ikon:"alert", nada:"amber",
                   kaki:"belum menambah stok" });
    ringkas.querySelectorAll(".stat-value[data-nilai]").forEach(n =>
      Grafik.angkaNaik(n, Number(n.getAttribute("data-nilai"))));
  }

  let baris = d.rows.map(r => {
    // Yang menentukan stok bertambah adalah statusnya. masuk_id tetap terisi
    // meski returnya dibatalkan, supaya baris barang masuknya bisa dipakai
    // ulang kalau statusnya dikembalikan.
    const masukStok = r.status === returStatusMasuk;
    return '<tr>'
      + '<td style="white-space:nowrap">' + fmtDate(r.tanggal) + '</td>'
      + '<td class="mono" style="font-size:11px">' + esc(r.no_pesanan || "-") + '</td>'
      + '<td class="mono" style="font-size:11px; color:var(--slateLo)">' + esc(r.sku || "-") + '</td>'
      + '<td><div class="item-name">' + esc(r.nama)
        + (r.master_id === null ? '<span class="flag-gen">TAK DIKENAL</span>' : '') + '</div>'
        + '<div class="item-sub">' + esc(r.barcode || "-") + '</div></td>'
      + '<td class="num" style="font-weight:700">' + fmtNum(r.jumlah) + '</td>'
      + '<td><span class="badge ' + (masukStok ? 'aman' : 'kritis') + '">' + esc(r.status) + '</span>'
        + (masukStok ? '<div class="item-sub" style="font-family:Inter">stok bertambah</div>' : '') + '</td>'
      + '<td style="font-size:11.5px; color:var(--slate)">' + esc(r.keterangan || "-") + '</td>'
      + '<td class="num" style="white-space:nowrap">'
        + '<button class="icon-btn" onclick="editRetur(' + r.id + ')" aria-label="Ubah retur">' + svgIcon("edit") + '</button>'
        + '<button class="icon-btn bahaya" onclick="hapusRetur(' + r.id + ')" aria-label="Hapus retur">' + svgIcon("trash") + '</button>'
        + '</td>'
      + '</tr>';
  }).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="8">Belum ada retur pada penyaring ini.</td></tr>';
  }

  returRows = d.rows;

  wadah.innerHTML =
    '<div class="info-box">Retur berketerangan <b>Lengkap</b> langsung menambah stok lewat '
    + 'barang masuk "Retur Masuk". Yang belum selesai dicatat saja dan belum menyentuh stok, '
    + 'sampai keterangannya diubah.</div>'
    + '<div class="table-card"><table style="min-width:980px"><thead><tr>'
    + ["Tanggal","No. pesanan","SKU","Nama produk","Qty","Keterangan retur","Ket.",""]
        .map((h,i)=>'<th'+(i===4?' class="num"':'')+'>'+esc(h)+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + paginationBar(d.total, d.page, d.total_pages, "returGoPage")
    + '</div>';
}

let returRows = [];

function editRetur(id){
  const r = returRows.find(x => x.id === id);
  if(!r) return;
  editReturId = id;
  $("rtTanggal").value   = r.tanggal;
  $("rtNoPesanan").value = r.no_pesanan || "";
  $("rtSku").value       = r.sku || "";
  $("rtBarcode").value   = r.barcode || "";
  $("rtNama").value      = r.nama || "";
  $("rtPicker").value    = r.nama || "";
  $("rtJumlah").value    = r.jumlah;
  $("rtStatus").value    = r.status;
  $("rtKet").value       = r.keterangan || "";
  $("rtSubmit").innerHTML = svgIcon("check") + "Simpan perubahan";
  $("rtBatal").style.display = "";
  $("rtForm").scrollIntoView({ behavior:"smooth", block:"start" });
}

function batalEditRetur(){
  editReturId = null;
  $("rtForm").reset();
  $("rtTanggal").value = todayISO();
  $("rtJumlah").value = 1;
  $("rtPicker").value = "";
  $("rtSubmit").innerHTML = svgIcon("plus") + "Catat retur";
  $("rtBatal").style.display = "none";
}

async function submitRetur(e){
  e.preventDefault();
  const body = {
    tanggal:    $("rtTanggal").value || todayISO(),
    no_pesanan: $("rtNoPesanan").value.trim(),
    sku:        $("rtSku").value.trim(),
    barcode:    $("rtBarcode").value.trim(),
    nama:       $("rtNama").value.trim(),
    jumlah:     Number($("rtJumlah").value),
    status:     $("rtStatus").value,
    keterangan: $("rtKet").value.trim()
  };
  if(editReturId) body.id = editReturId;

  if(!body.sku && !body.barcode){
    toast("Isi SKU atau barcode barangnya dulu.", "err");
    return;
  }
  if(!body.jumlah || body.jumlah < 1){
    toast("Qty minimal 1.", "err");
    return;
  }

  const tombol = $("rtSubmit");
  if(tombol) tombol.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.post("retur/save.php", body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    (res.peringatan || []).forEach(p => toast(p, "err"));
    batalEditRetur();
    returFilter.page = 1;
    refreshRetur();
  }catch(err){
    tampilGalat(err);
  }finally{
    if(tombol) tombol.disabled = false;
  }
}

async function hapusRetur(id){
  const r = returRows.find(x => x.id === id);
  const ikutStok = r && r.status === returStatusMasuk;
  const ya = await konfirmasi(
    "Hapus retur ini?",
    ikutStok
      ? "Barang masuk yang dihasilkannya ikut dibatalkan, jadi stok berkurang lagi sebanyak "
        + fmtNum(r.jumlah) + " pcs."
      : "Retur ini belum menyentuh stok, jadi tidak ada stok yang berubah.",
    "Hapus"
  );
  if(!ya) return;

  setSaveStatus("saving");
  try{
    const res = await API.post("retur/delete.php", { id:id });
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    refreshRetur();
  }catch(e){ tampilGalat(e); }
}

/* ================================================================== */
/* Laporan stok opname                                                */
/* ================================================================== */
let opnameSesiId = null;                                   // null = daftar sesi
let opnameFilter = { q:"", kategori:"Semua", hanya:"semua", page:1 };
let opnameCariSesi = "";
let opnameSesiPage = 1;

function renderOpname(){
  if(opnameSesiId === null) renderOpnameDaftar();
  else renderOpnameDetail();
}

/* --- Daftar sesi --------------------------------------------------- */
function renderOpnameDaftar(){
  const bolehUbah = sayaAdmin();
  let html = "";

  if(bolehUbah){
    html += '<form class="form-card" id="opForm" onsubmit="submitOpname(event)">'
      + '<div class="form-grid">'
      + '<div class="span2"><label class="field-label" for="opNama">Nama laporan</label>'
        + '<input type="text" id="opNama" maxlength="150" placeholder="Contoh: LAPORAN STOK OPNAME JUNI 2026" required></div>'
      + '<div><label class="field-label" for="opPeriode">Periode</label>'
        + '<input type="text" id="opPeriode" maxlength="50" placeholder="Contoh: JUNI 2026"></div>'
      + '<div><label class="field-label" for="opTanggal">Tanggal opname</label>'
        + '<input type="date" id="opTanggal" value="' + todayISO() + '"></div>'
      + '<div><label class="field-label" for="opKategori">Kategori</label>'
        + '<select id="opKategori"><option value="">Seluruh kategori</option></select></div>'
      + '<div class="span2"><label class="field-label" for="opCatatan">Catatan</label>'
        + '<input type="text" id="opCatatan" maxlength="255" placeholder="Opsional"></div>'
      + '</div>'
      + '<button type="submit" class="btn" id="opSubmit">' + svgIcon("plus") + 'Buat laporan opname</button>'
      + '</form>';
  }else{
    html += '<div class="info-box">Hanya admin yang bisa membuat laporan opname baru. '
      + 'Laporan yang sudah ada tetap bisa dibuka dan diisi.</div>';
  }

  html += '<div class="toolbar">'
    + '<div class="search-wrap">' + svgIcon("search")
      + '<input type="text" id="opCari" placeholder="Cari nama laporan atau periode&hellip;" oninput="onOpnameCari()"></div>'
    + '</div>'
    + '<div id="opHasil"></div>';

  $("content").innerHTML = html;
  const c = $("opCari");
  if(c) c.value = opnameCariSesi;
  refreshOpnameDaftar();
}

const onOpnameCari = debounce(function(){
  opnameCariSesi = $("opCari").value;
  opnameSesiPage = 1;
  refreshOpnameDaftar();
}, 300);

async function refreshOpnameDaftar(){
  const wadah = $("opHasil");
  if(!wadah) return;

  let d;
  try{ d = await API.get("opname/list.php", { q: opnameCariSesi, page: opnameSesiPage }); }
  catch(e){ tampilGalat(e); return; }

  const selKat = $("opKategori");
  if(selKat && selKat.options.length <= 1 && d.kategori_options){
    selKat.innerHTML = '<option value="">Seluruh kategori</option>'
      + d.kategori_options.map(k => '<option value="' + esc(k) + '">' + esc(k) + '</option>').join("");
  }

  let baris = d.rows.map(r => {
    const lengkap = r.jml_item > 0 ? Math.round((r.jml_dicek / r.jml_item) * 100) : 0;
    return '<tr>'
      + '<td><div class="item-name">' + esc(r.nama) + '</div>'
        + '<div class="item-sub" style="font-family:Inter">'
        + (r.periode ? esc(r.periode) + ' · ' : '') + fmtDate(r.tanggal)
        + (r.kategori ? ' · ' + esc(r.kategori) : '') + '</div></td>'
      + '<td class="num">' + fmtNum(r.jml_item) + '</td>'
      + '<td class="num">' + fmtNum(r.jml_dicek) + ' <span style="color:var(--slateLo); font-weight:400">('
        + lengkap + '%)</span></td>'
      + '<td class="num" style="font-weight:700; color:' + (r.jml_selisih > 0 ? 'var(--danger)' : 'var(--slateLo)') + '">'
        + fmtNum(r.jml_selisih) + '</td>'
      + '<td>' + (r.status === "selesai"
          ? '<span class="badge aman">' + svgIcon("check") + 'Selesai</span>'
          : '<span class="badge rendah">Draft</span>') + '</td>'
      + '<td style="font-size:11.5px; color:var(--slateLo)">' + esc(r.oleh || "-") + '</td>'
      + '<td class="num" style="white-space:nowrap">'
        + '<button class="btn ghost" onclick="bukaOpname(' + r.id + ')">Buka</button>'
        + (sayaAdmin()
            ? '<button class="icon-btn bahaya" onclick="hapusOpname(' + r.id + ')" aria-label="Hapus laporan">'
              + svgIcon("trash") + '</button>'
            : '')
        + '</td>'
      + '</tr>';
  }).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="7">'
      + 'Belum ada laporan opname. Buat satu untuk mulai menghitung.</td></tr>';
  }

  wadah.innerHTML =
    '<div class="info-box">Isi laporan dibekukan saat dibuat: kolom <b>stok akhir</b> menyimpan '
    + 'posisi menurut sistem pada tanggal opname, dan tidak ikut berubah oleh transaksi '
    + 'sesudahnya. <b>Selisih barang</b> = stok hitung &minus; stok accurate.</div>'
    + '<div class="table-card"><table style="min-width:900px"><thead><tr>'
    + ["Laporan","Barang","Dicek","Berselisih","Status","Dibuat oleh",""]
        .map((h,i)=>'<th'+(i>=1&&i<=3?' class="num"':'')+'>'+esc(h)+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + paginationBar(d.total, d.page, d.total_pages, "opnameGoPage")
    + '</div>';
}

function opnameGoPage(p){ opnameSesiPage = p; refreshOpnameDaftar(); }

async function submitOpname(e){
  e.preventDefault();
  const body = {
    nama:     $("opNama").value.trim(),
    periode:  $("opPeriode").value.trim(),
    tanggal:  $("opTanggal").value || todayISO(),
    kategori: $("opKategori").value,
    catatan:  $("opCatatan").value.trim()
  };
  if(!body.nama){ toast("Isi nama laporannya dulu.", "err"); return; }

  const tombol = $("opSubmit");
  if(tombol) tombol.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.post("opname/save.php", body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    $("opForm").reset();
    $("opTanggal").value = todayISO();
    opnameSesiPage = 1;
    bukaOpname(res.id);
  }catch(err){
    tampilGalat(err);
  }finally{
    if(tombol) tombol.disabled = false;
  }
}

async function hapusOpname(id){
  const ya = await konfirmasi("Hapus laporan opname ini?",
    "Hasil hitungan fisik di dalamnya ikut hilang dari tampilan.", "Hapus");
  if(!ya) return;
  setSaveStatus("saving");
  try{
    const res = await API.post("opname/delete.php", { id:id });
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    refreshOpnameDaftar();
  }catch(e){ tampilGalat(e); }
}

function bukaOpname(id){
  opnameSesiId = id;
  opnameFilter = { q:"", kategori:"Semua", hanya:"semua", page:1 };
  renderOpnameDetail();
}

function tutupOpname(){
  opnameSesiId = null;
  renderOpnameDaftar();
}

/* --- Isi satu sesi -------------------------------------------------- */
function renderOpnameDetail(){
  $("content").innerHTML =
    '<div id="opKepala"></div>'
    + '<div class="toolbar">'
    + '<button type="button" class="btn ghost" onclick="tutupOpname()">' + svgIcon("x") + 'Kembali</button>'
    + '<div class="search-wrap">' + svgIcon("search")
      + '<input type="text" id="oiCari" placeholder="Cari nama barang atau SKU&hellip;" oninput="onOpnameItemCari()"></div>'
    + '<select id="oiKategori" onchange="onOpnameItemFilter()"><option value="Semua">Semua kategori</option></select>'
    + '<select id="oiHanya" onchange="onOpnameItemFilter()">'
      + '<option value="semua">Semua barang</option>'
      + '<option value="belum">Belum dihitung</option>'
      + '<option value="selisih">Ada selisih</option>'
    + '</select>'
    + '<a class="btn ghost" id="oiUnduh" href="#">' + svgIcon("download") + 'Unduh PDF</a>'
    + '</div>'
    + '<div class="stat-row" id="oiRingkas"></div>'
    + '<div id="oiHasil"></div>';
  $("oiCari").value = opnameFilter.q;
  refreshOpnameDetail();
}

const onOpnameItemCari = debounce(function(){
  opnameFilter.q = $("oiCari").value;
  opnameFilter.page = 1;
  refreshOpnameDetail();
}, 300);

function onOpnameItemFilter(){
  opnameFilter.q        = $("oiCari").value;
  opnameFilter.kategori = $("oiKategori").value;
  opnameFilter.hanya    = $("oiHanya").value;
  opnameFilter.page     = 1;
  refreshOpnameDetail();
}

function opnameItemGoPage(p){ opnameFilter.page = p; refreshOpnameDetail(); }

async function refreshOpnameDetail(){
  const wadah = $("oiHasil");
  if(!wadah) return;

  let d;
  try{
    d = await API.get("opname/detail.php", {
      id: opnameSesiId, q: opnameFilter.q, kategori: opnameFilter.kategori,
      hanya: opnameFilter.hanya, page: opnameFilter.page
    });
  }catch(e){ tampilGalat(e); return; }

  const s = d.sesi;
  const terkunci = s.status === "selesai";

  const kepala = $("opKepala");
  if(kepala){
    kepala.innerHTML =
      '<div class="panel" style="padding:16px 18px; margin-bottom:14px;">'
      + '<div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">'
        + '<div style="flex:1; min-width:220px;">'
          + '<div style="font-family:\'Barlow Condensed\',sans-serif; font-weight:800; font-size:20px; text-transform:uppercase; letter-spacing:.01em;">'
            + esc(s.nama) + '</div>'
          + '<div class="item-sub" style="font-family:Inter; margin-top:4px;">'
            + 'Periode ' + esc(s.periode || fmtDate(s.tanggal))
            + ' · dibuat ' + esc(s.oleh || "-")
            + (s.kategori ? ' · kategori ' + esc(s.kategori) : ' · seluruh kategori')
            + (s.catatan ? ' · ' + esc(s.catatan) : '')
            + '</div>'
        + '</div>'
        + (sayaAdmin()
            ? '<button type="button" class="btn ' + (terkunci ? 'ghost' : '') + '" onclick="toggleStatusOpname()">'
              + (terkunci ? svgIcon("edit") + 'Buka kembali' : svgIcon("check") + 'Tandai selesai') + '</button>'
            : '')
      + '</div></div>';
  }

  const selKat = $("oiKategori");
  if(selKat && d.kategori_options){
    const isi = '<option value="Semua">Semua kategori</option>'
      + d.kategori_options.map(k => '<option value="' + esc(k) + '">' + esc(k) + '</option>').join("");
    if(selKat.innerHTML !== isi){
      selKat.innerHTML = isi;
      selKat.value = opnameFilter.kategori;
    }
  }
  const selHanya = $("oiHanya");
  if(selHanya) selHanya.value = opnameFilter.hanya;

  const unduh = $("oiUnduh");
  if(unduh){
    unduh.href = "api/export/pdf.php?jenis=opname&id=" + encodeURIComponent(opnameSesiId)
      + "&q=" + encodeURIComponent(opnameFilter.q)
      + "&kategori=" + encodeURIComponent(opnameFilter.kategori);
  }

  const r = d.ringkas;
  const ringkas = $("oiRingkas");
  if(ringkas){
    ringkas.innerHTML =
        statCard({ label:"Barang", nilai:r.jml, ikon:"sku", nada:"biru", kaki:"dalam laporan ini" })
      + statCard({ label:"Sudah dicek", nilai:r.dicek, ikon:"tag", nada:"safe", tone:"safe",
                   kaki:fmtNum(r.belum) + " belum dihitung" })
      + statCard({ label:"Berselisih", nilai:r.beda, ikon:"alert",
                   nada:r.beda > 0 ? "danger" : "", tone:r.beda > 0 ? "danger" : "",
                   kaki:"hitung ≠ accurate" })
      + statCard({ label:"Jumlah selisih", nilai:Math.abs(r.total_selisih), ikon:"unit",
                   nada:r.total_selisih === 0 ? "" : "amber",
                   kaki:r.total_selisih === 0 ? "seimbang"
                        : (r.total_selisih > 0 ? "lebih di hitungan fisik" : "kurang di hitungan fisik") });
    ringkas.querySelectorAll(".stat-value[data-nilai]").forEach(n =>
      Grafik.angkaNaik(n, Number(n.getAttribute("data-nilai"))));
  }

  const mati = terkunci ? " disabled" : "";
  let baris = d.rows.map(it =>
    '<tr>'
    + '<td class="mono" style="font-size:11px; color:var(--slateLo)">' + esc(it.sku || "-") + '</td>'
    + '<td><div class="item-name">' + esc(it.nama) + '</div>'
      + '<div class="item-sub">' + esc(it.barcode || "-") + '</div></td>'
    + '<td class="num">' + fmtNum(it.stok_sistem) + '</td>'
    + '<td class="num"><input type="number" class="sel-angka" id="oh' + it.id + '" min="0"'
      + ' value="' + (it.stok_hitung === null ? "" : it.stok_hitung) + '"'
      + ' onchange="simpanItemOpname(' + it.id + ')"' + mati + '></td>'
    + '<td class="num"><input type="number" class="sel-angka" id="oa' + it.id + '" min="0"'
      + ' value="' + (it.stok_accurate === null ? "" : it.stok_accurate) + '"'
      + ' onchange="simpanItemOpname(' + it.id + ')"' + mati + '></td>'
    + '<td class="num"><input type="checkbox" class="sel-cek" id="oc' + it.id + '"'
      + (it.dicek ? " checked" : "") + ' onchange="simpanItemOpname(' + it.id + ')"' + mati + '></td>'
    + '<td>' + (it.kategori
        ? '<span class="badge netral">' + esc(it.kategori) + '</span>'
        : '<span style="color:var(--slateLo); font-size:11px">-</span>') + '</td>'
    + '<td class="num"><span id="sel' + it.id + '" class="' + kelasSelisih(it.selisih) + '">'
      + teksSelisih(it.selisih) + '</span></td>'
    + '<td><input type="text" class="sel-catatan" id="ok' + it.id + '" maxlength="255"'
      + ' value="' + esc(it.catatan || "") + '" placeholder="Ket."'
      + ' onchange="simpanItemOpname(' + it.id + ')"' + mati + '></td>'
    + '</tr>'
  ).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="9">Tidak ada barang pada penyaring ini.</td></tr>';
  }

  wadah.innerHTML =
    (terkunci
      ? '<div class="info-box">Laporan ini sudah ditandai <b>selesai</b>, jadi angkanya dikunci. '
        + 'Buka kembali statusnya bila memang perlu diubah.</div>'
      : '<div class="info-box">Isi <b>stok hitung</b> dari hasil hitungan fisik dan <b>stok accurate</b> '
        + 'dari catatan Accurate. Angkanya tersimpan begitu kamu pindah dari kolomnya.</div>')
    + '<div class="table-card"><table style="min-width:1040px"><thead><tr>'
    + ["SKU","Nama barang","Stok akhir","Stok hitung","Stok accurate","Dicek","Kategori","Selisih barang","Ket."]
        .map((h,i)=>'<th'+(i>=2&&i<=5||i===7?' class="num"':'')+'>'+esc(h)+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + paginationBar(d.total, d.page, d.total_pages, "opnameItemGoPage")
    + '</div>';
}

function teksSelisih(v){
  if(v === null || v === undefined) return "-";
  return (v > 0 ? "+" : "") + fmtNum(v);
}
function kelasSelisih(v){
  if(v === null || v === undefined || v === 0) return "sel-nol";
  return v > 0 ? "sel-lebih" : "sel-kurang";
}

async function simpanItemOpname(id){
  const h = $("oh" + id), a = $("oa" + id), c = $("oc" + id), k = $("ok" + id);
  if(!h) return;

  setSaveStatus("saving");
  try{
    const res = await API.post("opname/item.php", {
      id: id,
      stok_hitung:   h.value,
      stok_accurate: a ? a.value : "",
      dicek:         c ? c.checked : false,
      catatan:       k ? k.value : ""
    });
    setSaveStatus("ok");

    const sel = $("sel" + id);
    if(sel){
      sel.textContent = teksSelisih(res.selisih);
      sel.className   = kelasSelisih(res.selisih);
    }
  }catch(e){ tampilGalat(e); }
}

async function toggleStatusOpname(){
  let d;
  try{ d = await API.get("opname/detail.php", { id: opnameSesiId, page: 1 }); }
  catch(e){ tampilGalat(e); return; }

  const s = d.sesi;
  setSaveStatus("saving");
  try{
    await API.post("opname/save.php", {
      id: s.id, nama: s.nama, periode: s.periode, tanggal: s.tanggal,
      catatan: s.catatan, status: s.status === "selesai" ? "draft" : "selesai"
    });
    setSaveStatus("ok");
    refreshOpnameDetail();
  }catch(e){ tampilGalat(e); }
}

/* ================================================================== */
/* Master: Kategori                                                   */
/* ================================================================== */
let editKategoriId = null;
let kategoriRows = [];

function renderKategori(){
  const bolehUbah = sayaAdmin();

  let html = "";
  if(!bolehUbah){
    html += '<div class="info-box">Hanya admin yang bisa mengubah daftar kategori. '
      + 'Kamu masuk sebagai operator, jadi daftarnya hanya bisa dilihat.</div>';
  }

  if(bolehUbah){
    html += '<form class="form-card" id="katForm" onsubmit="submitKategori(event)">'
      + '<div class="form-grid">'
      + '<div><label class="field-label" for="kNama">Nama kategori</label>'
        + '<input type="text" id="kNama" maxlength="30" placeholder="Contoh: FISIO" required></div>'
      + '<div class="span2"><label class="field-label" for="kKet">Keterangan</label>'
        + '<input type="text" id="kKet" maxlength="120" placeholder="Untuk apa kategori ini"></div>'
      + '<div><label class="field-label" for="kUrutan">Urutan</label>'
        + '<input type="number" id="kUrutan" min="0" step="10" value="0"></div>'
      + '</div>'
      + '<div style="display:flex; gap:8px;">'
        + '<button type="submit" class="btn" id="kSubmit">' + svgIcon("plus") + 'Tambah kategori</button>'
        + '<button type="button" class="btn ghost" id="kBatal" style="display:none" onclick="batalEditKategori()">'
          + svgIcon("x") + 'Batal</button>'
      + '</div></form>';
  }

  html += '<div id="katHasil"></div>';
  $("content").innerHTML = html;
  refreshKategori();
}

async function refreshKategori(){
  const wadah = $("katHasil");
  if(!wadah) return;

  let d;
  try{ d = await API.get("kategori/list.php"); }
  catch(e){ tampilGalat(e); return; }

  kategoriRows = d.rows;
  const bolehUbah = sayaAdmin();

  let baris = d.rows.map(k =>
    '<tr>'
    + '<td><div class="item-name">' + esc(k.nama) + '</div>'
      + (k.keterangan ? '<div class="item-sub" style="font-family:Inter">' + esc(k.keterangan) + '</div>' : '')
      + '</td>'
    + '<td class="num">' + fmtNum(k.dipakai) + '</td>'
    + '<td class="num mono" style="color:var(--slateLo)">' + k.urutan + '</td>'
    + '<td>' + (k.aktif
        ? '<span class="badge aman">' + svgIcon("check") + 'Aktif</span>'
        : '<span class="badge belum_diatur">Nonaktif</span>') + '</td>'
    + (bolehUbah
        ? '<td class="num" style="white-space:nowrap">'
          + '<button class="icon-btn" onclick="editKategori(' + k.id + ')" aria-label="Ubah ' + esc(k.nama) + '">' + svgIcon("edit") + '</button>'
          + '<button class="icon-btn bahaya" onclick="hapusKategori(' + k.id + ')" aria-label="Hapus ' + esc(k.nama) + '">' + svgIcon("trash") + '</button>'
          + '</td>'
        : '')
    + '</tr>'
  ).join("");

  if(!d.rows.length){
    baris = '<tr class="empty-row"><td colspan="' + (bolehUbah?5:4) + '">Belum ada kategori.</td></tr>';
  }

  const kolom = ["Kategori","Dipakai","Urutan","Status"].concat(bolehUbah?[""]:[]);

  wadah.innerHTML =
    (d.tanpa_kategori > 0
      ? '<div class="info-box"><b>' + fmtNum(d.tanpa_kategori) + '</b> barang belum punya kategori. '
        + 'Atur lewat menu Master → Barang.</div>'
      : '')
    + '<div class="table-card"><table style="min-width:560px"><thead><tr>'
    + kolom.map((h,i)=>'<th' + (i===1||i===2?' class="num"':'') + '>' + esc(h) + '</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + '<div class="pagination"><span>' + fmtNum(d.total) + ' kategori</span></div>'
    + '</div>';
}

function editKategori(id){
  const k = kategoriRows.find(x => x.id === id);
  if(!k) return;
  editKategoriId = id;
  $("kNama").value   = k.nama;
  $("kKet").value    = k.keterangan;
  $("kUrutan").value = k.urutan;
  $("kSubmit").innerHTML = svgIcon("edit") + "Simpan perubahan";
  $("kBatal").style.display = "inline-flex";
  $("katForm").scrollIntoView({ behavior:"smooth", block:"center" });
}

function batalEditKategori(){ resetFormKategori(); }

function resetFormKategori(){
  editKategoriId = null;
  if($("kNama"))   $("kNama").value = "";
  if($("kKet"))    $("kKet").value = "";
  if($("kUrutan")) $("kUrutan").value = "0";
  if($("kSubmit")) $("kSubmit").innerHTML = svgIcon("plus") + "Tambah kategori";
  if($("kBatal"))  $("kBatal").style.display = "none";
}

async function submitKategori(e){
  e.preventDefault();
  const body = {
    id:         editKategoriId || 0,
    nama:       $("kNama").value.trim(),
    keterangan: $("kKet").value.trim(),
    urutan:     Number($("kUrutan").value) || 0,
    aktif:      true
  };
  if(!body.nama){ toast("Nama kategori wajib diisi.", "err"); return; }

  const tbl = $("kSubmit");
  if(tbl) tbl.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.post("kategori/save.php", body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    resetFormKategori();
    refreshKategori();
    segarkanDaftarKategori();
  }catch(err){ tampilGalat(err); }
  finally{ if(tbl) tbl.disabled = false; }
}

async function hapusKategori(id){
  const k = kategoriRows.find(x => x.id === id);
  if(!k) return;

  // Kategori yang masih dipakai butuh tujuan pemindahan lebih dulu.
  if(k.dipakai > 0){
    const tujuan = await pilihTujuanKategori(k);
    if(tujuan === null) return;
    await kirimHapusKategori(id, tujuan);
    return;
  }

  const ok = await konfirmasi(
    'Hapus kategori "' + k.nama + '"?',
    "Kategori ini belum dipakai barang mana pun, jadi aman dihapus.",
    "Hapus kategori"
  );
  if(!ok) return;
  await kirimHapusKategori(id, "");
}

/** Dialog pemilihan kategori tujuan sebelum menghapus yang masih dipakai. */
function pilihTujuanKategori(k){
  return new Promise(resolve => {
    const lain = kategoriRows.filter(x => x.id !== k.id && x.aktif);
    const m = modalKonten(false);
    m.isi(
      '<h3>Pindahkan barangnya dulu</h3>'
      + '<p>Kategori <b>' + esc(k.nama) + '</b> masih dipakai <b>' + fmtNum(k.dipakai)
      + '</b> barang. Pilih kategori tujuan sebelum menghapusnya.</p>'
      + '<label class="field-label" for="katTujuan">Pindahkan ke</label>'
      + '<select id="katTujuan" style="width:100%; margin-bottom:18px;">'
        + '<option value="">— Tanpa kategori —</option>'
        + lain.map(x => '<option value="' + esc(x.nama) + '">' + esc(x.nama) + '</option>').join("")
      + '</select>'
      + '<div class="modal-act">'
        + '<button type="button" class="btn ghost" data-act="tutup">Batal</button>'
        + '<button type="button" class="btn danger-btn" data-act="lanjut">Pindahkan &amp; hapus</button>'
      + '</div>'
    );
    m.el.addEventListener("click", ev => {
      if(ev.target.closest('[data-act="lanjut"]')){
        const v = m.el.querySelector("#katTujuan").value;
        m.tutup();
        resolve(v);
      } else if(ev.target.closest('[data-act="tutup"]')){
        resolve(null);
      }
    });
  });
}

async function kirimHapusKategori(id, pindahKe){
  setSaveStatus("saving");
  try{
    const res = await API.post("kategori/delete.php", { id:id, pindah_ke:pindahKe });
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    refreshKategori();
    segarkanDaftarKategori();
  }catch(e){ tampilGalat(e); }
}

/** Ambil ulang daftar kategori supaya dropdown di halaman lain ikut segar. */
async function segarkanDaftarKategori(){
  try{
    const d = await API.masterList({ page:1 });
    if(d.kategori_options) window.KATEGORI_OPTIONS = d.kategori_options;
  }catch(e){ /* bukan galat fatal */ }
}

/* ================================================================== */
/* Master: Pengguna                                                   */
/* ================================================================== */
let editPenggunaId = null;
let penggunaRows = [];

function renderPengguna(){
  if(!sayaAdmin()){
    $("content").innerHTML = '<div class="info-box">Halaman ini hanya untuk admin.</div>';
    return;
  }

  let html = '<form class="form-card" id="pForm" onsubmit="submitPengguna(event)">'
    + '<div class="form-grid">'
    + '<div><label class="field-label" for="pUser">Username</label>'
      + '<input type="text" id="pUser" maxlength="50" autocomplete="off" placeholder="huruf kecil, tanpa spasi" required></div>'
    + '<div class="span2"><label class="field-label" for="pNama">Nama lengkap</label>'
      + '<input type="text" id="pNama" maxlength="100" placeholder="Nama yang tampil di riwayat" required></div>'
    + '<div><label class="field-label" for="pRole">Peran</label>'
      + '<select id="pRole"><option value="operator">Operator</option><option value="admin">Admin</option></select></div>'
    + '<div><label class="field-label" for="pAktif">Status</label>'
      + '<select id="pAktif"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></div>'
    + '<div class="span2"><label class="field-label" for="pSandi">Password</label>'
      + '<input type="password" id="pSandi" autocomplete="new-password" placeholder="Minimal 8 karakter">'
      + '<div id="pSandiCatatan" style="font-size:11px; color:var(--slateLo); margin-top:5px; display:none;">'
        + 'Kosongkan bila tidak ingin mengganti password.</div></div>'
    + '</div>'
    + '<div style="display:flex; gap:8px;">'
      + '<button type="submit" class="btn" id="pSubmit">' + svgIcon("plus") + 'Tambah akun</button>'
      + '<button type="button" class="btn ghost" id="pBatal" style="display:none" onclick="batalEditPengguna()">'
        + svgIcon("x") + 'Batal</button>'
    + '</div></form>'
    + '<div id="pHasil"></div>';

  $("content").innerHTML = html;
  refreshPengguna();
}

async function refreshPengguna(){
  const wadah = $("pHasil");
  if(!wadah) return;

  let d;
  try{ d = await API.get("pengguna/list.php"); }
  catch(e){ tampilGalat(e); return; }

  penggunaRows = d.rows;

  const baris = d.rows.map(u => {
    // Admin aktif terakhir tidak boleh dihapus — tombolnya dimatikan
    // sekalian, bukan hanya ditolak server, supaya niatnya terbaca.
    const kunciHapus = u.ini_saya || (u.role === "admin" && u.aktif && d.admin_aktif <= 1);
    const alasan = u.ini_saya ? "Tidak bisa menghapus akun sendiri"
                 : (kunciHapus ? "Satu-satunya admin aktif" : "Hapus akun");
    return '<tr>'
      + '<td><div class="item-name">' + esc(u.nama_lengkap)
        + (u.ini_saya ? '<span class="flag-gen">AKUN ANDA</span>' : '') + '</div>'
        + '<div class="item-sub">' + esc(u.username) + '</div></td>'
      + '<td>' + (u.role === "admin"
          ? '<span class="badge kritis" style="background:#E4EDF1;color:var(--biru);border-color:#C6DAE2">Admin</span>'
          : '<span class="badge belum_diatur">Operator</span>') + '</td>'
      + '<td>' + (u.aktif
          ? '<span class="badge aman">' + svgIcon("check") + 'Aktif</span>'
          : '<span class="badge belum_diatur">Nonaktif</span>') + '</td>'
      + '<td style="font-size:11.5px; color:var(--slateLo)">'
        + (u.last_login_at ? esc(u.last_login_at) : "Belum pernah masuk") + '</td>'
      + '<td class="num" style="white-space:nowrap">'
        + '<button class="icon-btn" onclick="editPengguna(' + u.id + ')" aria-label="Ubah ' + esc(u.username) + '">' + svgIcon("edit") + '</button>'
        + '<button class="icon-btn bahaya" onclick="hapusPengguna(' + u.id + ')"'
          + (kunciHapus ? ' disabled' : '') + ' title="' + esc(alasan) + '" aria-label="' + esc(alasan) + '">'
          + svgIcon("trash") + '</button>'
      + '</td>'
      + '</tr>';
  }).join("");

  wadah.innerHTML =
    (d.admin_aktif <= 1
      ? '<div class="warn-box">Hanya ada 1 admin aktif. Kalau akun itu hilang aksesnya, '
        + 'tidak ada lagi yang bisa mengelola aplikasi. Sebaiknya angkat satu admin cadangan.</div>'
      : '')
    + '<div class="table-card"><table style="min-width:660px"><thead><tr>'
    + ["Pengguna","Peran","Status","Terakhir masuk",""].map(h=>'<th>'+esc(h)+'</th>').join("")
    + '</tr></thead><tbody>' + baris + '</tbody></table>'
    + '<div class="pagination"><span>' + fmtNum(d.total) + ' akun · ' + fmtNum(d.admin_aktif) + ' admin aktif</span></div>'
    + '</div>';
}

function editPengguna(id){
  const u = penggunaRows.find(x => x.id === id);
  if(!u) return;
  editPenggunaId = id;
  $("pUser").value  = u.username;
  $("pNama").value  = u.nama_lengkap;
  $("pRole").value  = u.role;
  $("pAktif").value = String(u.aktif);
  $("pSandi").value = "";
  $("pSandi").placeholder = "Biarkan kosong untuk mempertahankan password";
  $("pSandiCatatan").style.display = "block";
  $("pSubmit").innerHTML = svgIcon("edit") + "Simpan perubahan";
  $("pBatal").style.display = "inline-flex";
  $("pForm").scrollIntoView({ behavior:"smooth", block:"center" });
}

function batalEditPengguna(){ resetFormPengguna(); }

function resetFormPengguna(){
  editPenggunaId = null;
  ["pUser","pNama","pSandi"].forEach(id => { if($(id)) $(id).value = ""; });
  if($("pRole"))  $("pRole").value = "operator";
  if($("pAktif")) $("pAktif").value = "1";
  if($("pSandi")) $("pSandi").placeholder = "Minimal 8 karakter";
  if($("pSandiCatatan")) $("pSandiCatatan").style.display = "none";
  if($("pSubmit")) $("pSubmit").innerHTML = svgIcon("plus") + "Tambah akun";
  if($("pBatal"))  $("pBatal").style.display = "none";
}

async function submitPengguna(e){
  e.preventDefault();
  const body = {
    id:           editPenggunaId || 0,
    username:     $("pUser").value.trim(),
    nama_lengkap: $("pNama").value.trim(),
    role:         $("pRole").value,
    aktif:        $("pAktif").value === "1",
    password:     $("pSandi").value
  };
  if(!body.username || !body.nama_lengkap){
    toast("Username dan nama lengkap wajib diisi.", "err"); return;
  }
  if(!editPenggunaId && body.password.length < 8){
    toast("Password minimal 8 karakter untuk akun baru.", "err"); return;
  }

  const tbl = $("pSubmit");
  if(tbl) tbl.disabled = true;
  setSaveStatus("saving");
  try{
    const res = await API.post("pengguna/save.php", body);
    setSaveStatus("ok");
    toast(res.pesan || "Tersimpan.");
    resetFormPengguna();
    refreshPengguna();
  }catch(err){ tampilGalat(err); }
  finally{ if(tbl) tbl.disabled = false; }
}

async function hapusPengguna(id){
  const u = penggunaRows.find(x => x.id === id);
  if(!u) return;
  const ok = await konfirmasi(
    'Hapus akun "' + u.username + '"?',
    "Akun dihapus permanen dan orang ini tidak bisa masuk lagi. "
    + "Catatan transaksi yang pernah dibuatnya tetap tersimpan. "
    + "Untuk menutup akses sementara, pakai status Nonaktif.",
    "Hapus akun"
  );
  if(!ok) return;
  setSaveStatus("saving");
  try{
    const res = await API.post("pengguna/delete.php", { id:id });
    setSaveStatus("ok");
    toast(res.pesan || "Dihapus.");
    refreshPengguna();
  }catch(e){ tampilGalat(e); }
}

/* ---------------------------------------------------------------- */
/* Router & init                                                      */
/* ---------------------------------------------------------------- */
function renderContent(){
  if(tab==="dashboard") renderDashboard();
  else if(tab==="masuk") renderTransaksiTab("masuk");
  else if(tab==="keluar") renderTransaksiTab("keluar");
  else if(tab==="riwayat") renderRiwayat();
  else if(tab==="pertukaran") renderPertukaran();
  else if(tab==="retur") renderRetur();
  else if(tab==="opname") renderOpname();
  else if(tab==="aktivitas") renderAktivitas();
  else if(tab==="master") renderMaster();
  else if(tab==="kategori") renderKategori();
  else if(tab==="pengguna") renderPengguna();
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

  // Centang baris pada tabel review impor PDF.
  const kotak = e.target.closest && e.target.closest("[data-pilih]");
  if(kotak){
    const i = Number(kotak.getAttribute("data-pilih"));
    if(pdfImport.rows[i]){
      pdfImport.rows[i].pilih = kotak.checked;
      const tr = kotak.closest("tr");
      if(tr) tr.classList.toggle("tak-dipilih", !kotak.checked);
      ringkasPilihan();
    }
    return;
  }
  if(e.target.id === "pdfPilihSemua"){ setPilihSemua(e.target.checked); return; }

  // Ikon pertukaran -> dialog perbandingan produk lama vs baru.
  const tbTukar = e.target.closest && e.target.closest("[data-tukar]");
  if(tbTukar){ bukaDialogTukar(Number(tbTukar.getAttribute("data-tukar"))); return; }

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
