# Panduan Revisi — 17 s/d 25 Oktober 2026

Panduan pemakaian untuk revisi putaran ini. Ditulis untuk dibaca orang gudang,
bukan programmer: **apa yang baru, di mana letaknya, dan apa yang harus
dilakukan**.

Alasan teknis tiap perubahan — kenapa dirancang begitu, bug apa yang
ditemukan — ada di [REVISI.md](REVISI.md).

---

## Lakukan dulu sesudah deploy

Tiga hal ini perlu dikerjakan sekali, dan dua di antaranya tidak terjadi
sendiri.

### 1. Pastikan deploy-nya mendarat

Lihat **penanda versi di pojok kiri bawah sidebar**. Kalau tanggalnya masih
yang lama, halamannya masih dari cache — tekan `Ctrl+Shift+R` (atau tutup lalu
buka lagi di HP).

### 2. Susulkan retur yang belum masuk stok  ← penting

Buka **Retur**, **hapus penyaring tanggalnya**, lalu lihat kotak peringatan di
atas tabel.

Kalau ada, isinya menyebutkan berapa baris dan berapa pcs yang **keterangannya
mengembalikan barang ke stok tapi barang masuknya belum pernah dibuat**. Tekan
**Susulkan sekarang** (khusus admin). Itu yang benar-benar memasukkan barangnya
ke Barang masuk.

> Aman ditekan berulang kali. Yang sudah benar tidak disentuh.

Sampai tombol itu ditekan, stoknya memang belum bertambah — kartu "Sudah masuk
stok" tidak lagi berpura-pura sudah.

### 3. Beri akses menu baru ke akun operator

Ada dua menu baru: **Master → Stok minimal** dan **Barang deadstok**.

Akun **admin** langsung melihat keduanya. Tapi akun operator atau hanya-baca
yang **hak aksesnya pernah diatur satu per satu TIDAK otomatis mendapatkannya** —
daftar centangnya masih yang lama.

Jadi: **Pengguna → buka akunnya → centang "Stok minimal" dan "Barang
deadstok" → simpan.** Akun yang hak aksesnya belum pernah diatur sama sekali
langsung dapat keduanya.

---

## Yang baru, ringkas

| Di mana | Yang baru |
|---|---|
| **Master → Stok minimal** | Sub menu baru: setel ambang "perlu order" tiap barang, satuan atau massal |
| **Retur** | Satu pesanan bisa memuat beberapa produk sekaligus |
| **Retur** | Penanda Accurate punya keadaan ketiga: **Tidak masuk** |
| **Retur** | Penyaring Accurate di toolbar, dan kartu "Belum tersusul" |
| **Barang deadstok** | Menu baru: barang rusak & barang belum siap jual |
| **Laporan stok opname** | Nama barang di PDF tidak lagi terpotong |
| **Laporan stok opname** | Bisa diisi langsung dari HP |
| **Laporan stok opname** | Stok accurate boleh diisi angka minus |
| **Laporan stok opname** | Tombol **Centang semua**, **Sesuaikan semua**, **Batal sesuaikan** |

---

## Master → Stok minimal

Ambang "perlu order" dulu hanya bisa diubah lewat form **Master → Barang**, satu
barang per satu kali buka, dan khusus admin. Padahal **1.095 barang masih
berambang 0**, yang berarti statusnya tidak pernah bisa dinilai.

Sekarang satu tabel dengan satu kolom yang bisa diketik:

| Kolom | Gunanya |
|---|---|
| Stok akhir | keadaan sekarang |
| **Keluar/bln** | rata-rata barang keluar 90 hari terakhir dibagi tiga |
| **Stok minimal** | diketik langsung, tersimpan begitu pindah dari kolomnya |
| Status | ikut berubah begitu ambangnya berubah |

Kolom *Keluar/bln* itu pembantu memutuskan: kalau sebuah barang keluar 33 pcs
sebulan, ambang 12 berarti kehabisan sebelum restock datang.

**Penyaring "Ambangnya masih 0"** untuk mengerjakan sisa 1.095 barang tanpa
mencarinya satu per satu. **Setel massal** memasang satu angka ke SELURUH
barang yang lolos penyaring — bukan hanya yang terlihat di halaman, jadi 1.095
barang tidak perlu membuka 22 halaman.

Boleh dipakai operator: menyetel ambang **tidak menyentuh stok sama sekali**, ia
hanya menentukan kapan barang disebut perlu order. Tiap perubahan tercatat di
Log aktivitas berikut nilai lamanya.

---

## Retur

### Satu pesanan, banyak produk

Tombol **+ Tambah produk** di bawah baris produk.

```
TANGGAL   NO. PESANAN                    ← milik seluruh retur
─────────────────────────────────────
│ Produk 1                        [🗑]
│  Cari barang · SKU · Barcode
│  Nama · Qty · Keterangan retur · Ket.
─────────────────────────────────────
│ Produk 2                        [🗑]
  [+ Tambah produk]
  [+ Catat retur]
```

**Keterangan returnya per produk, bukan per pesanan.** Dalam satu paket bisa
saja satu barang kembali utuh dan satunya belum bisa diproses — dan keterangan
itulah yang menentukan barangnya masuk stok atau tidak. Keterangan produk
terakhir diwarisi produk berikutnya, tetap bisa diganti.

Kalau satu produk ditolak, **seluruh pesanan batal** dan stok tidak bergeser
sedikit pun. Separuh pesanan yang terlanjur masuk stok baru ketahuan saat stok
opname.

Barang yang sama disebut dua kali:

| Keadaan | Hasil |
|---|---|
| Barang + keterangan + catatan **sama persis** | **Ditolak** — jadikan satu baris, qty dijumlahkan |
| Barang + keterangan sama, catatan **beda** | Diterima, diberi peringatan |
| Barang sama, **keterangan beda** | Diterima |

Mengubah retur yang sudah tersimpan tetap satu produk, dan tetap khusus admin.

### Penanda Accurate: tiga keadaan

| Keadaan | Artinya | Ikut hitungan "Belum"? |
|---|---|---|
| **Belum** (abu muda) | belum diinput, masih sisa pekerjaan | ya |
| **Sudah** (hijau) | sudah diinput ke Accurate | tidak |
| **Tidak masuk** (abu tua) | sengaja tidak dibukukan | **tidak** |

Retur yang memang tidak perlu dibukukan dulu selamanya nyangkut di "Belum",
sehingga angka "Belum ke Accurate" tidak pernah bisa nol — dan angka yang tidak
pernah bisa nol berhenti berarti apa pun. Sekarang angka itu kembali berarti
sisa pekerjaan nyata; yang "Tidak masuk" disebut terpisah di kaki kartunya.

Kolomnya jadi **pilihan**, bukan tombol yang berputar — dengan tiga keadaan,
tombol berputar berarti tekan dua kali untuk mundur satu, dan tiap langkah di
tengahnya tersimpan sebagai tanda yang salah.

Tombol **Tandai Accurate** massal hanya menyentuh baris berkeadaan "Belum".
Baris "Tidak masuk" dibiarkan — menyapunya jadi "Sudah" akan membatalkan
keputusan itu diam-diam.

Toolbar juga dapat **penyaring Accurate**: Semua / Belum / Sudah / Tidak masuk.
Pilihannya ikut terbawa ke Unduh PDF.

### Kartu "Belum tersusul"

Kartu di halaman Retur kini tiga ember yang saling lepas:

| Kartu | Artinya |
|---|---|
| **Sudah masuk stok** | baris barang masuknya ADA — stok memang naik |
| **Belum tersusul** | keterangannya menyuruh masuk stok, barisnya TIDAK ADA |
| **Belum masuk stok** | keterangannya memang menahan |

Baris "belum tersusul" digambar **amber** dengan sub-teks *belum masuk stok* —
bukan hijau seolah sudah beres. Perbaikannya lewat kotak peringatan di langkah
2 di atas.

---

## Barang deadstok  *(menu baru)*

Di grup **Operasional**, antara Retur dan Laporan stok opname. Isinya barang
yang **ada di gudang tapi tidak bisa dijual**, dua kategori:

- **Barang Rusak**
- **Barang Belum Siap Jual** (belum dipacking, belum dilabeli, dan sejenisnya)

### Mencatat deadstok TIDAK mengurangi stok

Ini yang paling perlu dipahami. Barangnya masih di rak, dan stok opname
menghitung rak — kalau mencatat deadstok langsung memotong stok, **setiap
opname akan melaporkan selisih palsu** tepat pada barang-barang itu.

Yang berkurang kolom **Siap jual**, yang ditaruh persis di samping Stok:

```
Stok   Siap jual
 201      197      ← selisihnya 4 pcs yang tertahan
```

Angka siap jual yang **minus** dicetak merah. Itu tanda stok sistemnya yang
perlu diperiksa, bukan sesuatu yang disembunyikan.

### Tiga keadaan

| Keadaan | Stok |
|---|---|
| **Masih tertahan** | tidak bergerak |
| **Sudah siap jual** | tidak bergerak |
| **Dibuang** | **berkurang** |

Hanya **Dibuang** yang menggerakkan stok, lewat satu baris Barang keluar
berketerangan `Deadstok Dibuang` — jadi koreksinya terbaca di Riwayat, bukan
berubah diam-diam. Karena memakai jalur Barang keluar biasa, **tidak bisa
membuang melebihi stok yang ada**.

Membatalkan pembuangan mengembalikan stoknya. Menghapus catatan yang sudah
dibuang juga mengembalikan stoknya.

Barang yang **belum terdaftar di master** boleh dicatat, tapi belum bisa
ditandai dibuang — daftarkan dulu di Master → Barang.

Operator boleh mencatat dan memindahkan keadaan, termasuk membuang. Mengubah
catatan yang sudah tersimpan dan menghapus tetap khusus admin.

---

## Laporan stok opname

### Nama barang di PDF sudah utuh

**908 dari 1.435 nama** di katalog ini lebih dari 28 karakter, terpanjang 93 —
jadi nama terpotong itu keadaan biasa, bukan kasus pinggiran. Sekarang teksnya
dibungkus ke beberapa baris:

```
sebelum   AYRES Jersey Bola Negara Argentina Dewasa Ka...
sesudah   AYRES Jersey Bola Negara Argentina Dewasa Kaos
          Futsal Sepakbola Dry Fit Premium Grade Ori L
```

Berlaku untuk **semua laporan PDF**, bukan hanya opname. Laporan penuh 1.435
baris jadi 49 halaman (sebelumnya ±39) — sepuluh halaman tambahan untuk nama
yang bisa dibaca.

### Bisa diisi dari HP

Tidak perlu lagi "situs versi desktop" lalu mencubit layar. Di layar sempit
tiap baris jadi **satu kartu**: nama barang jadi judulnya, tiap kolom membawa
labelnya sendiri, kolom isian dibesarkan supaya nyaman untuk jempol, dan kolom
angka memunculkan papan angka HP.

> Tampilan kartu ini baru dipasang di layar Laporan stok opname, karena di situ
> datanya diinput. Layar lain masih tabel lebar di HP.

### Stok accurate boleh minus

Kolom itu bukan hitungan rak, melainkan **salinan saldo dari Accurate** — dan
saldo pembukuan memang bisa minus, misalnya ketika barang terlanjur dikeluarkan
sebelum penerimaannya dibukukan. Sekarang angka yang benar-benar tertera di
sana bisa diketik apa adanya.

**Stok hitung tetap tidak boleh minus.** Tidak ada rak berisi minus tiga barang;
yang negatif di situ selalu salah ketik.

### Tombol massal

| Tombol | Yang terjadi |
|---|---|
| **Isi petugas** | Isi nama petugas untuk seluruh baris tersaring |
| **Centang semua** | Tandai Dicek untuk seluruh baris tersaring — tidak menyentuh stok |
| **Sesuaikan semua** | Setel "Stok Disesuaikan" — **ini menggeser stok** |
| **Batal sesuaikan** | Kembalikan ke "Tidak Disesuaikan", koreksi stoknya ikut ditarik |

Semuanya bekerja pada **seluruh baris yang lolos penyaring sekarang**, bukan
hanya yang terlihat di halaman. Jadi persempit dulu penyaringnya — misalnya per
kategori — kalau tidak ingin mengenai semuanya.

**Sesuaikan semua** menyebut jumlahnya lebih dulu: berapa baris yang akan
disesuaikan, dan berapa yang dilewati beserta sebabnya. Baris yang **belum diisi
stok hitungnya** atau **tidak terhubung ke master** dilewati, bukan
menggagalkan semuanya. Dibatasi 1.000 baris sekali jalan.

Aman diulang: menekannya dua kali tidak menaikkan stok dua kali. Dan kalau
massalnya keliru, **Batal sesuaikan** membatalkannya massal juga.

---

## Siapa boleh apa

| Tindakan | Hanya-baca | Operator | Admin |
|---|---|---|---|
| Melihat semua menu yang dicentang | ✅ | ✅ | ✅ |
| Setel stok minimal | ❌ | ✅ | ✅ |
| Catat retur (termasuk banyak produk) | ❌ | ✅ | ✅ |
| Tandai Accurate | ❌ | ✅ | ✅ |
| Catat deadstok & pindahkan keadaannya | ❌ | ✅ | ✅ |
| Isi laporan stok opname & tombol massalnya | ❌ | ✅ | ✅ |
| **Ubah catatan yang sudah tersimpan** | ❌ | ❌ | ✅ |
| **Hapus** | ❌ | ❌ | ✅ |
| **Susulkan semua retur ke stok** | ❌ | ❌ | ✅ |

Aturannya satu: mencatat hal baru boleh operator, **mengubah atau menghapus
catatan yang sudah tersimpan khusus admin** — karena yang tersimpan sudah
menggeser stok, dan perubahannya tidak meninggalkan jejak di daftarnya sendiri.
Salah catat? Catat koreksinya, atau minta admin memperbaikinya.

---

## Yang tidak berubah

- **Rumus stoknya sama**: `stok awal + masuk − keluar`. Tidak ada angka baru
  yang ikut dihitung diam-diam.
- **Dashboard dan Riwayat masih memakai stok akhir**, bukan angka siap jual
  dari Deadstok. Mengubahnya akan menggeser angka "Perlu order" yang dilihat
  tiap hari — itu keputusan tersendiri, bukan efek samping menambah satu menu.
- Impor PDF picking list, pertukaran barang, dan log aktivitas tidak disentuh.

---

## Masih menggantung

Hal-hal yang perlu keputusan atau tindakan di luar aplikasi:

1. **Kata sandi admin `admin123` tercetak di README repo publik** — ganti.
2. **Sandi database Hostinger** pernah terlihat di screenshot — putar di hPanel,
   perbarui `gudang-config.php`.
3. `sql/002_seed_master.sql` di repo publik memuat katalog 1.435 produk asli —
   pindahkan ke `deploy/`?
4. **163 barcode masih `SEMENTARA`** (dibuatkan sistem) dan perlu yang asli.
5. Kartu **SLA Design / SLA Proofing** di `crm.ayreslab.id` — aplikasi lain,
   belum saya temukan repo-nya.
6. Tampilan kartu HP baru di layar opname; layar lain menyusul bila diminta.

> Catatan: [README.md](README.md) masih dokumen teknis dari masa prototipe —
> isinya menyebut penyimpanan di browser dan 1.404 item, yang sudah lama tidak
> berlaku. Bilang kalau mau saya segarkan.

---

## Kalau ada yang aneh

Tiap galat di aplikasi ini membawa **kode 6 huruf**. Sebutkan kodenya — dengan
itu kejadiannya bisa dicari persis di **Log aktivitas**, di tabel galat sistem
di bagian bawah halaman: lengkap dengan endpoint, pesan asli dari database, dan
siapa yang mengalaminya. Jauh lebih cepat daripada menebak dari layar.

(Tabel itu hanya terlihat oleh akun yang punya akses **Log aktivitas**.)
