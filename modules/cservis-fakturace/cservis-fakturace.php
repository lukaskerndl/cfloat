<?php
require_once __DIR__ . '/lib.php';
$hasXlsx = is_file(csf_products_xlsx_path());
$hasCache = is_file(csf_products_cache_file());
$productCount = 0;
try {
    $cache = csf_json_load(csf_products_cache_file(), []);
    if (is_array($cache['products'] ?? null)) $productCount = count($cache['products']);
} catch (Throwable $e) {
    $productCount = 0;
}
$todayDt = new DateTime('now', new DateTimeZone('Europe/Prague'));
$today = $todayDt->format('Y-m-d');
$monthStart = (clone $todayDt)->modify('first day of this month')->format('Y-m-d');
$monthEnd = (clone $todayDt)->modify('last day of this month')->format('Y-m-d');
?>

<style>
.csf-page { width:100%; }
.csf-head { display:flex; gap:14px; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; margin-bottom:14px; }
.csf-title h1 { margin:0; font-size:28px; }
.csf-title p { margin:5px 0 0; color:#555; font-size:13px; }
.csf-grid { display:grid; grid-template-columns:minmax(350px, 0.92fr) minmax(680px, 1.48fr); gap:16px; align-items:start; }
.csf-panel { background:#fff; border-radius:20px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,.10); }
.csf-panel h2 { margin:0 0 12px; font-size:18px; }
.csf-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.csf-search-bar { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:10px; align-items:end; }
.csf-field { display:flex; flex-direction:column; gap:4px; flex:1; min-width:160px; }
.csf-field label { font-size:12px; color:#555; font-weight:700; }
.csf-field input, .csf-field select, .csf-field textarea { width:100%; box-sizing:border-box; border:1px solid #d8dedb; border-radius:10px; padding:9px 10px; font:inherit; background:#fff; }
.csf-field textarea { min-height:58px; resize:vertical; }
.csf-btn { border:0; border-radius:999px; padding:10px 14px; cursor:pointer; font-weight:800; background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; white-space:nowrap; }
.csf-btn.secondary { background:#111; color:#fff; }
.csf-btn.light { background:#eef8f0; color:#097a28; border:1px solid #bce8c5; }
.csf-btn.warn { background:#fff8e6; color:#9a6700; border:1px solid #f2d288; }
.csf-btn.tiny { padding:3px 6px; font-size:10px; line-height:1.05; border-radius:12px; }
.csf-btn.compact { padding:8px 14px; font-size:13px; min-width:auto; }
.csf-btn.danger { background:#fff0f0; color:#b42318; border:1px solid #f6c8c8; }
.csf-btn[disabled] { opacity:.55; cursor:default; }
.csf-msg { padding:10px 12px; border-radius:12px; margin:10px 0; font-size:13px; }
.csf-msg.ok { background:#ecfdf3; color:#067a30; border:1px solid #bce8c5; }
.csf-msg.err { background:#fff0f0; color:#b42318; border:1px solid #f6c8c8; }
.csf-muted { color:#666; font-size:10.5px; }
.csf-results { display:flex; flex-direction:column; gap:8px; max-height:650px; overflow:auto; padding-right:2px; }
.csf-product { border:1px solid #e6ebe8; border-radius:14px; padding:10px; display:grid; grid-template-columns:1fr auto; gap:8px; align-items:start; background:#fff; }
.csf-product strong { display:block; font-size:14px; }
.csf-product small { color:#666; display:block; margin-top:3px; line-height:1.3; }
.csf-code { font-weight:900; color:#0b9150; }
.csf-price { font-weight:900; white-space:nowrap; }
.csf-table-wrap { width:100%; overflow:auto; border:1px solid #edf1ee; border-radius:14px; }
.csf-table { width:100%; border-collapse:collapse; font-size:12px; min-width:920px; }
.csf-table th, .csf-table td { border-bottom:1px solid #edf1ee; padding:8px; vertical-align:middle; }
.csf-table th { background:#f4fbf5; text-align:left; font-size:11px; color:#334; }
.csf-table input { width:100%; box-sizing:border-box; border:1px solid #d8dedb; border-radius:8px; padding:7px 8px; font:inherit; }
.csf-table .num { text-align:right; white-space:nowrap; }
.csf-totals { margin-top:12px; display:flex; justify-content:flex-end; }
.csf-totals table { min-width:340px; font-size:14px; }
.csf-totals td { padding:4px 0; }
.csf-totals td:last-child { text-align:right; font-weight:900; }
.csf-recent { margin-top:14px; }
.csf-recent-columns { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:10px; }
.csf-series-col { border:1px solid #edf1ee; border-radius:16px; padding:8px; background:#fafdfb; }
.csf-series-head { display:flex; justify-content:space-between; align-items:center; gap:7px; margin-bottom:6px; }
.csf-series-head h3 { margin:0; font-size:14px; }
.csf-recent-list { display:grid; gap:4px; }
.csf-invoice-card { border:1px solid #e7ece9; border-radius:11px; padding:4px 6px; font-size:10px; background:#fff; display:grid; grid-template-columns:64px 70px 52px minmax(105px,1fr) 94px auto; gap:5px; align-items:center; position:relative; min-height:27px; }
.csf-invoice-card strong { display:block; font-size:10.5px; line-height:1.05; }
.csf-invoice-card.is-new { background:#fff8d6 !important; border-color:#f1c75b !important; }
.csf-card-actions { display:flex; gap:3px; flex-wrap:nowrap; justify-content:flex-end; align-items:center; }
.csf-card-sub { color:#555; line-height:1.1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.csf-card-money { color:#111; line-height:1.1; white-space:nowrap; font-size:10px; }
.csf-card-money b { font-weight:900; }
.csf-card-col { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.csf-hover-preview { position:absolute; left:8px; top:calc(100% + 7px); min-width:min(640px, 92vw); max-width:760px; background:#fff; border:1px solid #cfe3d4; box-shadow:0 10px 28px rgba(0,0,0,.18); border-radius:14px; padding:10px; z-index:50; opacity:0; visibility:hidden; transform:translateY(-3px); transition:opacity .15s ease .55s, visibility .15s ease .55s, transform .15s ease .55s; pointer-events:none; }
.csf-invoice-card:hover .csf-hover-preview { opacity:1; visibility:visible; transform:translateY(0); }
.csf-hover-preview h4 { margin:0 0 6px; font-size:13px; }
.csf-hover-preview table { width:100%; border-collapse:collapse; font-size:11px; }
.csf-hover-preview th, .csf-hover-preview td { border-bottom:1px solid #edf1ee; padding:5px 4px; text-align:left; vertical-align:top; }
.csf-hover-preview th { background:#f4fbf5; font-size:10px; }
.csf-hover-preview .num { text-align:right; white-space:nowrap; }

.csf-recent-top { display:grid; grid-template-columns:auto auto auto auto auto minmax(220px,1fr) auto; gap:9px; align-items:center; }
.csf-invoice-search { display:grid; grid-template-columns:minmax(160px,1fr) auto; gap:8px; align-items:center; }
.csf-date-filter { display:flex; align-items:center; gap:5px; white-space:nowrap; font-size:12px; color:#555; }
.csf-date-filter input { width:132px; box-sizing:border-box; border:1px solid #d8dedb; border-radius:999px; padding:8px 10px; font:inherit; background:#fff; }
.csf-invoice-search input { width:100%; box-sizing:border-box; border:1px solid #d8dedb; border-radius:999px; padding:9px 12px; font:inherit; }
.csf-detail { margin-top:14px; display:none; }
.csf-report-page { margin-top:14px; display:none; }
.csf-report-top { display:grid; grid-template-columns:auto auto auto auto minmax(220px,1fr) auto auto; gap:9px; align-items:center; margin-bottom:12px; }
.csf-report-search { display:grid; grid-template-columns:minmax(180px,1fr) auto; gap:8px; align-items:center; }
.csf-report-search input { width:100%; box-sizing:border-box; border:1px solid #d8dedb; border-radius:999px; padding:9px 12px; font:inherit; }
.csf-report-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:8px; margin:10px 0 12px; }
.csf-report-box { border:1px solid #d9e5dd; background:#fbfdfb; border-radius:14px; padding:10px; }
.csf-report-box small { display:block; color:#666; font-size:11px; margin-bottom:3px; }
.csf-report-box b { font-size:16px; }
.csf-report-table-wrap { overflow:auto; border:1px solid #edf1ee; border-radius:14px; }
.csf-report-table { width:100%; min-width:980px; border-collapse:collapse; font-size:11px; table-layout:auto; }
.csf-report-table th, .csf-report-table td { border-bottom:1px solid #edf1ee; padding:5px 6px; vertical-align:middle; white-space:nowrap; }
.csf-report-table th { background:#f4fbf5; text-align:left; font-size:10.5px; }
.csf-report-table .num { text-align:right; white-space:nowrap; }
.csf-report-item { cursor:pointer; font-weight:700; max-width:260px; overflow:hidden; text-overflow:ellipsis; }
.csf-report-name { max-width:155px; overflow:hidden; text-overflow:ellipsis; }
.csf-report-code { max-width:70px; overflow:hidden; text-overflow:ellipsis; }
.csf-detail-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; margin-bottom:10px; }
.csf-profit-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:8px; margin:10px 0 12px; }
.csf-profit-box { border:1px solid #d9e5dd; background:#fbfdfb; border-radius:14px; padding:10px; }
.csf-profit-box small { display:block; color:#666; font-size:11px; margin-bottom:3px; }
.csf-profit-box b { font-size:16px; }
.csf-detail-table-wrap { overflow:auto; border:1px solid #edf1ee; border-radius:14px; }
.csf-detail-table { width:100%; min-width:980px; border-collapse:collapse; font-size:12px; }
.csf-detail-table th, .csf-detail-table td { border-bottom:1px solid #edf1ee; padding:8px; vertical-align:top; }
.csf-detail-table th { background:#f4fbf5; text-align:left; font-size:11px; }
.csf-detail-table .num { text-align:right; white-space:nowrap; }
.csf-card-items { color:#444; font-size:11px; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; }
@media (max-width: 1100px) { .csf-grid { grid-template-columns:1fr; } .csf-recent-columns, .csf-recent-columns-3 { grid-template-columns:1fr; } }
@media (max-width: 760px) { .csf-invoice-card { grid-template-columns:1fr; } .csf-card-actions { flex-wrap:wrap; justify-content:flex-start; } .csf-recent-top { grid-template-columns:1fr; } .csf-invoice-search { grid-template-columns:1fr; } .csf-report-top { grid-template-columns:1fr; } .csf-report-search { grid-template-columns:1fr; } }
@media (max-width: 700px) { .csf-title h1 { font-size:22px; } .csf-panel { padding:13px; } .csf-product { grid-template-columns:1fr; } .csf-search-bar { grid-template-columns:1fr; } }
</style>

<div class="csf-page">
  <div class="csf-head">
    <div class="csf-title">
      <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
      <h1>Cservis FAKTURACE</h1>
      <p>Vyhledání položek z databáze SERVIS_ALL.xlsx, automatická číselná řada a PDF faktura.</p>
    </div>
    <div>
      <button type="button" class="csf-btn light" id="csfRebuild">Obnovit cache produktů</button>
    </div>
  </div>

  <?php if ($hasXlsx): ?>
    <div class="csf-msg ok">Databáze produktů je nahraná. <?php echo $productCount ? 'Cache má ' . (int)$productCount . ' položek.' : 'Cache se vytvoří při prvním hledání.'; ?></div>
  <?php elseif ($hasCache && $productCount): ?>
    <div class="csf-msg ok">Databáze XLSX není na serveru, ale je nahraná produktová cache: <?php echo (int)$productCount; ?> položek. Hledání bude fungovat z cache.</div>
  <?php else: ?>
    <div class="csf-msg err">Chybí databáze produktů: <b>modules/cservis-fakturace/data/SERVIS_ALL.xlsx</b>. Nahraj celou složku <b>modules/cservis-fakturace/data/</b> z ZIPu.</div>
  <?php endif; ?>

  <div class="csf-grid">
    <section class="csf-panel">
      <h2>Vybrat produkt</h2>
      <div class="csf-search-bar">
        <div class="csf-field">
          <label for="csfSearch">Hledat podle objednacího čísla / kódu / EAN / názvu / popisu</label>
          <input type="text" id="csfSearch" placeholder="např. EAN, MS790, 20min, plášť, brzda…" autocomplete="off">
        </div>
        <button type="button" class="csf-btn" id="csfSearchBtn">Hledat</button>
      </div>
      <div id="csfSearchMsg" class="csf-muted" style="margin:10px 0;"></div>
      <div id="csfResults" class="csf-results"></div>
    </section>

    <section class="csf-panel">
      <h2>Nová faktura</h2>
      <div id="csfCreateMsg"></div>
      <div class="csf-row">
        <div class="csf-field">
          <label for="csfCustomer">Jméno za „C-Servis“</label>
          <input type="text" id="csfCustomer" placeholder="např. Kerndl">
        </div>
        <div class="csf-field">
          <label for="csfPhone">Telefon</label>
          <input type="text" id="csfPhone" placeholder="např. 777 123 456">
        </div>
        <div class="csf-field">
          <label for="csfPayment">Platba / číselná řada</label>
          <select id="csfPayment">
            <option value="cash">Hotově - řada 7</option>
            <option value="card">Kartou - řada 9</option>
          </select>
        </div>
        <div class="csf-field" style="max-width:180px;">
          <label for="csfIssueDate">Datum vystavení</label>
          <input type="date" id="csfIssueDate" value="<?php echo csf_h($today); ?>">
        </div>
      </div>

      <div class="csf-row" style="margin-top:10px;">
        <div class="csf-field" style="flex:2; min-width:280px;">
          <label for="csfAddress">Adresa odběratele (volitelné)</label>
          <textarea id="csfAddress" placeholder="Ulice, město…"></textarea>
        </div>
        <div class="csf-field" style="max-width:170px;">
          <label for="csfIco">IČO odběratele</label>
          <input type="text" id="csfIco">
        </div>
        <div class="csf-field" style="max-width:190px;">
          <label for="csfDic">DIČ odběratele</label>
          <input type="text" id="csfDic">
        </div>
      </div>

      <div class="csf-table-wrap" style="margin-top:12px;">
        <table class="csf-table" id="csfItemsTable">
          <thead>
            <tr>
              <th style="width:110px;">Kód produktu</th>
              <th>Název</th>
              <th style="width:75px;">Ks</th>
              <th style="width:115px;">Cena/MJ s DPH</th>
              <th style="width:80px;">Sleva %</th>
              <th style="width:115px;">Cena/MJ bez DPH</th>
              <th style="width:120px;">Celkem s DPH</th>
              <th style="width:70px;">Akce</th>
            </tr>
          </thead>
          <tbody id="csfItemsBody">
            <tr class="csf-empty"><td colspan="8" class="csf-muted">Zatím není přidaná žádná položka.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="csf-totals">
        <table>
          <tr><td>Celkem bez DPH</td><td id="csfTotalNet">0,00 Kč</td></tr>
          <tr><td>DPH 21 %</td><td id="csfTotalVat">0,00 Kč</td></tr>
          <tr><td><b>Celkem s DPH</b></td><td id="csfTotalGross">0,00 Kč</td></tr>
        </table>
      </div>

      <div class="csf-field" style="margin-top:10px;">
        <label for="csfNote">Poznámka na fakturu (volitelné)</label>
        <textarea id="csfNote"></textarea>
      </div>

      <div class="csf-row" style="justify-content:flex-end; margin-top:12px;">
        <button type="button" class="csf-btn secondary" id="csfClear">Vyčistit</button>
        <button type="button" class="csf-btn" id="csfCreate">Vytvořit PDF fakturu</button>
      </div>
    </section>
  </div>

  <section class="csf-panel csf-recent">
    <div class="csf-recent-top">
      <h2 style="margin:0;">Poslední faktury</h2>
      <button type="button" class="csf-btn secondary compact" id="csfOpenReport">Přehled položek / zisk</button>
      <label class="csf-date-filter">Od <input type="date" id="csfInvoiceDateFrom" value="<?php echo csf_h($monthStart); ?>"></label>
      <label class="csf-date-filter">Do <input type="date" id="csfInvoiceDateTo" value="<?php echo csf_h($monthEnd); ?>"></label>
      <button type="button" class="csf-btn light compact" id="csfPohodaExportMain">Export Pohoda XML řada 9</button>
      <div class="csf-invoice-search">
        <input type="text" id="csfInvoiceSearch" placeholder="Hledat fakturu: jméno, telefon, číslo, cena, zboží…">
        <button type="button" class="csf-btn light" id="csfInvoiceSearchBtn">Hledat</button>
      </div>
      <button type="button" class="csf-btn light" id="csfReloadList">Načíst znovu</button>
    </div>
    <div class="csf-recent-columns csf-recent-columns-3">
      <div class="csf-series-col">
        <div class="csf-series-head">
          <h3>Nová</h3>
          <span class="csf-muted">Nově vytvořené faktury</span>
        </div>
        <div id="csfRecentNew" class="csf-recent-list"></div>
      </div>
      <div class="csf-series-stack">
        <div class="csf-series-col">
          <div class="csf-series-head">
            <h3>Řada 9 – Kartou</h3>
            <span class="csf-muted">Faktury začínající 9</span>
          </div>
          <div id="csfRecent9" class="csf-recent-list"></div>
        </div>
        <div class="csf-series-col">
          <div class="csf-series-head">
            <h3>Řada 7 – Hotově</h3>
            <span class="csf-muted">Faktury začínající 7</span>
          </div>
          <div id="csfRecent7" class="csf-recent-list"></div>
        </div>
      </div>
    </div>
  </section>

  <section class="csf-panel csf-report-page" id="csfReportPage">
    <div class="csf-report-top">
      <h2 style="margin:0;">Přehled položek a zisku</h2>
      <label class="csf-date-filter">Od <input type="date" id="csfReportDateFrom" value="<?php echo csf_h($monthStart); ?>"></label>
      <label class="csf-date-filter">Do <input type="date" id="csfReportDateTo" value="<?php echo csf_h($monthEnd); ?>"></label>
      <button type="button" class="csf-btn light compact" id="csfPohodaExportReport">Export Pohoda XML řada 9</button>
      <div class="csf-report-search">
        <input type="text" id="csfReportSearch" placeholder="Hledat v přehledu: faktura, jméno, telefon, zboží, cena…">
        <button type="button" class="csf-btn light" id="csfReportSearchBtn">Hledat</button>
      </div>
      <button type="button" class="csf-btn light" id="csfReportReload">Načíst znovu</button>
      <button type="button" class="csf-btn secondary compact" id="csfBackMain">Zpět na faktury</button>
    </div>
    <div id="csfReportSummary" class="csf-report-summary"></div>
    <div class="csf-muted" style="margin-bottom:8px;">Řada 7 počítá zisk z prodejní ceny s DPH. Řada 9 počítá zisk z prodejní ceny bez DPH. Více kusů se násobí počtem kusů.</div>
    <div id="csfReportContent"></div>
  </section>

  <section class="csf-panel csf-detail" id="csfDetailPanel">
    <div class="csf-detail-head">
      <div>
        <h2 id="csfDetailTitle" style="margin:0;">Detail faktury</h2>
        <div id="csfDetailMeta" class="csf-muted" style="margin-top:4px;"></div>
      </div>
      <button type="button" class="csf-btn secondary" id="csfDetailClose">Zavřít detail</button>
    </div>
    <div id="csfDetailContent"></div>
  </section>
</div>

<script>
(function(){
  const api = 'modules/cservis-fakturace/api.php';
  const VAT = 1.21;
  let items = [];
  let editingInvoiceNumber = null;

  function money(n){
    n = Number(n || 0);
    return n.toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' Kč';
  }
  function num(v){
    if (typeof v === 'number') return v;
    v = String(v || '').replace(/\s/g,'').replace(',', '.').replace(/[^0-9.\-]/g,'');
    const n = Number(v);
    return isFinite(n) ? n : 0;
  }
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  }
  function setMsg(el, text, ok){
    el.innerHTML = text ? '<div class="csf-msg '+(ok?'ok':'err')+'">'+esc(text)+'</div>' : '';
  }
  function shortDesc(s){
    s = String(s || '').trim();
    return s.length > 180 ? s.slice(0, 180) + '…' : s;
  }
  async function readJson(res){
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e){
      const clean = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      const msg = clean ? clean.slice(0, 240) : ('HTTP ' + res.status);
      throw new Error('API nevrátilo JSON: ' + msg);
    }
  }

  const searchInput = document.getElementById('csfSearch');
  const resultsEl = document.getElementById('csfResults');
  const searchMsg = document.getElementById('csfSearchMsg');
  const createMsg = document.getElementById('csfCreateMsg');

  async function search(){
    const q = searchInput.value.trim();
    if (!q) { resultsEl.innerHTML=''; searchMsg.textContent='Zadej hledaný text.'; return; }
    searchMsg.textContent = 'Hledám…';
    resultsEl.innerHTML = '';
    try {
      const res = await fetch(api + '?action=search&q=' + encodeURIComponent(q), {credentials:'same-origin'});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Chyba hledání.');
      searchMsg.textContent = data.items.length ? ('Nalezeno ' + data.items.length + ' položek.') : 'Nic nenalezeno.';
      resultsEl.innerHTML = data.items.map((p, idx) => `
        <div class="csf-product">
          <div>
            <strong><span class="csf-code">${esc(p.code)}</span> - ${esc(p.name)}</strong>
            <small>${esc(shortDesc(p.description))}</small>
            ${p.ean ? `<small>EAN: ${esc(p.ean)}</small>` : ``}
            <small class="csf-price">Cena s DPH: ${money(p.price_vat)}</small>
          </div>
          <button type="button" class="csf-btn light" data-add="${idx}">Přidat</button>
        </div>`).join('');
      resultsEl.querySelectorAll('[data-add]').forEach(btn => {
        btn.addEventListener('click', () => addItem(data.items[Number(btn.dataset.add)]));
      });
    } catch(e) {
      searchMsg.textContent = '';
      resultsEl.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }

  function addItem(p){
    items.push({ code:p.code || '', name:p.name || '', qty:1, unit_gross:Number(p.price_vat || 0), discount_percent:0, purchase_price:Number(p.purchase_price || 0) });
    renderItems();
  }
  function calcItem(it){
    const qty = Math.max(0, num(it.qty));
    const base = Math.max(0, num(it.unit_gross));
    const disc = Math.min(100, Math.max(0, num(it.discount_percent)));
    const gross = Math.round(base * (1 - disc/100) * 100) / 100;
    const net = Math.round((gross / VAT) * 100) / 100;
    const totalGross = Math.round(gross * qty * 100) / 100;
    const totalNet = Math.round(net * qty * 100) / 100;
    const vat = Math.round((totalGross - totalNet) * 100) / 100;
    return {qty, base, disc, gross, net, totalGross, totalNet, vat};
  }
  function renderItems(){
    const body = document.getElementById('csfItemsBody');
    if (!items.length) {
      body.innerHTML = '<tr class="csf-empty"><td colspan="8" class="csf-muted">Zatím není přidaná žádná položka.</td></tr>';
      updateTotals();
      return;
    }
    body.innerHTML = items.map((it, i) => {
      const c = calcItem(it);
      return `<tr data-i="${i}">
        <td><input data-f="code" value="${esc(it.code)}"></td>
        <td><input data-f="name" value="${esc(it.name)}"></td>
        <td><input data-f="qty" value="${esc(it.qty)}" inputmode="decimal"></td>
        <td><input data-f="unit_gross" value="${esc(it.unit_gross)}" inputmode="decimal"></td>
        <td><input data-f="discount_percent" value="${esc(it.discount_percent)}" inputmode="decimal"></td>
        <td class="num">${money(c.net)}</td>
        <td class="num">${money(c.totalGross)}</td>
        <td><button type="button" class="csf-btn danger" data-remove="${i}">Smazat</button></td>
      </tr>`;
    }).join('');
    body.querySelectorAll('input').forEach(inp => {
      inp.addEventListener('input', () => {
        const tr = inp.closest('tr');
        const i = Number(tr.dataset.i);
        const f = inp.dataset.f;
        items[i][f] = inp.value;
        if (['qty','unit_gross','discount_percent'].includes(f)) renderItems(); else updateTotals();
      });
    });
    body.querySelectorAll('[data-remove]').forEach(btn => {
      btn.addEventListener('click', () => { items.splice(Number(btn.dataset.remove), 1); renderItems(); });
    });
    updateTotals();
  }
  function updateTotals(){
    let gross=0;
    items.forEach(it => { const c=calcItem(it); gross+=c.totalGross; });
    gross = Math.round(gross * 100) / 100;
    const net = Math.round((gross / VAT) * 100) / 100;
    const vat = Math.round((gross - net) * 100) / 100;
    document.getElementById('csfTotalNet').textContent = money(net);
    document.getElementById('csfTotalVat').textContent = money(vat);
    document.getElementById('csfTotalGross').textContent = money(gross);
  }

  function clearInvoiceForm(keepMessage = false){
    editingInvoiceNumber = null;
    document.getElementById('csfCreate').textContent = 'Vytvořit PDF fakturu';
    document.getElementById('csfCustomer').value = '';
    document.getElementById('csfPhone').value = '';
    document.getElementById('csfPayment').value = 'cash';
    document.getElementById('csfAddress').value = '';
    document.getElementById('csfIco').value = '';
    document.getElementById('csfDic').value = '';
    document.getElementById('csfNote').value = '';
    items = [];
    renderItems();
    if (!keepMessage) setMsg(createMsg, '', true);
  }

  async function createInvoice(){
    setMsg(createMsg, '', true);
    if (!items.length) { setMsg(createMsg, 'Nejdřív přidej alespoň jednu položku.', false); return; }
    const payload = {
      customer_suffix: document.getElementById('csfCustomer').value,
      customer_phone: document.getElementById('csfPhone').value,
      payment: document.getElementById('csfPayment').value,
      issue_date: document.getElementById('csfIssueDate').value,
      customer_address: document.getElementById('csfAddress').value,
      customer_ico: document.getElementById('csfIco').value,
      customer_dic: document.getElementById('csfDic').value,
      note: document.getElementById('csfNote').value,
      items: items.map(it => ({
        code: it.code, name: it.name, qty: num(it.qty), unit_gross: num(it.unit_gross), discount_percent: num(it.discount_percent), purchase_price: num(it.purchase_price)
      }))
    };
    const btn = document.getElementById('csfCreate');
    btn.disabled = true;
    try {
      if (editingInvoiceNumber) payload.invoice_number = editingInvoiceNumber;
      const action = editingInvoiceNumber ? 'update' : 'create';
      const res = await fetch(api + '?action=' + action, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || (editingInvoiceNumber ? 'Fakturu se nepodařilo upravit.' : 'Fakturu se nepodařilo vytvořit.'));
      createMsg.innerHTML = editingInvoiceNumber
        ? '<div class="csf-msg ok">Faktura '+esc(data.invoice.invoice_number)+' byla upravena ve stejné faktuře. <a class="csf-btn light" style="margin-left:8px;" href="'+esc(data.pdf_url)+'">Stáhnout PDF</a></div>'
        : '<div class="csf-msg ok">Faktura '+esc(data.invoice.invoice_number)+' byla vytvořena do stavu Nová. <a class="csf-btn light" style="margin-left:8px;" href="'+esc(data.pdf_url)+'">Stáhnout PDF</a></div>';
      clearInvoiceForm(true);
      loadRecent();
    } catch(e) {
      setMsg(createMsg, e.message, false);
    } finally {
      btn.disabled = false;
    }
  }

  function previewHtml(inv){
    const rows = Array.isArray(inv.preview_items) ? inv.preview_items : [];
    const itemsRows = rows.length ? rows.map(r => `
      <tr>
        <td>${esc(r.code || '')}</td>
        <td>${esc(r.name || '')}</td>
        <td class="num">${Number(r.qty || 0).toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        <td class="num">${money(r.unit_net || 0)}</td>
        <td class="num">${money(r.unit_gross || 0)}</td>
        <td class="num">${money(r.total_gross || 0)}</td>
      </tr>`).join('') : '<tr><td colspan="6" class="csf-muted">Bez položek.</td></tr>';
    return `<div class="csf-hover-preview">
      <h4>${esc(inv.invoice_number)} | ${esc(inv.customer_name || '')} | ${esc(inv.payment_label || '')}</h4>
      <table>
        <thead><tr><th>Kód</th><th>Zboží</th><th class="num">Ks</th><th class="num">MJ bez DPH</th><th class="num">MJ s DPH</th><th class="num">Celkem s DPH</th></tr></thead>
        <tbody>${itemsRows}</tbody>
      </table>
    </div>`;
  }

  function renderRecentColumn(el, list, columnType){
    if (!list.length) {
      el.innerHTML = '<div class="csf-muted">Zatím zde nejsou žádné faktury.</div>';
      return;
    }
    el.innerHTML = list.map(inv => {
      const isNew = String(inv.workflow_state || '') === 'new';
      const moveButtons = isNew
        ? `<button type="button" class="csf-btn warn tiny" data-move="${esc(inv.invoice_number)}" data-target="card">Do řady 9</button>
           <button type="button" class="csf-btn warn tiny" data-move="${esc(inv.invoice_number)}" data-target="cash">Do řady 7</button>`
        : `<button type="button" class="csf-btn warn tiny" data-move="${esc(inv.invoice_number)}" data-target="${inv.payment === 'card' ? 'cash' : 'card'}">${inv.payment === 'card' ? 'Do řady 7' : 'Do řady 9'}</button>`;
      return `<div class="csf-invoice-card ${(isNew || columnType === 'new') ? 'is-new' : ''}" data-row-detail="${esc(inv.invoice_number)}">
        <div class="csf-card-col"><strong>${esc(inv.invoice_number)}</strong></div>
        <div class="csf-card-col">${esc(inv.issue_date || '')}</div>
        <div class="csf-card-col">${esc(inv.payment_label || '')}</div>
        <div class="csf-card-sub">${esc(inv.customer_name || '')}</div>
        <div class="csf-card-money">
          bez DPH: <b>${money(inv.total_net || 0)}</b><br>
          s DPH: <b>${money(inv.total_gross || 0)}</b>
        </div>
        <div class="csf-card-actions">
          ${isNew ? `<button type="button" class="csf-btn light tiny" data-edit="${esc(inv.invoice_number)}">Upravit</button>` : `<button type="button" class="csf-btn light tiny" data-load="${esc(inv.invoice_number)}">Načíst</button>`}
          <a class="csf-btn light tiny" href="${api}?action=pdf&no=${encodeURIComponent(inv.invoice_number)}">PDF</a>
          <button type="button" class="csf-btn danger tiny" data-delete="${esc(inv.invoice_number)}">Smazat</button>
          ${moveButtons}
        </div>
        ${previewHtml(inv)}
      </div>`;
    }).join('');
    el.querySelectorAll('[data-move]').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); moveInvoice(btn.dataset.move, btn.dataset.target); });
    });
    el.querySelectorAll('[data-delete]').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); deleteInvoice(btn.dataset.delete); });
    });
    el.querySelectorAll('[data-load]').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); loadInvoice(btn.dataset.load, false); });
    });
    el.querySelectorAll('[data-edit]').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); loadInvoice(btn.dataset.edit, true); });
    });
    el.querySelectorAll('[data-row-detail]').forEach(row => {
      row.addEventListener('click', () => openInvoiceDetail(row.dataset.rowDetail));
    });
  }

  function suffixFromCustomer(inv){
    const suffix = String(inv.customer_suffix || '').trim();
    if (suffix) return suffix;
    const name = String(inv.customer_name || '').trim();
    return name.replace(/^C\s*-?\s*Servis\s*/i, '').trim();
  }

  function renderInvoiceDetail(inv){
    const detail = inv.profit_detail || {};
    const rows = Array.isArray(detail.rows) ? detail.rows : [];
    document.getElementById('csfDetailTitle').textContent = 'Detail faktury ' + (inv.invoice_number || '');
    document.getElementById('csfDetailMeta').textContent = [
      inv.customer_name || '',
      inv.customer_phone ? ('tel: ' + inv.customer_phone) : '',
      inv.payment === 'card' ? 'Kartou' : 'Hotově'
    ].filter(Boolean).join(' | ');

    const rowsHtml = rows.length ? rows.map(r => `
      <tr>
        <td>${esc(r.code)}</td>
        <td>${esc(r.name)}</td>
        <td class="num">${num(r.qty).toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        <td class="num">${money(r.purchase_unit)}</td>
        <td class="num">${money(r.purchase_total)}</td>
        <td class="num">${money(r.unit_net)}</td>
        <td class="num">${money(r.unit_gross)}</td>
        <td class="num">${money(r.total_net)}</td>
        <td class="num">${money(r.total_gross)}</td>
        <td class="num"><b>${money(r.profit)}</b></td>
      </tr>`).join('') : '<tr><td colspan="10" class="csf-muted">Faktura nemá položky.</td></tr>';

    document.getElementById('csfDetailContent').innerHTML = `
      <div class="csf-profit-summary">
        <div class="csf-profit-box"><small>Výpočet zisku</small><b>${esc(detail.profit_mode_label || '')}</b></div>
        <div class="csf-profit-box"><small>Prodej pro výpočet</small><b>${money(detail.total_sale_for_profit || 0)}</b></div>
        <div class="csf-profit-box"><small>Nákupní cena celkem</small><b>${money(detail.total_purchase || 0)}</b></div>
        <div class="csf-profit-box"><small>Zisk ze všech položek</small><b>${money(detail.total_profit || 0)}</b></div>
      </div>
      <div class="csf-detail-table-wrap">
        <table class="csf-detail-table">
          <thead>
            <tr>
              <th>Kód</th>
              <th>Název zboží</th>
              <th class="num">Ks</th>
              <th class="num">Nákup/MJ</th>
              <th class="num">Nákup celkem</th>
              <th class="num">Prodej/MJ bez DPH</th>
              <th class="num">Prodej/MJ s DPH</th>
              <th class="num">Celkem bez DPH</th>
              <th class="num">Celkem s DPH</th>
              <th class="num">Zisk</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>`;
    document.getElementById('csfDetailPanel').style.display = 'block';
    document.getElementById('csfDetailPanel').scrollIntoView({behavior:'smooth', block:'start'});
  }

  async function openInvoiceDetail(invoiceNumber){
    try {
      const res = await fetch(api + '?action=get&no=' + encodeURIComponent(invoiceNumber), {credentials:'same-origin'});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Detail faktury se nepodařilo načíst.');
      renderInvoiceDetail(data.full || {});
    } catch(e) {
      createMsg.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }

  async function loadInvoice(invoiceNumber, editMode = false){
    try {
      const res = await fetch(api + '?action=get&no=' + encodeURIComponent(invoiceNumber), {credentials:'same-origin'});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Fakturu se nepodařilo načíst.');
      const inv = data.full || {};
      document.getElementById('csfCustomer').value = suffixFromCustomer(inv);
      document.getElementById('csfPhone').value = inv.customer_phone || '';
      document.getElementById('csfPayment').value = inv.payment === 'card' ? 'card' : 'cash';
      document.getElementById('csfIssueDate').value = inv.issue_date || document.getElementById('csfIssueDate').value;
      document.getElementById('csfAddress').value = inv.customer_address || '';
      document.getElementById('csfIco').value = inv.customer_ico || '';
      document.getElementById('csfDic').value = inv.customer_dic || '';
      document.getElementById('csfNote').value = inv.note || '';
      items = Array.isArray(inv.items) ? inv.items.map(it => ({
        code: it.code || '',
        name: it.name || '',
        qty: it.qty || 1,
        unit_gross: it.unit_gross_base || it.unit_gross || 0,
        discount_percent: it.discount_percent || 0,
        purchase_price: it.purchase_price || 0
      })) : [];
      renderItems();
      if (editMode) {
        editingInvoiceNumber = invoiceNumber;
        document.getElementById('csfCreate').textContent = 'Uložit úpravy faktury';
        createMsg.innerHTML = '<div class="csf-msg ok">Upravuješ fakturu '+esc(invoiceNumber)+'. Uložením se přepíše stejná faktura, nevytvoří se nová.</div>';
      } else {
        editingInvoiceNumber = null;
        document.getElementById('csfCreate').textContent = 'Vytvořit PDF fakturu';
        createMsg.innerHTML = '<div class="csf-msg ok">Faktura '+esc(invoiceNumber)+' je načtená ve formuláři. Můžeš ji upravit a vytvořit novou PDF fakturu.</div>';
      }
      document.querySelector('.csf-grid').scrollIntoView({behavior:'smooth', block:'start'});
    } catch(e) {
      createMsg.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }


  async function deleteInvoice(invoiceNumber){
    if (!confirm('Opravdu smazat fakturu ' + invoiceNumber + '?')) return;
    try {
      const res = await fetch(api + '?action=delete', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({invoice_number: invoiceNumber})
      });
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Fakturu se nepodařilo smazat.');
      createMsg.innerHTML = '<div class="csf-msg ok">Faktura <b>'+esc(invoiceNumber)+'</b> byla smazána.</div>';
      const detailTitle = document.getElementById('csfDetailTitle');
      if (detailTitle && detailTitle.textContent.indexOf(invoiceNumber) !== -1) {
        document.getElementById('csfDetailPanel').style.display = 'none';
      }
      loadRecent();
      if (document.getElementById('csfReportPage') && document.getElementById('csfReportPage').style.display === 'block') {
        loadReport();
      }
    } catch(e) {
      createMsg.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }

  async function moveInvoice(invoiceNumber, targetPayment){
    const label = targetPayment === 'card' ? 'řady 9 / kartou' : 'řady 7 / hotově';
    if (!confirm('Přesunout fakturu ' + invoiceNumber + ' do ' + label + '? Vytvoří se nové číslo ve správné souvislé řadě.')) return;
    try {
      const res = await fetch(api + '?action=move', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({invoice_number: invoiceNumber, target_payment: targetPayment})});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Fakturu se nepodařilo přesunout.');
      createMsg.innerHTML = '<div class="csf-msg ok">Faktura byla přesunuta. Nové číslo: <b>'+esc(data.invoice.invoice_number)+'</b>. <a class="csf-btn light" style="margin-left:8px;" href="'+esc(data.pdf_url)+'">Stáhnout PDF</a></div>';
      loadRecent();
    } catch(e) {
      createMsg.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }


  function showMainPage(){
    document.querySelector('.csf-grid').style.display = '';
    document.querySelector('.csf-recent').style.display = '';
    document.getElementById('csfReportPage').style.display = 'none';
    document.getElementById('csfDetailPanel').style.display = 'none';
    window.scrollTo({top:0, behavior:'smooth'});
  }

  function showReportPage(){
    document.querySelector('.csf-grid').style.display = 'none';
    document.querySelector('.csf-recent').style.display = 'none';
    document.getElementById('csfReportPage').style.display = 'block';
    document.getElementById('csfDetailPanel').style.display = 'none';
    loadReport();
    window.scrollTo({top:0, behavior:'smooth'});
  }

  function renderReportSummary(summary){
    const el = document.getElementById('csfReportSummary');
    summary = summary || {};
    el.innerHTML = `
      <div class="csf-report-box"><small>Počet faktur</small><b>${Number(summary.count_invoices || 0).toLocaleString('cs-CZ')}</b></div>
      <div class="csf-report-box"><small>Počet položek</small><b>${Number(summary.count_items || 0).toLocaleString('cs-CZ')}</b></div>
      <div class="csf-report-box"><small>Nákupní cena celkem</small><b>${money(summary.total_purchase || 0)}</b></div>
      <div class="csf-report-box"><small>Prodej pro výpočet zisku</small><b>${money(summary.total_sale_for_profit || 0)}</b></div>
      <div class="csf-report-box"><small>Zisk celkem</small><b>${money(summary.total_profit || 0)}</b></div>
    `;
  }

  function renderReportRows(rows){
    const el = document.getElementById('csfReportContent');
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      el.innerHTML = '<div class="csf-muted">Nenalezeny žádné položky.</div>';
      return;
    }
    const rowsHtml = rows.map(r => {
      const namePhone = `${r.customer_name || ''}${r.customer_phone ? ' | tel: ' + r.customer_phone : ''}`;
      return `
      <tr>
        <td>${esc(r.invoice_number)}</td>
        <td>${esc(r.issue_date)}</td>
        <td class="csf-report-name" title="${esc(namePhone)}">${esc(namePhone)}</td>
        <td>${esc(r.payment_label)}</td>
        <td class="csf-report-code" title="${esc(r.code || '')}">${esc(r.code)}</td>
        <td class="csf-report-item" data-detail="${esc(r.invoice_number)}" title="${esc(r.name || '')}">${esc(r.name)}</td>
        <td class="num">${Number(r.qty || 0).toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        <td class="num">${money(r.purchase_unit)}</td>
        <td class="num">${money(r.purchase_total)}</td>
        <td class="num">${money(r.unit_net)}</td>
        <td class="num">${money(r.unit_gross)}</td>
        <td class="num">${money(r.total_net)}</td>
        <td class="num">${money(r.total_gross)}</td>
        <td class="num"><b>${money(r.profit)}</b></td>
      </tr>`;
    }).join('');
    el.innerHTML = `
      <div class="csf-report-table-wrap">
        <table class="csf-report-table">
          <thead>
            <tr>
              <th>Faktura</th>
              <th>Datum</th>
              <th>Jméno / telefon</th>
              <th>Platba</th>
              <th>Kód</th>
              <th>Zboží</th>
              <th class="num">Ks</th>
              <th class="num">Nákup/MJ</th>
              <th class="num">Nákup celkem</th>
              <th class="num">Prodej/MJ bez DPH</th>
              <th class="num">Prodej/MJ s DPH</th>
              <th class="num">Celkem bez DPH</th>
              <th class="num">Celkem s DPH</th>
              <th class="num">Zisk</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>`;
    el.querySelectorAll('[data-detail]').forEach(btn => {
      btn.addEventListener('click', () => openInvoiceDetail(btn.dataset.detail));
    });
  }

  function exportPohodaXml(fromId, toId){
    const from = document.getElementById(fromId) ? document.getElementById(fromId).value : '';
    const to = document.getElementById(toId) ? document.getElementById(toId).value : '';
    window.location.href = api + '?action=pohoda_export&date_from=' + encodeURIComponent(from) + '&date_to=' + encodeURIComponent(to);
  }

  async function loadReport(){
    const summaryEl = document.getElementById('csfReportSummary');
    const contentEl = document.getElementById('csfReportContent');
    summaryEl.innerHTML = '';
    contentEl.innerHTML = '<div class="csf-muted">Načítám přehled…</div>';
    try {
      const q = document.getElementById('csfReportSearch') ? document.getElementById('csfReportSearch').value.trim() : '';
      const from = document.getElementById('csfReportDateFrom') ? document.getElementById('csfReportDateFrom').value : '';
      const to = document.getElementById('csfReportDateTo') ? document.getElementById('csfReportDateTo').value : '';
      const res = await fetch(api + '?action=report&q=' + encodeURIComponent(q) + '&date_from=' + encodeURIComponent(from) + '&date_to=' + encodeURIComponent(to), {credentials:'same-origin'});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Přehled se nepodařilo načíst.');
      renderReportSummary(data.summary || {});
      renderReportRows(data.items || []);
    } catch(e) {
      contentEl.innerHTML = '<div class="csf-msg err">' + esc(e.message) + '</div>';
    }
  }

  async function loadRecent(){
    const colNew = document.getElementById('csfRecentNew');
    const col7 = document.getElementById('csfRecent7');
    const col9 = document.getElementById('csfRecent9');
    const loading = '<div class="csf-muted">Načítám…</div>';
    colNew.innerHTML = loading;
    col7.innerHTML = loading;
    col9.innerHTML = loading;
    try {
      const q = document.getElementById('csfInvoiceSearch') ? document.getElementById('csfInvoiceSearch').value.trim() : '';
      const from = document.getElementById('csfInvoiceDateFrom') ? document.getElementById('csfInvoiceDateFrom').value : '';
      const to = document.getElementById('csfInvoiceDateTo') ? document.getElementById('csfInvoiceDateTo').value : '';
      const res = await fetch(api + '?action=list&q=' + encodeURIComponent(q) + '&date_from=' + encodeURIComponent(from) + '&date_to=' + encodeURIComponent(to), {credentials:'same-origin'});
      const data = await readJson(res);
      if (!data.ok) throw new Error(data.message || 'Nelze načíst faktury.');
      const items = Array.isArray(data.items) ? data.items : [];
      const listNew = items.filter(inv => String(inv.workflow_state || '') === 'new');
      const list7 = items.filter(inv => String(inv.workflow_state || '') !== 'new' && String(inv.series || '').startsWith('7'));
      const list9 = items.filter(inv => String(inv.workflow_state || '') !== 'new' && String(inv.series || '').startsWith('9'));
      renderRecentColumn(colNew, listNew, 'new');
      renderRecentColumn(col9, list9, 'card');
      renderRecentColumn(col7, list7, 'cash');
    } catch(e) {
      const html = '<div class="csf-msg err">' + esc(e.message) + '</div>';
      colNew.innerHTML = html;
      col7.innerHTML = html;
      col9.innerHTML = html;
    }
  }

  document.getElementById('csfOpenReport').addEventListener('click', showReportPage);
  document.getElementById('csfBackMain').addEventListener('click', showMainPage);
  document.getElementById('csfReportReload').addEventListener('click', () => { document.getElementById('csfReportSearch').value=''; loadReport(); });
  document.getElementById('csfReportDateFrom').addEventListener('change', loadReport);
  document.getElementById('csfReportDateTo').addEventListener('change', loadReport);
  document.getElementById('csfReportSearchBtn').addEventListener('click', loadReport);
  document.getElementById('csfReportSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); loadReport(); } });
  document.getElementById('csfPohodaExportMain').addEventListener('click', () => exportPohodaXml('csfInvoiceDateFrom', 'csfInvoiceDateTo'));
  document.getElementById('csfPohodaExportReport').addEventListener('click', () => exportPohodaXml('csfReportDateFrom', 'csfReportDateTo'));
  document.getElementById('csfSearchBtn').addEventListener('click', search);
  searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
  document.getElementById('csfCreate').addEventListener('click', createInvoice);
  document.getElementById('csfClear').addEventListener('click', () => { clearInvoiceForm(false); });
  document.getElementById('csfReloadList').addEventListener('click', () => { document.getElementById('csfInvoiceSearch').value=''; loadRecent(); });
  document.getElementById('csfInvoiceDateFrom').addEventListener('change', loadRecent);
  document.getElementById('csfInvoiceDateTo').addEventListener('change', loadRecent);
  document.getElementById('csfInvoiceSearchBtn').addEventListener('click', loadRecent);
  document.getElementById('csfInvoiceSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); loadRecent(); } });
  document.getElementById('csfDetailClose').addEventListener('click', () => { document.getElementById('csfDetailPanel').style.display='none'; });
  document.getElementById('csfRebuild').addEventListener('click', async () => {
    const btn = document.getElementById('csfRebuild'); btn.disabled = true;
    try {
      const res = await fetch(api + '?action=rebuild_cache', {credentials:'same-origin'});
      const data = await readJson(res);
      alert(data.ok ? (data.message + ' Položek: ' + data.count) : data.message);
    } catch(e) { alert(e.message); }
    btn.disabled = false;
  });

  renderItems();
  loadRecent();
})();
</script>
