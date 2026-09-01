<?php
// /vip-registrace/index.php
?><!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>VIP registrace – C-Store.cz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--w:760px;--pad:16px;--gap:8px;--r:10px;--b:#4caf50;--bh:#449d48}
    *{box-sizing:border-box}
    html{background:#fff;margin:0;padding:0;}
    body{font-family:Arial,Helvetica,sans-serif;background:#fff;margin:0;color:#222}
    .container{background:#fff;max-width:var(--w);margin:18px auto;padding:var(--pad);border-radius:var(--r);box-shadow:0 2px 10px rgba(0,0,0,.08)}
    h2{margin:0 0 8px;font-size:18px}
    label{display:block;margin-top:10px;font-weight:bold;font-size:13px}
    input[type="text"],input[type="email"]{width:100%;padding:9px;border:1px solid #ccc;border-radius:8px}
    button{background:var(--b);color:#fff;border:0;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:700}
    button:hover{background:var(--bh)}
    .message{margin-top:10px;font-weight:bold;font-size:13px}
    .error{color:#d93025}.success{color:#0f9d58}
    .info{border:1px solid #b7d7ff;background:#eef6ff;border-radius:8px;padding:10px;margin-top:10px;font-size:13px}
  </style>
</head>
<body>
  <div class="container">
    <h2>VIP REGISTRACE - C-Store.cz</h2>

    <div class="info">
      Vyplňte prosím všechny údaje. Po odeslání vám účet aktivujeme do 24 hodin a do e-mailu vám přijde odkaz na vytvoření hesla.
    </div>

    <form id="vip-form" autocomplete="off">
      <label for="full_name">Jméno a příjmení</label>
      <input type="text" id="full_name" autocomplete="name">

      <label for="company_name">Firma / Oddíl</label>
      <input type="text" id="company_name" autocomplete="organization">

      <label for="email">E-mail</label>
      <input type="email" id="email" autocomplete="email">

      <div style="margin-top:12px">
        <button type="submit" id="btn-save">Odeslat</button>
      </div>

      <div id="save-status" class="message"></div>
    </form>
  </div>

<script>
(function(){
  const $ = id => document.getElementById(id);

  function apiUrl(path){
    const origin = window.location.origin;
    let p = window.location.pathname || '/';
    if (!p.endsWith('/')) p = p.replace(/\/[^\/]*$/, '/');
    if (!p.endsWith('/')) p += '/';
    return origin + p + path.replace(/^\//,'');
  }

  function validEmail(v){
    return /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(String(v || '').trim());
  }

  async function saveVip(e){
    if (e) e.preventDefault();
    const status = $('save-status');
    status.className = 'message';
    status.textContent = '';

    const fullName = ($('full_name').value || '').trim();
    const companyName = ($('company_name').value || '').trim();
    const email = ($('email').value || '').trim();

    if (!fullName || !companyName || !email) {
      status.className = 'message error';
      status.textContent = 'Vyplňte prosím všechna pole.';
      return;
    }

    if (!validEmail(email)) {
      status.className = 'message error';
      status.textContent = 'Zadejte prosím platný e-mail.';
      return;
    }

    status.className = 'message success';
    status.textContent = 'Odesílám…';

    const payload = {
      fullName: fullName,
      companyName: companyName,
      email: email
    };

    const url = apiUrl('api/submit.php');

    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });

      const text = await r.text();
      let j = null;
      try { j = JSON.parse(text); } catch(err) {
        status.className = 'message error';
        status.textContent = 'API nevrátilo JSON. URL: ' + url + ' | HTTP ' + r.status + ' | ukázka: ' + text.slice(0,120);
        return;
      }

      if (!r.ok || !j.success) {
        status.className = 'message error';
        status.textContent = (j && (j.message || j.error)) ? (j.message || j.error) : ('HTTP ' + r.status + ' – chyba');
        return;
      }

      status.className = 'message success';
      status.textContent = j.message || 'Děkujeme. Váš účet bude aktivní do 24 hodin. Do emailu Vám přijde odkaz na vytvoření hesla.';
      $('vip-form').reset();

    } catch(err) {
      console.error(err);
      status.className = 'message error';
      status.textContent = 'Chyba při odeslání: ' + (err && err.message ? err.message : err);
    }
  }

  window.addEventListener('DOMContentLoaded', function(){
    $('vip-form').addEventListener('submit', saveVip);
    $('full_name').focus();
  });
})();
</script>
</body>
</html>
