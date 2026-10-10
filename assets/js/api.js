/* ==========================================================================
 * api.js — pembungkus fetch, pengganti loadKey()/saveKey() milik prototipe.
 *
 * Prototipe memakai window.storage (API sandbox, bukan API browser) sehingga
 * di browser biasa semua data hilang tiap refresh — audit B1. Semua akses
 * data kini lewat endpoint PHP di folder api/.
 * ========================================================================== */

const API = (function(){

  function qs(params){
    const bersih = {};
    Object.keys(params || {}).forEach(k => {
      const v = params[k];
      if(v !== undefined && v !== null && v !== "") bersih[k] = v;
    });
    const s = new URLSearchParams(bersih).toString();
    return s ? ("?" + s) : "";
  }

  async function tangani(res){
    if(res.status === 401){
      location.href = "login.php";
      throw new Error("Sesi berakhir.");
    }
    // Endpoint kita SELALU menjawab JSON, juga saat menolak. Badan respons
    // dibaca sebagai teks lebih dulu, bukan res.json(): badan hanya boleh
    // dibaca sekali, jadi penguraian yang gagal akan menghabiskannya dan
    // buktinya hilang.
    const teks = await res.text();

    let data = null;
    try{
      data = JSON.parse(teks);
    }catch(e){
      // Sebelum menyerah, coba selamatkan.
      //
      // Yang berdiri di depan PHP kadang menempelkan byte di depan badan
      // respons: tanda urutan byte dari berkas yang tersimpan dengan BOM,
      // atau peringatan PHP yang tercetak duluan. JSON-nya sendiri utuh dan
      // masih terbaca, jadi menolak seluruh jawaban karena sampah di depannya
      // berarti menyembunyikan pesan server yang sebenarnya — dan itu yang
      // membuat penolakan izin biasa tampak seperti gangguan hosting.
      const bersih = teks.replace(/^[﻿\s\0]+/, "");
      const mulai  = bersih.indexOf("{");
      if(mulai >= 0){
        try{ data = JSON.parse(bersih.slice(mulai)); }catch(e2){ data = null; }
      }
    }

    if(data === null){
      // Tidak ada JSON sama sekali. Jawaban mentahnya ikut ditampilkan, bukan
      // hanya dicatat ke konsol: yang memakai aplikasi ini petugas gudang,
      // dan tebakan tentang sebabnya pernah menyesatkan. Biar server sendiri
      // yang bicara.
      console.error("Respons bukan JSON", res.status, teks.slice(0, 500));
      const cuplikan = teks.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 140);
      const err = new Error("Server menjawab tanpa JSON (HTTP " + res.status + ", "
        + teks.length + " byte). "
        + (cuplikan ? "Jawabannya: “" + cuplikan + "”" : "Jawabannya kosong.")
        + " Tunjukkan pesan ini apa adanya.");
      err.status  = res.status;
      err.nonJson = true;          // dipakai pemanggil untuk menjalankan uji sendiri
      err.mentah  = teks.slice(0, 500);
      throw err;
    }
    if(!res.ok || !data.ok){
      const err = new Error(data.error || ("Permintaan gagal (HTTP " + res.status + ")."));
      err.status = res.status;
      err.detail = data.detail || null;
      err.data   = data;
      // Kode galat dan pesan teknisnya dipisah, supaya layar bisa
      // menampilkannya tanpa membedah isi jawaban sendiri.
      err.kodeGalat = data.kode_galat || null;
      err.teknis    = data.teknis || null;
      throw err;
    }
    return data;
  }

  async function get(path, params){
    const res = await fetch("api/" + path + qs(params), {
      credentials: "same-origin",
      headers: { "Accept": "application/json" }
    });
    return tangani(res);
  }

  async function post(path, body){
    const res = await fetch("api/" + path, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": window.CSRF_TOKEN || ""
      },
      body: JSON.stringify(Object.assign({ _csrf: window.CSRF_TOKEN || "" }, body || {}))
    });
    return tangani(res);
  }

  return {
    get: get,
    post: post,

    dashboard : (p)  => get("dashboard/stats.php", p),

    masterList: (p)  => get("master/list.php", p),
    masterPick: (q)  => get("master/list.php", { picker: 1, q: q }),
    masterSave: (b)  => post("master/save.php", b),
    masterDel : (id) => post("master/delete.php", { id: id }),

    trxList   : (jenis, p)  => get(jenis + "/list.php", p),
    trxCreate : (jenis, b)  => post(jenis + "/create.php", b),
    trxDelete : (jenis, id) => post(jenis + "/delete.php", { id: id }),

    importCek : (b) => post("import/check.php", b),
    importSave: (b) => post("import/commit.php", b)
  };
})();
