<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/nav.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

$role = require_role($tenant, 'admin');
$user = auth_user();
$pdo  = db_connect();

$stmt = $pdo->prepare('SELECT id, name, description, default_start_time, default_duration_minutes FROM raid_templates WHERE guild_id = ? ORDER BY name');
$stmt->execute([$tenant['id']]);
$raidTemplates = array_map(function ($t) {
    return [
        'id'                     => (int)$t['id'],
        'name'                   => $t['name'],
        'description'            => $t['description'],
        'defaultStartTime'       => $t['default_start_time'],
        'defaultDurationMinutes' => $t['default_duration_minutes'] !== null ? (int)$t['default_duration_minutes'] : null,
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

function h($s) { return htmlspecialchars($s ?? ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Design &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif;
      color: #e8ecff;
    }
    .wrap { max-width: 640px; width: 100%; margin: 0 auto; padding: 48px 24px 110px; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 14px; margin-bottom: 28px; }
    h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; margin: 0 0 12px; }
    .card {
      background: #111827; border: 1px solid rgba(255,255,255,0.08);
      border-radius: 10px; padding: 16px 18px; margin-bottom: 10px;
    }
    .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .name { font-size: 14px; font-weight: 600; }
    button.btn, a.btn {
      display: inline-block; padding: 7px 16px; font: inherit;
      background: #5865f2; border: none; border-radius: 999px; color: #fff;
      font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer;
    }
    button.btn:hover, a.btn:hover { background: #4752c4; }
    button.btn.danger { background: rgba(224,85,85,0.15); color: #e88585; }
    button.btn.danger:hover { background: rgba(224,85,85,0.3); }
    .empty { color: #7f8bad; font-size: 14px; padding: 8px 0; }
    .hint { color: #7f8bad; font-size: 12px; margin-top: 4px; }

    .hidden { display: none !important; }
    .form-group { margin-bottom: 12px; display: flex; flex-direction: column; gap: 5px; }
    .form-group label { font-size: 11px; color: #7f8bad; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .form-group input {
      padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px;
      background: #0a0f1e; color: #e8ecff; font-size: 13px; font: inherit;
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-row .form-group { margin-bottom: 0; }
    .btn-cancel-tpl { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); color: #a8b4d0; }
    .btn-cancel-tpl:hover { background: rgba(255,255,255,0.1); }

    .mode-tabs { display: flex; gap: 4px; }
    .tab-btn-pill {
      flex: 1; background: none; border: 1px solid rgba(255,255,255,0.12); color: #7f8bad;
      font: inherit; font-size: 12px; font-weight: 600; padding: 7px; border-radius: 7px; cursor: pointer;
    }
    .tab-btn-pill.active { background: rgba(88,101,242,0.15); border-color: rgba(88,101,242,0.4); color: #a3adfa; }
    .tab-btn-pill:disabled { opacity: 0.5; cursor: not-allowed; }

    .tpl-item { cursor: pointer; }
    .tpl-item .name { flex: 1; }
    .tpl-meta { font-size: 12px; color: #7f8bad; }
    .tpl-actions { display: flex; gap: 6px; }

    .section { margin-bottom: 32px; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'design'); ?>
  <div class="wrap">
    <h1>Design</h1>
    <p class="sub">Build and edit raid templates &mdash; their roster/assignment grid layout lives here. Scheduling which day runs which template is in Admin.</p>

    <div class="section">
      <h2>Raid templates</h2>
      <div id="templateList"></div>
      <div class="card" id="templateFormCard">
        <div class="form-group">
          <label for="tplName">Name</label>
          <input type="text" id="tplName" placeholder="e.g. Molten Core">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="tplStart">Default start time</label>
            <input type="time" id="tplStart">
          </div>
          <div class="form-group">
            <label for="tplDuration">Default duration (min)</label>
            <input type="number" id="tplDuration" min="0" step="15" placeholder="e.g. 180">
          </div>
        </div>
        <div class="form-group">
          <label for="tplDesc">Description</label>
          <input type="text" id="tplDesc" placeholder="Optional notes">
        </div>
        <div class="form-buttons" style="display:flex;gap:8px;margin-top:10px;">
          <button class="btn" id="tplSaveBtn">Save template</button>
          <button class="btn btn-cancel-tpl hidden" id="tplCancelEditBtn">Cancel edit</button>
          <a class="btn btn-cancel-tpl hidden" id="tplEditStructureBtn" href="#">Edit structure</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    const SLUG = <?= json_encode($slug) ?>;
    const TPL_SAVE_URL = <?= json_encode('/raids/templates-save.php?slug=' . $slug) ?>;
    let templates = <?= json_encode($raidTemplates) ?>;
    let editingTplId = null;

    function escH(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function renderTemplateList() {
      const el = document.getElementById('templateList');
      if (!templates.length) { el.innerHTML = '<p class="empty">No templates yet — add one below.</p>'; return; }
      el.innerHTML = templates.map(t => {
        const meta = [];
        if (t.defaultStartTime) meta.push(t.defaultStartTime.slice(0,5));
        if (t.defaultDurationMinutes) meta.push(t.defaultDurationMinutes + ' min');
        return `<div class="card row tpl-item">
          <div class="name" onclick="editTemplate(${t.id})">${escH(t.name)}${meta.length ? ' <span class="tpl-meta">(' + meta.join(', ') + ')</span>' : ''}</div>
          <div class="tpl-actions">
            <a class="btn" style="padding:5px 12px;font-size:12px;" href="/raids/template-edit.php?slug=${encodeURIComponent(SLUG)}&id=${t.id}">Structure</a>
            <button class="btn" style="padding:5px 12px;font-size:12px;" onclick="editTemplate(${t.id})">Edit</button>
            <button class="btn danger" style="padding:5px 12px;font-size:12px;" onclick="deleteTemplate(${t.id})">Delete</button>
          </div>
        </div>`;
      }).join('');
    }

    function editTemplate(id) {
      const t = templates.find(x => x.id === id);
      if (!t) return;
      editingTplId = id;
      document.getElementById('tplName').value = t.name;
      document.getElementById('tplStart').value = (t.defaultStartTime || '').slice(0, 5);
      document.getElementById('tplDuration').value = t.defaultDurationMinutes || '';
      document.getElementById('tplDesc').value = t.description || '';
      document.getElementById('tplCancelEditBtn').classList.remove('hidden');
      const structBtn = document.getElementById('tplEditStructureBtn');
      structBtn.href = '/raids/template-edit.php?slug=' + encodeURIComponent(SLUG) + '&id=' + id;
      structBtn.classList.remove('hidden');
    }

    function resetTemplateForm() {
      editingTplId = null;
      document.getElementById('tplName').value = '';
      document.getElementById('tplStart').value = '';
      document.getElementById('tplDuration').value = '';
      document.getElementById('tplDesc').value = '';
      document.getElementById('tplCancelEditBtn').classList.add('hidden');
      document.getElementById('tplEditStructureBtn').classList.add('hidden');
    }
    document.getElementById('tplCancelEditBtn').addEventListener('click', resetTemplateForm);

    document.getElementById('tplSaveBtn').addEventListener('click', function () {
      const name = document.getElementById('tplName').value.trim();
      if (!name) return;
      const payload = {
        action: 'save',
        id: editingTplId,
        name,
        defaultStartTime: document.getElementById('tplStart').value || null,
        defaultDurationMinutes: document.getElementById('tplDuration').value ? parseInt(document.getElementById('tplDuration').value, 10) : null,
        description: document.getElementById('tplDesc').value.trim() || null,
      };
      fetch(TPL_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json()).then(d => {
          if (!d.success) return;
          const i = templates.findIndex(x => x.id === d.template.id);
          if (i === -1) templates.push(d.template); else templates[i] = d.template;
          renderTemplateList();
          resetTemplateForm();
        });
    });

    function deleteTemplate(id) {
      if (!confirm('Delete this template? Any recurring days using it will stop auto-populating.')) return;
      fetch(TPL_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) })
        .then(r => r.json()).then(d => {
          if (!d.success) return;
          templates = templates.filter(t => t.id !== id);
          renderTemplateList();
        });
    }

    renderTemplateList();
  </script>
</body>
</html>
