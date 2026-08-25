# Papan Kendali Gudang — Stok Fingertape & Perlengkapan

Dokumentasi lengkap, hasil audit, dan rancangan migrasi ke PHP (Hostinger).

| | |
|---|---|
| **Nama aplikasi** | Stok Fingertape & Perlengkapan — Papan Kendali Gudang |
| **Bahasa antarmuka** | Indonesia (`<html lang="id">`) |
| **Prototipe** | `aplikasi-gudang (2).html` — 951 baris, 262 KB, single-file |
| **Lingkungan kerja** | XAMPP di komputer lokal (Apache + PHP + MariaDB) |
| **Target produksi** | PHP 8.x + MySQL, shared hosting Hostinger |
| **Status** | Versi PHP **sudah dibangun dan berjalan** — lihat [Bagian 14](#14-status-implementasi) |
| **Dokumen ini** | Hasil audit mendalam + spesifikasi versi PHP |

---

## Daftar Isi

1. [Ringkasan Fungsional](#1-ringkasan-fungsional)
2. [Alur Kerja Pengguna](#2-alur-kerja-pengguna)
3. [Arsitektur Prototipe](#3-arsitektur-prototipe)
4. [Model Data](#4-model-data)
5. [Mesin Perhitungan Stok](#5-mesin-perhitungan-stok)
6. [Mesin Parser PDF Picking List](#6-mesin-parser-pdf-picking-list) ← *inti aplikasi*
7. [Peta Kode Prototipe](#7-peta-kode-prototipe)
8. [Hasil Audit](#8-hasil-audit)
9. [Rancangan Versi PHP untuk Hostinger](#9-rancangan-versi-php-untuk-hostinger)
10. [Skema Database](#10-skema-database)
11. [Spesifikasi API](#11-spesifikasi-api)
12. [Rencana Kerja Bertahap](#12-rencana-kerja-bertahap)
13. [Glosarium](#13-glosarium)
14. [Status Implementasi](#14-status-implementasi) ← *status, impor data, **panduan deploy***

---

## 1. Ringkasan Fungsional

Aplikasi ini adalah **papan kendali stok gudang** untuk produk fingertape dan
perlengkapan olahraga. Fungsinya mencatat pergerakan barang keluar-masuk lalu
menghitung stok akhir secara otomatis, dengan peringatan dini untuk barang yang
harus segera diorder.

Fitur pembeda utamanya: **impor otomatis PDF Picking List**. Ketika admin gudang
mengunggah PDF picking list yang dicetak dari sistem pesanan, aplikasi membaca
seluruh baris barang di dalamnya (barcode, nama, SKU, qty, no. pesanan),
menampilkannya dalam tabel review yang bisa dikoreksi, lalu — setelah admin
menekan Konfirmasi — menyimpan semuanya sekaligus sebagai transaksi barang
keluar. Tanpa fitur ini admin harus mengetik ulang puluhan sampai ratusan baris
per picking list.

**Empat tab utama:**

| Tab | ID internal | Fungsi |
|---|---|---|
| Dashboard stok | `dashboard` | Ringkasan stok akhir semua SKU + status kritis/menipis/aman |
| Barang masuk | `masuk` | Input manual barang masuk + riwayat |
| Barang keluar | `keluar` | **Impor PDF** + input manual barang keluar + riwayat |
| Master barang | `master` | CRUD katalog barang (SKU, barcode, nama, stok awal, stok minimal, kategori) |

**Skala data saat ini:** 1.404 item master ter-*seed* di dalam file HTML
(`MASTER_SEED`, baris 146 — sendirian 211 KB dari 262 KB total file).

---

## 2. Alur Kerja Pengguna

### 2.1 Alur utama — impor PDF picking list

```
┌────────────────────────────────────────────────────────────────────┐
│ 1. Admin buka tab "Barang keluar"                                  │
│ 2. Klik "Pilih file PDF" → pilih picking list dari sistem pesanan  │
│ 3. Sistem baca PDF di browser (pdf.js), ekstrak baris barang       │
│ 4. Tabel REVIEW tampil: barcode / nama / SKU / qty / no. pesanan   │
│    + badge status per baris:                                       │
│       ✓ Cocok master   — barcode ditemukan di master barang        │
│       ! Tak dikenal    — barcode tidak ada di master               │
│       ! Barcode kosong — parser gagal baca barcode baris ini       │
│ 5. Admin koreksi manual bila ada yang meleset (semua sel editable) │
│    - bisa tambah baris, bisa hapus baris                           │
│ 6. Klik "Konfirmasi & Simpan Semua"                                │
│ 7. Semua baris masuk ke tabel barang keluar sekaligus              │
│ 8. Dashboard otomatis menghitung ulang stok akhir                  │
└────────────────────────────────────────────────────────────────────┘
```

Prinsip desain penting: **PDF tidak pernah langsung tersimpan.** Selalu lewat
tahap review manual dulu. Ini disengaja — parser PDF tidak akan pernah 100%
akurat pada layout yang bervariasi, jadi admin gudang berperan sebagai
verifikator akhir terhadap fisik barang.

### 2.2 Alur input manual (masuk / keluar)

```
Pilih tanggal (default hari ini)
  → ketik di kolom "Cari barang" → dropdown autocomplete muncul (maks 20 hasil,
    cocokkan terhadap nama / barcode / SKU)
  → klik hasil → barcode + nama terisi otomatis
  → isi jumlah
  → pilih keterangan
  → Simpan
```

Barang yang tidak ada di master tetap bisa diinput manual (barcode + nama
diketik langsung). Ini fitur, bukan bug — supaya operasional gudang tidak
terhenti gara-gara master belum lengkap.

**Pilihan keterangan:**

- Barang masuk: `Barang Baru`, `Restock`, `Retur Masuk`, `Lainnya`
- Barang keluar: `Pesanan MP`, `Retur`, `Rusak / Reject`, `Lainnya`

### 2.3 Alur master barang

Form CRUD sederhana di atas, tabel berpaginasi (50 baris/halaman) di bawah.
Tombol edit memuat data ke form dan menggulirkan halaman ke form tersebut;
tombol hapus langsung menghapus.

---

## 3. Arsitektur Prototipe

### 3.1 Bentuk

Satu file HTML mandiri. Tidak ada framework, tidak ada build step, tidak ada
`node_modules`. Vanilla JavaScript murni dengan pola render manual:

```
state (variabel global)  →  fungsi render*()  →  innerHTML  →  DOM
        ↑                                                       │
        └──────────────── event handler inline (onclick=) ──────┘
```

Semua handler dipasang lewat atribut inline (`onclick="switchTab('masuk')"`),
sehingga setiap fungsi yang dipanggil dari HTML **wajib berada di global scope**.

### 3.2 Dependensi eksternal

| Dependensi | Versi | Sumber | Dipakai untuk |
|---|---|---|---|
| PDF.js | 3.11.174 | cdnjs | Ekstraksi teks + koordinat dari PDF |
| PDF.js worker | 3.11.174 | cdnjs | Worker thread parser PDF |
| Google Fonts | — | fonts.googleapis.com | Barlow Condensed, Inter, IBM Plex Mono |

Semuanya via CDN → **aplikasi butuh internet**. Tanpa internet, impor PDF mati
total (ada penanganan galat: *"Pembaca PDF gagal dimuat. Periksa koneksi
internet lalu coba lagi."*).

### 3.3 Lapisan penyimpanan — titik kritis

```js
async function loadKey(key, seed){
  const res = await window.storage.get(key, true);   // ← BUKAN API browser
  ...
}
async function saveKey(key, value){
  await window.storage.set(key, JSON.stringify(value), true);
}
```

`window.storage` **bukan Web API standar**. Ini API sandbox khusus (lingkungan
artifact) dengan semantik "penyimpanan tim" — argumen `true` menandakan scope
bersama antar pengguna. Di browser biasa objek ini `undefined`, sehingga:

- `loadKey()` melempar galat → tertangkap `catch` → mengembalikan `seed`
- Akibatnya aplikasi **selalu tampil dengan data awal**, semua input hilang saat
  halaman di-*refresh*
- `saveKey()` selalu `return false` → status header berbunyi *"Gagal menyimpan"*

Inilah alasan utama file ini belum bisa langsung diunggah ke Hostinger. Lapisan
inilah yang diganti PHP + MySQL. Detail di [Bagian 9](#9-rancangan-versi-php-untuk-hostinger).
Pengembangannya dilakukan lokal dengan XAMPP — lihat [Bagian 9.3](#93-lingkungan-pengembangan--xampp).

**Tiga kunci penyimpanan yang dipakai:**

| Kunci | Isi | Nilai awal |
|---|---|---|
| `gudang-master-barang` | Array master barang | `MASTER_SEED` (1.404 item) |
| `gudang-barang-masuk` | Array transaksi masuk | `[]` |
| `gudang-barang-keluar` | Array transaksi keluar | `[]` |

Setiap penulisan menyimpan **seluruh array** (bukan delta). Pada 1.404 item,
sekali simpan ≈ 200 KB JSON. Pola ini tidak layak dipertahankan di versi server.

### 3.4 State global

```js
let master = [];        // katalog barang
let masuk  = [];        // transaksi masuk
let keluar = [];        // transaksi keluar
let tab    = "dashboard";
let loading = true;
let saving  = false;

let dashFilters   = { q:"", kategori:"Semua", status:"semua", page:1 };
let masterFilters = { q:"", page:1 };
let pdfImport     = { status:"idle", header:null, rows:[], fileName:"" };
let editingMasterId = null;
```

`pdfImport.status` adalah mesin status 5 keadaan:
`idle → parsing → ready | empty | error`

---

## 4. Model Data

### 4.1 Master barang

```js
{
  id:          "m_0",              // string, unik
  sku:         "FI-0002",          // kode internal
  barcode:     "12132519",         // KUNCI RELASI ke transaksi
  nama:        "FINGERTAPE BIRU MUDA",
  stokAwal:    0,                  // saldo pembuka
  stokMinimal: 0,                  // ambang peringatan
  kategori:    ""                  // FISIO|FOX|AVO|AYRES|AC|LAINNYA
}
```

### 4.2 Transaksi masuk

```js
{
  id:         "id_lx8k2p_a9f3z",
  tanggal:    "2026-08-12",        // ISO yyyy-mm-dd
  barcode:    "12132519",
  nama:       "FINGERTAPE BIRU MUDA",
  jumlah:     50,
  keterangan: "Restock"
}
```

### 4.3 Transaksi keluar

Sama seperti masuk, **plus satu field**:

```js
{
  ...,
  noPesanan: "MP-8891023"          // atau nomor picking sebagai cadangan
}
```

### 4.4 Baris review PDF (sementara, tidak disimpan)

```js
{
  barcode:    "12132519",
  nama:       "FINGERTAPE BIRU MUDA",
  sku:        "FI-0002",
  qty:        3,
  noPesanan:  "MP-8891023",
  keterangan: "Pesanan MP"          // default
}
```

### 4.5 Metadata header PDF

```js
{
  noPicking:     "PICK-20260812-001",
  tanggalCetak:  "12/08/2026",
  dicetakOleh:   "admin_gudang",
  jumlahPesanan: "14",
  jumlahProduk:  "87"
}
```

### 4.6 Relasi antar entitas

```
master_barang
     │ barcode (string, TANPA constraint unik)
     ├──────────────────► barang_masuk.barcode
     └──────────────────► barang_keluar.barcode
```

**Barcode adalah satu-satunya kunci relasi.** Bukan `id`, bukan `sku`.
Konsekuensinya dibahas di [Audit #D1–D3](#82-integritas-data).

### 4.7 Profil data seed (1.404 item)

Analisis terhadap `MASTER_SEED` yang tertanam di baris 146:

**Panjang barcode:**

| Panjang | Jumlah | Catatan |
|---|---|---|
| 0 (kosong) | **356** | ⚠️ tidak bisa menerima transaksi apa pun |
| 8 | 762 | kode internal, mis. `12132519` |
| 11 | 1 | |
| 12 | 181 | mis. `SFN004073043` (alfanumerik) |
| 13 | 100 | EAN-13 |
| 14 | 4 | |

**Awalan SKU:**

| Awalan | Jumlah |
|---|---|
| (numerik murni, mis. `100337`) | 1.015 |
| `SFN` | 181 |
| `AV` | 103 |
| `FA` | 33 |
| `FI` | 31 |
| `AC` | 24 |
| `AO` | 6 |
| `FOX` | 5 |
| `TR`, `GY` | 3 masing-masing |

**Duplikat:** 3 barcode ganda (`12132848`, `12132897`, `12132898`) + 1 grup
barcode kosong beranggota 356 item. 2 grup SKU ganda.

**Kategori:** seluruh 1.404 item ber-`kategori: ""` → kartu statistik "Kategori"
di dashboard menampilkan **0**.

**Stok:** seluruh item ber-`stokAwal: 0` dan `stokMinimal: 0` → seluruhnya
berstatus `kritis` sejak awal (lihat [Audit #D4](#82-integritas-data)).

---

## 5. Mesin Perhitungan Stok

Fungsi `computeStats()` (baris 235–248) adalah jantung dashboard.

### 5.1 Rumus

```
stokAkhir = stokAwal + Σ(masuk WHERE barcode) − Σ(keluar WHERE barcode)
```

### 5.2 Implementasi

```js
function computeStats(){
  const masukByBarcode = {};
  masuk.forEach(x => { masukByBarcode[x.barcode] = (masukByBarcode[x.barcode]||0) + Number(x.jumlah||0); });
  const keluarByBarcode = {};
  keluar.forEach(x => { keluarByBarcode[x.barcode] = (keluarByBarcode[x.barcode]||0) + Number(x.jumlah||0); });
  return master.map(item => {
    const masukTotal  = masukByBarcode[item.barcode]  || 0;
    const keluarTotal = keluarByBarcode[item.barcode] || 0;
    const stokAkhir   = Number(item.stokAwal||0) + masukTotal - keluarTotal;
    const min         = Number(item.stokMinimal||0);
    const status      = stokAkhir <= min ? "kritis" : (stokAkhir <= min*1.3 ? "rendah" : "aman");
    return Object.assign({}, item, { masukTotal, keluarTotal, stokAkhir, status });
  });
}
```

Dua indeks agregat dibangun sekali (O(m+k)), lalu dipetakan ke master (O(n)).
Efisien secara algoritma, tapi **dijalankan ulang setiap render** — termasuk
setiap ketikan di kotak pencarian dashboard.

### 5.3 Aturan status

| Status | Kondisi | Label UI | Warna |
|---|---|---|---|
| `kritis` | `stokAkhir <= stokMinimal` | "Perlu order" | Merah `#B23A2E` |
| `rendah` | `stokAkhir <= stokMinimal × 1,3` | "Menipis" | Amber `#C77F0E` |
| `aman` | selain itu | "Aman" | Hijau `#25725C` |

Ambang `1,3` = zona penyangga 30% di atas stok minimal.

### 5.4 Indikator gauge

Setiap baris dashboard punya bar horizontal 80 px:

```js
denom   = max(stokMinimal × 2, stokAkhir, 1)   // skala adaptif
pct     = clamp(0, 100, stokAkhir / denom × 100)   // lebar isi bar
markPct = min(100, stokMinimal / denom × 100)      // posisi garis penanda minimum
```

Skala adaptif per baris, jadi bar antar baris **tidak sebanding** — hanya untuk
membaca posisi relatif stok terhadap batas minimalnya sendiri.

---

## 6. Mesin Parser PDF Picking List

Bagian paling kompleks dan paling bernilai dari aplikasi ini. ±200 baris kode
(baris 498–699).

### 6.1 Pipeline lengkap

```
File PDF
   │
   ▼ handlePdfUpload()            validasi MIME/ekstensi .pdf
   ▼ FileReader.readAsArrayBuffer
   ▼ pdfjsLib.getDocument()
   │
   ├─ untuk SETIAP halaman:
   │     page.getTextContent()
   │     → item mentah { str, transform[4]=x, transform[5]=y }
   │     → buang item kosong
   │     → urutkan: y menurun (atas→bawah), lalu x menaik (kiri→kanan)
   │     → KELOMPOKKAN JADI BARIS: item dianggap sebaris bila |Δy| <= 3.5
   │     → tiap baris diurutkan ulang berdasarkan x
   │
   ▼ allLines = gabungan baris semua halaman
   │
   ▼ extractPickingListRows(allLines)
   │     ├─ cari baris header tabel
   │     ├─ buildColumnsFromHeader()   → jangkar koordinat x tiap kolom
   │     ├─ untuk setiap baris data:
   │     │     isNonDataLine()?        → lewati
   │     │     assignLineToColumns()   → petakan tiap fragmen teks ke kolom
   │     │     kolom "No" berisi angka murni? → baris BARU
   │     │     bukan?                        → SAMBUNG ke baris berjalan
   │     └─ finalizePdfRow()           → bersihkan + konversi tipe
   │
   ▼ pdfImport = { status:"ready", header, rows }
   ▼ renderPdfReview()                 tabel editable untuk admin
   ▼ confirmPdfReview()                simpan ke barang keluar
```

### 6.2 Rekonstruksi baris dari koordinat

PDF tidak menyimpan konsep "baris tabel" — hanya potongan teks beserta posisi
absolutnya. Parser merekonstruksinya:

```js
items.sort((a,b) => (b.y - a.y) || (a.x - b.x));   // atas→bawah, kiri→kanan
let lines = [], currentY = null, currentLine = [];
const TOL = 3.5;                                    // toleransi vertikal (unit PDF)
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
```

`TOL = 3.5` mengakomodasi ketidakrataan baseline dalam satu baris. Nilai terlalu
besar → dua baris tabel menyatu; terlalu kecil → satu baris terpecah.

### 6.3 Deteksi header tabel

Sebuah baris dianggap header bila teks gabungannya (lowercase) mengandung
**keempat** kata kunci sekaligus:

```js
text.includes("barcode") && text.includes("nama")
&& text.includes("sku")  && text.includes("qty")
```

Lalu `buildColumnsFromHeader()` memetakan tiap fragmen header ke kunci kolom
dan mencatat koordinat `x`-nya:

| Teks header (dinormalisasi) | Kunci kolom |
|---|---|
| tepat `no` | `no` |
| mengandung `barcode` | `barcode` |
| mengandung `nama` | `nama` |
| tepat `sku` | `sku` |
| mengandung `qty` | `qty` |
| mengandung `pesanan` | `noPesanan` |

Header ditolak (`return null`) bila `barcode`, `nama`, `sku`, atau `qty` tidak
lengkap. Kolom didedup lalu diurutkan berdasarkan `x`.

### 6.4 Pemetaan sel ke kolom — batas titik tengah

Bagian ini pernah diperbaiki dan komentarnya masih terekam di kode
(baris 634–639). Pendekatan naif "x header + toleransi kecil" membuat huruf
pertama sebuah sel sering nyasar ke kolom sebelumnya — komentar menyebut contoh
nyata: `"Kanti Slip..."` kehilangan `"Ka"` yang tersedot ke kolom Barcode.

Solusinya, batas kolom = **titik tengah antara jangkar kolom ini dan jangkar
kolom sebelumnya**:

```js
const bounds = cols.map((c, idx) => idx===0 ? -Infinity : (cols[idx-1].x + c.x) / 2);
items.forEach(it => {
  let bestIdx = 0;
  for(let i=0;i<cols.length;i++){ if(it.x >= bounds[i]) bestIdx = i; }
  const key = cols[bestIdx].key;
  result[key] = result[key] ? (result[key] + " " + it.text.trim()) : it.text.trim();
});
```

Jauh lebih toleran terhadap sel yang tidak rata kiri persis dengan teks
headernya.

### 6.5 Penyaring baris non-data

Header tabel dicetak ulang di setiap halaman PDF, dan ada baris ringkasan.
Tanpa penyaring, baris-baris ini tersambung ke baris data terakhir dan
mengotorinya. Komentar kode (baris 595–599) mencatat gejala aslinya:
`"12132458Jumlah"`, `"SKU 100074"`.

`isNonDataLine()` membuang baris yang:

- mengandung keempat kata header sekaligus (header berulang)
- cocok `/dicetak\s*oleh/i`
- cocok `/tanggal\s*cetak/i`
- cocok `/jumlah\s*pesanan/i`
- cocok `/jumlah\s*produk/i`
- mengandung `/\bpick-[\w-]+/i` **dan** tidak diawali digit
- cocok `/no\.?\s*pick/i`
- diawali `halaman` / `page`, atau berpola `n / n`

### 6.6 Penggabungan baris multi-line

Nama barang panjang sering terbungkus ke baris berikutnya di PDF. Parser
memakai kolom **No** sebagai penanda batas baris:

```js
const isNewRow = /^\d+$/.test(noVal);   // kolom "No" berisi angka murni
if(isNewRow){
  if(current) rows.push(finalizePdfRow(current));
  current = { barcode:…, nama:[…], sku:…, qty:…, noPesanan:… };
} else if(current){
  if(assigned.barcode)   current.barcode += assigned.barcode;      // sambung
  if(assigned.nama)      current.nama.push(assigned.nama);         // akumulasi
  if(assigned.sku)       current.sku += (" "+assigned.sku);
  if(assigned.qty)       current.qty += assigned.qty;
  if(assigned.noPesanan) current.noPesanan += (" "+assigned.noPesanan);
}
```

`nama` ditampung sebagai array lalu digabung dengan spasi di
`finalizePdfRow()`; field lain disambung sebagai string.

### 6.7 Normalisasi akhir

```js
function finalizePdfRow(r){
  const nama = r.nama.join(" ").replace(/\s+/g," ").trim();
  return {
    barcode:    (r.barcode||"").replace(/\s+/g,"").trim(),      // buang SEMUA spasi
    nama:       nama,                                            // rapatkan spasi ganda
    sku:        (r.sku||"").trim(),
    qty:        parseInt((r.qty||"").replace(/[^\d]/g,""),10) || 0,  // digit saja
    noPesanan:  (r.noPesanan||"").trim(),
    keterangan: "Pesanan MP"                                     // default
  };
}
```

Baris tanpa `barcode` **dan** tanpa `nama` dibuang di akhir
`extractPickingListRows()`.

### 6.8 Mode cadangan (fallback)

Bila header tabel tidak terdeteksi sama sekali, `fallbackRegexParseRows()`
dijalankan: seluruh teks digabung, lalu dipotong pada setiap **deret 8–14 digit**
(diasumsikan barcode):

```js
const matches = [...fullText.matchAll(/\b(\d{8,14})\b/g)];
// untuk tiap match: potongan = dari match ini sampai match berikutnya (maks 200 char)
// barcode = angka itu sendiri
// nama    = sisa potongan, angka trailing dibuang, dipotong 120 char
// qty     = angka 1–4 digit TERAKHIR di potongan  →  /\b(\d{1,4})\b(?!.*\d{1,4}\b)/
```

Hasilnya kasar dan disengaja — tujuannya bukan akurasi, tapi memberi admin
kerangka baris yang tinggal dilengkapi manual, alih-alih layar kosong.

### 6.9 Tabel review & konfirmasi

Setiap sel adalah `<input>`/`<select>` yang menulis balik ke `pdfImport.rows`
lewat `updatePdfRow(idx, field, value)`. Badge status dihitung ulang secara
*live* saat barcode diubah.

`confirmPdfReview()` melakukan:

1. Tolak bila tidak ada baris
2. Tolak bila ada baris dengan `barcode` kosong **atau** `qty` = 0/kosong
3. `tanggal` = **hari ini** (`todayISO()`) — bukan tanggal cetak PDF
4. `sumber` = `header.noPicking` → cadangan: `fileName`
5. Untuk tiap baris:
   - `nama` = nama hasil parse → cadangan: nama dari master → cadangan: `"(tanpa nama)"`
   - `noPesanan` = no. pesanan baris → cadangan: `sumber`
6. Sisipkan semua entri di **depan** array `keluar` (terbaru di atas)
7. Simpan, tampilkan toast `"N barang keluar berhasil disimpan."`, reset state impor

---

## 7. Peta Kode Prototipe

| Baris | Blok | Isi |
|---|---|---|
| 1–9 | Head | Meta, Google Fonts, `<script>` PDF.js |
| 10–117 | CSS | Sistem desain lengkap (variabel warna, tabel, badge, gauge, kartu) |
| 120–135 | Markup | Kerangka: header, `#tabs`, `#content`, `#toastWrap` |
| 141–144 | Konstanta | `KATEGORI_OPTIONS`, `KET_MASUK`, `KET_KELUAR`, `PAGE_SIZE=50` |
| **146** | Data seed | `MASTER_SEED` — 1.404 item, **211 KB dalam satu baris** |
| 148–161 | State | Variabel global + konfigurasi worker PDF.js |
| 166–189 | Helper | `uid`, `todayISO`, `fmtDate`, `fmtNum`, `esc`, `$`, `svgIcon` |
| 194–230 | Storage | `loadKey`, `saveKey`, `setSaveStatus`, `toast`, `persist` |
| 235–248 | Stok | `computeStats()` |
| 253–272 | Navigasi | `renderBarcodeStripe`, `TABS`, `renderTabs`, `switchTab` |
| 277–379 | Dashboard | `renderDashboard`, `refreshDashboard`, `statCard`, `paginationBar` |
| 384–493 | Transaksi | `renderTransaksiTab`, picker autocomplete, `submitTransaksi`, `deleteTransaksi` |
| **498–699** | **Parser PDF** | `handlePdfUpload` → `parsePdfPickingList` → `extractPickingListRows` → helper |
| 701–818 | Review PDF | `renderPdfImportStatus`, `renderPdfReview`, `updatePdfRow`, `confirmPdfReview` |
| 824–913 | Master | `renderMaster`, `refreshMasterTable`, `submitMaster`, `editMaster`, `deleteMaster` |
| 918–948 | Router & init | `renderContent`, `init()`, listener penutup dropdown |

### Sistem desain

Estetika "kertas industri" — abu-abu kertas `#EAEFF1`, kartu putih, aksen
oranye/merah/hijau tanah, plus **garis-garis barcode dekoratif** di header
(`renderBarcodeStripe()`, pola lebar `[2,1,3,1,1,2,4,…]`).

Tiga jenis huruf: Barlow Condensed (judul & angka besar), Inter (teks), IBM Plex
Mono (barcode & SKU). Palet lengkap terdefinisi sebagai CSS custom properties di
`:root` — **pertahankan apa adanya saat migrasi ke PHP**.

---

## 8. Hasil Audit

Skala keparahan: 🔴 blocker · 🟠 tinggi · 🟡 sedang · 🔵 rendah

### 8.1 Blocker produksi

<a id="b1"></a>

#### 🔴 B1 — `window.storage` tidak ada di browser biasa

`aplikasi-gudang (2).html:196, 205`

API penyimpanan yang dipakai bukan API web standar. Diunggah apa adanya ke
Hostinger, aplikasi tampil normal tapi **semua data hilang setiap refresh** dan
header selalu berbunyi "Gagal menyimpan". Ini alasan utama migrasi.

**Perbaikan:** ganti `loadKey`/`saveKey` dengan panggilan `fetch()` ke endpoint
PHP. Lihat [Bagian 9.5](#95-strategi-penggantian-lapisan-storage).

#### 🔴 B2 — Tidak ada autentikasi sama sekali

Siapa pun yang tahu URL bisa membaca, menambah, dan menghapus seluruh data
stok. Untuk data operasional gudang di internet publik, ini tidak bisa
diterima.

**Perbaikan:** login berbasis sesi PHP + tabel `users` dengan
`password_hash()`. Semua endpoint API menolak sesi kosong.

#### 🔴 B3 — Penyimpanan seluruh array pada tiap perubahan

`persist()` menulis ulang **seluruh koleksi** untuk satu perubahan kecil.
Menghapus satu transaksi = mengirim ulang seluruh riwayat. Pada 1.404 master
item ≈ 200 KB per operasi, dan tanpa penguncian, dua admin yang menyimpan
bersamaan akan saling menimpa (*lost update*).

**Perbaikan:** operasi per-baris (`INSERT`/`UPDATE`/`DELETE`) ke MySQL.

### 8.2 Integritas data

#### 🟠 D1 — Barcode sebagai kunci relasi, tanpa constraint unik

`computeStats()` menjumlahkan transaksi berdasarkan barcode. Data seed punya
**3 barcode ganda** (`12132848`, `12132897`, `12132898`). Item ber-barcode sama
akan menampilkan angka masuk/keluar yang **identik** — stok yang sama terhitung
dua kali di total keseluruhan.

**Perbaikan:** `UNIQUE KEY` pada barcode + kolom relasi `master_id` (FK) pada
tabel transaksi; barcode tetap disimpan sebagai jejak historis.

#### 🟠 D2 — 356 item master tanpa barcode

25% dari katalog ber-`barcode: ""`. Karena barcode adalah kunci relasi, item-item
ini **secara struktural tidak bisa menerima transaksi apa pun** — stoknya
selamanya sama dengan stok awal. Lebih buruk lagi, semuanya punya barcode kosong
yang sama, jadi transaksi apa pun yang ber-barcode kosong akan muncul di
ke-356 baris sekaligus.

**Perbaikan:** wajibkan barcode saat membuat master; untuk data lama, generate
barcode internal (mis. `INT-<sku>`) atau tandai item sebagai non-aktif.

#### 🟠 D3 — Tidak ada validasi stok negatif

Barang keluar bisa melebihi stok tersedia. Tidak ada peringatan, tidak ada
penolakan. Stok akhir bisa bernilai negatif dan tampil apa adanya.

**Perbaikan:** validasi di sisi server sebelum `INSERT`; sediakan mode "izinkan
minus" bila realita gudang memang membutuhkannya (retur mendahului penerimaan).

#### 🟠 D4 — Seluruh seed berstatus kritis sejak awal

`stokAwal: 0` dan `stokMinimal: 0` pada 1.404 item. Karena aturannya
`stokAkhir <= stokMinimal` → `0 <= 0` → **`kritis`**. Dashboard menyambut
pengguna dengan spanduk merah *"1.404 item perlu segera diorder"* dan kartu
"Perlu order" bernilai 1.404. Peringatan yang selalu menyala = peringatan yang
diabaikan.

**Perbaikan:** kecualikan item ber-`stokMinimal = 0` dari status kritis, atau
lakukan opname awal untuk mengisi stok nyata sebelum go-live.

#### 🟡 D5 — Impor PDF ganda tidak terdeteksi

Picking list yang sama diunggah dua kali menghasilkan dua set transaksi keluar
— stok terpotong dua kali. Tidak ada pemeriksaan `noPicking` yang sudah pernah
diimpor.

**Perbaikan:** tabel `import_batch` dengan `UNIQUE` pada `no_picking` dan hash
SHA-256 isi file; tolak/peringatkan bila sudah ada.

#### 🟡 D6 — Tanggal transaksi tidak mengikuti tanggal cetak PDF

`confirmPdfReview()` selalu memakai `todayISO()` meski `header.tanggalCetak`
sudah berhasil diekstrak. Picking list kemarin yang baru diinput hari ini akan
tercatat bertanggal hari ini.

**Perbaikan:** isi awal tanggal dari `tanggalCetak` bila tersedia, dan sediakan
satu kolom tanggal yang bisa diubah admin di panel review.

#### 🟡 D7 — Kategori seed kosong, tapi form memaksa kategori

Semua seed ber-`kategori: ""`, sementara `KATEGORI_OPTIONS` tidak punya opsi
kosong. Begitu sebuah item lama diedit lewat form master, kategorinya otomatis
berubah jadi `FISIO` (opsi pertama) tanpa disadari pengguna.

**Perbaikan:** tambahkan opsi `— Tanpa kategori —` bernilai `""` di dropdown.

### 8.3 Fungsional & UX

#### 🟠 F1 — Riwayat transaksi terpotong di 200 baris

`renderTransaksiTable()` memakai `rows.slice(0,200)` **tanpa paginasi dan tanpa
keterangan apa pun**. Setelah 200 transaksi, riwayat lama menjadi tidak
terlihat sama sekali — pengguna mengira datanya hilang.

**Perbaikan:** paginasi sisi server (`LIMIT`/`OFFSET`) + filter rentang tanggal.

#### 🟠 F2 — Hapus tanpa konfirmasi

`deleteMaster()` dan `deleteTransaksi()` langsung menghapus pada klik pertama.
Tidak ada dialog, tidak ada urungkan. Salah klik ikon tempat sampah = data
hilang permanen.

**Perbaikan:** dialog konfirmasi + *soft delete* (kolom `deleted_at`) supaya
bisa dipulihkan.

#### 🟡 F3 — Tidak ada ekspor data

Tidak ada ekspor CSV/Excel. Data gudang terkunci di dalam aplikasi, padahal
laporan bulanan hampir pasti dibutuhkan.

**Perbaikan:** endpoint ekspor CSV untuk dashboard, riwayat masuk, dan riwayat
keluar.

#### 🟡 F4 — Tidak ada riwayat audit

Tidak tercatat siapa mengubah apa dan kapan. Untuk data stok yang dipegang
beberapa admin, jejak audit adalah kebutuhan dasar.

**Perbaikan:** kolom `user_id` + `created_at` di semua tabel transaksi, plus
tabel `activity_log` untuk operasi hapus/ubah.

#### 🔵 F5 — Dropdown keterangan tidak ikut ter-reset

`submitTransaksi()` mengosongkan barcode, nama, jumlah, dan picker — tapi
membiarkan `<select>` keterangan pada pilihan terakhir. Minor, tapi memancing
salah kategori pada input beruntun.

#### 🔵 F6 — `computeStats()` dipanggil dua kali per render dashboard

Sekali di `renderDashboard()`, sekali lagi di `refreshDashboard()` yang
dipanggil di baris terakhirnya. Pada 1.404 item ini terasa saat mengetik di
kotak pencarian (setiap `oninput` memicu hitung ulang penuh).

**Perbaikan:** pindahkan agregasi ke SQL (`GROUP BY`) atau cache hasil hingga
data berubah.

#### 🔵 F7 — Kolom pencarian kehilangan fokus? Tidak — tapi hampir

`refreshDashboard()` hanya mengganti `#dashResults`, bukan seluruh `#content`,
sehingga fokus input aman. Pola ini benar dan **harus dipertahankan** saat
menulis ulang render di versi PHP — mengganti seluruh `#content` pada tiap
ketikan akan mematahkannya.

### 8.4 Keamanan

#### 🟠 S1 — Tidak ada autentikasi/otorisasi

Lihat [B2](#b2). Diulang di sini karena ini juga temuan keamanan, bukan hanya
blocker fungsional.

#### 🟡 S2 — Konstruksi HTML lewat string

Seluruh UI dirakit sebagai string lalu dipasang via `innerHTML`. Helper `esc()`
sudah diterapkan konsisten pada data yang ditampilkan, sehingga **tidak
ditemukan jalur XSS yang bisa dieksploitasi** pada kode saat ini. Namun polanya
rapuh: satu interpolasi baru yang lupa `esc()` langsung membuka celah — dan
sumber datanya adalah PDF dari sistem eksternal.

**Perbaikan versi PHP:** `htmlspecialchars()` pada semua keluaran server; di
sisi klien, gunakan `textContent` untuk nilai dinamis, atau pertahankan `esc()`
sebagai disiplin wajib dengan tinjauan kode.

#### 🟡 S3 — Handler inline dengan interpolasi ID

Pola `onclick="deleteMaster('<id>')"` menyisipkan ID ke dalam string JavaScript
di dalam atribut HTML. ID saat ini digenerate `uid()` sehingga aman
(alfanumerik). Tapi bila kelak ID berasal dari database atau input pengguna,
`esc()` yang meng-escape HTML **tidak melindungi konteks JavaScript**.

**Perbaikan:** `addEventListener` + `data-id`, bukan atribut inline.

#### 🔵 S4 — Ketergantungan CDN tanpa Subresource Integrity

`<script src="…cdnjs…/pdf.min.js">` tanpa atribut `integrity`. Bila CDN
dikompromikan, skrip apa pun akan berjalan penuh di halaman.

**Perbaikan:** host sendiri `pdf.min.js` + `pdf.worker.min.js` di server
Hostinger (juga menghilangkan ketergantungan internet eksternal), atau tambahkan
`integrity` + `crossorigin`.

### 8.5 Kualitas yang patut dipertahankan

Bagian-bagian ini sudah dipikirkan matang. Jangan ditulis ulang tanpa alasan
kuat saat migrasi:

- ✅ **Alur review sebelum simpan pada impor PDF** — keputusan desain yang tepat
- ✅ **Batas kolom titik-tengah** di `assignLineToColumns()` — hasil perbaikan
  bug nyata, komentarnya menjelaskan alasannya
- ✅ **Penyaring `isNonDataLine()`** — menangani header/footer berulang antar halaman
- ✅ **Mode cadangan regex** — degradasi bertahap, bukan layar kosong
- ✅ **Badge status per baris review** (cocok master / tak dikenal / barcode kosong)
- ✅ **Penggabungan baris multi-line** via kolom No sebagai penanda
- ✅ **Sistem desain** — konsisten, terbaca, sudah responsif
- ✅ **Paginasi 50 baris** di dashboard & master
- ✅ **Pola render parsial** yang menjaga fokus input saat memfilter

---

## 9. Rancangan Versi PHP untuk Hostinger

### 9.1 Prinsip migrasi

1. **Parser PDF tetap di browser.** Algoritma berbasis koordinat sudah tuned dan
   teruji; memindahkannya ke PHP berarti menulis ulang dari nol dengan pustaka
   yang akses koordinatnya lebih lemah. Selain itu, parsing di klien tidak
   membebani CPU shared hosting.
2. **Yang dikirim ke server adalah baris hasil review**, bukan file PDF.
3. **Ganti hanya lapisan penyimpanan.** Semua fungsi `render*()` dipertahankan.
4. **PHP prosedural, tanpa framework.** Tanpa Composer, tanpa build step —
   berjalan identik di XAMPP lokal maupun shared hosting Hostinger. Cukup salin
   folder, tidak ada langkah kompilasi yang bisa gagal saat *deploy*.
5. **Perbaiki temuan blocker + integritas data** saat migrasi, jangan diwariskan.

### 9.2 Struktur folder

```
public_html/
├── index.php                  Halaman utama (cek sesi → redirect login)
├── login.php                  Form login
├── logout.php
│
├── config/
│   ├── database.php           Kredensial MySQL (di luar public_html bila bisa)
│   └── config.php             Konstanta aplikasi
│
├── includes/
│   ├── db.php                 Koneksi PDO + helper
│   ├── auth.php               requireLogin(), currentUser(), CSRF
│   ├── response.php           jsonResponse(), jsonError()
│   └── helpers.php            sanitasi, validasi, format
│
├── api/
│   ├── master/
│   │   ├── list.php           GET   daftar + cari + paginasi
│   │   ├── save.php           POST  tambah / ubah
│   │   └── delete.php         POST  hapus (soft delete)
│   ├── masuk/
│   │   ├── list.php
│   │   ├── create.php
│   │   └── delete.php
│   ├── keluar/
│   │   ├── list.php
│   │   ├── create.php
│   │   └── delete.php
│   ├── import/
│   │   ├── check.php          POST  cek duplikat no_picking / hash file
│   │   └── commit.php         POST  simpan batch hasil review (transaksional)
│   ├── dashboard/
│   │   └── stats.php          GET   stok akhir teragregasi via SQL
│   └── export/
│       └── csv.php            GET   ekspor dashboard / masuk / keluar
│
├── assets/
│   ├── css/app.css            CSS dipindahkan dari <style> prototipe
│   ├── js/
│   │   ├── app.js             State, router, render*() dari prototipe
│   │   ├── api.js             Pembungkus fetch (pengganti loadKey/saveKey)
│   │   └── pdf-parser.js      Baris 498–699 prototipe, dipindah apa adanya
│   └── vendor/
│       ├── pdf.min.js         Host sendiri (hilangkan CDN)
│       └── pdf.worker.min.js
│
├── sql/
│   ├── 001_schema.sql         DDL
│   └── 002_seed_master.sql    1.404 item hasil konversi MASTER_SEED
│
└── .htaccess                  Paksa HTTPS, blokir /config, header keamanan
```

### 9.3 Lingkungan pengembangan — XAMPP

Pengembangan dilakukan lokal di XAMPP, produksi di Hostinger. Keduanya
menjalankan Apache + PHP + MySQL/MariaDB, jadi kodenya sama — yang berbeda hanya
kredensial database dan beberapa perilaku bawaan.

#### Penempatan proyek

```
C:\xampp\htdocs\web-stock\        ← seluruh isi public_html/ ditaruh di sini
```

Aplikasi diakses di **`http://localhost/web-stock/`**.

Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel. Bila port 80 bentrok
(sering karena IIS, Skype, atau VMware), ubah `Listen 80` → `Listen 8080` di
`C:\xampp\apache\conf\httpd.conf`, lalu akses lewat
`http://localhost:8080/web-stock/`.

#### Membuat database

1. Buka `http://localhost/phpmyadmin`
2. Tab **Databases** → nama `web_stock` → collation `utf8mb4_general_ci` → Create
3. Tab **Import** → pilih `sql/001_schema.sql` → Go
4. Ulangi untuk `sql/002_seed_master.sql`

**Seed 1.404 baris kemungkinan besar menembus batas unggah bawaan phpMyAdmin
(2 MB).** Dua jalan keluar:

**Cara A — naikkan batas** (`C:\xampp\php\php.ini`, lalu restart Apache):

```ini
upload_max_filesize = 64M
post_max_size       = 64M
max_execution_time  = 300
memory_limit        = 256M
```

**Cara B — impor lewat CLI** (lebih cepat, tanpa batas unggah):

```bat
cd C:\xampp\mysql\bin
mysql -u root web_stock < C:\web-stock\sql\001_schema.sql
mysql -u root web_stock < C:\web-stock\sql\002_seed_master.sql
```

#### Kredensial bawaan XAMPP

| Item | Nilai bawaan |
|---|---|
| Host | `localhost` |
| User | `root` |
| Password | *(kosong)* |
| Port | `3306` |

> **Peringatan keamanan.** `root` tanpa password hanya boleh untuk lokal. Jangan
> pernah menyalin kredensial ini ke server produksi, dan pastikan
> `config/database.php` tidak ikut ter-*commit* dengan nilai produksi di
> dalamnya. Bila XAMPP dijalankan di jaringan yang bisa diakses orang lain, beri
> password pada `root` lewat phpMyAdmin → User accounts.

#### Beda XAMPP (MariaDB) vs Hostinger (MySQL)

XAMPP versi terkini mengirimkan **MariaDB**, bukan MySQL Oracle. Untuk skema di
[Bagian 10](#10-skema-database), perbedaan yang relevan:

| Fitur | MariaDB (XAMPP) | MySQL (Hostinger) | Catatan |
|---|---|---|---|
| `CHECK` constraint | Ditegakkan sejak 10.2 | Ditegakkan sejak 8.0; **diabaikan diam-diam** di 5.7 | `chk_masuk_jumlah` bisa aktif di lokal tapi tidak di produksi lama → **validasi jumlah wajib tetap ada di PHP**, jangan bergantung pada constraint |
| Tipe `JSON` | Alias `LONGTEXT` + `json_valid()` | Tipe asli | Kolom `activity_log.detail` tetap jalan di keduanya; jangan pakai operator `->>` khusus MySQL |
| `FULLTEXT` di InnoDB | Didukung | Didukung | `ft_cari` aman |
| `utf8mb4` | Bawaan | Bawaan | Aman |

Kesimpulan praktis: **tulis SQL yang berjalan di keduanya**, dan taruh semua
aturan bisnis (stok tidak boleh minus, jumlah > 0, barcode wajib) di lapisan PHP
— constraint database diperlakukan sebagai jaring pengaman kedua, bukan yang
utama.

#### Konfigurasi dua lingkungan

Satu berkas, deteksi otomatis lokal vs produksi — tidak perlu ganti-ganti isi
file saat *deploy*:

```php
<?php
// config/database.php
declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLokal = in_array($host, ['localhost', '127.0.0.1'], true)
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '192.168.');

if ($isLokal) {
    // XAMPP
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'web_stock');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('APP_DEBUG', true);
} else {
    // Hostinger — isi dari hPanel → Databases
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'uXXXXXX_web_stock');
    define('DB_USER', 'uXXXXXX_gudang');
    define('DB_PASS', 'GANTI_DENGAN_PASSWORD_ASLI');
    define('APP_DEBUG', false);
}
```

```php
<?php
// includes/db.php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // prepared statement asli
    ]);
    $pdo->exec("SET time_zone = '+07:00'");      // WIB, konsisten lokal & produksi
    return $pdo;
}
```

`APP_DEBUG` mengatur apakah pesan galat ditampilkan (lokal) atau hanya dicatat
ke log (produksi). Jangan pernah menampilkan `getMessage()` PDO ke pengguna di
produksi — isinya bisa membocorkan struktur database.

#### Penyesuaian `php.ini` XAMPP

`C:\xampp\php\php.ini` — restart Apache setelah mengubah:

```ini
date.timezone = Asia/Jakarta     ; bawaan sering kosong/UTC → tanggal transaksi meleset
extension=pdo_mysql              ; pastikan tidak dikomentari (biasanya sudah aktif)
display_errors = On              ; lokal saja; produksi Off
```

#### Perbedaan yang menggigit saat pindah ke Hostinger

| Hal | XAMPP (Windows) | Hostinger (Linux) |
|---|---|---|
| Nama berkas | **Tidak** peka huruf besar/kecil | **Peka** — `Api/List.php` ≠ `api/list.php` |
| Pemisah path | `\` diterima | Hanya `/` — selalu pakai `DIRECTORY_SEPARATOR` atau `/` |
| Nama database | Bebas | Berawalan wajib `uXXXXXX_` |
| Zona waktu | Ikut Windows | Sering UTC |
| HTTPS | Tidak ada | Wajib, paksa via `.htaccess` |

Yang paling sering menjatuhkan orang adalah **peka huruf besar/kecil**: kode
berjalan mulus di XAMPP lalu `404` di Hostinger karena satu huruf kapital di
nama file. Pakai penamaan huruf kecil konsisten sejak awal.

#### Uji cepat setelah setup

Simpan sementara sebagai `htdocs/web-stock/cek.php`, buka di browser,
**hapus setelah selesai**:

```php
<?php
require __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo 'PHP        : ' . PHP_VERSION . "\n";
echo 'pdo_mysql  : ' . (extension_loaded('pdo_mysql') ? 'ada' : 'TIDAK ADA') . "\n";
echo 'Zona waktu : ' . date_default_timezone_get() . "\n";

try {
    $pdo = db();
    echo 'Server DB  : ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
    echo 'Koneksi    : OK' . "\n";
    echo 'Master     : ' . $pdo->query('SELECT COUNT(*) FROM master_barang')->fetchColumn() . " baris\n";
} catch (Throwable $e) {
    echo 'Koneksi    : GAGAL — ' . $e->getMessage() . "\n";
}
```

Jumlah master seharusnya cocok dengan hasil konversi `MASTER_SEED`
(1.404 dikurangi baris yang sengaja dibuang saat menangani barcode kosong dan
duplikat — lihat [Bagian 10.2](#102-konversi-master_seed--sql)).

### 9.4 Catatan khusus Hostinger

| Hal | Keterangan |
|---|---|
| PHP | Pilih 8.1+ di hPanel → Advanced → PHP Configuration |
| MySQL | Buat database + user di hPanel → Databases → Management |
| Host DB | `localhost` (bukan IP eksternal) |
| Nama DB & user | Otomatis berawalan `uXXXXXX_` — sesuaikan di `config/database.php` |
| Impor SQL | phpMyAdmin bawaan hPanel; `002_seed_master.sql` besar → pecah bila melebihi batas unggah |
| HTTPS | SSL gratis via hPanel → Security → SSL; paksa redirect lewat `.htaccess` |
| Zona waktu | Set `date_default_timezone_set('Asia/Jakarta')` di `config.php` — server bisa saja UTC |
| `session.save_path` | Bawaan sudah jalan; tidak perlu diubah |
| Batas unggah | Prototipe tidak mengunggah PDF ke server, jadi `upload_max_filesize` tidak relevan |
| Ekstensi | Butuh `pdo_mysql` (aktif secara default) |
| Unggah berkas | File Manager hPanel, atau FTP (FileZilla) ke `public_html/` |

### 9.5 Strategi penggantian lapisan storage

Ini seluruh inti migrasi. Antarmuka `loadKey`/`saveKey` diganti pembungkus
`fetch`, dan sisa aplikasi hampir tidak berubah:

```js
// assets/js/api.js — pengganti loadKey/saveKey

async function apiGet(path, params = {}){
  const qs  = new URLSearchParams(params).toString();
  const res = await fetch(`api/${path}` + (qs ? `?${qs}` : ''), {
    credentials: 'same-origin'
  });
  if(res.status === 401){ location.href = 'login.php'; return null; }
  if(!res.ok) throw new Error('Gagal memuat data');
  return res.json();
}

async function apiPost(path, body){
  const res = await fetch(`api/${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.CSRF_TOKEN
    },
    body: JSON.stringify(body)
  });
  if(res.status === 401){ location.href = 'login.php'; return null; }
  const data = await res.json();
  if(!res.ok || !data.ok) throw new Error(data.error || 'Gagal menyimpan');
  return data;
}
```

**Perubahan pola yang mengikutinya:**

| Prototipe | Versi PHP |
|---|---|
| `persist("gudang-barang-masuk", [entry, ...masuk], …)` | `apiPost('masuk/create.php', entry)` lalu muat ulang halaman aktif saja |
| `computeStats()` di klien atas 1.404 item | `GET api/dashboard/stats.php` — agregasi `GROUP BY` di SQL |
| `master.filter(...)` + `slice()` di klien | `GET api/master/list.php?q=…&page=…` — `WHERE` + `LIMIT` di SQL |
| `confirmPdfReview()` → satu penulisan array raksasa | `POST api/import/commit.php` — satu transaksi SQL berisi banyak `INSERT` |
| `rows.slice(0,200)` | paginasi server sungguhan |

`renderDashboard()` dan kawan-kawan menjadi `async`, dengan sumber data dari
respons API alih-alih variabel global. Struktur objeknya dibuat identik dengan
prototipe supaya kode render tidak perlu diubah.

### 9.6 Alur impor PDF di versi PHP

```
Browser                                    Server PHP
───────                                    ──────────
pilih PDF
  │
  ▼ pdf-parser.js (kode prototipe, utuh)
baris hasil parse
  │
  ▼ POST api/import/check.php ──────────►  cek no_picking & hash file
  ◄────────────────────────────────────    { duplikat: true/false, batch_sebelumnya }
  │
  ▼ tampilkan tabel review
  │  (+ peringatan bila duplikat)
  ▼ admin koreksi & konfirmasi
  │
  ▼ POST api/import/commit.php ─────────►  BEGIN TRANSACTION
     { header, rows[], tanggal }             INSERT import_batch
                                             INSERT barang_keluar × N
                                             (cocokkan barcode → master_id)
                                           COMMIT
  ◄────────────────────────────────────    { ok:true, tersimpan:N, batch_id }
```

Hash file dihitung di browser (`crypto.subtle.digest('SHA-256', buffer)`) dari
`ArrayBuffer` yang sudah ada di tangan — tidak perlu mengunggah PDF sama sekali.

---

## 10. Skema Database

Skema ini berjalan apa adanya di **MariaDB 10.4+ (XAMPP)** maupun **MySQL 5.7+ /
8.0 (Hostinger)**. Perbedaan perilaku antar keduanya dirangkum di
[Bagian 9.3](#93-lingkungan-pengembangan--xampp) — poin terpentingnya: `CHECK`
constraint tidak ditegakkan di MySQL 5.7, jadi **validasi yang sama wajib ada di
PHP**.

```sql
-- ============================================================
-- 001_schema.sql
-- MariaDB 10.4+ (XAMPP)  |  MySQL 5.7+ / 8.0 (Hostinger)
-- ============================================================
SET NAMES utf8mb4;

-- Jalankan setelah database dibuat:
--   XAMPP     : phpMyAdmin → Databases → "web_stock" → utf8mb4_general_ci
--   Hostinger : hPanel → Databases → Management (nama berawalan uXXXXXX_)

-- Pengguna -----------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,      -- password_hash(), PASSWORD_DEFAULT
  nama_lengkap  VARCHAR(100) NOT NULL,
  role          ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  aktif         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master barang ------------------------------------------------
CREATE TABLE master_barang (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku          VARCHAR(50)  NOT NULL DEFAULT '',
  barcode      VARCHAR(50)  NOT NULL,        -- WAJIB (perbaikan audit D2)
  nama         VARCHAR(255) NOT NULL,
  stok_awal    INT          NOT NULL DEFAULT 0,
  stok_minimal INT          NOT NULL DEFAULT 0,
  kategori     VARCHAR(30)  NOT NULL DEFAULT '',
  aktif        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP    NULL DEFAULT NULL,   -- soft delete (audit F2)
  UNIQUE KEY uq_barcode (barcode),               -- perbaikan audit D1
  KEY idx_sku      (sku),
  KEY idx_nama     (nama),
  KEY idx_kategori (kategori),
  FULLTEXT KEY ft_cari (nama, sku)               -- pencarian cepat 1.404+ baris
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Batch impor PDF ---------------------------------------------
CREATE TABLE import_batch (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no_picking     VARCHAR(100) NOT NULL DEFAULT '',
  nama_file      VARCHAR(255) NOT NULL DEFAULT '',
  file_hash      CHAR(64)     NOT NULL DEFAULT '',   -- SHA-256, anti-duplikat (audit D5)
  tanggal_cetak  DATE         NULL,
  dicetak_oleh   VARCHAR(100) NOT NULL DEFAULT '',
  jumlah_pesanan INT          NOT NULL DEFAULT 0,
  jumlah_produk  INT          NOT NULL DEFAULT 0,
  jumlah_baris   INT          NOT NULL DEFAULT 0,
  user_id        INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_no_picking (no_picking),
  KEY idx_file_hash  (file_hash),
  CONSTRAINT fk_batch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Barang masuk -------------------------------------------------
CREATE TABLE barang_masuk (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,
  master_id  INT UNSIGNED NULL,               -- relasi kuat (perbaikan audit D1)
  barcode    VARCHAR(50)  NOT NULL,           -- jejak historis
  nama       VARCHAR(255) NOT NULL,
  jumlah     INT          NOT NULL,
  keterangan VARCHAR(50)  NOT NULL DEFAULT 'Restock',
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_tanggal   (tanggal),
  KEY idx_master    (master_id),
  KEY idx_barcode   (barcode),
  CONSTRAINT fk_masuk_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_masuk_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_masuk_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Barang keluar ------------------------------------------------
CREATE TABLE barang_keluar (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal    DATE         NOT NULL,
  master_id  INT UNSIGNED NULL,
  barcode    VARCHAR(50)  NOT NULL,
  nama       VARCHAR(255) NOT NULL,
  jumlah     INT          NOT NULL,
  keterangan VARCHAR(50)  NOT NULL DEFAULT 'Pesanan MP',
  no_pesanan VARCHAR(100) NOT NULL DEFAULT '',
  batch_id   INT UNSIGNED NULL,               -- NULL = input manual
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP    NULL DEFAULT NULL,
  KEY idx_tanggal    (tanggal),
  KEY idx_master     (master_id),
  KEY idx_barcode    (barcode),
  KEY idx_batch      (batch_id),
  KEY idx_no_pesanan (no_pesanan),
  CONSTRAINT fk_keluar_master FOREIGN KEY (master_id) REFERENCES master_barang(id) ON DELETE SET NULL,
  CONSTRAINT fk_keluar_batch  FOREIGN KEY (batch_id)  REFERENCES import_batch(id)  ON DELETE SET NULL,
  CONSTRAINT fk_keluar_user   FOREIGN KEY (user_id)   REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT chk_keluar_jumlah CHECK (jumlah > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jejak audit (perbaikan audit F4) -----------------------------
CREATE TABLE activity_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  aksi       VARCHAR(50)  NOT NULL,           -- create|update|delete|import
  entitas    VARCHAR(50)  NOT NULL,           -- master|masuk|keluar|batch
  entitas_id INT UNSIGNED NULL,
  detail     JSON         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_waktu (user_id, created_at),
  KEY idx_entitas    (entitas, entitas_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 10.1 Query stok akhir (pengganti `computeStats()`)

```sql
SELECT
  m.id, m.sku, m.barcode, m.nama, m.kategori,
  m.stok_awal, m.stok_minimal,
  COALESCE(i.total, 0)                                        AS masuk_total,
  COALESCE(o.total, 0)                                        AS keluar_total,
  m.stok_awal + COALESCE(i.total,0) - COALESCE(o.total,0)     AS stok_akhir,
  CASE
    WHEN m.stok_awal + COALESCE(i.total,0) - COALESCE(o.total,0) <= m.stok_minimal       THEN 'kritis'
    WHEN m.stok_awal + COALESCE(i.total,0) - COALESCE(o.total,0) <= m.stok_minimal * 1.3 THEN 'rendah'
    ELSE 'aman'
  END                                                         AS status
FROM master_barang m
LEFT JOIN (
  SELECT master_id, SUM(jumlah) AS total
  FROM barang_masuk  WHERE deleted_at IS NULL GROUP BY master_id
) i ON i.master_id = m.id
LEFT JOIN (
  SELECT master_id, SUM(jumlah) AS total
  FROM barang_keluar WHERE deleted_at IS NULL GROUP BY master_id
) o ON o.master_id = m.id
WHERE m.deleted_at IS NULL AND m.aktif = 1
ORDER BY m.nama
LIMIT :limit OFFSET :offset;
```

Agregasi dilakukan MySQL, bukan browser — mengatasi [F6](#83-fungsional--ux)
sekaligus membuat aplikasi tetap ringan saat data transaksi tumbuh besar.

### 10.2 Konversi `MASTER_SEED` → SQL

`MASTER_SEED` di baris 146 diubah menjadi `002_seed_master.sql` sekali saja,
dengan dua penyesuaian wajib:

1. **356 item ber-barcode kosong** — kolom `barcode` kini `UNIQUE NOT NULL`,
   jadi tidak bisa dimuat apa adanya. Pilih salah satu:
   - beri barcode internal: `CONCAT('INT-', sku)`; item tanpa SKU pakai nomor urut
   - atau muat dengan `aktif = 0` dan lengkapi barcodenya kemudian
2. **3 barcode duplikat** (`12132848`, `12132897`, `12132898`) — putuskan mana
   yang benar sebelum impor, atau beri akhiran pembeda.

Konversi bisa dijalankan sekali dengan skrip PHP sekali pakai yang membaca array
JSON dari file HTML lama dan menghasilkan `INSERT` bertumpuk.

---

## 11. Spesifikasi API

Semua endpoint mengembalikan JSON. Semua kecuali login memerlukan sesi aktif;
tanpa sesi → HTTP `401` dengan `{"ok":false,"error":"Sesi berakhir"}`.
Semua permintaan `POST` memerlukan header `X-CSRF-Token`.

### Autentikasi

| Metode | Endpoint | Body | Respons |
|---|---|---|---|
| POST | `login.php` | `username`, `password` | redirect atau `{ok, user}` |
| GET | `logout.php` | — | redirect ke login |

### Master barang

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/master/list.php` | `q`, `page`, `per_page` | `{ok, rows[], total, page, total_pages}` |
| POST | `api/master/save.php` | `id?`, `sku`, `barcode`, `nama`, `stok_awal`, `stok_minimal`, `kategori` | `{ok, id}` |
| POST | `api/master/delete.php` | `id` | `{ok}` |

Validasi `save.php`: `barcode` dan `nama` wajib; `barcode` harus unik (kecuali
terhadap dirinya sendiri saat ubah); `stok_awal`/`stok_minimal` ≥ 0.

### Transaksi

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/masuk/list.php` | `q`, `dari`, `sampai`, `page` | `{ok, rows[], total, …}` |
| POST | `api/masuk/create.php` | `tanggal`, `barcode`, `nama`, `jumlah`, `keterangan` | `{ok, id}` |
| POST | `api/masuk/delete.php` | `id` | `{ok}` |
| GET | `api/keluar/list.php` | `q`, `dari`, `sampai`, `batch_id`, `page` | `{ok, rows[], total, …}` |
| POST | `api/keluar/create.php` | idem + `no_pesanan` | `{ok, id}` |
| POST | `api/keluar/delete.php` | `id` | `{ok}` |

`create.php` mencocokkan `barcode` ke `master_barang` untuk mengisi `master_id`;
bila tidak ketemu, `master_id` dibiarkan `NULL` dan responsnya menyertakan
`{"peringatan":"Barcode tidak ada di master"}` — transaksi tetap tercatat
(mempertahankan perilaku prototipe agar operasional tidak terhenti).

### Impor PDF

**`POST api/import/check.php`**

```json
{ "no_picking": "PICK-20260812-001", "file_hash": "a3f9…" }
```

```json
{ "ok": true, "duplikat": true,
  "batch_sebelumnya": { "id": 12, "created_at": "2026-08-11 09:14:22",
                        "jumlah_baris": 87, "dicetak_oleh": "admin_gudang" } }
```

**`POST api/import/commit.php`**

```json
{
  "header": {
    "noPicking": "PICK-20260812-001",
    "tanggalCetak": "12/08/2026",
    "dicetakOleh": "admin_gudang",
    "jumlahPesanan": "14",
    "jumlahProduk": "87"
  },
  "fileName": "picking-list.pdf",
  "fileHash": "a3f9…",
  "tanggal":  "2026-08-12",
  "abaikanDuplikat": false,
  "rows": [
    { "barcode":"12132519", "nama":"FINGERTAPE BIRU MUDA", "sku":"FI-0002",
      "qty":3, "noPesanan":"MP-8891023", "keterangan":"Pesanan MP" }
  ]
}
```

```json
{ "ok": true, "batch_id": 13, "tersimpan": 87,
  "tanpa_master": 4, "peringatan": [] }
```

Diproses dalam **satu transaksi SQL**: `INSERT import_batch`, lalu seluruh
`INSERT barang_keluar` dengan `batch_id` tersebut. Bila satu baris gagal →
`ROLLBACK` penuh. Baris dengan `barcode` kosong atau `qty` ≤ 0 ditolak sebelum
transaksi dimulai (validasi server, menduplikasi pemeriksaan klien —
pemeriksaan klien saja tidak pernah cukup).

### Dashboard & ekspor

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/dashboard/stats.php` | `q`, `kategori`, `status`, `page` | `{ok, rows[], ringkasan:{total_sku,total_stok,perlu_order,kategori}, …}` |
| GET | `api/export/pdf.php` | `jenis=dashboard\|masuk\|keluar\|riwayat\|pertukaran\|master\|aktivitas` + filter layar asalnya | berkas PDF |

### Hak akses

Diatur per akun lewat menu **Pengguna**, dan ditegakkan di
`includes/izin.php`. Dua hal yang diatur:

| | |
|---|---|
| **Menu** | daftar menu yang boleh dibuka akun ini |
| **Peran** | `admin` penuh · `operator` boleh menulis · `viewer` hanya melihat |

Peran `viewer` ditolak di **setiap** endpoint yang menulis, di menu mana pun
— bukan sekadar tombolnya disembunyikan.

```php
// includes/izin.php — jalur endpoint -> [menu yang dibutuhkan, apakah menulis]
'keluar/create.php'      => ['keluar', true],
'master/list.php'        => [null,     false],   // dipakai form di banyak menu
'master/save.php'        => ['master', true],
'export/pdf.php'         => ['@ekspor', false],  // menu ditentukan dari `jenis`
```

Petanya ditulis lengkap dan eksplisit, bukan ditebak dari nama folder atau
metode HTTP: sebagian endpoint POST sebenarnya hanya membaca
(`master/cek_barcode.php`), dan sebagian endpoint di folder `master` dipakai
oleh form di menu lain. Menebaknya akan salah persis di tempat-tempat itu.
Endpoint yang tidak terdaftar **ditolak**, supaya endpoint baru yang lupa
didaftarkan berakhir dengan galat yang kelihatan, bukan celah yang diam.

Pemeriksaannya dipasang di `wajibLoginApi()` — fungsi yang sudah dipanggil
setiap endpoint API — jadi endpoint baru ikut terjaga tanpa perlu diingat
satu per satu. Ekspor PDF diperiksa terpisah di `api/export/pdf.php`, karena
unduhan itu navigasi biasa dan penolakannya harus berupa halaman, bukan JSON.

**Dibaca dari database tiap permintaan, bukan dari sesi.** Peran dan daftar
akses diambil ulang dari tabel `users` setiap kali. Kalau dibaca dari isi
sesi, akun yang aksesnya baru dicabut — atau yang baru dinonaktifkan — tetap
bisa bekerja sampai ia logout sendiri. Karena alasan yang sama,
`wajibLoginHalaman()` ikut memeriksa `aktif`.

Dua batas yang tetap keras:

- Menu **Pengguna** tidak bisa diberikan ke akun non-admin. Akun non-admin
  yang bisa mengelola pengguna dapat mengangkat dirinya sendiri jadi admin.
- Admin selalu punya seluruh menu; kolom `akses`-nya tidak disimpan, supaya
  tidak menyesatkan saat perannya kelak diturunkan.

Akun yang kolom `akses`-nya kosong mendapat **menu bawaan**: seluruh menu
kecuali Log aktivitas — sama persis dengan jangkauan operator sebelum fitur
ini ada. Akun lama tidak kehilangan akses karena pembaruan, dan juga tidak
mendadak mendapat akses yang dulu tidak dimilikinya.

### Master keterangan transaksi

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/keterangan/list.php` | `jenis=masuk\|keluar` | `{ok, rows[], tanpa_keterangan, total}` |
| POST | `api/keterangan/save.php` | `{id?, jenis, nama, catatan?, urutan?, aktif?}` | `{ok, id, ikut, pesan}` |
| POST | `api/keterangan/delete.php` | `{id, pindah_ke?}` | `{ok, pesan, dipindah}` |

Isi dropdown **Keterangan** di Barang masuk dan Barang keluar. Sebelumnya
dipaku di `config/config.php` sebagai `KET_MASUK`/`KET_KELUAR`, jadi menambah
satu pilihan berarti menyunting berkas dan deploy ulang.

Keterangan disimpan sebagai **teks** di tabel transaksi, bukan sebagai
relasi — sama seperti kategori pada master barang. Konsekuensinya ditangani
langsung:

- **Ganti nama** ikut memperbarui seluruh transaksi yang memakainya, dalam
  satu transaksi SQL. Tanpa itu, catatan lama akan memuat nilai yang tidak
  ada lagi di daftar dan hilang dari penyaringan tanpa pesan.
- **Hapus** ditolak bila masih dipakai, kecuali disertai `pindah_ke`;
  catatannya dipindahkan dulu, baru pilihannya dihapus.

Baris ber-`terkunci = 1` tidak bisa dihapus, diganti nama, atau
dinonaktifkan. Saat ini hanya `Retur Masuk`: nilainya ditulis sistem ketika
retur ditandai Lengkap, jadi mengubahnya akan memutus sambungan itu tanpa
ada yang memberi tahu.

`daftarKeterangan()` jatuh kembali ke daftar bawaan di `config.php` bila
tabelnya belum ada atau seluruh isinya dinonaktifkan — form transaksi tidak
boleh pernah kehilangan pilihannya.

### Log aktivitas

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/aktivitas/list.php` | `q`, `dari`, `sampai`, `aksi`, `entitas`, `user`, `page` | `{ok, rows[], hari_ini, orang_hari, opsi:{aksi[],entitas[],user[]}, …}` |

Hanya admin. Tiap baris sudah diterjemahkan server jadi kalimat
(`judul`, `rincian`, `modul`, `nada`) oleh `includes/aktivitas.php`, supaya
tampilan layar dan PDF tidak pernah berbeda kata. Kolom `detail` mentah
tidak ikut dikirim.

Yang tercatat: masuk, keluar dari sistem, percobaan masuk gagal, input dan
penghapusan barang masuk/keluar, impor picking list PDF, perubahan master
barang, kategori, pengguna, penyamaan nama transaksi, dan setiap unduhan
laporan PDF. Pencatatan unduhan terjadi **sesudah** pemeriksaan hak akses,
jadi permintaan yang ditolak tidak meninggalkan jejak unduhan palsu.

Log tidak bisa diubah atau dihapus lewat aplikasi — tidak ada endpoint
tulis untuk `activity_log`.

### Riwayat (rekap per barang)

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/riwayat/list.php` | `q`, `kategori`, `dari`, `sampai`, `page` | `{ok, rows[], total_awal, total_masuk, total_keluar, total_akhir, kategori_options, …}` |

Satu baris = satu **barang**, bukan satu transaksi. Dasarnya `master_barang`
dengan agregat mutasi di-JOIN, sehingga barang yang tidak bergerak sama
sekali tetap tampil — saat menutup periode, "tidak bergerak" juga jawaban
yang dicari.

```
stok awal   = stok_awal master + seluruh mutasi SEBELUM tanggal "dari"
stok masuk  = jumlah masuk di dalam rentang
stok keluar = jumlah keluar di dalam rentang
stok akhir  = stok awal + masuk - keluar
```

Bila `dari` kosong tidak ada apa pun sebelum rentang, jadi stok awalnya
adalah `stok_awal` master itu sendiri. Query-nya ada di
`includes/riwayat.php` dan dipakai bersama oleh layar dan ekspor PDF supaya
angkanya tidak mungkin berbeda. Barang nonaktif ikut tampil selama belum
dihapus: stoknya masih nyata ada di rak.

### Retur

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/retur/list.php` | `q`, `dari`, `sampai`, `status`, `page` | `{ok, rows[], total_unit, unit_ke_stok, unit_tertahan, status_options, status_masuk, …}` |
| POST | `api/retur/save.php` | `{id?, tanggal, no_pesanan, barcode, sku, nama, jumlah, status, keterangan}` | `{ok, id, peringatan[], pesan}` |
| POST | `api/retur/delete.php` | `{id}` | `{ok, pesan}` |

Retur berstatus **Lengkap** ikut membuat satu baris `barang_masuk`
berketerangan `Retur Masuk`, dan id-nya disimpan di `retur.masuk_id`.
Keduanya selalu ditulis dalam satu transaksi:

| Yang terjadi pada retur | Yang terjadi pada barang masuk |
|---|---|
| status jadi Lengkap | baris dibuat |
| jumlah / tanggal / barang berubah | baris itu diperbarui |
| status kembali belum selesai | baris itu di-soft delete |
| status jadi Lengkap lagi | baris yang sama dihidupkan kembali |
| retur dihapus | baris itu ikut dihapus |

`masuk_id` sengaja **tidak** dikosongkan saat barisnya dihapus. Kalau
dikosongkan, setiap kali status bolak-balik akan lahir baris barang masuk
baru dan yang lama menumpuk sebagai sampah terhapus. Karena itu yang
menentukan "retur ini menambah stok" adalah statusnya, bukan ada tidaknya
`masuk_id` — dan server mengirim `status_masuk` supaya layar tidak perlu
menebaknya dari teks.

Barang dicari lewat barcode dulu, lalu SKU. Lembar retur gudang ditulis per
SKU dan SKU tidak dijamin unik di master, jadi kecocokan ganda dipakai yang
pertama sambil memberi peringatan.

### Stok opname

| Metode | Endpoint | Parameter | Respons |
|---|---|---|---|
| GET | `api/opname/list.php` | `q`, `page` | `{ok, rows[], kategori_options, …}` |
| POST | `api/opname/save.php` | `{id?, nama, periode, tanggal, kategori?, status?, catatan?}` | `{ok, id, jml_item, pesan}` |
| POST | `api/opname/delete.php` | `{id}` | `{ok, pesan}` |
| GET | `api/opname/detail.php` | `id`, `q`, `kategori`, `hanya`, `page` | `{ok, sesi, rows[], ringkas, kategori_options, …}` |
| POST | `api/opname/item.php` | `{id, stok_hitung?, stok_accurate?, dicek?, penyesuaian?, petugas?, catatan?}` | `{ok, …, selisih}` |
| POST | `api/opname/massal.php` | `{id, q?, kategori?, hanya?, petugas?, penyesuaian?, pratinjau?}` | `{ok, jumlah, cocok, pesan}` |

Membuat dan menghapus sesi hanya untuk admin; mengisi hasil hitungan boleh
siapa saja yang bisa masuk, karena itu pekerjaan petugas gudang.

Saat sesi dibuat, seluruh barang aktif yang lolos penyaring kategori
disalin ke `opname_item` beserta **stok menurut sistem pada tanggal
opname**. Angka itu dibekukan dan tidak dihitung ulang saat laporan dibuka:
kalau dihitung ulang, laporan bulan lalu akan berubah sendiri setiap ada
transaksi baru dan tidak bisa lagi dipakai sebagai bukti hitungan. Identitas
barang (SKU, nama, kategori) ikut dibekukan dengan alasan yang sama.

```
selisih barang = stok hitung - stok accurate
```

Selisih tidak pernah disimpan — selalu dihitung saat ditampilkan, sehingga
tidak mungkin basi terhadap kedua angka itu. `stok_hitung`/`stok_accurate`
bernilai `NULL` berarti belum diisi, dan itu berbeda dari `0` yang berarti
barangnya memang habis; mengirim string kosong mengembalikannya ke `NULL`.

Sesi berstatus `selesai` menolak perubahan baris sampai statusnya dibuka
kembali.

**Penyesuaian** mencatat keputusan atas selisihnya — `Tidak Disesuaikan`
atau `Stok Disesuaikan`. Ini catatan, bukan pemicu: memilih
`Stok Disesuaikan` **tidak mengubah stok**. Pembetulan stok tetap lewat
Barang masuk / Barang keluar, supaya setiap pergerakan stok punya satu
jalur yang sama dan terbaca di Riwayat. Kalau opname boleh menggeser stok
sendiri, akan ada dua sumber pergerakan yang tidak bisa direkonsiliasi.

**Petugas** menyimpan nama orang yang menghitung baris itu. Karena satu
sesi bisa memuat ribuan barang yang dihitung orang yang sama,
`api/opname/massal.php` mengisikannya sekaligus untuk seluruh baris yang
sedang tersaring — penyaringnya dibangun di `includes/opname.php` dan
dipakai bersama layar, supaya tombolnya tidak mungkin mengenai baris yang
tidak sedang terlihat. Antarmuka memanggilnya dengan `pratinjau` lebih dulu
dan menyebut jumlah barisnya di dialog konfirmasi sebelum menimpa apa pun.
Field yang tidak dikirim tidak disentuh, jadi mengisi petugas tidak
diam-diam ikut menimpa keputusan penyesuaian.

---

### Pemeriksaan sebelum commit

```
php tools\uji_menu.php
```

Dua pemeriksaan sekaligus:

1. **Menu vs fungsi penggambar.** Setiap id di `TABS` punya cabang di
   `renderContent()`, dan fungsi yang dipanggilnya benar-benar ada. Ada
   karena satu penyuntingan pernah menghapus seluruh blok "Log aktivitas":
   menunya tetap tampil, dan yang terjadi saat diklik hanya `ReferenceError`
   di konsol — judul halaman berganti sementara isi halaman sebelumnya tetap
   terpampang. Tidak ada yang gagal dengan berisik, jadi lolos sampai
   dipakai.
2. **Endpoint vs peta izin.** Setiap berkas di `api/` terdaftar di
   `petaEndpoint()` dan sebaliknya, dan menu yang disebutnya benar-benar
   ada. Endpoint yang lupa didaftarkan akan ditolak 500 saat dipakai.

Keduanya hanya membaca teks: tanpa Node, tanpa browser, tanpa paket
tambahan, tanpa menyentuh database.

---

## 12. Rencana Kerja Bertahap

### Tahap 0 — Siapkan XAMPP *(lokal)*

- [ ] Pasang XAMPP, jalankan Apache + MySQL dari Control Panel
- [ ] Buat folder proyek `C:\xampp\htdocs\web-stock\`
- [ ] Set `date.timezone = Asia/Jakarta` di `C:\xampp\php\php.ini`, restart Apache
- [ ] Naikkan `upload_max_filesize`/`post_max_size` (untuk impor seed), atau
      siapkan impor lewat `mysql` CLI
- [ ] Buat database `web_stock` (utf8mb4) di phpMyAdmin
- [ ] Verifikasi dengan `cek.php` ([Bagian 9.3](#93-lingkungan-pengembangan--xampp)), lalu hapus berkasnya

### Tahap 1 — Fondasi *(blocker)*

- [ ] Jalankan `001_schema.sql` di database lokal
- [ ] Konversi `MASTER_SEED` → `002_seed_master.sql`, selesaikan 356 barcode
      kosong + 3 duplikat ([D1](#82-integritas-data), [D2](#82-integritas-data))
- [ ] `config/database.php` dengan deteksi lokal/produksi + `includes/db.php`
      (PDO, prepared statement, `SET time_zone`)
- [ ] Login berbasis sesi + tabel `users` ([B2](#b2))
- [ ] `.htaccess`: paksa HTTPS *(produksi saja)*, blokir akses `/config`

### Tahap 2 — Pemecahan aset

- [ ] `<style>` → `assets/css/app.css` (salin apa adanya)
- [ ] Baris 498–699 → `assets/js/pdf-parser.js` (salin apa adanya, jangan diubah)
- [ ] Sisa JS → `assets/js/app.js`
- [ ] Unduh `pdf.min.js` + `pdf.worker.min.js` ke `assets/vendor/` ([S4](#84-keamanan))
- [ ] `index.php` merender kerangka + menyuntikkan token CSRF

### Tahap 3 — API inti

- [ ] `api/master/*` — list, save, delete
- [ ] `api/masuk/*`, `api/keluar/*`
- [ ] `api/dashboard/stats.php` dengan query agregat [Bagian 10.1](#101-query-stok-akhir-pengganti-computestats)
- [ ] `assets/js/api.js` menggantikan `loadKey`/`saveKey` ([B1](#b1))
- [ ] Ubah `render*()` jadi `async`, pertahankan pola render parsial ([F7](#83-fungsional--ux))

### Tahap 4 — Impor PDF

- [ ] Hash SHA-256 di klien dari `ArrayBuffer` yang sudah ada
- [ ] `api/import/check.php` — deteksi duplikat ([D5](#82-integritas-data))
- [ ] `api/import/commit.php` — transaksional
- [ ] Kolom tanggal yang bisa diubah di panel review, isi awal dari `tanggalCetak` ([D6](#82-integritas-data))
- [ ] **Uji regresi dengan PDF picking list asli** — bandingkan hasil parse
      lama vs baru baris demi baris; parser tidak boleh berubah perilaku

### Tahap 5 — Perbaikan temuan audit

- [ ] Paginasi riwayat transaksi, hapus `slice(0,200)` ([F1](#83-fungsional--ux))
- [ ] Dialog konfirmasi hapus + soft delete ([F2](#83-fungsional--ux))
- [ ] Validasi stok negatif ([D3](#82-integritas-data))
- [ ] Opsi `— Tanpa kategori —` di dropdown master ([D7](#82-integritas-data))
- [ ] Reset dropdown keterangan setelah simpan ([F5](#83-fungsional--ux))
- [ ] Perlakuan `stokMinimal = 0` agar spanduk kritis tidak selalu menyala ([D4](#82-integritas-data))
- [ ] `activity_log` terisi di semua operasi tulis ([F4](#83-fungsional--ux))

### Tahap 6 — Nilai tambah

- [x] Ekspor PDF ([F3](#83-fungsional--ux))
- [ ] Filter rentang tanggal di riwayat masuk/keluar
- [ ] Laporan mutasi stok per periode
- [ ] Impor master massal via CSV
- [ ] Manajemen pengguna (khusus role admin)
- [ ] Cetak daftar barang yang perlu order

### Tahap 7 — Naik ke Hostinger

- [ ] Buat database + user di hPanel, catat nama berawalan `uXXXXXX_`
- [ ] Isi cabang produksi di `config/database.php`
- [ ] Ekspor database lokal: phpMyAdmin → Export → SQL (struktur + data)
- [ ] Impor ke database Hostinger; bila berkas terlalu besar, pecah per tabel
- [ ] Unggah seluruh isi folder ke `public_html/` (File Manager atau FTP)
- [ ] Aktifkan SSL, uji redirect HTTPS
- [ ] `display_errors = Off` / `APP_DEBUG = false` di produksi
- [ ] **Periksa penamaan huruf besar/kecil semua berkas** — Linux peka, Windows
      tidak; ini penyebab `404` paling umum saat pindah dari XAMPP
- [ ] Ganti password akun default; pastikan tidak ada kredensial `root`/kosong
      yang terbawa dari konfigurasi lokal
- [ ] Uji ulang alur impor PDF dengan picking list asli di lingkungan produksi

---

## 13. Glosarium

| Istilah | Arti |
|---|---|
| **SKU** | *Stock Keeping Unit* — kode identifikasi internal barang |
| **Barcode** | Kode batang; di aplikasi ini menjadi kunci relasi antar tabel |
| **Picking list** | Daftar barang yang harus diambil dari rak untuk memenuhi pesanan; dicetak dari sistem pesanan sebagai PDF |
| **No Pick** | Nomor identitas picking list, berpola `PICK-…` |
| **Stok awal** | Saldo pembuka sebelum transaksi apa pun tercatat di sistem |
| **Stok minimal** | Ambang batas; di bawah ini barang perlu diorder |
| **Stok akhir** | `stok awal + total masuk − total keluar` |
| **Kritis / Perlu order** | Stok akhir ≤ stok minimal |
| **Menipis** | Stok akhir ≤ stok minimal × 1,3 |
| **Aman** | Di atas zona penyangga |
| **Pesanan MP** | Keterangan default barang keluar — pesanan dari *marketplace* |
| **Retur** | Barang dikembalikan (masuk: dari pembeli; keluar: ke pemasok) |
| **Batch impor** | Satu sesi impor PDF; seluruh barisnya berbagi satu `batch_id` |
| **Soft delete** | Data ditandai terhapus (`deleted_at`) tanpa dibuang dari database |
| **XAMPP** | Paket server lokal (Apache + MariaDB + PHP + phpMyAdmin) untuk pengembangan di komputer sendiri |
| **htdocs** | Folder akar web XAMPP, `C:\xampp\htdocs\` — setara `public_html/` di Hostinger |
| **MariaDB** | Turunan MySQL yang dikirim XAMPP; kompatibel, dengan beberapa beda perilaku ([Bagian 9.3](#93-lingkungan-pengembangan--xampp)) |
| **phpMyAdmin** | Antarmuka web pengelola database — `localhost/phpmyadmin` di XAMPP, tersedia juga di hPanel |

---

## 14. Status Implementasi

Versi PHP sudah dibangun mengikuti fungsi dan tampilan prototipe. Bagian ini
mencatat apa yang sudah jadi, cara menjalankannya, dan apa yang masih terbuka.

### 14.1 Cara menjalankan

**Prasyarat:** XAMPP dengan Apache + MySQL menyala.

```
1. Salin/link folder proyek ke htdocs:
     mklink /D C:\xampp\htdocs\web-stock C:\web-stock      (CMD as Administrator)
   atau salin isinya secara manual.

2. Database sudah dibuat & terisi. Bila perlu mengulang dari nol:
     C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE web_stock CHARACTER SET utf8mb4"
     C:\xampp\mysql\bin\mysql.exe -u root web_stock < sql\001_schema.sql
     C:\xampp\mysql\bin\mysql.exe -u root web_stock < sql\002_seed_master.sql

3. Buka http://localhost/web-stock/
     Login: admin / admin123      <-- ganti sebelum dipakai sungguhan
```

**Uji mandiri:**

```
C:\xampp\php\php.exe tools\uji_fondasi.php      38 pemeriksaan fondasi
C:\xampp\php\php.exe tools\buat_pdf_contoh.php  buat PDF picking list sintetis
node tools\uji_parser.mjs                       22 asersi parser (butuh: npm i pdfjs-dist@3.11.174)
```

### 14.2 Yang sudah selesai

| Tahap | Isi | Status |
|---|---|---|
| 0 | Setup XAMPP, database `web_stock` | ✅ terisi 1.404 item |
| 1 | Skema SQL, konversi seed, config, auth, CSRF | ✅ 38/38 uji lulus |
| 2 | Pecah aset: CSS, app.js, api.js, parser, vendor pdf.js | ✅ CDN dilepas |
| 3 | Endpoint API: master, masuk, keluar, dashboard | ✅ diuji lewat HTTP |
| 4 | Impor PDF: check + commit transaksional | ✅ rollback terverifikasi |
| 5 | Perbaikan temuan audit | ✅ lihat tabel di bawah |
| 6 | Ekspor CSV | ✅ BOM UTF-8, pemisah titik koma |
| 7 | Naik ke Hostinger | ⬜ belum |

### 14.3 Temuan audit yang sudah ditutup

| Kode | Temuan | Perbaikan |
|---|---|---|
| B1 | `window.storage` bukan API browser | Diganti PHP + MySQL lewat `assets/js/api.js` |
| B2 | Tanpa autentikasi | Login sesi, `password_hash`, CSRF, `session_regenerate_id` |
| B3 | Tulis ulang seluruh array | `INSERT`/`UPDATE` per baris |
| D1 | Barcode duplikat | `UNIQUE KEY` + kolom relasi `master_id` |
| D2 | 356 barcode kosong | Digenerate `INT-<sku>`, ditandai `barcode_asli = 0` |
| D3 | Stok bisa minus | Divalidasi server, bisa diatur lewat `IZINKAN_STOK_MINUS` |
| D4 | 1.404 item kritis sejak awal | Status keempat `belum_diatur` untuk `stok_minimal = 0` |
| D5 | Impor PDF ganda | Hash SHA-256 + `no_picking`, wajib konfirmasi ulang |
| D6 | Tanggal selalu hari ini | Diisi awal dari tanggal cetak PDF, bisa diubah admin |
| D7 | Kategori dipaksa terisi | Opsi `— Tanpa kategori —` bernilai kosong |
| F1 | Riwayat terpotong 200 baris | Paginasi server + filter rentang tanggal |
| F2 | Hapus tanpa konfirmasi | Dialog konfirmasi + soft delete `deleted_at` |
| F3 | Tidak ada ekspor | `api/export/pdf.php` untuk 5 jenis laporan |
| F4 | Tidak ada jejak audit | Tabel `activity_log` terisi di semua operasi tulis |
| F5 | Dropdown keterangan tidak reset | Direset setelah simpan |
| F6 | `computeStats()` dua kali | Agregasi pindah ke SQL `GROUP BY` |
| S2 | Rakit HTML lewat string | `htmlspecialchars()` di server, `esc()` dipertahankan di klien |
| S4 | CDN tanpa SRI | pdf.js di-host sendiri di `assets/vendor/` |

### 14.4 Keterbatasan diketahui

**Parser `dicetakOleh` rapuh.** Regex bawaan prototipe:

```js
dicetakOleh: get(/Dicetak Oleh:?\s*([A-Za-z0-9 _-]+?)(?:\s+Picking|$)/i)
```

Perilakunya yang terukur:

| Teks setelah nama | Hasil |
|---|---|
| `Picking List` | ✅ `"admin_gudang"` |
| (akhir teks) | ✅ `"admin_gudang"` |
| `Halaman 1` | ⚠️ ikut tertelan: `"admin_gudang Halaman 1"` |
| `Jumlah Pesanan: 3` | ❌ kosong — titik dua di luar character class |

Parser **sengaja tidak diubah**: layout PDF asli belum pernah diuji, dan
alternatif `\s+Picking` menunjukkan regex ini ditulis untuk layout tertentu
yang kemungkinan besar cocok di sana. Dampaknya hanya metadata tampilan
(`import_batch.dicetak_oleh` dan panel review) — **tidak mempengaruhi
perhitungan stok sama sekali**. Verifikasi dengan PDF asli sebelum memutuskan
mengubahnya.

**Uji parser memakai PDF sintetis.** `tools/buat_pdf_contoh.php` menghasilkan
picking list tiruan berdasarkan tebakan atas apa yang dicari parser. Semua
22 asersi lulus — termasuk penggabungan nama terbungkus dua baris dan
pengabaian footer halaman — tapi itu membuktikan alurnya berjalan, bukan
bahwa layout PDF asli sudah pasti cocok.

### 14.5 Impor data nyata dari KARTU STOK

Master barang sudah diisi data operasional sungguhan dari
`KARTU STOK AGUSTUS 2026 (1).xlsx`, sheet **DAFTAR REKAP BARANG**
(header baris 4, data mulai baris 6, 1.404 baris).

```
C:\xampp\php\php.exe tools\audit_kartu_stok.php        periksa dulu, tanpa menulis
C:\xampp\php\php.exe tools\impor_kartu_stok.php        simulasi
C:\xampp\php\php.exe tools\impor_kartu_stok.php --tulis  simpan
```

XLSX dibaca tanpa pustaka luar — `tools/baca_xlsx.php` membongkar ZIP-nya dan
membaca `sharedStrings.xml` + `sheet1.xml` langsung.

#### Kolom yang dipakai

| Kolom | Isi | Dipakai |
|---|---|---|
| A | SKU | ✅ |
| B | KODE BARCODE | ✅ |
| C | NAMA BARANG | ✅ |
| D | STOK AWAL | ✅ |
| E | BARANG MASUK | ❌ **seluruh 1.404 baris `#REF!`** |
| F | BARANG KELUAR | ❌ **`#REF!`** |
| G | STOK AKHIR | ❌ **`#REF!`** |
| H | STOK MINIMAL | ✅ |
| J | KATEGORI | ✅ |

Kolom E/F/G rumusnya rusak di berkas sumber. Tidak masalah: ketiganya nilai
turunan, dan aplikasi menghitungnya sendiri dari tabel transaksi.

#### Cara pencocokan

Berurutan, dan baris master yang sudah diklaim baris Excel lain tidak
dipertimbangkan lagi — supaya dua produk bernama sama tidak berebut baris yang
sama:

| Jalur | Cocok |
|---|---|
| 1. barcode persis | 1.045 |
| 2. SKU persis | 350 |
| 3. nama persis | 9 |
| **Total** | **1.404 / 1.404** |

Nol baris Excel gagal dicocokkan, nol baris master tanpa pasangan.

#### Keputusan atas data bermasalah

| Masalah | Jumlah | Perlakuan |
|---|---|---|
| Stok minimal **pecahan** | 180 | **Dibulatkan ke atas** (`ceil`). Kolom DB bertipe `INT`; ambang yang dibulatkan ke bawah membuat peringatan order terlambat menyala |
| Stok minimal kosong/`#REF!` | 1.010 | → 0, berarti status `belum diatur` |
| Stok awal kosong | 2 | → 0 (`GYMBALL 55CM KUNING`, `GYMBALL 65CM KUNING`) |
| Stok awal **negatif** (−1) | 1 | → 0 (`GYMBALL 65CM PUTIH`) — stok pembuka tidak mungkin minus |
| Barcode kembar | 3 | Akhiran `-D2` **dipertahankan**, tidak dikembalikan ke barcode asli |

Soal barcode kembar: berkas sumber memberi barcode yang sama kepada dua produk
yang berbeda —

```
12132848  AYRES GLOVE EXERION FINGER SAFE RED BLACK 9  ← memegang barcode asli
12132848  AYRES GLOVE EXERION RED BLACK 10             ← jadi 12132848-D2
12132897  FOX KAOS KAKI BLAST MERAH / STANDAR MERAH
12132898  FOX KAOS KAKI BLAST PUTIH / STANDAR PUTIH
```

Mengembalikan barcode aslinya bukan cuma menabrak `UNIQUE`, tapi akan
**menggabungkan dua produk berbeda menjadi satu** dalam perhitungan stok.
Importir punya pemeriksaan tabrakan yang membatalkan seluruh impor bila ini
terjadi. Akhiran `-D2` bertahan sampai gudang memutuskan barcode mana yang
benar; barisnya bertanda `barcode_asli = 0` dan tampil dengan label
`SEMENTARA`.

#### Kategori diperbaiki

Daftar kategori prototipe hanya cocok 3 dari 10 kategori yang benar-benar
dipakai. `KATEGORI_OPTIONS` di `config/config.php` diganti dengan yang nyata:

| Kategori | Item | Unit | Merah |
|---|---|---|---|
| AYRES | 687 | 22.353 | 40 |
| SAIFENU | 181 | 814 | 0 |
| FASHION | 176 | 16.880 | 31 |
| AVO | 130 | 8.578 | 12 |
| JERSEY | 89 | 88 | 0 |
| ACC | 51 | 323 | 7 |
| FISIO | 33 | 28.335 | 8 |
| GYM | 23 | 8 | 0 |
| AOLIKES | 18 | 1.436 | 9 |
| TRAINING | 15 | 239 | 3 |

`FOX` dan `AC` dibuang — tidak pernah dipakai sebagai kategori di data nyata
(produk FOX masuk `FASHION`/`JERSEY`), dan `AC` sebenarnya tertulis `ACC`.

Daftar ini kini **hanya ada di satu tempat**: `config/config.php`.
`index.php` menyuntikkannya ke `window.KATEGORI_OPTIONS`, jadi `app.js` tidak
lagi menyalinnya — dulu daftarnya kembar di dua berkas dan bisa menyimpang.

#### Hasil

```
1.404 baris master diperbarui
79.123 unit stok awal
  350 item punya ambang stok minimal
   10 kategori
```

| Status | Jumlah | Warna |
|---|---|---|
| **Perlu order** (`stok_akhir <= stok_minimal`) | **110** | 🔴 merah |
| Menipis (≤ minimal × 1,3) | 11 | 🟠 amber |
| Aman | 229 | 🟢 hijau |
| Belum diatur (`stok_minimal = 0`) | 1.054 | ⚪ abu |

Aturan merahnya ada di `sqlStatusStok()` (`includes/helpers.php`) dan dipakai
bersama oleh dashboard maupun ekspor CSV, jadi ambangnya tidak mungkin
menyimpang antar tampilan. Badge memakai `.badge.kritis` (`#B23A2E`) dan bar
gauge-nya ikut merah.

**Verifikasi:** 1.046 dari 1.048 baris berbarcode dibanding sel demi sel
dengan Excel — cocok. Dua sisanya adalah pasangan barcode kembar di atas,
yang setelah diperiksa manual juga sudah benar (dicocokkan lewat nama).
Akurasi impor **1.404/1.404**.

### 14.6 Panduan deploy ke Hostinger

Ikuti berurutan. Langkah 3 dan 6 adalah yang paling sering menggagalkan.

#### 1. Siapkan database di hPanel

```
hPanel → Databases → MySQL Databases
  Buat database         → catat namanya (berawalan uXXXXXX_)
  Buat user + password  → catat keduanya
  Tambahkan user ke database, beri ALL PRIVILEGES
```

#### 2. Impor struktur dan data

phpMyAdmin dari hPanel → pilih database → tab **Import**:

```
1. sql/001_schema.sql             7 tabel + akun admin awal
2. sql/002_seed_master.sql        1.404 barang, 79.123 unit, 350 ambang (108 KB)
3. sql/003_kategori_pengguna.sql  tabel kategori + 11 kategori awal
4. sql/004_pertukaran.sql         tabel riwayat pertukaran produk
5. sql/005_indeks_aktivitas.sql   indeks waktu untuk halaman Log aktivitas
6. sql/006_retur.sql              tabel retur barang
7. sql/007_opname.sql             tabel sesi + baris stok opname
8. sql/008_opname_penyesuaian.sql kolom penyesuaian + petugas
9. sql/009_akses_pengguna.sql     peran viewer + kolom akses menu
10. sql/010_keterangan.sql        daftar pilihan keterangan transaksi
```

**Sejak versi ini migrasi berjalan otomatis.** Berkas di `sql/` diterapkan
sendiri saat halaman pertama dibuka, dan yang sudah pernah dijalankan
dicatat di tabel `migrasi` sehingga tidak terulang. Impor manual di atas
hanya diperlukan untuk memasang database dari nol dengan cepat.

Migrasi lama bersifat merusak bila diulang — 001 diawali `DROP TABLE`, 002
diawali `DELETE FROM master_barang`. Karena itu tiap berkas menyatakan
syarat lewatnya sendiri:

```
-- @lewati-jika-tabel: master_barang     lewati bila tabel itu sudah ada
-- @lewati-jika-terisi: master_barang    lewati bila tabel itu sudah berisi
-- @lewati-jika-indeks: activity_log.idx_waktu   lewati bila indeks sudah ada
-- @lewati-jika-kolom: opname_item.penyesuaian   lewati bila kolom sudah ada
```

Dua penjaga terakhir ada karena `ADD INDEX` dan `ADD COLUMN` tidak punya
bentuk `IF NOT EXISTS` yang berlaku di MySQL maupun MariaDB sekaligus;
keberadaannya diperiksa dari PHP lewat `information_schema`.

Database yang sudah berisi data otomatis ter-baseline: berkas lama dicatat
sebagai dilewati tanpa dijalankan, dan hanya migrasi baru yang benar-benar
dieksekusi.

Urutannya wajib. `003` mengambil kategori yang benar-benar dipakai dari
`master_barang`, jadi harus dijalankan setelah `002`.

`002_seed_master.sql` sudah berisi **data nyata dari KARTU STOK** — bukan
seed nol dari prototipe. Berkas itu diawali `DELETE FROM master_barang`,
jadi aman diimpor ulang. Regenerasi kapan pun dengan:

```
php tools\ekspor_master.php
```

#### 3. Isi kredensial produksi ⚠️

`config/database.php` di repositori berisi **placeholder**, bukan
kredensial asli. Ubah cabang produksinya **di server**, bukan di repo:

```php
} else {
    // ---- Hostinger (produksi) ----
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'uXXXXXX_web_stock');      // dari langkah 1
    define('DB_USER', 'uXXXXXX_gudang');
    define('DB_PASS', 'password-asli-anda');
    define('APP_DEBUG', false);
    define('APP_ENV', 'produksi');
}
```

Repositori ini publik. **Jangan pernah meng-commit kredensial asli.**
Deteksi lingkungan berjalan otomatis lewat `HTTP_HOST`, jadi cabang lokal
tetap dipakai saat kamu bekerja di XAMPP.

#### 4. Unggah berkas

File Manager hPanel atau FTP, ke `public_html/`. Yang perlu diunggah:

```
index.php  login.php  logout.php  .htaccess
api/  assets/  config/  includes/
```

`sql/` dan `tools/` boleh ikut (`.htaccess` sudah memblokir aksesnya lewat
web), atau tidak diunggah sama sekali setelah langkah 2 selesai.

**Jangan unggah** berkas `.xlsx` dan prototipe `.html` — keduanya sudah
di-*gitignore* dan tidak diperlukan aplikasi.

#### 5. Aktifkan HTTPS

```
hPanel → Security → SSL → pasang sertifikat gratis
```

`.htaccess` sudah memaksa redirect ke HTTPS, dan sengaja melewati
`localhost` supaya pengembangan tidak terganggu. Cookie sesi otomatis
menjadi `secure` begitu situs berjalan di HTTPS.

#### 6. Ganti password admin ⚠️

Skema mengirim akun `admin` / `admin123`. **Ganti sebelum dipakai.**

```
php tools\buat_password.php "PasswordBaruAnda"
```

Perintah itu mencetak `UPDATE users SET password_hash = ...` yang tinggal
dijalankan di phpMyAdmin. Passwordnya sendiri tidak pernah tersimpan di
berkas mana pun — hanya hashnya.

Alat yang sama juga mencetak `INSERT` untuk menambah pengguna baru, karena
antarmuka manajemen pengguna belum dibuat.

#### 7. Periksa setelah naik

| Periksa | Harapan |
|---|---|
| `https://domain/login.php` | halaman masuk tampil |
| Login | masuk ke dashboard, 1.404 SKU, 110 merah |
| `https://domain/config/database.php` | **403 / 404** — bukan isi berkas |
| `https://domain/sql/001_schema.sql` | **403 / 404** |
| Lencana di kaki halaman masuk | tertulis `produksi`, bukan `lokal` |
| PHP version di hPanel | **8.1+** |

Bila `config/database.php` bisa dibuka isinya, `.htaccess` tidak terbaca —
periksa apakah berkasnya benar-benar terunggah (namanya diawali titik, dan
sebagian klien FTP menyembunyikannya).

#### Beda lingkungan yang perlu diingat

| Hal | XAMPP (Windows) | Hostinger (Linux) |
|---|---|---|
| Nama berkas | tidak peka huruf besar/kecil | **peka** — `Api/List.php` ≠ `api/list.php` |
| Zona waktu | ikut Windows | sering UTC — sudah dipaksa `Asia/Jakarta` di `config.php` |
| `CHECK` constraint | MariaDB menegakkan | MySQL 5.7 **mengabaikan diam-diam** |

Poin ketiga sudah diantisipasi: seluruh aturan (jumlah > 0, stok tidak
minus, barcode wajib) divalidasi di PHP, dan constraint database hanya jaring
pengaman kedua.

### 14.7 Menu Master: kategori dan pengguna

Sidebar kini terbagi dua kelompok:

```
OPERASIONAL          MASTER
  Dashboard stok       Barang       katalog, barcode, ambang stok
  Barang masuk         Kategori     daftar kategori barang
  Barang keluar        Pengguna     akun yang bisa masuk   (admin saja)
```

#### Kategori

Daftar kategori pindah dari konstanta PHP ke tabel `kategori`, sehingga bisa
dikelola lewat aplikasi tanpa menyentuh kode. `KATEGORI_OPTIONS` di
`config/config.php` tinggal jadi cadangan bila tabelnya belum ada.

`master_barang.kategori` **tetap disimpan sebagai teks**, bukan diubah jadi
foreign key: 1.404 baris sudah terisi, dan mengubah relasinya berisiko tanpa
manfaat nyata di skala ini. Konsekuensinya ditangani eksplisit:

| Aksi | Perlakuan |
|---|---|
| **Ganti nama** | Seluruh barang yang memakainya ikut diperbarui dalam satu transaksi. Tanpa ini, barangnya menunjuk nama yang sudah tidak ada dan hilang dari penyaringan |
| **Hapus, belum dipakai** | Langsung dihapus setelah konfirmasi |
| **Hapus, masih dipakai** | **Ditolak** disertai jumlah pemakainya. Muncul dialog untuk memilih kategori tujuan; barangnya dipindahkan dulu, baru kategorinya dihapus |

Endpoint: `api/kategori/{list,save,delete}.php`

#### Pengguna

Antarmuka pengelolaan akun, menggantikan cara lama lewat phpMyAdmin.
**Hanya admin** yang bisa membukanya — menunya pun tidak muncul untuk
operator.

Penjaga yang semuanya ditegakkan di server, bukan sekadar disembunyikan di
antarmuka:

- Password disimpan sebagai hash `password_hash()`, dan **hash tidak pernah
  ikut terkirim ke klien**
- Username unik, 3–50 karakter, hanya huruf kecil/angka/`.`/`-`/`_`
- Password minimal 8 karakter
- **Admin aktif terakhir tidak bisa** dihapus, dinonaktifkan, atau
  diturunkan perannya — kalau bisa, tidak ada lagi yang mampu mengelola
  aplikasi
- Tidak bisa menghapus, menonaktifkan, atau menurunkan **akun sendiri**
- Saat mengubah akun, password kosong berarti "jangan ganti"

Akun dihapus permanen, bukan *soft delete*: menyimpan akun mati hanya
memperbesar peluang salah pakai. Untuk menutup akses sementara, pakai status
**Nonaktif**. Riwayat transaksi yang pernah dibuatnya tetap utuh — kolom
`user_id` memakai `ON DELETE SET NULL`.

Endpoint: `api/pengguna/{list,save,delete}.php`

> **Catatan:** `wajibAdminApi()` sudah ada di `includes/auth.php` sejak awal
> tapi belum pernah dipakai — sebelum ini, operator secara teknis bisa
> menghapus master barang. Endpoint kategori dan pengguna adalah yang
> pertama menegakkannya. Membatasi endpoint operasional lain (mis. hapus
> master barang) masih terbuka.

### 14.8 Popup riwayat per barang

Angka pada kolom **MASUK** dan **KELUAR** di dashboard bisa diklik. Sekali
klik membuka popup berisi seluruh riwayat transaksi barang itu — tanpa perlu
pindah tab lalu mencari barangnya satu per satu.

**Isi popup:**

- nama barang, SKU, dan barcode
- total unit, jumlah catatan, stok awal, kategori
- rincian per keterangan (mis. `Barang Baru 300 pcs (1x)`, `Restock 150 pcs (1x)`)
- tabel: tanggal, jumlah, keterangan, dan siapa yang mencatat
- khusus barang keluar: **No. Pesanan** dan **asal** — `Input manual` atau
  `Impor PDF · PICK-20260812-009`
- paginasi 25 baris per halaman bila riwayatnya panjang

Endpoint: `GET api/master/riwayat.php?master_id=&jenis=masuk|keluar&page=`

**Catatan implementasi.** Nama barang dikirim lewat atribut `data-nama`,
bukan diinterpolasi ke dalam `onclick="...('nama')"`. Sepuluh produk memuat
karakter `&` di namanya, dan `esc()` yang meng-escape HTML **tidak** aman
untuk konteks string JavaScript di dalam atribut. Listener-nya didelegasikan
dari `document` supaya tetap bekerja setelah tabel dirender ulang oleh filter
atau paginasi.

### 14.9 Masih terbuka

- **PDF picking list asli** untuk uji regresi parser — satu-satunya hal yang
  belum bisa diverifikasi
- **1.054 item tanpa ambang stok minimal** — berkas KARTU STOK hanya mengisi
  350 dari 1.404. Selama masih 0, statusnya `belum diatur` dan peringatan
  order tidak akan menyala untuk item itu
- **359 barcode `barcode_asli = 0`** perlu dilengkapi barcode sungguhan lewat
  menu Master barang (tampil dengan penanda `SEMENTARA`). Tiga di antaranya
  adalah barcode kembar yang perlu diputuskan gudang — lihat
  [Bagian 14.5](#145-impor-data-nyata-dari-kartu-stok)
- **Verifikasi stok awal ke fisik gudang** — 79.123 unit sudah masuk dari
  KARTU STOK, tapi angkanya per Agustus 2026 dan belum dicocokkan ulang
  dengan hitungan fisik
- **Manajemen pengguna** — skema sudah punya role `admin`/`operator`, tapi
  antarmuka pengelolaannya belum dibuat; user tambahan untuk sementara
  ditambahkan lewat phpMyAdmin
- **Deploy ke Hostinger** (Tahap 7)

---

## Lampiran — Berkas Proyek

```
C:\web-stock\
├── aplikasi-gudang (2).html    Prototipe — sumber kebenaran fungsional (tidak di-commit)
├── README.md                   Dokumen ini
├── index.php                   Halaman utama
├── login.php / logout.php      Autentikasi
├── .htaccess                   HTTPS, blokir folder sensitif, header keamanan
├── config/
│   ├── database.php            Kredensial, deteksi lokal/produksi otomatis
│   └── config.php              Konstanta aplikasi
├── includes/
│   ├── db.php                  PDO + helper query
│   ├── auth.php                Sesi, login, CSRF, jejak audit
│   ├── helpers.php             Validasi, sanitasi, logika stok bersama
│   ├── response.php            Keluaran JSON seragam
│   └── transaksi.php           Logika bersama barang masuk/keluar
├── api/
│   ├── dashboard/stats.php     Agregasi stok via SQL
│   ├── master/                 list, save, delete, cek_barcode
│   ├── masuk/  keluar/         list, create, delete
│   ├── import/                 check (deteksi ganda), commit (transaksional)
│   ├── export/pdf.php          Ekspor 5 jenis laporan
│   └── riwayat/list.php        Riwayat gabungan masuk + keluar
├── assets/
│   ├── css/app.css             CSS prototipe + tambahan versi PHP
│   ├── js/pdf-parser.js        Parser PDF — SALINAN UTUH prototipe
│   ├── js/api.js               Pembungkus fetch, pengganti loadKey/saveKey
│   ├── js/app.js               State, router, seluruh render*()
│   └── vendor/                 pdf.min.js + worker (host sendiri, lepas CDN)
├── sql/
│   ├── 001_schema.sql          7 tabel
│   └── 002_seed_master.sql     1.404 item hasil konversi
└── tools/
    ├── convert_seed.php        Konversi MASTER_SEED -> SQL
    ├── buat_pdf_contoh.php     Buat PDF picking list sintetis
    ├── uji_fondasi.php         38 pemeriksaan fondasi
    └── uji_parser.mjs          22 asersi parser (Node + pdfjs-dist)
```

Prototipe **jangan dihapus** selama migrasi. Ia adalah spesifikasi yang bisa
dijalankan: setiap perilaku yang tidak tercatat di dokumen ini masih bisa
diverifikasi langsung dari sana, terutama untuk penyetelan halus parser PDF.
