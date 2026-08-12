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
    let data;
    try{
      data = await res.json();
    }catch(e){
      throw new Error("Respons server tidak bisa dibaca (HTTP " + res.status + ").");
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
