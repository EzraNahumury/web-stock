# Catatan Revisi — WAREHOUSE AVA

Ringkasan perubahan yang sudah masuk ke server, ditulis untuk dibaca sebelum
atau sesudah deploy. Yang paling baru ada di atas.

Dokumentasi teknis lengkapnya tetap di [README.md](README.md); berkas ini
hanya menjawab tiga hal untuk tiap revisi: **apa yang bermasalah**, **apa yang
berubah**, dan **bagaimana memastikannya jalan**.

---

## 25 Oktober 2026 — laporan stok opname: PDF, HP, dan isi massal

Lima hal, semuanya di Laporan stok opname.

### 1. Nama barang di PDF tidak lagi terpotong

Laporan yang dipakai SAMBIL menghitung barang di rak memuat belasan baris
`AYRES CELANA RUNNING DOUBLE LAYE...` yang tidak bisa dibedakan satu sama
lain — tepat pada laporan yang paling butuh dibedakan.

Ini bukan kasus pinggiran: **908 dari 1.435 nama** di katalog ini lebih dari 28
karakter, dan yang terpanjang 93. Melebarkan kolomnya tidak akan pernah cukup,
jadi `PdfTabel` sekarang bisa **membungkus** teks ke beberapa baris:

```
sebelum   AYRES Jersey Bola Negara Argentina Dewasa Ka...
sesudah   AYRES Jersey Bola Negara Argentina Dewasa Kaos
          Futsal Sepakbola Dry Fit Premium Grade Ori L
```

Dipenggal di spasi; kata yang sendirian saja tidak muat dipenggal per karakter;
paling banyak tiga baris supaya satu nama aneh tidak membuat satu baris tabel
setinggi setengah halaman. Tinggi barisnya mengikuti isinya, dan halaman
diputus berdasarkan tinggi yang sebenarnya — bukan 15 poin yang ditebak.

Berlaku untuk **seluruh laporan**, bukan hanya opname: katalognya sama, jadi
masalahnya sama. Laporan opname penuh 1.435 baris jadi 49 halaman (sebelumnya
sekitar 39) — sepuluh halaman untuk nama yang terbaca, dan **nol nama tersisa
terpotong**.

### 2. Bisa diisi dari HP

Tabelnya 11 kolom dengan `min-width:1320px`. Di HP itu berarti menggeser-geser
ke samping untuk mengisi satu baris, dan petugas akhirnya meminta "situs versi
desktop" lalu mencubit layar sepanjang hari opname — padahal justru di gudang
HP yang dipegang.

Di bawah **820px** tiap baris jadi **satu kartu**: kepala tabelnya
disembunyikan dan tiap sel memakai labelnya sendiri, nama barang jadi judul
kartunya, dan kolom isiannya dibesarkan supaya nyaman untuk jempol. Kolom angka
juga memunculkan papan angka HP (`inputmode="numeric"`).

Tidak ada cabang JavaScript: HTML-nya sama persis, hanya tampilannya yang
berbeda — jadi tidak ada dua jalur yang bisa berbeda perilaku.

### 3. Stok accurate boleh minus

Kolom itu **bukan hitungan rak**, melainkan salinan saldo dari Accurate — dan
saldo pembukuan memang bisa minus, misalnya ketika barang terlanjur dikeluarkan
sebelum penerimaannya dibukukan. `min="0"` dan penolakan di server membuat angka
yang benar-benar tertera di sana tidak bisa diketik, sehingga selisih yang
justru paling perlu dilihat tidak pernah bisa dicatat.

**Stok hitung tetap tidak boleh minus.** Tidak ada rak berisi minus tiga
barang; yang negatif di sana selalu salah ketik, dan membiarkannya masuk berarti
penyesuaiannya ikut menggeser stok ke angka yang mustahil.

### 4. Centang semua "Dicek"

Tombol **Centang semua** menandai Dicek untuk SELURUH baris yang lolos penyaring
sekarang — bukan hanya yang terlihat di halaman ini. Tandanya tidak menyentuh
stok sama sekali. Bisa dilepas lagi dengan cara yang sama.

### 5. Sesuaikan semua

Endpoint massal dulu **menolak** penyesuaian, karena tiap baris menulis koreksi
stok sendiri dan ribuan koreksi dalam satu klik terasa terlalu besar. Gudang
memintanya tetap ada — menyetel ratusan baris satu per satu pada hari opname
memakan waktu yang tidak mereka punya. Jadi sekarang bisa, dengan tiga pagar:

1. **Jumlahnya disebut lebih dulu.** Dialognya menyatakan berapa baris yang
   AKAN disesuaikan dan berapa yang dilewati beserta sebabnya. Menyebut "55
   baris" padahal yang berubah 12 berarti orang menyetujui hal yang berbeda
   dari yang terjadi.
2. **Baris yang belum dihitung atau tidak terhubung ke master DILEWATI**, bukan
   menggagalkan seluruh permintaan. Pada sesi berisi ratusan barang selalu ada
   yang belum dihitung, dan menggagalkan karena itu membuat tombolnya tidak
   pernah bisa dipakai.
3. **Dibatasi 1.000 baris sekali jalan**, dengan pesan yang menyuruh
   mempersempit penyaring — pagar transaksi, karena tiap baris menulis satu
   baris barang masuk/keluar.

Tiap baris tetap lewat `sinkronPenyesuaianStok()` — jalur yang sama persis
dengan menyetelnya satu per satu — bukan UPDATE massal yang hanya mengubah
kolom penyesuaian tanpa menggeser stoknya. Aman diulang: menekannya dua kali
tidak menaikkan stok dua kali.

Tombol **Batal sesuaikan** adalah kebalikannya, dan koreksi stok yang pernah
ditulis ikut ditarik — jadi kalau massalnya keliru, pembatalannya juga massal.

### Catatan uji

`tools/http/uji_opname_hp.cjs` menjaga kelimanya, termasuk membaca kembali isi
PDF-nya untuk memastikan nama 93 karakter benar-benar utuh dan tidak ada satu
pun nama yang berakhir elipsis.

Satu kegagalan sempat muncul dan ternyata salah harness, bukan aplikasi:
memipa suite ke `head` menutup pipe lebih awal dan membunuh prosesnya di tengah
jalan, sehingga pembersihannya tidak pernah berjalan dan jalan berikutnya mulai
dari basis data yang kotor. Peringatannya ditulis di
[tools/http/JALANKAN.md](tools/http/JALANKAN.md).

---

## 24 Oktober 2026 — "sudah masuk stok" yang ternyata belum

### Laporannya

Di halaman Retur, kartu **Sudah masuk stok** menunjukkan **398 pcs** dan tiap
barisnya berlencana hijau *stok bertambah*. Dicek di Barang masuk: tidak ada.
Stoknya memang tidak pernah naik.

### Sebabnya

Kartu dan lencananya menyimpulkan "stok bertambah" **dari nama keterangannya**:

```php
// api/retur/list.php — SEBELUM
SUM(r.jumlah) ... WHERE r.status IN ('Dikirim','Pembatalan','Lengkap', ...)
```

```js
// app.js — SEBELUM
const masukStok = returIkutStok(r.status);
```

Keterangan menyatakan apa yang **seharusnya** terjadi, bukan apa yang **sudah**
terjadi. Urutan kejadiannya:

1. Retur Oktober dicatat ketika keterangannya belum mengembalikan barang ke
   stok → `masuk_id` NULL, tidak ada baris Barang masuk
2. Migrasi `022` menyetel `tambah_stok = 1` untuk semua keterangan — lewat SQL
   mentah, bukan lewat jalur yang menyusulkan barisnya (migrasi itu sendiri
   sudah menuliskan batasan ini)
3. Layar membaca keterangannya yang baru dan berkata "stok bertambah"
4. Barang masuk tidak pernah punya barisnya

Direproduksi lokal persis seperti laporannya: layar bilang 7 pcs masuk stok,
`barang_masuk` kosong, stok 201 → 201.

### Perbaikannya: baca bukti, bukan dugaan

Yang menjawab "sudah bertambah atau belum" sekarang **ada tidaknya baris barang
masuk yang masih hidup**, bukan nama keterangannya. `masuk_id` saja tidak cukup
— ia sengaja tetap terisi meski barisnya dihapus, supaya bisa dipakai ulang —
jadi `deleted_at`-nya ikut diperiksa di server dan dikirim sebagai
`masuk_aktif`.

Angkanya kini terbagi tiga ember yang **saling lepas** dan berjumlah total:

| Kartu | Artinya |
|---|---|
| **Sudah masuk stok** | baris barang masuknya ADA — stok memang sudah naik |
| **Belum tersusul** | keterangannya mengembalikan ke stok, tapi barisnya TIDAK ADA |
| **Belum masuk stok** | keterangannya memang tidak mengembalikan ke stok |

Baris "belum tersusul" digambar **amber** dengan sub-teks *belum masuk stok* —
bukan hijau seolah beres, bukan juga merah seolah sengaja ditahan.

### Kotak peringatan yang membawa tombolnya

Angka yang berubah saja tidak cukup; orang perlu tahu apa yang harus
dilakukan. Jadi di atas tabel muncul kotak yang menyebut jumlahnya,
menerangkan sebabnya, dan — untuk admin — memuat tombol **Susulkan sekarang**
yang memanggil jalur yang sama dengan "Masukkan semua ke stok". Aman diulang:
yang sudah benar tidak disentuh.

Kebalikannya juga terdeteksi: baris barang masuknya ada padahal keterangannya
berkata tidak boleh (`baris_kelebihan`). Tombol yang sama menariknya kembali.

### `returIkutStok()` dibuang, bukan diperbaiki

Fungsi itulah perangkapnya. Selama ia ada, orang berikutnya akan memakainya
lagi untuk pertanyaan yang salah. Tempatnya diisi komentar yang menerangkan
kenapa ia tidak ada.

### Empat query jadi satu

Ringkasannya dulu empat query terpisah yang menyaring tabel yang sama
berulang-ulang. Sekarang satu. Bukan sekadar lebih hemat: angka yang dihitung
terpisah bisa saling bertentangan kalau salah satu syaratnya tidak persis sama
— dan itu persis yang terjadi pada kasus ini.

`tambah_stok` dibaca lewat JOIN ke tabel `keterangan`, bukan dengan menyusun
daftar nama lalu `IN (?)`. Perbandingannya jadi kolom-lawan-kolom dengan
collation yang sama, bukan kolom-lawan-parameter yang COERCIBLE — bentuk yang
dulu menjatuhkan Dashboard dengan galat 1267. Sudah ikut diuji pada server
bercollation berbeda.

### Uji HTTP pindah ke repo

Hari ini folder sementara dibersihkan sistem dan **sebelas suite uji HTTP
hilang sekaligus** — termasuk yang menjaga pencatatan retur, pencabutan centang
keterangan, dan penghapusan catatan pertukaran. Yang hilang tidak bisa
dikembalikan.

Lima yang tersisa dipindah ke **`tools/http/`** beserta
[JALANKAN.md](tools/http/JALANKAN.md). Cakupan paling relevan dari yang hilang
— mencentang "menambah stok" lewat Master menyusulkan retur lama tanpa tombol
apa pun — ditulis ulang di `uji_tersusul.cjs`.

Uji yang hanya hidup di folder sementara bukan uji; ia hanya kebetulan pernah
lulus.

### Yang perlu dilakukan di server

Sesudah deploy, buka **Retur**, hapus penyaring tanggalnya, dan lihat kotak
peringatannya. Kalau ada, tekan **Susulkan sekarang** sebagai admin. Itu yang
benar-benar memasukkan 398 pcs itu ke Barang masuk.

---

## 22 Oktober 2026 (sore) — menu Barang deadstok

Menu baru di grup **Operasional**, di antara Retur dan Laporan stok opname.
Isinya barang yang **ada di gudang tapi tidak bisa dijual**, dengan dua
kategori yang diminta gudang:

- **Barang Rusak**
- **Barang Belum Siap Jual** (belum dipacking, belum dilabeli, dan sejenisnya)

### Mencatat deadstok TIDAK mengurangi stok

Ini keputusan rancangan yang paling penting di sini, jadi disebut di layarnya
juga. Barang rusak dan barang yang belum siap jual **masih berada di rak**, dan
stok opname menghitung rak. Kalau mencatat deadstok langsung memotong
`stok_akhir`, setiap opname akan melaporkan **selisih palsu** tepat pada
barang-barang itu — dan justru laporan itulah yang paling dipercaya gudang.

Yang sebenarnya dibutuhkan bukan "stoknya berkurang", melainkan "dari sekian
stok, berapa yang benar-benar bisa dijual". Angka itu dihitung saat
ditampilkan, dan kolomnya ditaruh persis di samping Stok:

```
siap jual = stok akhir − SUM(deadstok yang masih tertahan)
```

Jadi tidak ada baris ledger yang dikarang, dan opname tetap cocok dengan rak.
Angka siap jual yang jadi **minus** tidak disembunyikan — ia dicetak merah,
karena itu tanda stok sistemnya yang perlu diperiksa.

### Tiga keadaan, satu yang menggerakkan stok

| Keadaan | Artinya | Stok |
|---|---|---|
| **Masih tertahan** | rusak / belum siap, masih di rak | tidak bergerak |
| **Sudah siap jual** | sudah diperbaiki atau sudah siap | tidak bergerak |
| **Dibuang** | barangnya benar-benar dibuang | **berkurang** |

Hanya "Dibuang" yang menyentuh stok, dan sentuhnya lewat **ledger biasa**: satu
baris barang keluar berketerangan `Deadstok Dibuang` (terkunci, sama seperti
"Retur Masuk"). Dengan begitu Riwayat, Dashboard, dan stok opname melihat hal
yang sama — bukan angka tersendiri yang hanya diketahui satu layar.

Karena memakai ledger biasa, aturannya ikut aturan Barang keluar: **tidak boleh
membuang melebihi stok yang tersedia**. Pemeriksaannya dilakukan per barang dan
**sebelum** transaksi, bukan per baris di dalamnya — beberapa baris bisa
menunjuk barang yang sama, masing-masing muat tapi jumlahnya tidak.

Membatalkan pembuangan menghidupkan kembali baris barang keluar yang **sama**,
bukan membuat yang baru, jadi status yang bolak-balik tidak menumpuk sampah
terhapus. Menghapus catatan yang sudah dibuang ikut menghapus baris barang
keluarnya, supaya stok tidak tetap berkurang sementara catatan yang
menerangkannya sudah hilang.

### Yang boleh dikerjakan siapa

| Tindakan | Operator | Admin |
|---|---|---|
| Melihat | ya | ya |
| Mencatat deadstok baru | **ya** | ya |
| Memindahkan keadaan (termasuk membuang) | **ya** | ya |
| Mengubah catatan yang sudah tersimpan | tidak | ya |
| Menghapus | tidak | ya |

Operator boleh mencatat dan memindahkan keadaan karena dialah yang memegang
barangnya dan yang tahu barang itu sudah siap jual atau sudah dibuang.
Mengubah catatan yang sudah tersimpan tetap admin: baris yang sudah "dibuang"
memegang baris barang keluar, jadi mengubah qty-nya menggeser stok.

Barang yang **belum terdaftar di master** boleh dicatat, tapi **tidak bisa
ditandai dibuang**: tanpa `master_id`, baris barang keluarnya tidak
menggerakkan stok apa pun dan hanya jadi baris yatim yang menyesatkan.
Penolakannya terang, bukan diam-diam dilewati.

### Ikut diperbaiki saat membangunnya

`menuLaporan()` mengembalikan `null` untuk jenis laporan yang tidak terdaftar,
dan `pdf.php` **melewati pemeriksaan hak akses sama sekali** kalau nilainya
null. Jadi laporan baru yang lupa didaftarkan di sana bisa diunduh siapa pun
yang login, tanpa memandang menu yang dia punya. `deadstok` didaftarkan, dan
ujinya sekarang menegaskan akun tanpa menu Barang deadstok ditolak 403 saat
mengunduh PDF-nya.

Aturan "cari barcode dulu, baru SKU" yang dipakai Retur dipindah ke
`cariMasterBarcodeAtauSku()` di `includes/helpers.php` dan dipakai bersama,
bukan disalin. Dua salinan aturan yang sama akan menyimpang cepat atau lambat,
dan selisihnya muncul sebagai barang yang dikenali di satu layar tapi tidak di
layar lain. `cariMasterReturn()` tetap ada sebagai nama lama yang meneruskan.

### Yang sengaja BELUM diubah

Dashboard dan Riwayat masih memakai `stok_akhir`, bukan angka siap jual.
Mengubahnya akan menggeser angka "Perlu order" yang setiap hari dilihat gudang,
dan itu keputusan tersendiri — bukan efek samping dari menambah satu menu.
Katakan kalau memang mau diikutkan.

---

## 22 Oktober 2026 — penanda Accurate punya keadaan ketiga

### "Tidak masuk"

Penanda Accurate hanya punya dua keadaan: **Sudah** dan **Belum**. Retur yang
memang tidak perlu dibukukan tidak punya tempat, jadi selamanya tertinggal di
**Belum** — dan karena itu angka "Belum ke Accurate" tidak pernah bisa mencapai
nol. Angka yang tidak pernah bisa nol berhenti memberi tahu apa pun.

Sekarang ada tiga:

| Keadaan | Artinya | Ikut hitungan "Belum"? |
|---|---|---|
| **Belum** | belum diinput, masih sisa pekerjaan | ya |
| **Sudah** | sudah diinput ke Accurate | tidak |
| **Tidak masuk** | sengaja tidak dibukukan | **tidak** |

Sesudah ini "Belum ke Accurate" kembali berarti sisa pekerjaan yang nyata.
Yang bertanda "Tidak masuk" tidak hilang dari pandangan — jumlahnya disebut
terpisah di kaki kartunya.

### Pilihan, bukan tombol berputar

Kolomnya dulu tombol yang berbalik antara dua keadaan. Dengan tiga keadaan,
tombol berputar berarti harus menekan dua kali untuk mundur satu langkah, dan
**setiap langkah di tengahnya tersimpan sebagai tanda yang salah**. Jadi
pilihannya sekarang eksplisit, tapi warnanya tetap ikut keadaannya supaya
kolomnya masih terbaca sekilas:

- **Sudah** hijau · **Belum** abu-abu muda · **Tidak masuk** abu-abu tua pekat

"Tidak masuk" sengaja bukan merah: itu keputusan yang sah, bukan kesalahan
yang menunggu diperbaiki.

### Penyaring Accurate

Toolbar Retur sekarang punya dropdown **Semua Accurate / Belum / Sudah / Tidak
masuk**. Penyaringnya sebenarnya sudah lama ada di server dan di unduhan PDF,
tapi **tidak pernah ada kontrolnya di layar** — `returFilter.accurate` dipakai
untuk menulis catatan "angka di atas hanya menghitung…", padahal kuncinya tidak
pernah ada di objeknya dan `resetRetur` menimpanya. Sekarang keduanya
tersambung, dan pilihannya ikut terbawa ke Unduh PDF.

### Jejak, dan yang tidak ikut tersapu

Waktu dan pelakunya dicatat untuk **"Sudah" maupun "Tidak masuk"** — keduanya
keputusan seseorang, dan kelak perlu ditelusuri kalau angkanya berbeda dengan
yang ada di Accurate. Hanya "Belum" yang berarti belum ada keputusan, jadi
jejaknya dikosongkan lagi.

Tombol **Tandai Accurate** massal hanya menyentuh baris berkeadaan "Belum".
Menyapu baris "Tidak masuk" jadi "Sudah" akan membatalkan keputusan itu
diam-diam, dan orangnya baru tahu saat angkanya tidak cocok dengan Accurate.

### Tanpa migrasi

Kolomnya tetap `retur.accurate` yang sama: `0` belum, `1` sudah, `2` tidak
masuk. TINYINT sudah muat, `idx_accurate` tetap terpakai, dan satu-satunya yang
menulis ke sana adalah `api/retur/accurate.php` yang memetakan nama keadaan ke
angkanya lewat daftar tertutup. Menambahkan CHECK constraint hanya untuk itu
berarti mempertaruhkan boot seluruh aplikasi demi perlindungan yang sudah ada
— persis cara kolom bernama `sqlstate` dulu menjatuhkan semuanya.

Bentuk permintaan lama `{ sudah: true|false }` masih diterima dan dipetakan ke
`sudah`/`belum`.

---

## 18 Oktober 2026 — satu pesanan, banyak produk retur

### Tombol "Tambah produk"

Satu nomor pesanan hanya bisa memuat satu produk, padahal pembeli sering
mengembalikan beberapa barang sekaligus dalam satu paket. Akibatnya petugas
mengetik nomor pesanan yang sama berulang kali, satu kali per barang.

Sekarang formnya terbagi dua. Tanggal dan nomor pesanan milik seluruh retur;
barang, qty, dan keterangannya milik tiap produk:

| Bagian | Isinya |
|---|---|
| Kepala | tanggal, no. pesanan |
| **Tiap produk** | cari barang, SKU, barcode, nama, qty, keterangan retur, ket. |

**Keterangan returnya per produk, bukan per pesanan.** Itu yang terpenting:
dalam satu paket bisa saja satu barang kembali utuh dan satunya lagi belum
bisa diproses — dan karena keterangan itulah yang menentukan barangnya masuk
stok atau tidak, menyamakannya untuk seluruh pesanan berarti memaksa stok
yang salah. Keterangan produk terakhir diwarisi produk berikutnya, karena
biasanya memang sama, tapi tetap bisa diganti per baris.

### Gagal satu, batal semuanya

Seluruh baris retur beserta baris barang masuknya ditulis dalam **satu
transaksi**. Kalau produk ketiga ditolak, dua yang pertama ikut batal dan
stoknya tidak bergeser sama sekali. Separuh pesanan yang terlanjur masuk stok
lebih berbahaya daripada tidak ada yang masuk: selisihnya baru ketahuan saat
stok opname, berminggu-minggu kemudian.

Pesan galatnya menyebut produk yang bermasalah, dan layarnya langsung meloncat
ke baris itu.

### Barang yang sama disebut dua kali

| Keadaan | Yang terjadi |
|---|---|
| Barang + keterangan retur + catatan **sama persis** | **Ditolak** — jadikan satu baris, qty dijumlahkan |
| Barang + keterangan sama, catatan **berbeda** | Diterima, diberi peringatan |
| Barang sama, **keterangan retur berbeda** | Diterima tanpa peringatan |

Yang pertama nyaris selalu tombol tertekan dua kali, dan akibatnya stok
bertambah dua kali lipat. Yang kedua memang bisa dua kejadian berbeda ("2 pcs
sobek", "1 pcs salah kirim"), jadi tidak ditolak — hanya diingatkan. Barang
dikenali lewat `master_id`, sehingga memasukkan barang yang sama lewat SKU di
satu baris dan barcode di baris lain tetap terbaca sebagai barang yang sama.

### Mengubah tetap satu baris

Baris yang sudah tersimpan berdiri sendiri-sendiri, masing-masing dengan baris
barang masuknya. Jadi saat mengubah, formnya kembali ke satu produk dan tombol
"Tambah produk" disembunyikan — kalau dibiarkan, ia terbaca seolah produk bisa
diselipkan ke retur yang sudah tercatat, padahal yang terjadi baris baru.
Operator tetap boleh mencatat, dan tetap tidak boleh mengubah.

Bentuk permintaan lama — satu produk tanpa `items` — masih diterima dan lewat
jalur yang sama persis, bukan jalur cadangan tersendiri.

### Ikut diperbaiki: keterangan penambah stok dibaca dari daftarnya

Sejak revisi 16 Oktober **seluruh** keterangan retur mengembalikan barang ke
stok, tapi layarnya masih membandingkan dengan satu nama saja. Akibatnya baris
berketerangan "Sistem Belum Selesai" — yang sebenarnya menambah stok —
tergambar merah "belum masuk stok", dan konfirmasi hapusnya menyatakan stok
tidak akan bergeser padahal bergeser. Ketiganya sekarang membaca daftar yang
dikirim server.

---

## 17 Oktober 2026 — sub menu Stok minimal

### Master → Stok minimal

Ambang "perlu order" sebelumnya hanya bisa diubah lewat form **Master → Barang**,
satu barang per satu kali buka — dan khusus admin, karena form itu mengubah
seluruh data barangnya. Padahal yang paling tahu angkanya orang gudang, dan
**1.095 barang masih berambang 0**, sehingga statusnya tidak pernah bisa
dinilai.

Sekarang ada sub menu tersendiri. Satu tabel, satu kolom yang bisa diketik:

| Kolom | Gunanya |
|---|---|
| Stok akhir | keadaan sekarang |
| **Keluar/bln** | rata-rata barang keluar 90 hari terakhir dibagi tiga |
| **Stok minimal** | diketik langsung, tersimpan begitu kolomnya ditinggalkan |
| Status | ikut berubah begitu ambangnya berubah |

Kolom *Keluar/bln* itu pembantu memutuskan — kalau sebuah barang keluar 33 pcs
sebulan, ambang 12 berarti kehabisan sebelum restock datang. Angkanya tidak
dipaksakan; yang menentukan tetap orang gudang.

Penyaringnya: pencarian, kategori, status, dan **"Ambangnya masih 0"** — yang
terakhir itu untuk mengerjakan sisa 1.095 barang tadi tanpa mencarinya satu per
satu.

**Setel massal** memasang satu angka ke SELURUH barang yang lolos penyaring,
bukan hanya yang terlihat di halaman. Menyetel 1.095 barang tidak perlu membuka
22 halaman.

### Boleh dipakai operator

Berbeda dengan mengubah data barang, menyetel ambang **tidak menyentuh stok
sama sekali** — ia hanya menentukan kapan sebuah barang disebut perlu order.
Jadi operator boleh, dan setiap perubahan tercatat di Log aktivitas berikut
nilai lamanya supaya bisa dikembalikan bila keliru. Akun hanya-baca tetap
hanya bisa melihat.

### Catatan pertukaran "manual"

Tombol hapusnya sudah ada sejak revisi 14 Oktober, tapi **khusus admin** —
akun operator tidak melihatnya. Catatan pertukaran menerangkan perpindahan
stok yang nyata, jadi menghapusnya keputusan audit, bukan operasional. Masuk
sebagai admin, lalu tekan ikon tong sampah di baris itu.

---

## 15 Oktober 2026 — galat Dashboard ketemu dan diperbaiki

### Sebabnya: collation, bukan bentuk querynya

Pesan servernya akhirnya terbaca:

```
1267 Illegal mix of collations (utf8mb4_unicode_ci,COERCIBLE)
                            and (utf8mb4_general_ci,COERCIBLE) for operation '='
```

Penyaring status bekerja dengan menghitung status tiap barang menjadi **teks**
lewat `CASE`, lalu membandingkannya dengan parameter. Keduanya dianggap
*COERCIBLE* oleh MySQL — artinya tidak ada yang lebih berhak menentukan
collation. Di server produksi collation koneksinya `utf8mb4_unicode_ci`,
sementara parameter prepared statement memakai collation bawaan charsetnya,
`utf8mb4_general_ci`. Berbeda, sama-sama COERCIBLE, jadi MySQL menolak.

Di mesin pengembangan keduanya kebetulan sama, jadi tidak pernah terlihat.
Itu sebabnya berminggu-minggu tidak ketemu: yang diuji selalu **bentuk
querynya** — di MariaDB, lalu di MySQL 8 asli dengan 250.000 baris transaksi,
puluhan kombinasi penyaring — dan semuanya memang lolos. Yang tidak pernah
diuji adalah collationnya.

### Yang berubah

Perbandingan teksnya dihapus sama sekali. Statusnya kini disaring sebagai
**syarat angka**:

| Status | Syarat |
|---|---|
| Belum diatur | `stok_minimal = 0` |
| Perlu order | `stok_minimal > 0 AND akhir <= stok_minimal` |
| Menipis | `akhir > stok_minimal AND akhir <= stok_minimal × 1,3` |
| Aman | `akhir > stok_minimal × 1,3` |

Tidak ada teks, tidak ada collation, tidak ada yang bisa bertabrakan. Lebih
cepat juga: penyaringnya langsung ikut `WHERE`, jadi seluruh pilihan tidak
perlu lagi dibungkus tabel turunan. Ekspor PDF Dashboard ikut diperbaiki.

Angkanya tidak berubah — dibandingkan dengan hitungan mandiri lewat PHP untuk
tiap status dan tiap kategori, di collation bawaan maupun collation produksi,
dan hasilnya sama persis. Kartu ringkasan juga tetap menghitung seluruh hasil
pencarian, tidak ikut tersaring status.

### Supaya tidak terulang

`node tools/uji_collation.js` menghantam **seluruh endpoint** lewat server yang
collationnya sengaja dibuat berbeda, lalu menolak setiap jawaban yang gagal
karena collation. Penjaganya diuji dengan mengembalikan kode lama: empat
alamat Dashboard langsung tertangkap, dan nol setelah diperbaiki.

---

## 14 Oktober 2026 — catatan pertukaran bisa dihapus, dan kode galat untuk semua

### 1. Catatan pertukaran bisa dihapus sendiri

Baris pertukaran hanya ikut terhapus bila baris **Barang keluar**-nya dihapus.
Catatan yang memang salah dicatat — misalnya percobaan pertukaran manual yang
tidak pernah benar-benar terjadi — tidak ada cara membuangnya.

Sekarang ada tombol hapus di tiap baris halaman **Pertukaran barang**, khusus
admin. Penghapusannya lunak: barisnya ditandai, bukan dibuang.

**Stok tidak berubah sama sekali.** Yang memotong stok adalah baris Barang
keluarnya, dan baris itu tidak disentuh — dialog konfirmasinya menyebutkan itu,
dan ujinya membuktikannya.

### 2. Kode galat basis data kini disebutkan ke siapa pun yang login

Pesan teknis sebelumnya hanya dikirim kepada admin. Masuk akal, tapi yang
melaporkan galat dari gudang adalah operator — jadi laporannya tidak pernah
memuat keterangan apa pun, dan penelusurannya berhenti di situ.

Sekarang **kode basis datanya** ikut ditampilkan untuk semua yang login,
misalnya `42S02/1146` atau `HY000/3024`. Kode itu sudah cukup mengenali jenis
kegagalannya dan tidak menyebutkan satu pun nama tabel, kolom, atau isi data.
Pesan lengkapnya tetap hanya untuk admin.

Juga dirapikan: kotak galat di Dashboard sempat menulis "Kode galat" dua kali.

### 3. Galat Dashboard — masih belum tereproduksi

Kode `CDE00B` pada kombinasi AOLIKES + Perlu order. Seluruh kombinasi kategori
dan status sudah diuji lewat HTTP di MariaDB dan di MySQL 8 asli dengan 250.000
baris transaksi; semuanya dijawab 200. Jadi bentuk querynya bukan penyebabnya.

Dengan perubahan nomor 2 di atas, kejadian berikutnya akan menyebutkan kode
basis datanya sendiri di layar — dan dari situ sebabnya bisa dipastikan, bukan
ditebak.

---

## 10 Oktober 2026 (sore) — retur langsung masuk stok, dan galat yang menyebut dirinya

### 1. Semua retur langsung masuk stok, tanpa menunggu siapa pun

Sebelumnya keterangan retur yang baru dibuat bawaannya **tidak** menambah
stok, dan harus diubah dulu agar returnya masuk. Itu terbalik: yang jarang
terjadi justru retur yang memang tidak boleh menambah stok.

Sekarang:

- Keterangan retur yang **baru dibuat** bawaannya **mengembalikan barang ke
  stok**. Tidak ada yang perlu diatur lagi setelah membuatnya.
- Migrasi `022` menandai **seluruh keterangan retur yang aktif** sekali jalan,
  jadi retur baru langsung masuk stok sejak deploy.
- Yang memang tidak boleh menambah stok tetap bisa dibuat — pilih *Tidak
  menambah stok* di kolom Pengaruh ke stok.

Untuk retur yang **sudah tercatat**, tekan sekali tombol **Masukkan semua ke
stok** di halaman Retur.

**Satu bug ikut tertangkap.** Dengan bawaan baru itu, mengganti nama atau
urutan sebuah keterangan diam-diam ikut menghidupkan pengaruh stoknya — dan
retur lama tersusul tanpa diminta. Uji lama yang menangkapnya. Sekarang
"tidak dikirim" berarti *tidak diubah*, dan bawaan hanya berlaku untuk
keterangan yang benar-benar baru.

### 2. Dashboard: tabel tidak lagi menampilkan hasil penyaring yang lama

Ketika pemuatan gagal, barisnya dibiarkan apa adanya. Yang terlihat: penyaring
sudah berganti ke "Menipis", tapi tabel masih memuat isi penyaring sebelumnya
— dan itu terbaca sebagai "penyaringnya salah", padahal permintaannya yang
gagal. Toast galatnya sendiri hilang dalam beberapa detik.

Sekarang tabelnya dikosongkan dan diganti kotak yang menyebutkan apa yang
terjadi, lengkap dengan kode galatnya.

### 3. Admin melihat pesan galat yang sebenarnya

Selama ini pesan teknis disembunyikan dari semua orang. Terdengar aman, dan
justru itulah yang membuat galat di produksi tidak pernah bisa ditelusuri:
yang sampai ke layar hanya "Terjadi kesalahan di server", sementara pesan
aslinya terkubur di error log hosting yang tidak bisa dibuka.

Sekarang pesan teknisnya ikut ditampilkan — **hanya untuk admin**. Operator
tetap melihat pesan umum beserta kodenya. Galat `9F9DF1` berikutnya akan
menyebutkan sendiri sebabnya di layar.

---

## 10 Oktober 2026 — tombol sekali klik, penanda versi, dan penjaga yang ternyata buta

### Ketiga keluhan hari ini sudah diperbaiki kemarin — tapi belum sampai ke server

Penyaring keterangan di Barang masuk, pertukaran yang ikut terhapus, dan retur
yang bisa masuk stok: ketiganya ada di revisi **9 Oktober**. Saya periksa ulang
hari ini dengan menguji HTML yang benar-benar digambar tiap layar — semuanya
ada. Jadi yang perlu dilakukan adalah menarik revisi itu ke server.

Supaya pertanyaan ini tidak terulang, di bawah tombol **Keluar** pada sidebar
kini tertulis **versi** beserta tanggal dan jamnya, diambil dari waktu ubah
berkas aplikasi di server. Kalau tanggalnya lebih tua dari revisi yang dicari,
deploynya memang belum sampai.

### Tombol "Masukkan semua ke stok"

Kemarin, membuat seluruh retur masuk stok berarti membuka Master → Keterangan
retur dan mengubah tiap keterangan satu per satu. Sekarang ada tombol
**Masukkan semua ke stok** di halaman Retur (khusus admin):

- seluruh keterangan retur yang aktif ditandai mengembalikan barang ke stok
- retur yang sudah tercatat langsung disusulkan ke Barang masuk

Aman diulang: yang sudah benar tidak disentuh, jadi menekannya dua kali tidak
melahirkan baris ganda. Masih bisa dibatalkan per keterangan lewat Master →
Keterangan retur, dan returnya ditarik kembali dari stok.

Diuji dengan 12 retur bercampur empat keterangan: 3 sudah masuk, 9 disusulkan,
pengulangan tidak mengubah apa pun, pembatalan satu keterangan menarik 3 retur
kembali, dan operator ditolak.

### Penjaga layar yang ternyata hampir tidak menguji apa-apa

`tools/uji_layar.js` yang dibuat 8 Oktober memanggil `renderContent(id)` untuk
setiap menu. Fungsi itu **tidak menerima argumen** — ia menggambar tab yang
sedang aktif. Jadi keempat belas "layar" yang diuji sebenarnya dashboard yang
sama, empat belas kali, dan ujinya berbunyi OK tanpa pernah melihat layar lain.
Akun tiruannya juga tidak diberi akses menu, sehingga yang tergambar hanya
pesan "tidak punya akses".

Keduanya sudah dibetulkan: tabnya dipindah lewat `switchTab()` seperti saat
menunya diklik, dan akunnya diberi akses penuh.

Ditambah `tools/uji_html.js`, yang memeriksa **isi** layarnya, bukan hanya
bahwa ia tergambar tanpa galat — penyaringnya ada, kolomnya ada, kalimatnya
sudah yang benar. Sebuah penyaring bisa hilang sama sekali tanpa melempar
galat apa pun, dan hanya uji semacam ini yang menangkapnya.

---

## 9 Oktober 2026 — retur tidak masuk stok, pertukaran tertinggal, penyaring keterangan

### 1. Retur Oktober tidak pernah masuk ke Barang masuk

**Sebabnya.** Sejak awal hanya SATU keterangan yang menambah stok, namanya
dipaku di kode: **"Lengkap"**. Begitu daftar keterangan retur bisa dikelola
dari menu Master, gudang menambahkan "Pembatalan" dan "Pengiriman Gagal" — dan
retur dengan keterangan itu diam-diam tidak pernah menambah stok, karena
namanya bukan "Lengkap". Tidak ada pesan, tidak ada tanda. Itu sebabnya
sepanjang Oktober tidak ada satu pun retur yang tercatat di Barang masuk.

Ini kelalaian saya: waktu membuat daftar keterangan bisa diubah, saya tidak
ikut memindahkan sifat "menambah stok" ke daftarnya.

**Yang berubah.** Sifat itu sekarang melekat pada keterangannya sendiri, bukan
pada namanya. Di **Master → Keterangan retur**, tiap keterangan punya kolom
**Pengaruh ke stok**:

- *Tidak menambah stok* — returnya dicatat saja
- *Mengembalikan barang ke stok* — returnya langsung masuk ke Barang masuk
  "Retur Masuk"

Keterangan yang bertanda **Menambah stok** terlihat langsung di daftarnya.

**Retur lama ikut disusulkan.** Begitu sebuah keterangan dicentang, seluruh
retur yang sudah tercatat dengan keterangan itu langsung disusulkan ke Barang
masuk — dalam satu transaksi. Jadi untuk membetulkan Oktober: buka Master →
Keterangan retur, ubah "Pembatalan" dan "Pengiriman Gagal" menjadi
*Mengembalikan barang ke stok*, dan returnya masuk semua sekaligus.

Melepas centangnya menarik kembali, juga sekaligus. Barisnya tidak dibuang,
hanya ditandai, jadi mencentang lagi tidak melahirkan baris ganda.

Nilai awalnya sengaja sama dengan perilaku lama — hanya "Lengkap" yang
dicentang. Mencentang yang lain adalah keputusan gudang, bukan keputusan
migrasi.

### 2. Pertukaran barang tertinggal setelah barang keluarnya dihapus

Baris pertukaran lahir dari sebuah baris barang keluar. Ketika baris barang
keluarnya dihapus, catatan pertukarannya tetap tinggal — menyebutkan
perpindahan stok yang sudah tidak ada lagi.

Sekarang keduanya terhapus bersama, dalam satu transaksi, dan pesannya
menyebutkan berapa catatan pertukaran yang ikut. Penghapusannya lunak seperti
transaksi: barisnya hanya ditandai, jejaknya tetap ada.

### 3. Penyaring keterangan di Barang masuk dan Barang keluar

Ditambahkan dropdown **Semua keterangan** di toolbar kedua halaman, dan ikut
terbawa ke PDF yang diunduh.

Pilihannya bukan hanya daftar yang berlaku sekarang, tapi juga keterangan yang
benar-benar ada di data — termasuk yang sudah dihapus dari Master. Kalau tidak,
catatan lama berketerangan itu tidak akan pernah bisa ditemukan lewat
penyaring.

### 4. Stok opname: ya, penyesuaian memang mengubah stok

Pertanyaannya terjawab: **ya.** Memilih **"Stok Disesuaikan"** langsung
membetulkan stoknya — stok akhir barang itu mengikuti stok hitung, lewat satu
baris Barang masuk atau Barang keluar berketerangan "Penyesuaian Opname", jadi
koreksinya ikut terbaca di Riwayat.

Yang salah adalah keterangan di layarnya sendiri, yang berbunyi *"Penyesuaian
hanya mencatat keputusan — memilih Stok Disesuaikan tidak mengubah stok
sendiri"*. Itu kalimat lama dari sebelum fiturnya dibuat, dan tidak pernah
ikut diperbarui. Sudah diganti.

### 5. Galat Dashboard saat menyaring — belum ketemu

Kode galat **9F9DF1** belum bisa saya reproduksi. Query Dashboard diuji ulang
atas 55 kombinasi kategori, status, dan kata pencarian lewat HTTP: semuanya
dijawab 200. Sebelumnya juga sudah diuji di MySQL 8 asli.

Yang bisa dilakukan sekarang: catatan galat ikut menyimpan **parameter
penyaringnya**, bukan hanya nama endpoint. Jadi laporan berikutnya langsung
menyebutkan kombinasi mana yang gagal, bukan sekadar "dashboard/stats.php".

Pesan galat untuk kode 9F9DF1 bisa dibuka admin di **Log aktivitas**, di panel
paling atas.

---

## 8 Oktober 2026 — penanda Accurate pada retur, dan pertukaran pada input manual

### 1. Tanda "sudah diinput ke Accurate" pada retur

**Masalahnya.** Retur yang sudah dicatat di gudang masih harus dimasukkan satu
per satu ke Accurate, dan tidak ada tempat untuk menandai mana yang sudah.
Pengecekannya jadi mengandalkan ingatan — baris yang sama bisa terinput dua
kali, atau terlewat sama sekali.

**Yang berubah.** Ada kolom **Accurate** di tabel retur, berisi tombol:

- **Belum** — klik bila retur itu sudah diinput ke Accurate
- **Sudah** — klik lagi bila ternyata keliru

Di bawah tandanya tertulis tanggal dan nama orang yang menandai, supaya nanti
bisa ditelusuri kalau angkanya ternyata berbeda dengan yang ada di Accurate.

Tambahannya:

- Kartu **Belum ke Accurate** di deretan kartu atas.
- Penyaring **Accurate: semua / belum / sudah** di toolbar.
- Tombol **Tandai Accurate** untuk menandai sekaligus seluruh baris yang
  sedang tampil di halaman itu.
- Kolomnya ikut di PDF retur, beserta penyaringnya, jadi laporan yang diunduh
  memuat persis yang sedang dilihat.

**Tandanya tidak menyentuh stok sama sekali.** Yang menambah stok tetap
keterangan returnya (`Lengkap`). Ini murni catatan pembukuan. Operator boleh
menandainya — memang dia yang menginput ke Accurate — dan setiap penandaan
tercatat di Log aktivitas.

### 2. Pertukaran barang pada input barang keluar manual

**Masalahnya.** Impor picking list sudah mencatat pertukaran sejak dulu: kalau
petugas mengganti produk sebuah baris sebelum menyimpan, penggantiannya masuk
ke menu Pertukaran barang. Pencatatan manual belum punya itu, padahal
kejadiannya sama — yang dipesan satu barang, yang dikirim barang lain karena
yang asli kosong.

**Yang berubah.** Di form Barang keluar ada centang **"Barang ini menggantikan
barang lain"**. Begitu dicentang, muncul isian barang yang *diminta*, lengkap
dengan pencarian nama/barcode seperti isian utamanya.

Yang dicatat:

| | |
|---|---|
| Barang yang diminta | yang diisi di bagian pertukaran |
| Barang yang dikirim | isian utama form |
| Stok berkurang untuk | hanya barang yang benar-benar dikirim |
| Muncul di | menu Pertukaran barang, bertanda "Dicatat manual" |

Transaksinya dan catatan pertukarannya ditulis bersama dalam satu transaksi
basis data: tidak mungkin stok berkurang tanpa keterangan penggantinya, atau
sebaliknya. Kalau stoknya ternyata kurang dan pencatatannya ditolak, tidak ada
baris pertukaran yang tertinggal.

Pengisian yang separuh ditolak dengan pesan jelas: barang pengganti yang sama
dengan yang diminta, atau nama terisi tapi barcodenya kosong.

### 3. Satu bug saya sendiri, dari revisi kemarin

Pada revisi 6 Oktober, perubahan di halaman Retur gagal tersimpan separuh:
variabel `penyaringAktif` dipakai tapi tidak pernah dideklarasikan. Akibatnya
halaman Retur menampilkan kartu lalu berhenti — tabelnya tidak muncul.

Sudah diperbaiki. Supaya tidak terulang, ditambahkan `node tools/uji_layar.js`:
setiap layar digambar sekali di luar browser dan dipastikan tidak melempar
galat. Penjaganya diuji dengan menghapus kembali deklarasi itu — `node --check`
tetap meloloskannya, uji layar langsung menangkapnya.

---

## 7 Oktober 2026 — Dashboard dipercepat, dan galat server bisa dibaca

### 1. "Terjadi kesalahan di server" saat menyaring atau mencari

**Yang dicari.** Query Dashboard diuji ulang di **MySQL 8 asli** — mesin yang
dipakai server, bukan MariaDB yang dipakai komputer kerja — atas katalog
lengkap 1.435 barang. Lebih dari 60 kombinasi penyaring, kategori, dan kata
pencarian: semuanya lolos, tidak satu pun galat. Jadi bentuk SQL-nya bukan
penyebabnya.

**Yang ditemukan justru biayanya.** Setelah 200.000 baris barang keluar dan
50.000 barang masuk dimasukkan, tiap query Dashboard memakan ~250 ms — dan
satu permintaan Dashboard menjalankan penjumlahan itu **tiga kali**. Di
hosting bersama, query yang lama memperbesar peluang permintaan ditutup di
tengah jalan oleh batas waktu atau batas koneksi, dan itulah yang terbaca
sebagai galat yang datang sesekali.

**Yang berubah.**

| Perubahan | Hasil ukur |
|---|---|
| Indeks penutup `(master_id, deleted_at, jumlah)` di barang masuk & keluar | satu query ~250 ms → **~78 ms** |
| Ringkasan dan penghitung baris digabung jadi satu query | tiga lintasan → **dua** |

Totalnya: satu permintaan Dashboard turun dari sekitar 750 ms menjadi sekitar
160 ms pada volume itu. Angkanya sendiri tidak berubah — diuji dengan
membandingkan hasil cara lama, cara baru, dan hitungan mandiri lewat PHP, di
MySQL 8 maupun MariaDB, untuk tiap kombinasi penyaring.

### 2. Galat server sekarang bisa dibaca dari dalam aplikasi

**Masalahnya.** Saat gagal, layar hanya berbunyi "Terjadi kesalahan di
server." Pesan aslinya masuk ke error log PHP milik hosting, yang tidak bisa
dibuka dari aplikasi. Jadi tiap kali ada galat di produksi, penelusurannya
dimulai dari menebak — dan tebakannya pernah salah.

**Yang berubah.** Tiap galat kini diberi **kode enam karakter** yang disebut
di layar, dan dicatat lengkap dengan pesan, endpoint, berkas, nomor baris, dan
kode SQL-nya. Admin membukanya di menu **Log aktivitas**, di panel paling atas.
Jadi tangkapan layar dari gudang cukup memuat kodenya, dan barisnya bisa
langsung dicocokkan.

Isinya teknis, jadi khusus admin — operator yang punya menu Log aktivitas pun
tetap ditolak. Catatan yang lebih tua dari sebulan dibuang sendiri.

**Satu bug ikut tertangkap di sini.** Kolom catatan itu semula diberi nama
`sqlstate`, yang ternyata kata tercadang di MySQL dan MariaDB: migrasinya gagal
dengan galat sintaks, dan seluruh aplikasi tidak bisa dibuka. Ketahuan saat
diuji di komputer kerja, sebelum sampai ke server.

---

## 6 Oktober 2026 — impor panjang, kolom Urutan, angka di halaman Retur

Commit `e7354d5`.

### 1. Picking list panjang akhirnya bisa disimpan

**Masalahnya.** Beberapa picking list terbaca di layar tapi gagal disimpan.
Uji otomatis yang dipasang revisi sebelumnya memberi angkanya: **10 baris
lolos, 11 baris tidak**. Jadi yang membatasi adalah satu permintaan, bukan
datanya. Dari luar, endpoint yang sama masih menerima badan 744 KB dan seluruh
1.435 nama katalog sekaligus — artinya ini khusus permintaan dari sesi yang
sudah masuk di jaringan gudang, yang tidak bisa ditiru dari tempat lain.

**Yang berubah.** Impor yang lebih panjang dari lima baris tidak lagi dikirim
sekaligus. Barisnya dititipkan lima-lima ke tabel penampung, lalu satu
permintaan terakhir memerintahkan penyimpanannya. Tiap permintaan tinggal
sekitar 1,5 KB, sepanjang apa pun picking list-nya.

Yang penting tidak berubah:

- **Tidak ada satu baris pun masuk stok sampai perintah terakhir datang**, dan
  penulisannya tetap satu transaksi.
- Pengunggahan yang putus di tengah hanya meninggalkan isi penampung — bukan
  stok yang berkurang separuh. Sisanya dibuang sendiri setelah sehari.
- Penampung baru dikosongkan setelah penyimpanan berhasil, jadi impor yang
  ditolak karena stok kurang bisa diteruskan tanpa mengunggah ulang.
- Sesi pengiriman hanya bisa diselesaikan oleh akun yang menitipkannya.

Tambahan: salinan "apa adanya dari PDF" sekarang hanya ikut dikirim untuk
baris yang produknya benar-benar ditukar. Pada baris biasa isinya sama persis
dengan kolom di atasnya, jadi selama ini cuma menggandakan judul etalase yang
panjang.

**Cara memeriksanya.** Impor satu picking list berisi lebih dari sepuluh
baris. Selama pengiriman, pil status di kanan atas menghitung
"Mengirim 15 dari 31 baris…". Setelah selesai, jumlah baris yang tersimpan
harus sama dengan jumlah baris yang dicentang di tabel review.

**Kalau masih gagal.** Sekarang buktinya tidak ikut hilang: muncul kotak di
bawah tabel impor berisi jawaban mentah dari server, ditambah kesimpulan uji
yang membedakan "batas jumlah baris" dari "ada satu baris yang isinya
bermasalah". Tangkap layar kotak itu — isinya yang menerangkan sebabnya.

### 2. Kolom "Urutan" di menu Master

**Masalahnya.** Kolomnya menampilkan angka `10` dan `20` tanpa menerangkan
apa-apa.

**Yang berubah.** Angka itu memang hanya mengurutkan isi dropdown. Sekarang
diganti sepasang tombol ↑ ↓ untuk menukar posisi satu baris dengan
tetangganya. Judul kolomnya menjadi "Urutan tampil", dan di form ada
keterangan *angka kecil muncul lebih dulu di dropdown*.

Keterangan yang dikunci (`Lengkap` pada retur, `Retur Masuk` pada barang
masuk) tetap bisa digeser posisinya — yang dikunci hanya nama dan
penghapusannya.

### 3. Angka di halaman Retur

**Masalahnya.** Kartu "Sudah masuk stok" menunjukkan **0**, seolah tidak ada
satu pun retur yang pernah menambah stok.

**Sebenarnya tidak ada yang salah.** Keempat angka di halaman Retur dihitung
atas hasil penyaring yang sedang aktif, dan penyaringnya sedang disetel ke
`Sistem Belum Selesai`. Yang 110 baris itu memang belum menyentuh stok. Di
luar penyaring itu ada 526 retur berketerangan `Lengkap` yang stoknya sudah
bertambah.

**Yang berubah.** Selama ini tidak ada yang memberi tahu angka itu menghitung
apa. Sekarang, bila ada penyaring aktif, muncul keterangan di bawah kartu
beserta tautan **Tampilkan semua retur**.

---

## 2 Oktober 2026 — Keterangan retur bisa dikelola dari menu Master

Commit `b77e9d1`.

Isi dropdown **Keterangan retur** dulu dipaku di berkas konfigurasi, jadi
menambah satu pilihan berarti menyunting kode. Sekarang ada tab **Keterangan
retur** di menu Master — tambah, ganti nama, geser urutan, nonaktifkan, hapus,
sama seperti keterangan barang masuk dan barang keluar.

Satu nilai di daftar itu bukan sekadar label: retur berketerangan **`Lengkap`
menambah stok** lewat baris barang masuk miliknya sendiri. Jadi barisnya
dikunci — tidak bisa diganti nama, dinonaktifkan, atau dihapus — dan layar
menyebutkan alasannya.

Menghapus pilihan yang masih dipakai tetap menanyakan tujuan pemindahannya
dulu. Memindahkan retur *menjadi* `Lengkap` lewat jalan itu ditolak:
pemindahan di sana hanya menulis ulang kolom keterangan, jadi returnya akan
tampak selesai padahal stoknya tidak pernah bertambah. Mengganti nama ikut
memperbarui retur yang sudah memakainya, dalam satu transaksi.

**Cara memeriksanya.** Master → Keterangan retur. Tambah satu pilihan, lalu
buka menu Retur: pilihan itu harus sudah ada di dropdownnya.

---

## 30 September 2026 — penolakan yang menyebutkan alasannya

Commit `d17ed6c`.

Layar sempat menampilkan "permintaan diblokir sebelum sampai ke aplikasi —
biasanya pengaman hosting, bukan hak akses akun". Kalimat itu tebakan, dan
pengujian membuktikannya keliru.

Sekarang aplikasi tidak menebak. Bila jawaban server tidak bisa dibaca, yang
ditampilkan adalah **jawaban server itu sendiri**, lengkap dengan kode dan
ukurannya. Sebelum menyerah, aplikasi mencoba menyelamatkannya dulu: badan
jawaban yang kedahuluan sampah tetap diurai, supaya penolakan izin biasa tidak
lagi tersamar jadi gangguan hosting.

Penolakan izin juga menyebut apa yang kurang — `Akun ini tidak punya akses ke
menu "keluar"` — dan penolakan hanya-lihat mengingatkan untuk memuat ulang
halaman bila hak aksesnya baru saja diubah. Halaman menyembunyikan formulirnya
berdasarkan hak akses saat halaman dimuat, jadi hak yang berubah di tengah
jalan memang baru terasa setelah dimuat ulang.

---

## 29 September 2026 — semua picking list yang terbaca kini bisa diimpor

Commit `b1dfb35`.

Empat picking list nyata diuji. Satu berhasil, tiga gagal — dengan empat sebab
berbeda, dan **tiga di antaranya gagal dalam diam**: angkanya salah, bukan
berhenti dengan pesan.

| Sebab | Akibatnya dulu |
|---|---|
| Barang tanpa digit barcode di PDF (hanya ber-SKU) | satu baris menggagalkan seluruh berkas |
| Stok tercatat kurang | satu barang bersaldo nol menggagalkan seluruh berkas |
| Picking list berisi satu barang saja | terbaca jadi satu baris berqty 0, tidak bisa disimpan |
| Blok barang dicetak ulang di puncak halaman berikutnya | barangnya terhitung dua kali |

Yang berubah:

- Baris tanpa barcode dicocokkan lewat **SKU**, dan barcode master yang
  dipakai supaya catatan dan produknya tidak pernah menunjuk barang berbeda.
  SKU kembar tidak ditebak.
- Stok kurang **ditanyakan**, bukan ditolak mati. Picking list mencatat barang
  yang sudah dikirim, jadi petugas yang memutuskan; bila dilanjutkan, stok
  barang itu menjadi minus sebagai tanda perlu ditelusuri lewat stok opname.
- Nomor urut tunggal dipercaya bila berbunyi `1`.
- Blok yang dicetak ulang digabungkan, bukan dihitung sebagai barang kedua.

Keempat berkas kini terbaca persis sesuai angka yang dicetak Desty di kepala
berkasnya — jumlah unit cocok dengan "Jumlah produk", nomor pesanan unik cocok
dengan "Jumlah Pesanan". Pemeriksaan itu dijalankan ulang otomatis lewat
`node tools/uji_pdf_parser.js`.

---

## 24 September 2026 — tiga perbaikan yang dilaporkan

Commit `8978cc4`.

- **Memilih status di Dashboard berbuah "Terjadi kesalahan di server".**
  Penyaringnya memakai bentuk SQL yang diterima MariaDB di mesin pengembangan
  tapi ditolak MySQL di server — itu sebabnya hanya muncul di server. Sudah
  diganti bentuk yang tidak bergantung pada itu.
- **Operator masih bisa mengubah catatan lama.** Menghapus sudah khusus admin,
  tapi menimpa catatan lama menggeser stok dengan cara yang sama dan tidak
  meninggalkan jejak di daftar. Sekarang keduanya satu aturan. Mencatat hal
  baru dan mengisi hasil hitungan stok opname tetap boleh untuk operator.
- **"Tertahan" tidak menerangkan apa-apa.** Kartunya kini berbunyi "Sudah
  masuk stok" dan "Belum masuk stok".

---

## Yang berjalan sendiri saat deploy

Perubahan basis data dijalankan otomatis saat halaman pertama kali dibuka
setelah deploy. Tidak ada yang perlu dijalankan tangan, dan tiap berkas hanya
dijalankan sekali.

| Berkas | Isinya |
|---|---|
| `sql/013_keterangan_retur.sql` | daftar Keterangan retur + penguncian `Lengkap` |
| `sql/014_import_antrian.sql` | penampung baris picking list sebelum disimpan |
| `sql/015_indeks_agregat_keluar.sql` | indeks penutup barang keluar |
| `sql/016_indeks_agregat_masuk.sql` | indeks penutup barang masuk |
| `sql/017_galat_sistem.sql` | catatan galat server |
| `sql/018_retur_accurate.sql` | penanda Accurate pada retur |
| `sql/019_pertukaran_hapus.sql` | pertukaran bisa ikut terhapus |
| `sql/020_keterangan_tambah_stok.sql` | keterangan retur mana yang menambah stok |
| `sql/021_galat_endpoint_panjang.sql` | parameter penyaring ikut di catatan galat |
| `sql/022_retur_semua_tambah_stok.sql` | semua keterangan retur menambah stok |

---

## Masih menunggu tindakan

Dua hal ini di luar kode dan hanya bisa dikerjakan dari sisi pemilik akun:

1. **Ganti password akun admin bawaan.** Password bawaannya masih tertulis di
   dokumentasi repositori ini, dan repositori ini publik — siapa pun bisa
   membacanya lalu mencobanya di server. Setelah diganti, hapus juga
   penyebutannya dari dokumentasi.
2. **Ganti password basis data Hostinger.** Password itu sempat terlihat
   terbuka di sebuah tangkapan layar. Gantilah lewat hPanel, lalu perbarui
   berkas konfigurasi yang berada di luar `public_html`.
