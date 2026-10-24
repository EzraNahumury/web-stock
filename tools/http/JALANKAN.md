# Uji HTTP — menghantam aplikasi yang benar-benar berjalan

Berbeda dengan `tools/uji_html.js` dan `tools/uji_layar.js`, yang menggambar
layar di dalam DOM tiruan, suite di sini **menembak endpoint sungguhan lewat
HTTP**, memeriksa hasilnya langsung di basis data, lalu mengembalikan keadaan
basis data seperti semula.

## Kenapa ada di repo, bukan di folder sementara

Suite ini dulu ditulis dan dijalankan dari folder sementara. Pada 24 Oktober
2026 folder itu dibersihkan sistem dan **sebelas suite hilang sekaligus** —
termasuk yang menjaga pencatatan retur, pencabutan centang keterangan, dan
penghapusan catatan pertukaran. Yang hilang tidak bisa dikembalikan, dan
cakupannya baru ketahuan hilang ketika dibutuhkan.

Karena itu suite yang tersisa dipindah ke sini. Uji yang hanya hidup di folder
sementara bukan uji; ia hanya kebetulan pernah lulus.

## Menjalankan

Butuh dua hal: basis data hidup, dan satu server PHP.

```bash
php -S 127.0.0.1:8099 -t .
node tools/http/uji_tersusul.cjs
```

Seluruhnya sekaligus:

```bash
for f in tools/http/uji_*.cjs; do printf '%-28s ' "$f"; node "$f" | tail -1; done
```

Alamat lain lewat `UJI_BASE`. Ini yang dipakai untuk mengujinya pada collation
yang berbeda dari mesin pengembangan — bentuk galat yang dulu menjatuhkan
Dashboard di server dan tidak pernah bisa ditiru secara lokal:

```bash
WMS_DB_COLLATION=utf8mb4_unicode_ci php -S 127.0.0.1:8100 -t .
UJI_BASE=http://127.0.0.1:8100 node tools/http/uji_tersusul.cjs
```

## Isinya

| Berkas | Yang dijaga |
|---|---|
| `uji_tersusul.cjs` | Retur yang keterangannya mengembalikan barang ke stok tapi barang masuknya tidak pernah dibuat; penyusulan lewat tombol maupun lewat centang di Master |
| `uji_retur_banyak.cjs` | Satu pesanan berisi beberapa produk, termasuk pembatalan penuh saat satu produk ditolak |
| `uji_accurate3.cjs` | Penanda Accurate tiga keadaan, dan "tidak masuk" yang tidak boleh terhitung sebagai "belum" |
| `uji_deadstok.cjs` | Barang deadstok: mencatatnya tidak menggerakkan stok, hanya "dibuang" yang menggerakkan |
| `uji_minimal.cjs` | Setel ambang stok minimal, satuan maupun massal |

## Aturan menulis suite di sini

1. **Kembalikan keadaan basis data.** Catat `MAX(id)` di awal, hapus yang di
   atasnya di akhir, dan tegaskan dengan `cek()` bahwa keadaannya benar-benar
   kembali. Suite yang meninggalkan sampah membuat suite berikutnya gagal
   karena alasan yang salah.
2. **Pasang sendiri keadaan yang diasumsikan.** Jangan mengandalkan isi tabel
   `keterangan` apa adanya — dua suite di sini sengaja mengubahnya. Suite yang
   lulus-tidaknya bergantung pada urutan jalan lebih buruk daripada tidak ada
   suite sama sekali.
3. **Beri penanda pada baris yang dibuat**, lalu saring dengan penanda itu.
   Angka ringkasan dihitung atas seluruh hasil penyaring; tanpa penanda ia ikut
   menghitung data nyata.
4. **Periksa basis datanya, bukan hanya jawaban HTTP-nya.** Seluruh bug stok
   yang pernah lolos di proyek ini lolos karena jawabannya 200 dan terlihat
   benar.
