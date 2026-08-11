<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

$role = require_role($tenant, 'roster_management');
$user = auth_user();

$toons = json_decode(guild_load($tenant['id'], 'toons', '[]'), true) ?: [];

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Toon Management &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff; padding: 32px 24px;
    }
    .wrap { max-width: 1040px; margin: 0 auto; }
    .back { font-size: 13px; color: #7f8bad; text-decoration: none; }
    .back:hover { color: #a8b4d0; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 24px; }

    .tm-container { display: grid; grid-template-columns: 270px 1fr; gap: 20px; align-items: start; }
    .tm-sidebar { display: flex; flex-direction: column; gap: 8px; }
    .btn-add-new {
      width: 100%; padding: 10px; background: rgba(88,101,242,0.15); border: 1px solid rgba(88,101,242,0.35);
      color: #a3adfa; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .btn-add-new:hover { background: rgba(88,101,242,0.28); }
    #listSearch {
      width: 100%; padding: 9px 12px; border: 1px solid rgba(255,255,255,0.12); border-radius: 8px;
      background: #0a0f1e; color: #e8ecff; font-size: 13px; font: inherit;
    }
    #listSearch::placeholder { color: #55607a; }
    .tm-list { max-height: 60vh; overflow-y: auto; background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; }
    .tm-list-item { padding: 9px 12px; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); border-left: 3px solid transparent; }
    .tm-list-item:last-child { border-bottom: none; }
    .tm-list-item:hover { background: rgba(255,255,255,0.03); }
    .tm-list-item.active { background: rgba(88,101,242,0.1); border-left-color: #5865f2; }
    .tm-list-name { font-size: 13px; font-weight: 600; color: #e8ecff; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tm-list-meta { display: flex; gap: 6px; align-items: center; margin-top: 4px; flex-wrap: wrap; }
    .tm-alt-badge { font-size: 10px; color: #7f8bad; background: rgba(255,255,255,0.06); border-radius: 10px; padding: 1px 7px; }
    .pug-tag { font-size: 9px; background: rgba(232,196,119,0.18); color: #e8c477; border-radius: 4px; padding: 1px 5px; font-weight: 700; letter-spacing: .04em; }
    .tm-empty { padding: 24px; text-align: center; color: #55607a; font-size: 12px; }

    .tm-main { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
    .tm-form-panel { background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 20px; }
    .form-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
    .form-title-row h2 { margin: 0; font-size: 16px; color: #e8ecff; flex: 1; }
    .btn-back { font-size: 11px; color: #7f8bad; background: none; border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 4px 10px; cursor: pointer; }
    .btn-back:hover { color: #a8b4d0; }
    .btn-delete-toon { font-size: 11px; padding: 5px 10px; background: none; border: 1px solid rgba(224,85,85,0.35); color: #e88585; border-radius: 6px; cursor: pointer; }
    .btn-delete-toon:hover { background: rgba(224,85,85,0.12); }
    .form-group { margin-bottom: 12px; display: flex; flex-direction: column; gap: 5px; }
    .form-group label { font-size: 11px; color: #7f8bad; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .form-group input, .form-group select {
      padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px;
      background: #0a0f1e; color: #e8ecff; font-size: 13px; font: inherit;
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
    .form-row .form-group { margin-bottom: 0; }

    .lm-wrap { position: relative; }
    .lm-list {
      display: none; position: absolute; top: calc(100% + 2px); left: 0; right: 0; z-index: 300;
      margin: 0; padding: 3px 0; list-style: none; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.15);
      border-radius: 6px; max-height: 180px; overflow-y: auto; box-shadow: 0 6px 20px rgba(0,0,0,.45);
    }
    .lm-list.open { display: block; }
    .lm-opt { padding: 6px 10px; font-size: 12px; color: #c7cef2; cursor: pointer; }
    .lm-opt:hover, .lm-opt.active { background: rgba(88,101,242,0.15); color: #e8ecff; }
    .lm-opt.lm-none { color: #7f8bad; font-style: italic; }
    .lm-no-results { padding: 6px 10px; font-size: 12px; color: #7f8bad; font-style: italic; }
    #linkedMainSearch:disabled { opacity: .45; cursor: not-allowed; }

    .t2-toggle-label { cursor: pointer; font-size: 13px; font-weight: 700; color: #7f8bad; user-select: none; letter-spacing: .04em; }
    #fullT2:checked + .t2-toggle-label { color: #e8c477; }
    #fullT2 { margin-right: 6px; }

    .form-buttons { display: flex; gap: 8px; margin-top: 16px; }
    button.btn { display: inline-block; padding: 9px 20px; font: inherit; font-size: 13px; font-weight: 600; border-radius: 999px; cursor: pointer; border: none; }
    .btn-save { background: #5865f2; color: #fff; }
    .btn-save:hover { background: #4752c4; }
    .btn-cancel { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12) !important; color: #a8b4d0; }
    .btn-cancel:hover { background: rgba(255,255,255,0.1); }
    .form-message { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.4; background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.25); color: #e88585; }
    .form-message.success { background: rgba(88,196,120,0.1); border-color: rgba(88,196,120,0.25); color: #8fd6a8; }
    .form-message.hidden { display: none; }

    .tm-alts-panel { background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 20px; }
    .tm-alts-panel.hidden { display: none; }
    .alts-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .alts-header h3 { margin: 0; font-size: 12px; font-weight: 700; color: #7f8bad; text-transform: uppercase; letter-spacing: .08em; }
    .btn-add-alt { font-size: 11px; padding: 5px 12px; background: rgba(88,196,120,0.14); border: 1px solid rgba(88,196,120,0.3); color: #8fd6a8; border-radius: 6px; cursor: pointer; font-weight: 700; }
    .btn-add-alt:hover { background: rgba(88,196,120,0.24); }
    .alts-table { width: 100%; border-collapse: collapse; }
    .alts-table th { font-size: 10px; font-weight: 600; color: #7f8bad; text-transform: uppercase; letter-spacing: .07em; padding: 4px 8px 8px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .alts-table td { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; color: #c7cef2; vertical-align: middle; }
    .alts-table tr:last-child td { border-bottom: none; }
    .alt-spec-cell { font-size: 11px; color: #7f8bad; }
    .alt-row-btns { display: flex; gap: 4px; justify-content: flex-end; flex-wrap: wrap; }
    .alt-btn { padding: 4px 8px; font-size: 10px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); color: #a8b4d0; border-radius: 5px; cursor: pointer; white-space: nowrap; }
    .alt-btn:hover { background: rgba(255,255,255,0.1); }
    .alt-btn.danger { border-color: rgba(224,85,85,0.4); color: #e88585; }
    .alt-btn.danger:hover { background: rgba(224,85,85,0.15); }
    .alts-empty { padding: 16px; text-align: center; color: #55607a; font-size: 12px; }

    .class-chip { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 3px 9px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #000; white-space: nowrap; }
    .class-chip.sm { font-size: 10px; padding: 2px 7px; }
    .class-chip.sel-warrior{background:#c79c6e} .class-chip.sel-paladin{background:#f472b6}
    .class-chip.sel-priest{background:#eee;color:#000} .class-chip.sel-druid{background:#f59e0b}
    .class-chip.sel-rogue{background:#fff569;color:#000} .class-chip.sel-mage{background:#69ccf0}
    .class-chip.sel-warlock{background:#9482c9} .class-chip.sel-shaman{background:#0070de}
    .class-chip.sel-hunter{background:#abd473}

    @media (max-width: 900px) { .tm-container { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <a class="back" href="/<?= h($tenant['slug']) ?>/">&larr; Back to <?= h($tenant['name']) ?></a>
    <h1>Toon management</h1>
    <p class="sub">Signed in as <?= h($user['username']) ?> &middot; role: <?= h($role) ?></p>

    <div class="tm-container">
      <div class="tm-sidebar">
        <button class="btn-add-new" onclick="newMain()">+ Add New Main</button>
        <input type="text" id="listSearch" placeholder="Search toons, Discord, alts...">
        <div id="toonList" class="tm-list"></div>
      </div>

      <div class="tm-main">
        <div class="tm-form-panel">
          <div class="form-title-row">
            <h2 id="formTitle">Add New Main</h2>
            <button class="btn-back hidden" id="backBtn" onclick="backToMain()">&#8592; Back</button>
            <button class="btn-delete-toon hidden" id="deleteToonBtn">Delete</button>
          </div>

          <div class="form-group">
            <label for="mainName">Character Name</label>
            <input type="text" id="mainName" placeholder="Character name" autocomplete="off">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="discordName">Discord Name</label>
              <input type="text" id="discordName" placeholder="Discord#1234" autocomplete="off">
            </div>
            <div class="form-group">
              <label for="discordId">Discord ID <span style="font-size:10px;color:#7f8bad;font-weight:400;text-transform:none">(numeric)</span></label>
              <input type="text" id="discordId" placeholder="213301151523667970" pattern="[0-9]*" inputmode="numeric">
            </div>
          </div>

          <div class="form-group" id="linkedMainGroup" style="display:none">
            <label for="linkedMainSearch">Linked Main</label>
            <div class="lm-wrap" id="lmWrap">
              <input type="text" id="linkedMainSearch" placeholder="Search mains..." autocomplete="off">
              <input type="hidden" id="linkedMain">
              <ul class="lm-list" id="lmList"></ul>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="mainClass">Class</label>
              <select id="mainClass">
                <option value="">-- Class --</option>
                <option>Warrior</option><option>Paladin</option><option>Priest</option>
                <option>Druid</option><option>Rogue</option><option>Mage</option>
                <option>Warlock</option><option>Shaman</option><option>Hunter</option>
              </select>
            </div>
            <div class="form-group">
              <label for="toonStatus">Status</label>
              <select id="toonStatus">
                <option value="Main">Main</option>
                <option value="Alt">Alt</option>
                <option value="Pug">Pug</option>
              </select>
            </div>
          </div>
          <div class="form-row" id="rankGroup">
            <div class="form-group">
              <label for="toonRank">Rank</label>
              <select id="toonRank">
                <option value="raider">Raider</option>
                <option value="trial">Trial Raider</option>
                <option value="social">Social</option>
                <option value="pug">Pug</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="mainSpec">Main Spec</label>
              <select id="mainSpec"><option value="">-- Spec --</option></select>
            </div>
            <div class="form-group">
              <label for="altSpec">Alt Spec <span style="font-size:10px;color:#7f8bad;font-weight:400;text-transform:none">(optional)</span></label>
              <select id="altSpec"><option value="">-- None --</option></select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="toonServer">Server</label>
              <input type="text" id="toonServer" placeholder="Realm name" autocomplete="off">
            </div>
            <div class="form-group" id="fullT2Group" style="display:none;justify-content:flex-end;padding-top:22px">
              <div><input type="checkbox" id="fullT2"><label for="fullT2" class="t2-toggle-label">Full T2</label></div>
            </div>
          </div>

          <div class="form-buttons">
            <button class="btn btn-save" id="saveToonBtn">Save</button>
            <button class="btn btn-cancel" onclick="cancelForm()">Cancel</button>
          </div>
          <div id="formMessage" class="form-message hidden"></div>
        </div>

        <div class="tm-alts-panel hidden" id="altsSection">
          <div class="alts-header">
            <h3>Alts <span id="altsCount" style="color:#7f8bad"></span></h3>
            <button class="btn-add-alt" id="addAltBtn">+ Add Alt</button>
          </div>
          <div id="altsList"></div>
        </div>
      </div>
    </div>
  </div>

<script>
const CLASS_SPECS = {
  Warrior: ['Protection','Arms','Fury'],
  Paladin: ['Holy','Protection','Retribution'],
  Priest:  ['Holy','Discipline','Shadow'],
  Druid:   ['Restoration','Balance','Feral'],
  Rogue:   ['Assassination','Combat','Subtlety'],
  Mage:    ['Arcane','Fire','Frost'],
  Warlock: ['Affliction','Demonology','Destruction'],
  Shaman:  ['Restoration','Elemental','Enhancement'],
  Hunter:  ['Marksmanship','Survival','Beast Mastery']
};

const SAVE_URL = <?= json_encode('/toons/save.php?slug=' . $slug) ?>;
let toons = <?= json_encode($toons) ?>;
let filterQuery = '', activeMainId = null;
let editingId = null, editingType = 'main', editingParentId = null;

function gcc(cls) { return cls ? 'sel-' + cls.toLowerCase() : ''; }
function genId() { return 'toon_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9); }
function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function setMsg(msg, type) {
  const el = document.getElementById('formMessage');
  el.textContent = msg;
  el.classList.remove('hidden', 'success');
  if (type === 'success') el.classList.add('success');
}
function clearMsg() { document.getElementById('formMessage').classList.add('hidden'); }

function saveToons(silent) {
  return fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(toons) })
    .then(r => { if (!r.ok) throw new Error('Server ' + r.status); return r.json(); })
    .then(d => {
      if (!d || !d.success) throw new Error(d && d.error ? d.error : 'Invalid server response');
      if (!silent) { setMsg('Saved.', 'success'); setTimeout(clearMsg, 2500); }
    })
    .catch(e => { setMsg(e.message || 'Unable to save.'); throw e; });
}

function renderList() {
  const q = filterQuery.toLowerCase();
  let items = toons;
  if (q) items = toons.filter(t =>
    t.mainName.toLowerCase().includes(q) ||
    (t.discordName || '').toLowerCase().includes(q) ||
    (t.alts || []).some(a => a.name.toLowerCase().includes(q))
  );
  items = [...items].sort((a, b) => a.mainName.localeCompare(b.mainName));
  const c = document.getElementById('toonList');
  if (!items.length) { c.innerHTML = '<div class="tm-empty">No toons found</div>'; return; }
  c.innerHTML = items.map(t => {
    const n = (t.alts || []).length;
    const cls = gcc(t.class);
    return '<div class="tm-list-item' + (t.id === activeMainId ? ' active' : '') + '" onclick="selectMain(\'' + t.id + '\')">' +
      '<span class="tm-list-name">' + esc(t.mainName) + (t.status === 'Pug' ? ' <span class="pug-tag">PUG</span>' : '') + '</span>' +
      '<div class="tm-list-meta">' +
        '<span class="class-chip sm ' + cls + '">' + (t.class || '?') + '</span>' +
        (n ? '<span class="tm-alt-badge">' + n + ' alt' + (n > 1 ? 's' : '') + '</span>' : '') +
      '</div></div>';
  }).join('');
}

document.getElementById('listSearch').addEventListener('input', function () { filterQuery = this.value; renderList(); });

function showLM(v) { document.getElementById('linkedMainGroup').style.display = v ? '' : 'none'; }

function fillFields(obj, isAlt) {
  document.getElementById('mainName').value    = isAlt ? (obj.name || '') : (obj.mainName || '');
  document.getElementById('discordName').value = obj.discordName || '';
  document.getElementById('discordId').value   = obj.discordId || '';
  document.getElementById('mainClass').value   = obj.class || '';
  document.getElementById('mainClass').dispatchEvent(new Event('change'));
  document.getElementById('mainSpec').value    = obj.mainSpec || '';
  document.getElementById('altSpec').value     = obj.altSpec || '';
  document.getElementById('toonStatus').value  = obj.status || 'Main';
  document.getElementById('toonServer').value  = obj.server || '';
  document.getElementById('fullT2').checked    = obj.fullT2 || false;
  document.getElementById('toonRank').value    = obj.rank || 'raider';
  document.getElementById('rankGroup').style.display = '';
}

function selectMain(id) {
  activeMainId = id;
  const t = toons.find(x => x.id === id);
  if (!t) return;
  loadMainIntoForm(t);
  renderList();
}

function loadMainIntoForm(t) {
  editingId = t.id; editingType = 'main'; editingParentId = null;
  document.getElementById('formTitle').textContent = 'Edit — ' + t.mainName;
  document.getElementById('backBtn').classList.add('hidden');
  const db = document.getElementById('deleteToonBtn');
  db.classList.remove('hidden');
  db.onclick = () => deleteToon(t.id);
  showLM(false);
  fillFields(t, false);
  renderAltsSection(t);
  clearMsg();
}

function newMain() {
  activeMainId = null; editingId = null; editingType = 'main'; editingParentId = null;
  document.getElementById('formTitle').textContent = 'Add New Main';
  document.getElementById('backBtn').classList.add('hidden');
  document.getElementById('deleteToonBtn').classList.add('hidden');
  showLM(false);
  document.getElementById('mainName').value = '';
  document.getElementById('discordName').value = '';
  document.getElementById('discordId').value = '';
  document.getElementById('mainClass').value = '';
  document.getElementById('mainClass').dispatchEvent(new Event('change'));
  document.getElementById('mainSpec').value = '';
  document.getElementById('altSpec').value = '';
  document.getElementById('toonStatus').value = 'Main';
  document.getElementById('toonServer').value = '';
  document.getElementById('fullT2').checked = false;
  document.getElementById('toonRank').value = 'raider';
  document.getElementById('rankGroup').style.display = '';
  document.getElementById('altsSection').classList.add('hidden');
  renderLinkedMainOptions();
  renderList();
  clearMsg();
}

function cancelForm() { if (activeMainId) { selectMain(activeMainId); } else { newMain(); } }

function backToMain() {
  const id = document.getElementById('backBtn').dataset.mainId || editingParentId;
  if (id) { activeMainId = id; selectMain(id); } else { newMain(); }
}

function prepareNewAlt(mainId) {
  editingId = null; editingType = 'alt'; editingParentId = mainId;
  const parent = mainId ? toons.find(t => t.id === mainId) : null;
  document.getElementById('formTitle').textContent = 'Add Alt' + (parent ? ' for ' + parent.mainName : '');
  const bb = document.getElementById('backBtn');
  bb.classList.remove('hidden'); bb.dataset.mainId = mainId || '';
  document.getElementById('deleteToonBtn').classList.add('hidden');
  showLM(true); renderLinkedMainOptions(mainId);
  document.getElementById('mainName').value = '';
  document.getElementById('mainClass').value = '';
  document.getElementById('mainClass').dispatchEvent(new Event('change'));
  document.getElementById('mainSpec').value = '';
  document.getElementById('altSpec').value = '';
  document.getElementById('toonStatus').value = 'Alt';
  document.getElementById('toonServer').value = '';
  document.getElementById('fullT2').checked = false;
  document.getElementById('rankGroup').style.display = 'none';
  if (parent) {
    document.getElementById('discordName').value = parent.discordName || '';
    document.getElementById('discordId').value   = parent.discordId || '';
  }
  document.getElementById('altsSection').classList.add('hidden');
  clearMsg();
}

function editAlt(mainId, altId) {
  const t = toons.find(x => x.id === mainId); if (!t) return;
  const a = (t.alts || []).find(x => x.id === altId); if (!a) return;
  editingId = altId; editingType = 'alt'; editingParentId = mainId;
  document.getElementById('formTitle').textContent = 'Edit Alt — ' + a.name;
  const bb = document.getElementById('backBtn');
  bb.classList.remove('hidden'); bb.dataset.mainId = mainId;
  document.getElementById('deleteToonBtn').classList.add('hidden');
  showLM(true); renderLinkedMainOptions(mainId); fillFields(a, true);
  document.getElementById('rankGroup').style.display = 'none';
  document.getElementById('altsSection').classList.add('hidden');
  clearMsg();
}

function renderAltsSection(t) {
  const sec = document.getElementById('altsSection');
  if (!t) { sec.classList.add('hidden'); return; }
  sec.classList.remove('hidden');
  document.getElementById('altsCount').textContent = '(' + (t.alts || []).length + ')';
  document.getElementById('addAltBtn').onclick = () => prepareNewAlt(t.id);
  const alts = t.alts || [];
  const list = document.getElementById('altsList');
  if (!alts.length) { list.innerHTML = '<div class="alts-empty">No alts yet.</div>'; return; }
  list.innerHTML = '<table class="alts-table"><thead><tr><th>Name</th><th>Class</th><th>Spec</th><th style="text-align:right">Actions</th></tr></thead><tbody>' +
    alts.map(a =>
      '<tr>' +
      '<td style="font-weight:600;color:#e8ecff">' + esc(a.name) + '</td>' +
      '<td><span class="class-chip sm ' + gcc(a.class) + '">' + (a.class || '?') + '</span></td>' +
      '<td class="alt-spec-cell">' + esc(a.mainSpec || '') + (a.altSpec ? ' / ' + esc(a.altSpec) : '') + '</td>' +
      '<td><div class="alt-row-btns">' +
        '<button class="alt-btn" onclick="editAlt(\'' + t.id + '\',\'' + a.id + '\')">Edit</button>' +
        '<button class="alt-btn" onclick="promoteAltToMain(\'' + t.id + '\',\'' + a.id + '\')">Promote</button>' +
        '<button class="alt-btn danger" onclick="deleteAlt(\'' + t.id + '\',\'' + a.id + '\')">Remove</button>' +
      '</div></td></tr>'
    ).join('') +
  '</tbody></table>';
}

document.getElementById('saveToonBtn').addEventListener('click', function () {
  clearMsg();
  const mainName    = document.getElementById('mainName').value.trim();
  const discordName = document.getElementById('discordName').value.trim();
  const discordId   = document.getElementById('discordId').value.trim();
  const mainClass   = document.getElementById('mainClass').value;
  const mainSpec    = document.getElementById('mainSpec').value;
  const altSpec     = document.getElementById('altSpec').value;
  const toonStatus  = document.getElementById('toonStatus').value || 'Main';
  const toonRank    = document.getElementById('toonRank').value || 'raider';
  const toonServer  = document.getElementById('toonServer').value.trim();
  const linkedMain  = document.getElementById('linkedMain').value;
  const fullT2      = document.getElementById('fullT2').checked;

  if (!mainName || !discordName || !mainClass || !mainSpec) {
    setMsg('Please fill in Name, Discord Name, Class and Main Spec.');
    return;
  }
  const nl = mainName.toLowerCase();
  let redirectId = null;

  if (editingId) {
    if (editingType === 'main') {
      const t = toons.find(x => x.id === editingId); if (!t) return;
      if (toons.some(x => x.id !== editingId && x.mainName.toLowerCase() === nl)) { setMsg('A main named "' + mainName + '" already exists.'); return; }
      t.mainName = mainName; t.discordName = discordName; t.discordId = discordId;
      (t.alts || []).forEach(a => a.discordName = discordName);
      t.class = mainClass; t.mainSpec = mainSpec; t.altSpec = altSpec;
      t.status = toonStatus; t.server = toonServer; t.fullT2 = fullT2; t.rank = toonRank;
      redirectId = editingId;
    } else {
      const par = toons.find(x => x.id === editingParentId); if (!par) return;
      const alt = (par.alts || []).find(x => x.id === editingId); if (!alt) return;
      if (!linkedMain) {
        if (toons.some(x => x.id !== editingId && x.mainName.toLowerCase() === nl)) { setMsg('A main named "' + mainName + '" already exists.'); return; }
        par.alts = par.alts.filter(x => x.id !== editingId);
        const nid = genId();
        toons.push({ id: nid, mainName, discordName, discordId, class: mainClass, mainSpec, altSpec, status: toonStatus, server: toonServer, fullT2, rank: toonRank, alts: [] });
        redirectId = nid;
      } else if (linkedMain !== editingParentId) {
        par.alts = par.alts.filter(x => x.id !== editingId);
        const np = toons.find(x => x.id === linkedMain);
        if (np) np.alts.push({ id: editingId, name: mainName, discordName, discordId, class: mainClass, mainSpec, altSpec, status: toonStatus, server: toonServer, fullT2 });
        redirectId = linkedMain;
      } else {
        alt.name = mainName; alt.discordName = discordName; alt.discordId = discordId;
        alt.class = mainClass; alt.mainSpec = mainSpec; alt.altSpec = altSpec;
        alt.status = toonStatus; alt.server = toonServer; alt.fullT2 = fullT2;
        redirectId = editingParentId;
      }
    }
  } else {
    if (linkedMain) {
      const par = toons.find(x => x.id === linkedMain);
      if (par) par.alts.push({ id: genId(), name: mainName, discordName, discordId, class: mainClass, mainSpec, altSpec, status: toonStatus, server: toonServer, fullT2 });
      redirectId = linkedMain;
    } else {
      if (toons.some(x => x.mainName.toLowerCase() === nl)) { setMsg('A main named "' + mainName + '" already exists.'); return; }
      const nid = genId();
      toons.push({ id: nid, mainName, discordName, discordId, class: mainClass, mainSpec, altSpec, status: toonStatus, server: toonServer, fullT2, rank: toonRank, alts: [] });
      redirectId = nid;
    }
  }

  saveToons().then(() => {
    renderLinkedMainOptions();
    if (redirectId) {
      activeMainId = redirectId;
      const t = toons.find(x => x.id === redirectId);
      if (t) loadMainIntoForm(t);
      renderList();
    } else { newMain(); }
  }).catch(() => {});
});

function deleteToon(id) {
  if (!confirm('Delete this toon and all its alts?')) return;
  toons = toons.filter(x => x.id !== id);
  saveToons(true).then(() => { renderLinkedMainOptions(); newMain(); }).catch(() => {});
}

function deleteAlt(mainId, altId) {
  if (!confirm('Remove this alt?')) return;
  const t = toons.find(x => x.id === mainId);
  if (t) {
    t.alts = t.alts.filter(x => x.id !== altId);
    saveToons().then(() => { renderAltsSection(t); renderList(); }).catch(() => {});
  }
}

function promoteAltToMain(mainId, altId) {
  const idx = toons.findIndex(x => x.id === mainId); if (idx === -1) return;
  const t = toons[idx];
  const a = (t.alts || []).find(x => x.id === altId); if (!a) return;
  if (!confirm('Promote "' + a.name + '" to main and make "' + t.mainName + '" an alt?')) return;
  const dem = { id: t.id, name: t.mainName, discordName: t.discordName, discordId: t.discordId || '', class: t.class, mainSpec: t.mainSpec, altSpec: t.altSpec || '' };
  const rem = (t.alts || []).filter(x => x.id && x.id !== altId);
  const promoted = { id: a.id, mainName: a.name, discordName: a.discordName || t.discordName, discordId: a.discordId || t.discordId || '', class: a.class, mainSpec: a.mainSpec, altSpec: a.altSpec || '', status: a.status || 'Main', server: a.server || t.server || '', fullT2: a.fullT2 || false, rank: t.rank || 'raider', alts: [dem, ...rem] };
  toons[idx] = promoted;
  saveToons().then(() => { activeMainId = promoted.id; renderLinkedMainOptions(); renderList(); loadMainIntoForm(promoted); }).catch(() => {});
}

let lmAllToons = [];
function renderLinkedMainOptions(selId) {
  lmAllToons = [...toons].sort((a, b) => a.mainName.localeCompare(b.mainName)).map(t => ({ id: t.id, name: t.mainName }));
  const h = document.getElementById('linkedMain'), s = document.getElementById('linkedMainSearch');
  h.value = selId || '';
  const f = selId ? lmAllToons.find(t => t.id === selId) : null;
  s.value = f ? f.name : '';
}
function lmFilter(q) {
  const l = q.trim().toLowerCase();
  const items = l ? lmAllToons.filter(t => t.name.toLowerCase().includes(l)) : lmAllToons;
  let html = '<li class="lm-opt lm-none" data-id="">— No linked main —</li>';
  html += items.length ? items.map(t => '<li class="lm-opt" data-id="' + t.id + '">' + esc(t.name) + '</li>').join('') : '<li class="lm-no-results">No match</li>';
  document.getElementById('lmList').innerHTML = html;
}
function lmSel(id, name) {
  document.getElementById('linkedMain').value = id;
  document.getElementById('linkedMainSearch').value = id ? name : '';
  document.getElementById('lmList').classList.remove('open');
  document.getElementById('linkedMain').dispatchEvent(new Event('change'));
}
(function () {
  const se = document.getElementById('linkedMainSearch'), le = document.getElementById('lmList');
  se.addEventListener('focus', function () { lmFilter(this.value); le.classList.add('open'); });
  se.addEventListener('input', function () { lmFilter(this.value); le.classList.add('open'); document.getElementById('linkedMain').value = ''; });
  se.addEventListener('keydown', function (e) {
    if (!le.classList.contains('open')) return;
    const opts = Array.from(le.querySelectorAll('.lm-opt')), act = le.querySelector('.lm-opt.active');
    let idx = act ? opts.indexOf(act) : -1;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (act) act.classList.remove('active'); opts[(idx + 1) % opts.length].classList.add('active'); le.querySelector('.lm-opt.active').scrollIntoView({ block: 'nearest' }); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (act) act.classList.remove('active'); opts[(idx - 1 + opts.length) % opts.length].classList.add('active'); le.querySelector('.lm-opt.active').scrollIntoView({ block: 'nearest' }); }
    else if (e.key === 'Enter') { e.preventDefault(); if (act) lmSel(act.dataset.id, act.dataset.id ? act.textContent : ''); }
    else if (e.key === 'Escape') { le.classList.remove('open'); const c = document.getElementById('linkedMain').value; const f = c ? lmAllToons.find(t => t.id === c) : null; se.value = f ? f.name : ''; }
  });
  le.addEventListener('mousedown', function (e) { const o = e.target.closest('.lm-opt'); if (!o) return; e.preventDefault(); lmSel(o.dataset.id, o.dataset.id ? o.textContent : ''); });
  document.addEventListener('mousedown', function (e) {
    if (!document.getElementById('lmWrap').contains(e.target)) {
      le.classList.remove('open');
      const c = document.getElementById('linkedMain').value, f = c ? lmAllToons.find(t => t.id === c) : null;
      se.value = f ? f.name : '';
    }
  });
})();
document.getElementById('linkedMain').addEventListener('change', function () {
  const p = toons.find(t => t.id === this.value);
  if (p) { document.getElementById('discordName').value = p.discordName || ''; document.getElementById('discordId').value = p.discordId || ''; }
});

document.getElementById('mainClass').addEventListener('change', function () {
  const specs = CLASS_SPECS[this.value] || [];
  const ms = document.getElementById('mainSpec'), as = document.getElementById('altSpec'), pv = ms.value;
  ms.innerHTML = '<option value="">-- Spec --</option>';
  as.innerHTML = '<option value="">-- None --</option>';
  specs.forEach(s => { ms.innerHTML += '<option>' + s + '</option>'; as.innerHTML += '<option>' + s + '</option>'; });
  if (specs.includes(pv)) ms.value = pv;
  const ip = this.value === 'Priest';
  document.getElementById('fullT2Group').style.display = ip ? '' : 'none';
  if (!ip) document.getElementById('fullT2').checked = false;
});

renderLinkedMainOptions();
renderList();
newMain();
</script>
</body>
</html>
