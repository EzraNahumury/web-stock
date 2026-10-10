# Catatan Revisi — WAREHOUSE AVA

Ringkasan perubahan yang sudah masuk ke server, ditulis untuk dibaca sebelum
atau sesudah deploy. Yang paling baru ada di atas.

Dokumentasi teknis lengkapnya tetap di [README.md](README.md); berkas ini
hanya menjawab tiga hal untuk tiap revisi: **apa yang bermasalah**, **apa yang
berubah**, dan **bagaimana memastikannya jalan**.

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
