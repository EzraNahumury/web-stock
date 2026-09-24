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
    // Endpoint kita SELALU menjawab JSON, juga saat menolak. Jadi badan
    // respons yang tidak bisa diurai berarti yang menolak bukan aplikasi ini,
    // melainkan lapisan di depannya: pengaman hosting (mod_security /
    // Imunify), pembatas ukuran permintaan, atau proses PHP yang mati.
    // Pesannya menyebut itu, supaya yang membacanya tidak mengira haknya
    // kurang lalu sia-sia menyuruh admin menambah centang menu.
    //
    // Badan respons dibaca sebagai teks lebih dulu, bukan res.json(). Badan
    // hanya boleh dibaca sekali, jadi mengurai JSON yang gagal akan
    // menghabiskannya dan cuplikan untuk diagnosa hilang.
    const teks = await res.text();
    let data;
    try{
      data = JSON.parse(teks);
    }catch(e){
      console.error("Respons bukan JSON", res.status, teks.slice(0, 300));
      throw new Error(res.status === 403
        ? "Permintaan diblokir sebelum sampai ke aplikasi (HTTP 403). "
          + "Biasanya ini pengaman hosting, bukan hak akses akun. Coba lagi; "
          + "bila tetap gagal, tunjukkan pesan ini ke admin server."
        : "Respons server tidak bisa dibaca (HTTP " + res.status + ").");
    }
    if(!res.ok || !data.ok){
      const err = new Error(data.error || ("Permintaan gagal (HTTP " + res.status + ")."));
      err.status = res.status;
      err.detail = data.detail || null;
      err.data   = data;
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
