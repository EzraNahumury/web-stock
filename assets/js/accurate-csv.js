/* ==========================================================================
 * assets/js/accurate-csv.js — baca ekspor Accurate berupa CSV / Excel-CSV
 *
 * KENAPA ADA, PADAHAL SUDAH ADA PEMBACA PDF
 * Impor Accurate selama ini hanya menerima PDF, dan PDF tidak memuat kode
 * barang — jadi satu-satunya pegangan adalah nama, dan nama itu pegangan yang
 * rapuh: beda satu spasi atau satu kata saja sudah tidak ketemu.
 *
 * Ekspor Accurate ke CSV/Excel memuat kolom kode barang. Dengan kode itu,
 * pencocokannya persis — tidak bisa salah orang. Berkasnya juga dibaca apa
 * adanya, tanpa menebak koordinat teks seperti pada PDF.
 *
 * YANG DITOLERANSI
 * Berkas dari Accurate tidak selalu rapi. Yang ditangani di sini:
 *   - pemisah koma ATAU titik koma, dikenali sendiri dari baris judulnya
 *   - baris "sep=;" di paling atas (arahan Excel) — dilewati
 *   - BOM UTF-8 di awal berkas
 *   - beberapa baris kop sebelum baris judul kolom; judulnya dicari, bukan
 *     diandaikan ada di baris pertama
 *   - sel berkutip yang memuat pemisah atau ganti baris di dalamnya
 *   - angka bergaya Indonesia: "1.234" dan "1.234,56"
 *
 * Berkasnya TIDAK diunggah ke server. Yang dikirim hanya kode, nama, dan
 * kuantitas — sama seperti pembaca PDF-nya, dengan alasan yang sama: ekspor
 * Accurate memuat harga pokok per barang, dan menyimpannya di server tanpa
 * keperluan hanya menambah tempat data itu bisa bocor.
 * ========================================================================== */

(function (global) {
  'use strict';

  /** Pecah satu berkas CSV jadi larik baris berisi larik sel. */
  function pecah(teks, pemisah) {
    const baris = [];
    let sel = [];
    let kini = '';
    let dalamKutip = false;

    for (let i = 0; i < teks.length; i++) {
      const c = teks[i];

      if (dalamKutip) {
        if (c === '"') {
          // Dua kutip berturut-turut berarti satu kutip di dalam isinya.
          if (teks[i + 1] === '"') { kini += '"'; i++; }
          else { dalamKutip = false; }
        } else {
          kini += c;
        }
        continue;
      }

      if (c === '"') { dalamKutip = true; }
      else if (c === pemisah) { sel.push(kini); kini = ''; }
      else if (c === '\n') { sel.push(kini); baris.push(sel); sel = []; kini = ''; }
      else if (c !== '\r') { kini += c; }
    }
    sel.push(kini);
    baris.push(sel);

    return baris.filter(r => r.some(x => String(x).trim() !== ''));
  }

  /** Pemisah mana yang dipakai berkas ini. */
  function tebakPemisah(teks) {
    const arahan = teks.match(/^\s*sep=(.)\s*$/m);
    if (arahan) { return arahan[1]; }
    // Dihitung di luar kutip terlalu rumit untuk gunanya; yang terbanyak di
    // 20 baris pertama sudah cukup untuk membedakan koma dari titik koma.
    const awal = teks.split('\n').slice(0, 20).join('\n');
    const koma = (awal.match(/,/g) || []).length;
    const titik = (awal.match(/;/g) || []).length;
    return titik > koma ? ';' : ',';
  }

  const rapikan = (s) => String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
  const kunci = (s) => rapikan(s).toUpperCase();

  /* Judul kolom yang dicari. Accurate dalam bahasa Indonesia maupun Inggris,
   * dan beberapa laporan memakai istilah yang berbeda untuk hal yang sama. */
  const JUDUL = {
    kode: ['NO. BARANG', 'NO BARANG', 'KODE BARANG', 'KODE', 'ITEM NO', 'ITEM NO.',
           'ITEM CODE', 'NO. ITEM', 'SKU'],
    nama: ['NAMA BARANG', 'NAMA', 'DESKRIPSI', 'KETERANGAN BARANG',
           'ITEM NAME', 'DESCRIPTION'],
    qty:  ['KUANTITAS', 'KUANTITI', 'QTY', 'QUANTITY', 'SALDO', 'JUMLAH',
           'STOK', 'STOCK', 'ENDING QTY', 'SALDO AKHIR'],
  };

  /** Cari baris judul kolom, lalu petakan kolomnya. */
  function cariJudul(baris) {
    const batas = Math.min(baris.length, 30);
    for (let i = 0; i < batas; i++) {
      const sel = baris[i].map(kunci);
      const cari = (daftar) => {
        for (let j = 0; j < sel.length; j++) {
          if (daftar.indexOf(sel[j]) >= 0) { return j; }
        }
        return -1;
      };
      const iNama = cari(JUDUL.nama);
      const iQty  = cari(JUDUL.qty);
      // Nama dan kuantitas wajib; kode boleh tidak ada (laporan tertentu
      // memang tidak memuatnya) dan pencocokannya jatuh ke nama.
      if (iNama >= 0 && iQty >= 0) {
        return { baris: i, kode: cari(JUDUL.kode), nama: iNama, qty: iQty };
      }
    }
    return null;
  }

  /**
   * Angka bergaya Indonesia maupun Inggris.
   *
   * "1.234" itu seribu dua ratus tiga puluh empat, bukan 1,234. Dibedakan dari
   * posisinya: pemisah terakhir yang diikuti tepat tiga angka adalah pemisah
   * ribuan, selain itu pemisah desimal.
   */
  function keAngka(s) {
    let t = rapikan(s).replace(/[^\d,.\-]/g, '');
    if (t === '' || t === '-') { return null; }

    const koma = t.lastIndexOf(',');
    const titik = t.lastIndexOf('.');
    const akhir = Math.max(koma, titik);

    if (akhir >= 0) {
      const ekor = t.length - akhir - 1;
      if (ekor === 3) {
        // Pemisah ribuan: semuanya dibuang.
        t = t.replace(/[,.]/g, '');
      } else {
        // Pemisah desimal: yang di depannya ribuan.
        t = t.slice(0, akhir).replace(/[,.]/g, '') + '.' + t.slice(akhir + 1);
      }
    }
    const n = Number(t);
    return Number.isFinite(n) ? n : null;
  }

  /**
   * Baca berkas CSV jadi baris siap kirim.
   *
   * @returns {{rows: Array<{kode:string,nama:string,qty:number}>,
   *            kolom: object, dilewati: number, adaKode: boolean}}
   */
  function baca(teks) {
    if (teks.charCodeAt(0) === 0xFEFF) { teks = teks.slice(1); }

    const pemisah = tebakPemisah(teks);
    const baris = pecah(teks, pemisah);
    const kolom = cariJudul(baris);

    if (!kolom) {
      throw new Error(
        'Tidak ketemu baris judul kolomnya. Berkas ini perlu punya kolom nama '
        + 'barang dan kuantitas — misalnya "Nama Barang" dan "Kuantitas". '
        + 'Ekspor ulang dari Accurate sebagai CSV beserta baris judulnya.'
      );
    }

    const rows = [];
    let dilewati = 0;

    for (let i = kolom.baris + 1; i < baris.length; i++) {
      const r = baris[i];
      const nama = rapikan(r[kolom.nama]);
      const kode = kolom.kode >= 0 ? rapikan(r[kolom.kode]) : '';
      const qty = keAngka(r[kolom.qty]);

      /* Baris tanpa angka dilewati diam-diam: ekspor Accurate memuat baris
       * subtotal dan baris judul kelompok di tengah tabelnya, dan itu bukan
       * kesalahan yang perlu dilaporkan. */
      if ((nama === '' && kode === '') || qty === null) { dilewati++; continue; }

      rows.push({ kode: kode, nama: nama, qty: Math.round(qty) });
    }

    return { rows: rows, kolom: kolom, dilewati: dilewati, adaKode: kolom.kode >= 0 };
  }

  global.AccurateCsv = { baca: baca, keAngka: keAngka, pecah: pecah };
})(typeof window !== 'undefined' ? window : globalThis);
