<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/nav.php';
require_once __DIR__ . '/../includes/raid_fetch.php';
require_once __DIR__ . '/../includes/class_roles.php';

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo 'No such guild.';
    exit;
}

$role = require_role($tenant, 'readonly');
$user = auth_user();
$pdo  = db_connect();

$raidId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT * FROM raids WHERE id = ? AND guild_id = ?');
$stmt->execute([$raidId, $tenant['id']]);
$raid = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$raid) {
    http_response_code(404);
    echo 'Raid not found.';
    exit;
}

$canManage = role_at_least($role, 'raid_management');
$isAdmin = role_at_least($role, 'admin');
$templateId = $raid['template_id'] !== null ? (int)$raid['template_id'] : null;

// AngryERA export config is per-tab (per distinct section `kind`) and, unlike the rest of
// this page's editing tools, is available to anyone with at least readonly access — not
// gated behind $canManage. Only enabled tabs are exposed here.
$tabExports = [];
if ($templateId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_tab_exports WHERE template_id = ? AND enabled = 1');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $te) {
        $stmt2 = $pdo->prepare('SELECT id, name, template FROM raid_template_export_pages WHERE tab_export_id = ? ORDER BY sort_order, id');
        $stmt2->execute([$te['id']]);
        $pages = array_map(fn($p) => [
            'id' => (int)$p['id'], 'name' => $p['name'], 'template' => $p['template'],
        ], $stmt2->fetchAll(PDO::FETCH_ASSOC));
        $tabExports[$te['kind']] = [
            'singlePage' => (bool)$te['single_page'],
            'exportName' => $te['export_name'],
            'pages'      => $pages,
        ];
    }
}

$sections = fetch_raid_structure($pdo, $raidId);

$roster = [];
$pool = [];
$webhooks = [];
if ($canManage) {
    $stmt = $pdo->prepare('SELECT id, main_name, class, status, main_spec, full_t2 FROM toons WHERE guild_id = ? ORDER BY main_name');
    $stmt->execute([$tenant['id']]);
    $mains = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT id, main_id, name, class, status, main_spec, full_t2 FROM toon_alts WHERE guild_id = ? ORDER BY name');
    $stmt->execute([$tenant['id']]);
    $altsByMain = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $altsByMain[$a['main_id']][] = ['id' => $a['id'], 'name' => $a['name'], 'class' => $a['class'], 'status' => $a['status'], 'spec' => $a['main_spec'], 'fullT2' => (bool)$a['full_t2']];
    }

    $roster = array_map(fn($t) => [
        'id' => $t['id'], 'name' => $t['main_name'], 'class' => $t['class'], 'status' => $t['status'],
        'spec' => $t['main_spec'], 'fullT2' => (bool)$t['full_t2'],
        'alts' => $altsByMain[$t['id']] ?? [],
    ], $mains);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.toon_kind, p.toon_id, p.pug_name, p.pug_class, p.role, p.sort_order,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class,
                COALESCE(t.main_spec, a.main_spec) AS toon_spec,
                COALESCE(t.full_t2, a.full_t2) AS toon_full_t2
         FROM raid_pool p
         LEFT JOIN toons t ON p.toon_kind = \'main\' AND t.id = p.toon_id
         LEFT JOIN toon_alts a ON p.toon_kind = \'alt\' AND a.id = p.toon_id
         WHERE p.raid_id = ?
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$raidId]);
    $pool = array_map(function ($p) {
        $isPug = $p['toon_kind'] === 'pug';
        $class = $isPug ? $p['pug_class'] : $p['toon_class'];
        $spec  = $isPug ? null : $p['toon_spec'];
        return [
            'id' => (int)$p['id'], 'toonKind' => $p['toon_kind'], 'toonId' => $p['toon_id'],
            'pugName' => $p['pug_name'], 'pugClass' => $p['pug_class'],
            'name' => $isPug ? $p['pug_name'] : $p['toon_name'],
            'class' => $class,
            'spec' => $spec,
            'fullT2' => $isPug ? false : (bool)$p['toon_full_t2'],
            'role' => $p['role'] ?: default_role_for_class($class, $spec),
            'roleConfirmed' => $p['role'] !== null,
            'sortOrder' => (int)$p['sort_order'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT id, name, webhook_url FROM webhooks WHERE guild_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tenant['id']]);
    $webhooks = array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name'], 'url' => $w['webhook_url']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function h($s) { return htmlspecialchars($s ?? ''); }
function fmtTime($t) {
    if (!$t) return '';
    [$hh, $mm] = explode(':', $t);
    $h12 = (((int)$hh + 11) % 12) + 1;
    return $h12 . ':' . $mm . ((int)$hh < 12 ? 'am' : 'pm');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($raid['name']) ?> &mdash; <?= h($tenant['name']) ?> &mdash; RaidRoster</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <?= nav_asset_link() ?>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; background: #0a0f1e; font-family: system-ui, -apple-system, sans-serif; color: #e8ecff; }
    .wrap { max-width: 100%; margin: 0; padding: 32px 472px 110px 32px; transition: padding-right .18s ease; }
    body.pool-minimized .wrap { padding-right: 32px; }
    .raid-toolbar-stack { position: sticky; top: 0; z-index: 60; background: #0a0f1e; padding: 10px 0 6px; margin: 0 -32px; padding-left: 32px; padding-right: 32px; }
    .lock-sync-row { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
    .back { color: #7f8bad; font-size: 12px; text-decoration: none; }
    .back:hover { color: #a3adfa; }
    h1 { font-size: 22px; margin: 10px 0 4px; }
    p.sub { color: #a8b4d0; font-size: 13px; margin-bottom: 4px; }
    .status-cancelled { color: #e88585; font-weight: 700; }
    .readonly-note { color: #7f8bad; font-size: 12px; margin: 6px 0 20px; }

    .tabs-row { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin: 10px 0 4px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .tab-btn { background: none; border: none; font: inherit; cursor: pointer; color: #7f8bad; font-size: 13px; font-weight: 600; padding: 7px 14px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .tab-btn:hover { color: #c7cef2; }
    .tab-btn.active { color: #e8ecff; border-bottom-color: #5865f2; }

    .section-card { border-radius: 12px; overflow: hidden; margin: 22px 0; border: 1px solid rgba(255,255,255,0.08); }
    .section-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 18px; font-size: 15px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #fff; }
    .section-clear-btn { font-size: 10px; font-weight: 700; letter-spacing: normal; text-transform: none; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 5px 10px; border-radius: 999px; cursor: pointer; }
    .section-clear-btn:hover { background: rgba(0,0,0,0.4); }
    .section-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .mrt-export-bar { display: flex; align-items: center; gap: 6px; }
    .btn-mrt-export {
      font-size: 10px; font-weight: 700; letter-spacing: normal; text-transform: none;
      padding: 5px 10px; border-radius: 999px; cursor: pointer;
      background: rgba(255,180,50,0.18); border: 1px solid rgba(255,180,50,0.4); color: #ffdb8a;
    }
    .btn-mrt-export:hover { background: rgba(255,180,50,0.3); }
    .mrt-info {
      display: inline-flex; align-items: center; justify-content: center;
      width: 16px; height: 16px; border-radius: 50%; font-size: 10px; font-weight: 700;
      font-style: normal; text-transform: none; letter-spacing: normal;
      background: rgba(255,180,50,0.2); border: 1px solid rgba(255,180,50,0.4);
      color: #ffdb8a; cursor: default; position: relative; flex-shrink: 0;
    }
    .mrt-info::after {
      content: attr(data-tip);
      position: absolute; top: calc(100% + 8px); right: 0;
      width: 240px; padding: 8px 10px;
      background: #1a2340; border: 1px solid rgba(255,180,50,0.4);
      border-radius: 6px; color: #d4b870; font-size: 11px; font-weight: 400;
      line-height: 1.5; white-space: normal; text-align: left;
      pointer-events: none; opacity: 0; transition: opacity 0.15s;
      z-index: 99;
    }
    .mrt-info:hover::after { opacity: 1; }
    .section-body { background: #111827; padding: 16px 18px; display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; }
    .tbl-wrap { min-width: 0; max-width: 100%; }
    .tbl-wrap .grid-scroll + .grid-scroll { margin-top: 2px; }
    .tbl-title {
      font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
      color: #a8b4d0; background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 6px 6px 0 0;
    }
    .group-tables { display: flex; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 18px; margin: 8px 0 4px 4px; padding-left: 10px; border-left: 3px solid rgba(255,255,255,0.15); }
    .grid-scroll { overflow-x: auto; }
    table.grid { border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
    table.grid th, table.grid td { border: 1px solid rgba(255,255,255,0.08); padding: 8px 8px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
    table.grid td[rowspan] { vertical-align: top; }
    table.grid th { background: rgba(255,255,255,0.04); color: #a8b4d0; font-weight: 800; white-space: nowrap; }
    table.grid td.text-cell { text-align: left; white-space: nowrap; }
    table.grid td.swap-note-cell { cursor: pointer; }
    table.grid td.swap-note-cell:hover { background: rgba(88,101,242,0.14); }
    table.grid td.swap-note-cell .empty-slot { opacity: 0.35; }
    table.grid td.icon-cell { text-align: center; }
    table.grid th.group-th { font-size: 13px; letter-spacing: .04em; }
    td.cell { min-width: 90px; background: rgba(255,255,255,0.04); }
    /* Assignment-slot cells (whether filled or the "+" placeholder) get zero td padding --
       the padding lives on the inner span instead, sized identically in both states, so a
       row's height never shifts as its cells fill in one by one. */
    td.cell.slot { padding: 0; }
    .inline-raid-icon { margin: 0 1px; }
    .raid-icon-cell { display: inline-block; }
    th.spacer-th, td.spacer-cell { background: none; border-color: transparent; padding: 8px 4px; }

    .toon-chip {
      position: relative; display: inline-flex; align-items: center; gap: 4px; border-radius: 5px; padding: 3px 10px;
      font-size: 11px; font-weight: 700; color: #000; white-space: nowrap; cursor: default; max-width: 100%;
    }
    /* Crop instead of expanding the cell/column: the name is the only shrinkable child of
       the chip's flex row, so it's the only thing that clips when space runs out. */
    .chip-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex-shrink: 1; }
    /* Discord export fills each assigned cell edge-to-edge with the class color; mirror
       that on-screen instead of a small pill floating in a dark cell, so the live table
       looks the same as the posted image. Matched by .empty-slot below at the same font
       size/padding so a row's height doesn't change depending on which of its cells are
       filled yet -- a size mismatch here is what causes a row to visibly grow/shrink as
       its last empty slot gets assigned. */
    td.cell.slot .toon-chip {
      display: flex; width: 100%; height: 100%; box-sizing: border-box; border-radius: 0;
      justify-content: flex-start; padding: 8px; font-size: 13px; letter-spacing: .02em;
      text-transform: uppercase; text-shadow: 0 1px 1px rgba(0,0,0,0.2);
    }
    td.cell.editable .toon-chip[draggable="true"] { cursor: grab; }
    td.cell.editable.drop-hover { background: rgba(88,101,242,0.18); }
    td.cell.editable.drop-forbidden { background: rgba(220,80,80,0.28); outline: 2px solid #dc5050; outline-offset: -2px; }
    .chip-clear {
      display: inline-flex; align-items: center; justify-content: center; width: 13px; height: 13px;
      margin-left: 2px; border-radius: 50%; background: rgba(0,0,0,0.25); color: inherit; font-size: 11px;
      line-height: 1; cursor: pointer; flex-shrink: 0;
    }
    td.cell.slot .toon-chip .chip-clear { margin-left: auto; }
    .chip-clear:hover { background: rgba(0,0,0,0.45); }
    td.cell.slot .empty-slot {
      display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;
      box-sizing: border-box; padding: 8px; color: #4a5578; font-size: 13px;
    }
    .role-icon-sm {
      width: 16px; height: 16px; background-image: url('/assets/img/raid-roles.png');
      background-size: 300% 100%; background-repeat: no-repeat; display: inline-block;
      flex-shrink: 0; border-radius: 2px;
    }
    .role-icon-sm.role-tank   { background-position: 0% 0; }
    .role-icon-sm.role-healer { background-position: 50% 0; }
    .role-icon-sm.role-dps    { background-position: 100% 0; }
    .role-icon-sm.role-clickable { cursor: pointer; }
    .role-icon-sm.role-clickable:hover { outline: 1px solid rgba(255,255,255,.5); }
    .t2-badge {
      display: inline-block; padding: 0 4px; margin-left: auto; border-radius: 3px;
      font-size: 9px; font-weight: 800; background: #c8a020; color: #000;
      border: 1px solid rgba(0,0,0,0.35); letter-spacing: 0.02em; vertical-align: middle;
      line-height: 14px; flex-shrink: 0;
    }
    .section-note { margin: 6px 18px 0; font-size: 12px; font-weight: 700; color: #f0c04a; }
    .chip-marker { display: inline-block; margin-left: 4px; font-weight: 800; font-size: 11px; line-height: 1; color: rgba(255,255,255,0.35); }
    .chip-marker.clickable { cursor: pointer; }
    .chip-marker.active { color: #ffd76e; }

    .empty { color: #7f8bad; font-size: 13px; padding: 8px 0; }

    .pool-toolbar { margin: 8px 0 4px; }
    .btn-pool-toggle { background: rgba(88,101,242,0.15); border: 1px solid rgba(88,101,242,0.4); color: #b9c0ff; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
    .btn-pool-toggle:hover { background: rgba(88,101,242,0.28); }
    .pool-drawer {
      position: fixed; top: 0; right: 0; bottom: 0; width: 440px; max-width: 90vw; z-index: 400;
      background: #111827; border-left: 1px solid rgba(255,255,255,0.1); box-shadow: -8px 0 24px rgba(0,0,0,0.35);
      display: flex; flex-direction: column; padding: 16px; gap: 12px; overflow-y: auto;
      transform: translateX(0); transition: transform .18s ease, border-color .18s ease;
    }
    body.pool-minimized .pool-drawer { transform: translateX(100%); }
    .pool-drawer.stamp-mode { border-left-color: #f0c04a; box-shadow: -8px 0 24px rgba(240,192,74,0.25); }
    .pool-drawer-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .pool-drawer-head h2 { font-size: 15px; }
    .pool-drawer-handle {
      display: block; position: fixed; top: 50%; right: 440px; transform: translateY(-50%); z-index: 401;
      writing-mode: vertical-rl; text-orientation: mixed;
      background: #1a2338; border: 1px solid rgba(255,255,255,0.14); border-right: none; color: #a8b4d0;
      font-size: 11px; font-weight: 700; letter-spacing: .04em; border-radius: 8px 0 0 8px;
      padding: 12px 6px; cursor: pointer; transition: right .18s ease, color .18s ease, background .18s ease;
    }
    .pool-drawer-handle:hover { color: #e8ecff; background: #232e4d; }
    body.pool-minimized .pool-drawer-handle { display: none; }
    #poolReopenTab {
      display: none; position: fixed; top: 50%; right: 0; transform: translateY(-50%); z-index: 401;
      writing-mode: vertical-rl; text-orientation: mixed;
      background: #4a63e0; color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .04em;
      border: none; border-radius: 8px 0 0 8px; padding: 14px 8px; cursor: pointer;
      box-shadow: -4px 0 16px rgba(0,0,0,0.35);
    }
    body.pool-minimized #poolReopenTab { display: block; }
    .pool-search-wrap { position: relative; }
    #poolSearchInput {
      width: 100%; padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px;
      background: #0a0f1e; color: #e8ecff; font-size: 12.5px; font: inherit;
    }
    .pool-search-results {
      display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 10;
      max-height: 200px; overflow-y: auto; background: #0a0f1e; border: 1px solid rgba(255,255,255,0.15);
      border-radius: 7px; box-shadow: 0 8px 22px rgba(0,0,0,0.4);
    }
    .pool-search-results.open { display: block; }
    .pool-search-item { padding: 7px 10px; font-size: 12px; color: #c7cef2; cursor: pointer; }
    .pool-search-item:hover { background: rgba(88,101,242,0.15); color: #e8ecff; }
    .pool-search-empty { color: #7f8bad; font-style: italic; cursor: default; }
    .pool-search-empty:hover { background: none; }
    .alt-popup {
      position: absolute; z-index: 500; min-width: 150px; max-width: 240px;
      background: #0a0f1e; border: 1px solid rgba(255,255,255,0.15); border-radius: 7px;
      box-shadow: 0 8px 22px rgba(0,0,0,0.4); overflow: hidden; padding: 4px;
    }
    .alt-popup-item {
      display: flex; align-items: center; gap: 7px; padding: 7px 8px; font-size: 12.5px;
      color: #c7cef2; cursor: pointer; border-radius: 5px; white-space: nowrap;
    }
    .alt-popup-item:hover { background: rgba(88,101,242,0.18); color: #e8ecff; }
    .alt-popup-item.current { color: #7f8bad; cursor: default; font-style: italic; }
    .alt-popup-item.current:hover { background: none; }
    .alt-popup-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: var(--dot, #8892b0); }
    .swap-note-popup {
      position: absolute; z-index: 500; width: 220px;
      background: #0a0f1e; border: 1px solid rgba(255,255,255,0.15); border-radius: 7px;
      box-shadow: 0 8px 22px rgba(0,0,0,0.4); padding: 10px;
    }
    .swap-note-popup label { display: block; font-size: 11px; color: #7f8bad; margin: 8px 0 3px; }
    .swap-note-popup label:first-child { margin-top: 0; }
    .swap-note-popup input, .swap-note-popup select {
      width: 100%; padding: 6px 8px; border: 1px solid rgba(255,255,255,0.12); border-radius: 6px;
      background: #131a30; color: #e8ecff; font-size: 12.5px; font: inherit; box-sizing: border-box;
    }
    .swap-note-popup .swap-note-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }
    .swap-note-popup button {
      padding: 5px 12px; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; font: inherit;
    }
    .swap-note-popup .swap-note-save { background: #5865f2; color: #fff; }
    .swap-note-popup .swap-note-cancel { background: rgba(255,255,255,0.08); color: #c7cef2; }
    .pool-add-pug { display: flex; gap: 6px; }
    #pugNameInput { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; background: #0a0f1e; color: #e8ecff; font-size: 12.5px; font: inherit; }
    #pugClassInput { padding: 8px 6px; border: 1px solid rgba(255,255,255,0.12); border-radius: 7px; background: #0a0f1e; color: #e8ecff; font-size: 12px; font: inherit; }
    #pugAddBtn { padding: 8px 12px; border: none; border-radius: 7px; background: #4a63e0; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer; }
    #pugAddBtn:hover { background: #3b52c4; }
    .pool-list { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 8px; align-content: start; }
    .pool-chip-row { display: flex; align-items: center; justify-content: space-between; gap: 4px; min-width: 0; }
    .pool-chip { flex: 1; min-width: 0; cursor: grab; justify-content: flex-start; overflow: hidden; text-overflow: ellipsis; }
    .toon-chip.stamped { outline: 2px solid #f0c04a; outline-offset: 1px; }
    .pool-tag { font-size: 9px; font-weight: 700; opacity: .75; margin-left: 4px; }
    .pool-tag.pug { color: #5a3d00; }
    .pool-remove { flex-shrink: 0; background: none; border: none; color: #7f8bad; font-size: 15px; cursor: pointer; line-height: 1; padding: 2px 4px; }
    .pool-remove:hover { color: #e88585; }
    .pool-empty { color: #7f8bad; font-size: 12px; padding: 8px 0; }
    .pool-hint { color: #55607a; font-size: 11px; line-height: 1.5; margin-top: auto; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.06); }

    .lock-bar { margin: 8px 0 4px; }
    .lock-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #a8b4d0; cursor: pointer; user-select: none; }
    .lock-toggle input { display: none; }
    .lock-switch { width: 34px; height: 19px; border-radius: 999px; background: rgba(255,255,255,0.15); position: relative; transition: background .15s; flex-shrink: 0; }
    .lock-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 15px; height: 15px; border-radius: 50%; background: #e8ecff; transition: transform .15s; }
    .lock-toggle input:checked + .lock-switch { background: #4caf6a; }
    .lock-toggle input:checked + .lock-switch::after { transform: translateX(15px); }
    .lock-banner { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 8px; background: rgba(240,128,48,0.1); border: 1px solid rgba(240,128,48,0.3); color: #f0a030; font-size: 12px; }
    .lock-banner button.btn { background: #e05555; padding: 5px 12px; font-size: 11px; border: none; border-radius: 999px; color: #fff; font-weight: 600; cursor: pointer; }
    .lock-banner button.btn:hover { background: #c94444; }
    .lock-status { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #8fe0a8; }
    .lock-dot { width: 8px; height: 8px; border-radius: 50%; background: #4caf6a; flex-shrink: 0; }
    .lock-release-btn { background: none; border: 1px solid rgba(255,255,255,0.15); color: #a8b4d0; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 11px; cursor: pointer; }
    .lock-release-btn:hover { border-color: rgba(255,255,255,0.4); color: #e8ecff; }
    .lock-status.lock-released { color: #a8b4d0; }
    .lock-status.lock-released .lock-release-btn { border-color: rgba(88,101,242,0.4); color: #b9c0ff; }

    .sync-bar { margin: 8px 0 4px; }
    .btn-sync { background: rgba(88,101,242,0.15); border: 1px solid rgba(88,101,242,0.4); color: #b9c0ff; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
    .btn-sync:hover:not(:disabled) { background: rgba(88,101,242,0.28); }
    .btn-sync:disabled { opacity: .4; cursor: not-allowed; }

    .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    .modal-backdrop.open { display: flex; }
    .modal.sync-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; }
    .modal.sync-modal h2 { font-size: 17px; margin-bottom: 14px; }
    .diff-group { margin: 14px 0; }
    .diff-group h3 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #7f8bad; margin-bottom: 6px; }
    .diff-list { list-style: none; font-size: 13px; }
    .diff-list li { padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .diff-added { color: #6fd58a; }
    .diff-removed { color: #e88585; }
    .diff-changed { color: #f0c04a; }
    .diff-changed .fields { color: #7f8bad; font-size: 11px; }
    .diff-empty { color: #7f8bad; font-size: 13px; padding: 10px 0; }
    .removal-warning { background: rgba(224,85,85,0.1); border: 1px solid rgba(224,85,85,0.35); color: #f0a0a0; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; margin: 14px 0; }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    .modal-actions button { font-size: 13px; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
    .btn-cancel { background: rgba(255,255,255,0.08); color: #e8ecff; }
    .btn-cancel:hover { background: rgba(255,255,255,0.15); }
    .btn-confirm { background: #4a63e0; color: #fff; }
    .btn-confirm:hover { background: #3b52c4; }
    .btn-confirm.danger { background: #c94444; }
    .btn-confirm.danger:hover { background: #b03636; }
    .btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
    .modal.import-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; }
    .modal.import-modal h2 { font-size: 17px; margin-bottom: 14px; }
    .import-url-row { display: flex; gap: 8px; }
    .import-url-row input { flex: 1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 9px 12px; color: #e8ecff; font-size: 13px; }
    .import-hint { font-size: 11px; color: #7f8bad; margin: 6px 0 0; }
    .import-status { font-size: 12px; color: #b9c0ff; margin: 10px 0; }
    .import-rows { display: flex; flex-direction: column; gap: 5px; margin-top: 12px; max-height: 340px; overflow-y: auto; }
    .import-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,0.04); font-size: 12px; }
    .import-row .name { font-weight: 600; color: #e8ecff; }
    .import-row .detail { color: #9aa4c7; }
    .import-row.matched .status { color: #6fd88a; }
    .import-row.unmatched .status { color: #f0c04a; }
    .import-row.added .status { color: #6fd88a; font-weight: 700; }
    .import-row button { font-size: 11px; padding: 5px 10px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; background: #4a63e0; color: #fff; }
    .import-row button:disabled { opacity: .5; cursor: not-allowed; }
    .modal.discord-modal { background: #111827; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 22px; width: 100%; max-width: 720px; max-height: 90vh; overflow-y: auto; }
    .modal.discord-modal h2 { font-size: 17px; margin-bottom: 14px; }
    .discord-preview-wrap { display: flex; flex-direction: column; gap: 14px; max-height: 46vh; overflow-y: auto; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; background: #05070f; padding: 10px; }
    .discord-preview-item { text-align: center; }
    .discord-preview-item canvas { max-width: 100%; height: auto; border-radius: 4px; }
    .discord-preview-label { font-size: 10px; font-weight: 700; color: #7f8bad; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; }
    .discord-field { margin-top: 12px; }
    .discord-field label { display: block; font-size: 11px; font-weight: 700; color: #7f8bad; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; }
    .discord-field select, .discord-field textarea { width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 9px 12px; color: #e8ecff; font-size: 13px; font-family: inherit; }
    .discord-field textarea { resize: vertical; min-height: 60px; }
  </style>
</head>
<body>
  <?php render_nav_shell($tenant, $user, $role, 'raids'); ?>
  <div class="wrap">
    <a class="back" href="/<?= h($slug) ?>/raids">&larr; Back to calendar</a>
    <h1><?= h($raid['name']) ?><?php if ($raid['status'] === 'cancelled'): ?> <span class="status-cancelled">(cancelled)</span><?php endif; ?></h1>
    <div class="raid-toolbar-stack">
      <?php if ($canManage || ($isAdmin && $templateId !== null)): ?>
      <div class="lock-sync-row">
        <?php if ($canManage): ?><div class="lock-bar" id="lockBar"></div><?php endif; ?>
        <?php if ($isAdmin && $templateId !== null): ?><div class="sync-bar" id="syncBar"></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($tabExports): ?>
      <div class="pool-toolbar" id="eraExportToolbar">
        <?php foreach ($tabExports as $k => $te): ?>
          <button type="button" class="btn-pool-toggle" data-era-kind="<?= h($k) ?>">Export: <?= h($te['exportName'] ?: $k) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($canManage): ?><div class="pool-toolbar"><button type="button" class="btn-pool-toggle" id="discordToggleBtn">Discord post</button> <button type="button" class="btn-pool-toggle" id="clearAllBtn">Clear all</button></div><?php endif; ?>
    </div>
    <p class="sub"><?= h($raid['raid_date']) ?><?php if ($raid['start_time']): ?> &middot; <?= h(fmtTime($raid['start_time'])) ?><?php endif; ?></p>
    <?php if (!$sections): ?>
      <p class="empty">This raid has no roster/assignment structure (its template may not have one, or it was created without one).</p>
    <?php elseif (!$canManage): ?>
      <p class="readonly-note">You need raid management permission to edit assignments.</p>
    <?php endif; ?>

    <div class="tabs-row" id="tabsRowEl" hidden></div>
    <div id="sectionsEl"></div>
  </div>

  <div class="modal-backdrop" id="syncModalBackdrop">
    <div class="modal sync-modal" id="syncModalContent"></div>
  </div>

  <?php if ($canManage): ?>
  <div class="modal-backdrop" id="importModalBackdrop">
    <div class="modal import-modal">
      <h2>Import Raid signups</h2>
      <div class="import-url-row">
        <input type="text" id="importUrlInput" placeholder="Paste Raid-Helper event URL or ID&hellip;" autocomplete="off">
        <button type="button" class="btn-confirm" id="importFetchBtn">Fetch</button>
      </div>
      <p class="import-hint">Matched signups resolve to an existing main/alt; unmatched ones can be added as a one-off PUG. Nothing is added to the pool until you confirm below.</p>
      <div class="import-status" id="importStatus"></div>
      <div class="import-rows" id="importRows"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" id="importCloseBtn">Close</button>
        <button type="button" class="btn-confirm" id="importAllBtn" disabled>Add all to pool</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <div class="modal-backdrop" id="discordModalBackdrop">
    <div class="modal discord-modal">
      <h2>Post to Discord</h2>
      <div class="discord-preview-wrap" id="discordPreviewWrap"></div>
      <div class="discord-field">
        <label for="discordWebhookSelect">Webhook</label>
        <select id="discordWebhookSelect">
          <?php if (!$webhooks): ?><option value="">No webhooks configured</option><?php endif; ?>
          <?php foreach ($webhooks as $w): ?><option value="<?= h($w['url']) ?>"><?= h($w['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="discord-field">
        <label for="discordMessageInput">Message (optional)</label>
        <textarea id="discordMessageInput" placeholder="Add a note to post alongside the image&hellip;"></textarea>
      </div>
      <div class="import-status" id="discordStatus"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" id="discordCloseBtn">Close</button>
        <button type="button" class="btn-confirm" id="discordPostBtn">Post</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <button type="button" class="pool-drawer-handle" id="poolMinimizeBtn" title="Minimize the Available Toons sidebar">Minimize &raquo;</button>
  <div class="pool-drawer" id="poolDrawer">
    <div class="pool-drawer-head">
      <h2>Available toons</h2>
      <button type="button" class="btn-pool-toggle" id="importToggleBtn">Import Raid</button>
    </div>
    <div class="pool-search-wrap">
      <input type="text" id="poolSearchInput" placeholder="Search roster to add&hellip;" autocomplete="off">
      <div class="pool-search-results" id="poolSearchResults"></div>
    </div>
    <div class="pool-add-pug">
      <input type="text" id="pugNameInput" placeholder="PUG name">
      <select id="pugClassInput">
        <option value="">Class</option>
        <option>Warrior</option><option>Paladin</option><option>Priest</option><option>Druid</option>
        <option>Mage</option><option>Rogue</option><option>Warlock</option><option>Shaman</option><option>Hunter</option>
      </select>
      <button type="button" id="pugAddBtn">Add</button>
    </div>
    <div class="pool-list" id="poolList"></div>
    <p class="pool-hint">Drag a toon onto a slot to assign it. Alt+Click an assigned toon to see its alts. Ctrl/Cmd+Click a pool toon to stamp it repeatedly onto empty slots.</p>
  </div>
  <button type="button" id="poolReopenTab" title="Open the Available Toons sidebar">&laquo; Available Toons</button>
  <?php endif; ?>

<script>
const SLUG = <?= json_encode($slug) ?>;
const CAN_MANAGE = <?= json_encode($canManage) ?>;
const CELLS_SAVE_URL = <?= json_encode('/raids/cells-save.php?slug=' . $slug) ?>;
const LOCK_URL = <?= json_encode('/raids/lock-save.php?slug=' . $slug) ?>;
const IS_ADMIN = <?= json_encode($isAdmin) ?>;
const TEMPLATE_ID = <?= json_encode($templateId) ?>;
const PUSH_TEMPLATE_URL = <?= json_encode('/raids/push-template.php?slug=' . $slug) ?>;
const RAID_ID = <?= json_encode($raidId) ?>;
const USER_ID = <?= json_encode($user['id']) ?>;
let sections = groupSectionsByKind(<?= json_encode($sections) ?>);
const roster = <?= json_encode($roster) ?>;
let pool = <?= json_encode($pool) ?>;
const POOL_SAVE_URL = <?= json_encode('/raids/pool-save.php?slug=' . $slug) ?>;
const IMPORT_URL = <?= json_encode('/raids/import-signups.php?slug=' . $slug) ?>;
const WEBHOOKS = <?= json_encode($webhooks) ?>;
const TAB_EXPORTS = <?= json_encode($tabExports) ?>;
let stampToon = null;
let importRows = [];
let activeAltPopup = null; // currently-open Alt+Click sibling-picker popup, if any

// The in-flight drag payload, captured at dragstart so dragover can check assignment
// rules against it -- dataTransfer.getData() is not readable during dragover in most
// browsers, only at drop, so this same-page mirror is the only way to preview a
// violation while the drag is still in progress.
let dragPayload = null;

// Editing lock: advisory only, warns concurrent raid managers off each
// other's structural edits. Only relevant to users who can manage the raid.
let lockHeldByMe = false;
let lockedByOther = null;
let lockHeartbeatTimer = null;

function lockCall(action) {
  return fetch(LOCK_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, raidId: RAID_ID }) })
    .then(r => r.json());
}
function startHeartbeat() {
  stopHeartbeat();
  lockHeartbeatTimer = setInterval(() => {
    lockCall('lock_heartbeat').then(d => { if (!d.success) { lockHeldByMe = false; stopHeartbeat(); checkLock(); } });
  }, 30000);
}
function stopHeartbeat() {
  if (lockHeartbeatTimer) { clearInterval(lockHeartbeatTimer); lockHeartbeatTimer = null; }
}
if (CAN_MANAGE) {
  window.addEventListener('beforeunload', () => {
    if (lockHeldByMe) {
      navigator.sendBeacon(LOCK_URL, new Blob([JSON.stringify({ action: 'lock_release', raidId: RAID_ID })], { type: 'application/json' }));
    }
  });
}
// Auto-claims the lock for whoever loads this page first (no held lock yet) rather than
// requiring a manual "claim" click -- matches template-edit.php's auto-lock behavior.
// The lock stays purely advisory (see note above), so there's no real downside to
// acquiring it eagerly on behalf of the first viewer.
function checkLock() {
  return lockCall('lock_status').then(d => {
    const holder = d.holder;
    if (holder && holder.discordUserId === USER_ID) {
      lockHeldByMe = true; lockedByOther = null;
      if (!lockHeartbeatTimer) startHeartbeat();
      renderLockBar(); render();
    } else if (holder) {
      lockedByOther = holder; lockHeldByMe = false;
      renderLockBar(); render();
    } else {
      lockCall('lock_acquire').then(d2 => {
        if (d2.success) { lockHeldByMe = true; lockedByOther = null; startHeartbeat(); }
        else { lockedByOther = d2.holder; lockHeldByMe = false; }
        renderLockBar(); render();
      });
    }
  });
}
function renderLockBar() {
  const el = document.getElementById('lockBar');
  if (!el) return;
  if (lockedByOther) {
    const since = new Date(lockedByOther.lockedAt.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    el.innerHTML = `<div class="lock-banner">
      Locked by <strong>${esc(lockedByOther.username)}</strong> since ${since} &mdash; view only.
      <button class="btn" data-action="force-unlock" type="button">Force unlock</button>
    </div>`;
    el.querySelector('[data-action="force-unlock"]').addEventListener('click', () => {
      if (!confirm(`Force unlock? ${lockedByOther.username} may still be editing.`)) return;
      lockCall('lock_force_release').then(() => checkLock());
    });
  } else if (lockHeldByMe) {
    el.innerHTML = `<div class="lock-status">
      <span class="lock-dot"></span> Editing (locked to you)
      <button class="lock-release-btn" type="button" data-action="release-lock">Release</button>
    </div>`;
    el.querySelector('[data-action="release-lock"]').addEventListener('click', () => {
      lockCall('lock_release').then(() => { lockHeldByMe = false; lockedByOther = null; stopHeartbeat(); renderLockBar(); render(); });
    });
  } else {
    el.innerHTML = `<div class="lock-status lock-released">
      Lock released &mdash; no one is actively editing.
      <button class="lock-release-btn" type="button" data-action="reclaim-lock">Resume editing</button>
    </div>`;
    el.querySelector('[data-action="reclaim-lock"]').addEventListener('click', () => {
      lockCall('lock_acquire').then(d => {
        if (d.success) { lockHeldByMe = true; lockedByOther = null; startHeartbeat(); }
        else { lockedByOther = d.holder; lockHeldByMe = false; }
        renderLockBar(); render();
      });
    });
  }
  renderSyncBar();
}

// Push-template sync: structural resync of this raid from its linked template, gated at
// admin (a structural change, not ordinary cell assignment). Two-step diff/apply matching
// push-template.php's contract; a second explicit confirm is required before any removals.
function renderSyncBar() {
  const el = document.getElementById('syncBar');
  if (!el) return;
  const disabled = !!lockedByOther;
  el.innerHTML = `<button type="button" class="btn-sync" id="syncBtn"${disabled ? ' disabled title="Locked by another editor"' : ''}>&#8635; Sync from template</button>`;
  const btn = document.getElementById('syncBtn');
  if (btn && !disabled) btn.addEventListener('click', openSyncModal);
}

const DIFF_GROUP_LABELS = { sections: 'Sections', tables: 'Tables', groups: 'Column groups', columns: 'Columns', rows: 'Rows' };
const DIFF_KEYS = ['sections', 'tables', 'groups', 'columns', 'rows'];

function diffHasRemovals(diff) {
  return DIFF_KEYS.some(k => diff[k].removed.length > 0);
}

function renderDiffBody(diff) {
  let html = '';
  let hasAny = false;
  for (const k of DIFF_KEYS) {
    const d = diff[k];
    const items = [];
    d.added.forEach(x => items.push(`<li class="diff-added">+ ${esc(x.label)}</li>`));
    d.changed.forEach(x => items.push(`<li class="diff-changed">~ ${esc(x.label)} <span class="fields">(${x.changes.join(', ')})</span></li>`));
    d.removed.forEach(x => items.push(`<li class="diff-removed">&minus; ${esc(x.label)}</li>`));
    if (!items.length) continue;
    hasAny = true;
    html += `<div class="diff-group"><h3>${DIFF_GROUP_LABELS[k]}</h3><ul class="diff-list">${items.join('')}</ul></div>`;
  }
  if (!hasAny) html = '<p class="diff-empty">No differences &mdash; this raid already matches the template.</p>';
  return { html, hasAny };
}

function openSyncModal() {
  const backdrop = document.getElementById('syncModalBackdrop');
  const body = document.getElementById('syncModalContent');
  body.innerHTML = '<h2>Sync from template</h2><p class="diff-empty">Loading differences&hellip;</p>';
  backdrop.classList.add('open');
  fetch(PUSH_TEMPLATE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'diff', raidId: RAID_ID }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { showSyncModalError(d.error || 'Could not compute diff.'); return; }
    renderSyncModalDiff(d.diff, false);
  }).catch(() => showSyncModalError('Network error — could not compute diff.'));
}

function showSyncModalError(msg) {
  const body = document.getElementById('syncModalContent');
  body.innerHTML = `<h2>Sync from template</h2><p class="diff-empty">${esc(msg)}</p>
    <div class="modal-actions"><button type="button" class="btn-cancel" id="syncClose">Close</button></div>`;
  document.getElementById('syncClose').addEventListener('click', closeSyncModal);
}

function closeSyncModal() {
  document.getElementById('syncModalBackdrop').classList.remove('open');
}

function renderSyncModalDiff(diff, confirmingRemovals) {
  const body = document.getElementById('syncModalContent');
  const { html, hasAny } = renderDiffBody(diff);
  const removals = diffHasRemovals(diff);
  let warning = '';
  if (removals && confirmingRemovals) {
    const n = DIFF_KEYS.reduce((sum, k) => sum + diff[k].removed.length, 0);
    warning = `<div class="removal-warning"><strong>Warning:</strong> this will permanently delete ${n} item(s) shown above in red and any toon assignments in them. This cannot be undone.</div>`;
  }
  const applyLabel = removals && !confirmingRemovals ? 'Review removals' : 'Apply sync';
  const applyClass = confirmingRemovals ? 'btn-confirm danger' : 'btn-confirm';
  body.innerHTML = `<h2>Sync from template</h2>${html}${warning}
    <div class="modal-actions">
      <button type="button" class="btn-cancel" id="syncCancel">Cancel</button>
      ${hasAny ? `<button type="button" class="${applyClass}" id="syncApply">${applyLabel}</button>` : ''}
    </div>`;
  document.getElementById('syncCancel').addEventListener('click', closeSyncModal);
  const applyBtn = document.getElementById('syncApply');
  if (applyBtn) {
    applyBtn.addEventListener('click', () => {
      if (removals && !confirmingRemovals) { renderSyncModalDiff(diff, true); return; }
      applySync(removals);
    });
  }
}

function applySync(confirmRemovals) {
  const applyBtn = document.getElementById('syncApply');
  if (applyBtn) { applyBtn.disabled = true; applyBtn.textContent = 'Applying…'; }
  fetch(PUSH_TEMPLATE_URL, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'apply', raidId: RAID_ID, confirmRemovals: !!confirmRemovals }),
  }).then(r => r.json()).then(d => {
    if (!d.success) { alert(d.error || 'Sync failed'); closeSyncModal(); return; }
    closeSyncModal();
    location.reload();
  }).catch(() => { alert('Network error — sync may not have applied.'); closeSyncModal(); });
}

const KIND_META = {
  roster:      { label: 'Roster',             color: '#4a63e0', icon: '📋' },
  tank:        { label: 'Tank Assignments',   color: '#c94444', icon: '⚔️' },
  healer:      { label: 'Healer Assignments', color: '#3e9f5f', icon: '💚' },
  misc:        { label: 'Misc Assignments',   color: '#7c5cc4', icon: '📜' },
  assignments: { label: 'Assignments',        color: '#2fa89c', icon: '📌' },
};
const CLASS_COLORS = {
  warrior: '#c79c6e', paladin: '#f472b6', priest: '#eeeeee', druid: '#f59e0b',
  rogue: '#fff569', mage: '#69ccf0', warlock: '#9482c9', shaman: '#0070de', hunter: '#abd473',
};

// The DB query orders sections by a single flat sort_order/id sequence spanning every
// kind at once, not grouped by kind -- so a section added or reordered while the template
// editor's tab view was scoped to one kind can end up with a sort_order that interleaves
// it with another kind's sections. This page has no tab UI to isolate that damage (unlike
// template-edit.php, which filters by kind per tab), so re-group here by kind (first-
// appearance order, same convention as template-edit.php's currentTabs()) before ever
// rendering or measuring, so the page always reads one kind-block fully before the next.
function groupSectionsByKind(secs) {
  const order = [];
  for (const s of secs) if (!order.includes(s.kind)) order.push(s.kind);
  return order.flatMap(k => secs.filter(s => s.kind === k));
}

function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function classColor(cls) { return CLASS_COLORS[(cls || '').toLowerCase()] || '#8892b0'; }

function roleKey(role) { return role ? 'role-' + role.toLowerCase() : 'role-dps'; }

// Mirrors includes/class_roles.php's CLASS_ROLES -- which roles a class may cycle through.
const CLASS_ROLES = {
  Druid: ['Tank', 'Healer', 'DPS'], Paladin: ['Tank', 'Healer', 'DPS'],
  Warrior: ['Tank', 'DPS'], Rogue: ['DPS'], Warlock: ['DPS'], Mage: ['DPS'],
  Priest: ['Healer', 'DPS'], Hunter: ['DPS'], Shaman: ['Healer', 'DPS'],
};
function classRoles(cls) { return CLASS_ROLES[cls] || ['Tank', 'Healer', 'DPS']; }

function contrastText(hex) {
  if (!hex) return '#e8ecff';
  const h = hex.replace('#', '');
  const r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.6 ? '#111827' : '#ffffff';
}

function chipHtml(cell, noteEnabled) {
  if (!cell || !cell.name) return '<span class="empty-slot">+</span>';
  const color = classColor(cell.class);
  const dragAttrs = CAN_MANAGE
    ? ` draggable="true" data-source="cell" data-cell-id="${cell.id}" data-toon-kind="${esc(cell.toonKind)}" data-toon-id="${esc(cell.toonId || '')}" data-pug-name="${esc(cell.pugName || '')}" data-pug-class="${esc(cell.pugClass || '')}" data-role="${esc(cell.role || '')}"`
    : '';
  const roleTitle = cell.role ? `${cell.role}${cell.roleConfirmed ? '' : ' (default — click to change)'}` : '';
  const roleIcon = cell.role
    ? `<span class="role-icon-sm${CAN_MANAGE ? ' role-clickable' : ''} ${roleKey(cell.role)}" title="${esc(roleTitle)}"${CAN_MANAGE ? ` data-action="cycle-cell-role" data-cell-id="${cell.id}"` : ''}></span>`
    : '';
  let html = `<span class="toon-chip"${dragAttrs} style="background:${color};color:${contrastText(color)};">${roleIcon}<span class="chip-name">${esc(cell.name)}</span>`;
  if (noteEnabled && (cell.marked || CAN_MANAGE)) {
    const cls = 'chip-marker' + (cell.marked ? ' active' : '') + (CAN_MANAGE ? ' clickable' : '');
    const actionAttrs = CAN_MANAGE ? ` data-action="toggle-marker" data-cell-id="${cell.id}"` : '';
    html += `<span class="${cls}"${actionAttrs} title="${cell.marked ? 'Marked' : 'Mark this slot'}">*</span>`;
  }
  if (CAN_MANAGE) html += `<span class="chip-clear" data-action="clear" data-cell-id="${cell.id}">&times;</span>`;
  html += `</span>`;
  return html;
}

// Read-only chip for a Swaps table's From/To cells -- same filled class-colour look as an
// ordinary roster slot (chipHtml), but with no drag/clear affordances since there's no real
// cell behind it to reassign or clear.
function swapChipHtml(cell) {
  if (!cell || !cell.name) return '<span class="empty-slot">&mdash;</span>';
  const color = classColor(cell.class);
  const roleIcon = cell.role ? `<span class="role-icon-sm ${roleKey(cell.role)}" title="${esc(cell.role)}"></span>` : '';
  return `<span class="toon-chip" style="background:${color};color:${contrastText(color)};">${roleIcon}<span class="chip-name">${esc(cell.name)}</span></span>`;
}

// Every table anywhere in the tree (top-level or nested inside a group), flattened for lookup.
function allTables() {
  const out = [];
  const walk = tables => { for (const tb of tables) { out.push(tb); for (const g of tb.columnGroups) walk(g.tables); } };
  for (const sec of sections) walk(sec.tables);
  return out;
}

function findTable(id) { return allTables().find(t => t.id === id) || null; }

// Same tab concept as template-edit.php's currentTabs(): one tab per distinct section `kind`,
// in first-appearance order (sections here are already pre-grouped by kind, see the
// groupSectionsByKind() call that builds `sections` on load).
let activeTab = null;
function currentTabs() {
  const seen = [];
  for (const s of sections) if (!seen.includes(s.kind)) seen.push(s.kind);
  return seen;
}
function tabLabel(k) { return (KIND_META[k] && KIND_META[k].label) || k; }

function renderTabs() {
  const TABS = currentTabs();
  const tabsRowEl = document.getElementById('tabsRowEl');
  if (TABS.length < 2) {
    tabsRowEl.hidden = true;
    activeTab = TABS[0] || null;
    return TABS;
  }
  if (!activeTab || !TABS.includes(activeTab)) activeTab = TABS[0];
  tabsRowEl.hidden = false;
  tabsRowEl.innerHTML = TABS.map(k => `<button type="button" class="tab-btn ${k === activeTab ? 'active' : ''}" data-tab="${escAttr(k)}">${esc(tabLabel(k))}</button>`).join('');
  tabsRowEl.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => { activeTab = btn.dataset.tab; render(); });
  });
  return TABS;
}

function render() {
  renderTabs();
  const visibleSections = activeTab ? sections.filter(s => s.kind === activeTab) : sections;
  const el = document.getElementById('sectionsEl');
  el.innerHTML = visibleSections.map(renderSection).join('');

  el.querySelectorAll('[data-mrt-server]').forEach(btn => {
    btn.addEventListener('click', () => doMrtExport(btn));
  });

  if (CAN_MANAGE) {
    el.querySelectorAll('td.cell.editable').forEach(td => {
      td.addEventListener('dragover', e => {
        e.preventDefault();
        const violation = dragPayload ? clientRuleViolation(td, dragPayload) : null;
        td.classList.toggle('drop-forbidden', !!violation);
        td.classList.toggle('drop-hover', !violation);
      });
      td.addEventListener('dragleave', () => { td.classList.remove('drop-hover'); td.classList.remove('drop-forbidden'); });
      td.addEventListener('drop', e => {
        e.preventDefault();
        td.classList.remove('drop-hover');
        td.classList.remove('drop-forbidden');
        handleDrop(td, e);
      });
      td.addEventListener('click', () => {
        if (!stampToon) return;
        const cellId = parseInt(td.dataset.cellId, 10);
        const cur = findCellById(cellId);
        if (cur && cur.name) return; // stamp mode only fills empty slots
        const violation = clientRuleViolation(td, { source: 'pool', toonKind: stampToon.toonKind, toonId: stampToon.toonId, pugName: stampToon.pugName, pugClass: stampToon.pugClass });
        if (violation) { alert(violation); return; }
        saveCellPatch(cellId, { toonKind: stampToon.toonKind, toonId: stampToon.toonId, pugName: stampToon.pugName, pugClass: stampToon.pugClass, role: stampToon.role });
      });
    });
    el.querySelectorAll('.toon-chip[draggable="true"]').forEach(chip => {
      chip.addEventListener('dragstart', e => {
        const payload = {
          source: 'cell',
          cellId: parseInt(chip.dataset.cellId, 10),
          toonKind: chip.dataset.toonKind,
          toonId: chip.dataset.toonId || null,
          pugName: chip.dataset.pugName || null,
          pugClass: chip.dataset.pugClass || null,
          role: chip.dataset.role || null,
        };
        dragPayload = payload;
        e.dataTransfer.setData('text/plain', JSON.stringify(payload));
        e.dataTransfer.effectAllowed = 'move';
      });
      chip.addEventListener('dragend', () => { dragPayload = null; });
      chip.addEventListener('click', e => {
        if (e.altKey) {
          e.stopPropagation();
          showAltPopup(chip);
          return;
        }
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        e.stopPropagation();
        const next = {
          toonKind: chip.dataset.toonKind,
          toonId: chip.dataset.toonId || null,
          pugName: chip.dataset.pugName || null,
          pugClass: chip.dataset.pugClass || null,
          role: chip.dataset.role || null,
        };
        const same = stampToon && stampToon.toonKind === next.toonKind
          && stampToon.toonId === next.toonId && stampToon.pugName === next.pugName;
        stampToon = same ? null : next;
        updateStampVisuals();
      });
    });
    el.querySelectorAll('[data-action="cycle-cell-role"]').forEach(el2 => {
      el2.addEventListener('click', e => {
        e.stopPropagation();
        cycleCellRole(parseInt(el2.dataset.cellId, 10));
      });
    });
    el.querySelectorAll('.chip-clear').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const cellId = parseInt(btn.dataset.cellId, 10);
        saveCellPatch(cellId, { toonKind: null, toonId: null, pugName: null, pugClass: null, role: null });
      });
    });
    el.querySelectorAll('.chip-marker.clickable').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        toggleMarker(parseInt(btn.dataset.cellId, 10));
      });
    });
    el.querySelectorAll('[data-action="edit-swap-note"]').forEach(td => {
      td.addEventListener('click', e => { e.stopPropagation(); showSwapNotePopup(td); });
    });
    el.querySelectorAll('.section-clear-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!confirm('Clear every assignment in this section? This cannot be undone.')) return;
        clearCall({ action: 'clear_section', sectionId: parseInt(btn.dataset.sectionId, 10) });
      });
    });
    updateStampVisuals();
  }
}

function clearCall(body) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, ...body }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Clear failed'); return; }
      sections = groupSectionsByKind(d.sections);
      render();
      if (d.pool) { pool = d.pool; renderPool(); }
    });
}

function findCellById(cellId) {
  for (const tb of allTables()) {
    for (const key in tb.cells) if (tb.cells[key].id === cellId) return tb.cells[key];
  }
  return null;
}

function updateCellInPlace(cell) {
  if (!cell) return;
  for (const tb of allTables()) {
    for (const key in tb.cells) if (tb.cells[key].id === cell.id) { tb.cells[key] = cell; return; }
  }
}

function saveCellPatch(cellId, patch) {
  const cur = findCellById(cellId);
  const body = {
    action: 'assign', cellId,
    toonKind: patch.toonKind !== undefined ? patch.toonKind : (cur ? cur.toonKind : null),
    toonId: patch.toonId !== undefined ? patch.toonId : (cur ? cur.toonId : null),
    pugName: patch.pugName !== undefined ? patch.pugName : (cur ? cur.pugName : null),
    pugClass: patch.pugClass !== undefined ? patch.pugClass : (cur ? cur.pugClass : null),
    role: patch.role !== undefined ? patch.role : (cur ? cur.role : null),
  };
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Save failed'); return; }
      if (d.sections) { sections = groupSectionsByKind(d.sections); } else { updateCellInPlace(d.cell); }
      render();
    });
}

function cycleCellRole(cellId) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'setRole', cellId }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Save failed'); return; }
      updateCellInPlace(d.cell);
      render();
    });
}

function toggleMarker(cellId) {
  const cur = findCellById(cellId);
  const marked = !(cur && cur.marked);
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark', cellId, marked }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Save failed'); return; }
      updateCellInPlace(d.cell);
      render();
    });
}

function persistMove(fromCellId, toCellId) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'move', fromCellId, toCellId }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Move failed'); return; }
      if (d.sections) { sections = groupSectionsByKind(d.sections); } else { updateCellInPlace(d.from); updateCellInPlace(d.to); }
      render();
    });
}

function handleDrop(td, e) {
  let payload;
  try { payload = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
  if (!payload) return;
  const toCellId = parseInt(td.dataset.cellId, 10);
  if (!toCellId) return;
  const violation = clientRuleViolation(td, payload);
  if (violation) { alert(violation); return; }
  if (payload.source === 'cell') {
    if (payload.cellId === toCellId) return;
    persistMove(payload.cellId, toCellId);
  } else if (payload.source === 'pool') {
    saveCellPatch(toCellId, { toonKind: payload.toonKind, toonId: payload.toonId, pugName: payload.pugName, pugClass: payload.pugClass, role: payload.role });
  }
}

// Resolves a drag/stamp payload's class -- pool/pug payloads carry pugClass directly,
// main/alt payloads need a roster lookup (mirrors cells-save.php's toon_class_for()).
function toonClassForPayload(payload) {
  if (payload.toonKind === 'pug') return payload.pugClass;
  if (payload.toonKind === 'main') {
    const m = roster.find(r => String(r.id) === String(payload.toonId));
    return m ? m.class : null;
  }
  if (payload.toonKind === 'alt') {
    for (const m of roster) {
      const a = m.alts.find(x => String(x.id) === String(payload.toonId));
      if (a) return a.class;
    }
    return null;
  }
  return null;
}

// Client-side mirror of cells-save.php's rule_violation() -- instant drag-over/drop
// feedback only, not authoritative. The server re-checks on every assign/move and is
// the real gate; this just avoids a round-trip and matches IO's instant-feedback feel.
function clientRuleViolation(td, payload) {
  const tb = findTable(parseInt(td.dataset.tableId, 10));
  if (!tb || !tb.rules || !tb.rules.length) return null;
  const rowId = parseInt(td.dataset.rowId, 10);
  const columnId = parseInt(td.dataset.colId, 10);
  const toonKind = payload.toonKind;
  const toonId = payload.toonId;
  const toonClass = toonClassForPayload(payload);
  const toCellId = parseInt(td.dataset.cellId, 10) || 0;
  const excludeCellIds = payload.source === 'cell' ? [payload.cellId, toCellId] : [toCellId];

  for (const rule of tb.rules) {
    const inScope = rule.scope === 'table' || rule.cellRefs.some(cr => cr.rowId === rowId && cr.columnId === columnId);
    if (!inScope) continue;
    if (rule.ruleType === 'class_restrict') {
      const allowed = (rule.classes || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
      const cls = (toonClass || '').trim().toLowerCase();
      if (!cls || !allowed.includes(cls)) return rule.label || `Only ${rule.classes} may be assigned here`;
    } else if (rule.ruleType === 'max_count') {
      const max = rule.maxCount || 1;
      const scopedCells = rule.scope === 'table'
        ? Object.values(tb.cells)
        : rule.cellRefs.map(cr => tb.cells[cr.rowId + '_' + cr.columnId]).filter(Boolean);
      let count;
      if (toonKind === 'pug') {
        const name = (payload.pugName || '').trim().toLowerCase();
        if (!name) continue; // no identity to count against
        count = scopedCells.filter(c => c && c.toonKind === 'pug' && (c.pugName || '').trim().toLowerCase() === name && !excludeCellIds.includes(c.id)).length;
      } else {
        count = scopedCells.filter(c => c && String(c.toonKind) === String(toonKind) && String(c.toonId) === String(toonId) && !excludeCellIds.includes(c.id)).length;
      }
      if (count >= max) return rule.label || `This toon can only be assigned ${max} time${max === 1 ? '' : 's'} here`;
    }
  }
  return null;
}

// Ordered [main, alt1, alt2, ...] cycle list for whichever main/alt id owns this chip,
// used by Alt+Click to swap an assigned chip to the "next" character on the same person.
function siblingChain(toonKind, toonId) {
  for (const m of roster) {
    if ((toonKind === 'main' && m.id === toonId) || (toonKind === 'alt' && m.alts.some(a => a.id === toonId))) {
      return [{ toonKind: 'main', toonId: m.id, name: m.name, class: m.class }]
        .concat(m.alts.map(a => ({ toonKind: 'alt', toonId: a.id, name: a.name, class: a.class })));
    }
  }
  return [];
}

// Alt+Click a chip: show every sibling (main + all alts) in a positioned popup so the
// user can pick one directly, instead of blindly stepping to "the next" one.
function closeAltPopup() {
  if (activeAltPopup) { activeAltPopup.remove(); activeAltPopup = null; }
}

function showAltPopup(chip) {
  closeAltPopup();
  const toonKind = chip.dataset.toonKind;
  const toonId = chip.dataset.toonId;
  if (toonKind !== 'main' && toonKind !== 'alt') return; // pugs have no siblings
  const chain = siblingChain(toonKind, toonId);
  if (chain.length < 2) return;
  const cellId = parseInt(chip.dataset.cellId, 10);

  const popup = document.createElement('div');
  popup.className = 'alt-popup';
  popup.innerHTML = chain.map(t => {
    const isCurrent = t.toonKind === toonKind && String(t.toonId) === String(toonId);
    return `<div class="alt-popup-item${isCurrent ? ' current' : ''}" data-toon-kind="${esc(t.toonKind)}" data-toon-id="${esc(String(t.toonId))}" style="--dot:${esc(classColor(t.class))}">`
      + `<span class="alt-popup-dot"></span>${esc(t.name)}${isCurrent ? ' (current)' : ''}</div>`;
  }).join('');
  document.body.appendChild(popup);

  const rect = chip.getBoundingClientRect();
  const left = Math.min(rect.left + window.scrollX, window.innerWidth + window.scrollX - popup.offsetWidth - 8);
  popup.style.left = `${Math.max(8, left)}px`;
  popup.style.top = `${rect.bottom + window.scrollY + 4}px`;

  popup.querySelectorAll('.alt-popup-item:not(.current)').forEach(item => {
    item.addEventListener('click', ev => {
      ev.stopPropagation();
      const next = chain.find(t => t.toonKind === item.dataset.toonKind && String(t.toonId) === item.dataset.toonId);
      if (next) saveCellPatch(cellId, { toonKind: next.toonKind, toonId: next.toonId, pugName: null, pugClass: null, role: null });
      closeAltPopup();
    });
  });

  activeAltPopup = popup;
}

let activeSwapPopup = null; // currently-open Swaps note/boss editor popup, if any

function closeSwapNotePopup() {
  if (activeSwapPopup) { activeSwapPopup.remove(); activeSwapPopup = null; }
}

function showSwapNotePopup(td) {
  closeSwapNotePopup();
  closeAltPopup();
  const tableId = parseInt(td.dataset.tableId, 10);
  const playerId = td.dataset.playerId;
  const tb = findTable(tableId);
  const row = tb && tb.rows.find(r => r.playerMainToonId === playerId);
  if (!tb || !row) return;
  const noteCell = tb.cells[row.id + '_-4'];
  const bossCell = tb.cells[row.id + '_-5'];

  const currentWhen = noteCell ? (noteCell.textContent || '') : '';

  const popup = document.createElement('div');
  popup.className = 'swap-note-popup';
  popup.innerHTML = `
    <label>When</label>
    <select class="swap-note-input">
      <option value=""${currentWhen === '' ? ' selected' : ''}>&mdash;</option>
      <option value="Before"${currentWhen === 'Before' ? ' selected' : ''}>Before</option>
      <option value="After"${currentWhen === 'After' ? ' selected' : ''}>After</option>
    </select>
    <label>Boss</label>
    <input type="text" class="swap-boss-input" maxlength="60" value="${esc(bossCell ? (bossCell.textContent || '') : '')}">
    <div class="swap-note-actions">
      <button type="button" class="swap-note-cancel">Cancel</button>
      <button type="button" class="swap-note-save">Save</button>
    </div>`;
  document.body.appendChild(popup);

  const rect = td.getBoundingClientRect();
  const left = Math.min(rect.left + window.scrollX, window.innerWidth + window.scrollX - popup.offsetWidth - 8);
  popup.style.left = `${Math.max(8, left)}px`;
  popup.style.top = `${rect.bottom + window.scrollY + 4}px`;

  popup.addEventListener('click', e => e.stopPropagation());
  popup.querySelector('.swap-note-cancel').addEventListener('click', closeSwapNotePopup);
  popup.querySelector('.swap-note-save').addEventListener('click', () => {
    const note = popup.querySelector('.swap-note-input').value;
    const bossLabel = popup.querySelector('.swap-boss-input').value;
    closeSwapNotePopup();
    clearCall({ action: 'set_swap_note', tableId, playerMainToonId: playerId, note, bossLabel });
  });
  popup.querySelector(td.dataset.field === 'boss' ? '.swap-boss-input' : '.swap-note-input').focus();

  activeSwapPopup = popup;
}

document.addEventListener('click', () => { closeAltPopup(); closeSwapNotePopup(); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeAltPopup(); closeSwapNotePopup(); }
});

const MAX_DATA_COLS = 10;

// Spacer > Text > General: a cell is only a draggable toon slot when both its row and
// column are General; if either is Text, the cell shows that row/column's own Text
// content; if either is Spacer, the cell is blank. A cell-level kindOverride (set via
// "Add cell Override" in the designer) takes priority over all of that, forcing one
// specific cell to a chosen kind regardless of its row/column.
function effectiveKind(row, col, cell) {
  if (cell && cell.kindOverride) return cell.kindOverride;
  if (row.kind === 'spacer' || col.kind === 'spacer') return 'spacer';
  if (row.kind === 'icon' || col.kind === 'icon') return 'icon';
  if (row.kind === 'text' || col.kind === 'text') return 'text';
  return 'general';
}

// Kept in sync with raids/template-edit.php's CELL_FONT_STACKS -- small preset of generic
// web-safe stacks so the HTML page and the canvas export (drawn client-side below) render identically.
const CELL_FONT_STACKS = {
  serif: 'Georgia, "Times New Roman", serif',
  mono: 'Consolas, "Courier New", monospace',
  display: 'Impact, "Arial Black", sans-serif',
};
function cellTextStyle(cell) {
  const weight = cell && cell.bold ? 'font-weight:700;' : '';
  const family = cell && CELL_FONT_STACKS[cell.font] ? `font-family:${CELL_FONT_STACKS[cell.font]};` : '';
  return weight + family;
}

// Raid-target icon sprite (assets/img/raid-icons.png, 8 x 64px frames in a single row).
// Kept in sync with raids/template-edit.php's RAID_ICON_KEYS/raidIconStyle/parseCellText.
const RAID_ICON_KEYS = ['skull', 'cross', 'square', 'moon', 'triangle', 'diamond', 'circle', 'star'];
function raidIconStyle(key, sizePx) {
  const idx = RAID_ICON_KEYS.indexOf(key);
  if (idx < 0) return '';
  const pct = (idx / (RAID_ICON_KEYS.length - 1)) * 100;
  const size = sizePx || 20;
  return `width:${size}px;height:${size}px;background-image:url('/assets/img/raid-icons.png');background-position:${pct}% 0;background-size:${RAID_ICON_KEYS.length * 100}% 100%;background-repeat:no-repeat;display:inline-block;vertical-align:middle;`;
}
const ICON_TOKEN_RE = /:(skull|cross|square|moon|triangle|diamond|circle|star):/g;
function parseCellText(text) {
  const parts = [];
  if (!text) return parts;
  let last = 0, m;
  ICON_TOKEN_RE.lastIndex = 0;
  while ((m = ICON_TOKEN_RE.exec(text))) {
    if (m.index > last) parts.push({ type: 'text', value: text.slice(last, m.index) });
    parts.push({ type: 'icon', key: m[1] });
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push({ type: 'text', value: text.slice(last) });
  return parts;
}
function renderCellTextHtml(text) {
  return parseCellText(text).map(p => p.type === 'icon'
    ? `<span class="inline-raid-icon" style="${raidIconStyle(p.key, 14)}" title="${p.key}"></span>`
    : esc(p.value)).join('');
}

// Canvas can't use CSS background-position sprite slicing, so the same sprite sheet is
// preloaded as an Image and sliced via drawImage's source-rect args instead. Preloaded
// eagerly at page load so it's already decoded by the time a Discord export is drawn
// (drawBlock() below is synchronous, so this can't be awaited at draw time).
const RAID_ICON_IMG = new Image();
RAID_ICON_IMG.src = '/assets/img/raid-icons.png';
function drawRaidIcon(ctx, key, x, y, size) {
  const idx = RAID_ICON_KEYS.indexOf(key);
  if (idx < 0 || !RAID_ICON_IMG.complete || !RAID_ICON_IMG.naturalWidth) return;
  const frameW = RAID_ICON_IMG.naturalWidth / RAID_ICON_KEYS.length;
  const frameH = RAID_ICON_IMG.naturalHeight;
  ctx.drawImage(RAID_ICON_IMG, idx * frameW, 0, frameW, frameH, x, y, size, size);
}

let _measureCtx = null;
function measureTextPx(text, font) {
  if (!_measureCtx) _measureCtx = document.createElement('canvas').getContext('2d');
  _measureCtx.font = font || '600 12.5px system-ui, -apple-system, sans-serif';
  return _measureCtx.measureText(text || '').width;
}

// Longest rendered value in this column, across every non-spacer row — Text cells measure
// their own text_content, used to size a width:0 ("shrink to content") column. Assigned
// toon names are deliberately excluded: a slot column must not keep widening every time a
// longer name gets dropped into it — long names crop instead (see .toon-chip CSS).
function longestCellText(c, tb) {
  let longest = c.label || '';
  for (const r of tb.rows) {
    if (r.kind === 'spacer') continue;
    const cell = tb.cells[r.id + '_' + c.id];
    const eff = effectiveKind(r, c, cell);
    if (eff !== 'text') continue;
    const t = cell && cell.textContent;
    if (t && t.length > longest.length) longest = t;
  }
  return longest;
}

// Tables cap at MAX_DATA_COLS data columns (spacers don't count against it); beyond that
// they split into stacked column-blocks that each repeat the full row set, rather than
// growing arbitrarily wide.
function chunkColumns(columns) {
  const chunks = [];
  let current = [];
  let dataCount = 0;
  for (const c of columns) {
    if (c.kind === 'general' && dataCount >= MAX_DATA_COLS) {
      chunks.push(current);
      current = [];
      dataCount = 0;
    }
    current.push(c);
    if (c.kind === 'general') dataCount++;
  }
  chunks.push(current);
  return chunks;
}

function colWidthPx(c, tb) {
  if (c.kind === 'spacer') {
    const base = c.width || tb.defaultColumnWidth || 30;
    return Math.max(8, Math.round(base / 3));
  }
  // Always resolve to a real pixel width (never null) so every <col> in the colgroup is
  // explicit — table-layout:fixed only sums a colspan cell's width from its spanned <col>
  // widths correctly when all of them are explicit. An explicit width of 0 means "shrink
  // to the longest rendered value in this column" rather than falling through to default.
  const effWidth = (c.width !== null && c.width !== undefined) ? c.width : tb.defaultColumnWidth;
  if (effWidth === 0) {
    return Math.max(60, Math.round(measureTextPx(longestCellText(c, tb)) + 24));
  }
  return effWidth || 120; // 2 units at 60px/unit, matches the editor's default
}

function groupHeaderRow(cols, columnGroups, tb) {
  if (!columnGroups.length) return '';
  const cells = [];
  let i = 0;
  while (i < cols.length) {
    const gid = cols[i].groupId;
    if (!gid) { cells.push(`<th></th>`); i++; continue; }
    let span = 1;
    while (i + span < cols.length && cols[i + span].groupId === gid) span++;
    const grp = columnGroups.find(g => g.id === gid);
    if (grp) {
      cells.push(`<th class="group-th" colspan="${span}" style="background:${grp.color};color:${contrastText(grp.color)};">${esc(grp.title)}</th>`);
    } else {
      cells.push(`<th colspan="${span}"></th>`);
    }
    i += span;
  }
  return `<tr>${cells.join('')}</tr>`;
}

// Computed once per table (not per column-chunk, since rows are never chunked). Returns
// which (rowId,columnId) positions must render nothing at all (covered by an earlier
// row's rowspan), and the render-time-clamped {colspan, rowspan} for each anchor cell --
// clamped to the table's actual remaining rows so a stale value degrades gracefully.
function computeMergeCoverage(tb) {
  const covered = new Set();
  const spans = {};
  tb.cellMerges.forEach(m => {
    const rowIdx = tb.rows.findIndex(r => r.id === m.rowId);
    const colIdx = tb.columns.findIndex(c => c.id === m.columnId);
    if (rowIdx < 0 || colIdx < 0) return;
    const rowspan = Math.min(m.rowspan || 1, tb.rows.length - rowIdx);
    spans[`${m.rowId}_${m.columnId}`] = { colspan: m.colspan || 1, rowspan };
    for (let dr = 1; dr < rowspan; dr++) {
      const coveredRow = tb.rows[rowIdx + dr];
      for (let dc = 0; dc < (m.colspan || 1); dc++) {
        const coveredCol = tb.columns[colIdx + dc];
        if (coveredCol) covered.add(`${coveredRow.id}_${coveredCol.id}`);
      }
    }
  });
  return { covered, spans };
}

// Same walk-and-consume pattern for body cells: tb.cellMerges is a (rowId, columnId) ->
// colspan/rowspan lookup, independent of header merges. Covered positions get no <td> at all.
function bodyCellsForRow(r, chunkCols, tb, noteEnabled, coverage, sectionBg) {
  const out = [];
  let i = 0;
  while (i < chunkCols.length) {
    const c = chunkCols[i];
    if (coverage.covered.has(`${r.id}_${c.id}`)) { i++; continue; }
    const cell = tb.cells[r.id + '_' + c.id];
    const eff = effectiveKind(r, c, cell);
    const span = coverage.spans[`${r.id}_${c.id}`] || { colspan: 1, rowspan: 1 };
    const colspan = Math.min(span.colspan, chunkCols.length - i);
    const colspanAttr = colspan > 1 ? ` colspan="${colspan}"` : '';
    const rowspanAttr = span.rowspan > 1 ? ` rowspan="${span.rowspan}"` : '';
    // Column-kind min-width (matches the editor's NARROW_MIN_COL_PX = half the normal 60px
    // floor) -- icon/text columns hold compact content, so they're allowed narrower than the
    // generic td.cell 90px CSS floor.
    const minWidthStyle = (c.kind === 'icon' || c.kind === 'text' || c.kind === 'general') ? 'min-width:30px;' : '';
    if (eff === 'spacer') {
      // Unpainted fillers/spacers always resolve explicitly to the section's own background
      // (not just CSS "none"), so they show through the section colour even when the table
      // they sit in has its own painted background -- a spacer is meant to look like a gap
      // straight down to the section, not a hole in the table's colour.
      const spacerColor = (cell && cell.bgColor) || (r.kind === 'spacer' && r.bgColor) || c.bgColor || sectionBg;
      out.push(`<td${colspanAttr}${rowspanAttr} class="spacer-cell" style="${minWidthStyle}background:${spacerColor};"></td>`);
      i += colspan; continue;
    }
    if (eff === 'text' && tb.kind === 'swaps' && (c.id === -2 || c.id === -3)) {
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell" style="${minWidthStyle}">${swapChipHtml(cell)}</td>`);
    } else if (eff === 'text') {
      const style = `${minWidthStyle}${(cell && cell.bgColor) ? `background:${cell.bgColor};` : ''}color:${(cell && cell.textColor) || 'inherit'};${cellTextStyle(cell)}`;
      const isSwapNote = tb.kind === 'swaps' && CAN_MANAGE && (c.id === -4 || c.id === -5);
      const swapAttrs = isSwapNote ? ` data-action="edit-swap-note" data-table-id="${tb.id}" data-player-id="${esc(r.playerMainToonId || '')}" data-field="${c.id === -4 ? 'note' : 'boss'}"` : '';
      const textHtml = renderCellTextHtml(cell ? cell.textContent : '');
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell text-cell${isSwapNote ? ' swap-note-cell' : ''}" style="${style}"${swapAttrs}>${textHtml || (isSwapNote ? '<span class="empty-slot">+</span>' : '')}</td>`);
    } else if (eff === 'icon') {
      const bgStyle = ` style="${minWidthStyle}${(cell && cell.bgColor) ? `background:${cell.bgColor};` : ''}"`;
      const icon = (cell && cell.icon) ? `<span class="raid-icon-cell" style="${raidIconStyle(cell.icon, 26)}"></span>` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell icon-cell"${bgStyle}>${icon}</td>`);
    } else {
      const cellIdAttr = cell ? cell.id : '';
      const editableCls = CAN_MANAGE ? ' editable' : '';
      const bgStyle = (minWidthStyle || (cell && cell.bgColor)) ? ` style="${minWidthStyle}${(cell && cell.bgColor) ? `background:${cell.bgColor};` : ''}"` : '';
      out.push(`<td${colspanAttr}${rowspanAttr} class="cell slot${editableCls}" data-cell-id="${cellIdAttr}" data-table-id="${tb.id}" data-row-id="${r.id}" data-col-id="${c.id}"${bgStyle}>${chipHtml(cell, noteEnabled)}</td>`);
    }
    i += colspan;
  }
  return out.join('');
}

// Swaps tables have no admin-authored header row (their rows are all computed), so their
// column labels (Player/From/To/When/Boss) are synthesized here -- styled like an ordinary
// roster table's group-th bar so they read consistently with the rest of the raid page.
function swapsHeaderRow(chunkCols, tb) {
  if (tb.kind !== 'swaps') return '';
  const bg = tb.headerColor || '#2d3348';
  const color = contrastText(bg);
  return `<tr>${chunkCols.map(c => `<th class="group-th" style="background:${bg};color:${color};">${esc(c.label)}</th>`).join('')}</tr>`;
}

function renderColumnBlock(chunkCols, tb, noteEnabled, sectionBg) {
  const coverage = computeMergeCoverage(tb);
  const colgroup = `<colgroup>` +
    chunkCols.map(c => {
      const w = colWidthPx(c, tb);
      return `<col${w ? ` style="width:${w}px;"` : ''}>`;
    }).join('') + `</colgroup>`;

  const bodyRows = tb.rows.map(r => {
    if (r.kind === 'spacer') {
      return `<tr style="height:${r.height || 20}px;"><td class="spacer-cell" colspan="${chunkCols.length}" style="background:${r.bgColor || sectionBg};"></td></tr>`;
    }
    const heightAttr = r.height ? ` style="height:${r.height}px;"` : '';
    return `<tr${heightAttr}>${bodyCellsForRow(r, chunkCols, tb, noteEnabled, coverage, sectionBg)}</tr>`;
  }).join('');

  const groupRow = groupHeaderRow(chunkCols, tb.columnGroups, tb) || swapsHeaderRow(chunkCols, tb);

  return `<div class="grid-scroll">
      <table class="grid">
        ${colgroup}
        ${groupRow}
        ${bodyRows}
      </table>
    </div>`;
}

function renderTable(tb, noteEnabled, sectionBg) {
  const groupsWithTables = tb.columnGroups.filter(g => g.tables.length > 0);
  const isContainerOnly = tb.columns.length === 0 && groupsWithTables.length > 0;
  const blocks = isContainerOnly ? '' : chunkColumns(tb.columns).map(chunkCols => renderColumnBlock(chunkCols, tb, noteEnabled, sectionBg)).join('');
  const titleStyle = tb.headerColor ? ` style="background:${tb.headerColor};color:${contrastText(tb.headerColor)};"` : '';

  const nestedGroupsHtml = groupsWithTables.map(g => `
    <div class="group-tables">
      ${g.tables.map(ctb => renderTable(ctb, noteEnabled, sectionBg)).join('')}
    </div>`).join('');

  const headBar = tb.title ? `<div class="tbl-title"${titleStyle}>${esc(tb.title)}</div>` : '';
  const wrapStyle = tb.bgColor ? ` style="background:${tb.bgColor};"` : '';

  return `<div class="tbl-wrap"${wrapStyle}>
    ${headBar}
    ${blocks}
    ${nestedGroupsHtml}
  </div>`;
}

function renderSection(sec) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2', icon: '' };
  const headColor = sec.color || meta.color;
  const clearBtn = CAN_MANAGE ? `<button type="button" class="section-clear-btn" data-section-id="${sec.id}" title="Clear all assignments in this section">Clear section</button>` : '';
  const mrtBar = sec.kind === 'roster' ? `<div class="mrt-export-bar">
      ${MRT_SERVERS.map(s => `<button type="button" class="btn-mrt-export" data-mrt-server="${s.key}" data-section-id="${sec.id}">${s.label}</button>`).join('')}
      <span class="mrt-info" data-tip="${MRT_TIP}">i</span>
    </div>` : '';
  const noteEnabled = !!sec.noteEnabled;
  const noteBar = (noteEnabled && sec.noteText) ? `<p class="section-note">* ${esc(sec.noteText)}</p>` : '';
  const sectionBg = sec.bgColor || '#111827';
  return `<div class="section-card">
    <div class="section-head" style="background:${headColor};"><span>${meta.icon} ${esc(sec.title)}</span><div class="section-head-actions">${mrtBar}${clearBtn}</div></div>
    ${noteBar}
    <div class="section-body"${sec.bgColor ? ` style="background:${sec.bgColor};"` : ''}>
      ${sec.tables.map(tb => renderTable(tb, noteEnabled, sectionBg)).join('') || '<p class="empty">No tables in this section.</p>'}
    </div>
  </div>`;
}

function poolEntryHtml(p) {
  const color = classColor(p.class);
  const tagLabel = p.toonKind === 'pug' ? 'PUG' : (p.toonKind === 'alt' ? 'ALT' : '');
  const tag = tagLabel ? `<span class="pool-tag${p.toonKind === 'pug' ? ' pug' : ''}">${tagLabel}</span>` : '';
  const roleTitle = p.role ? `${p.role}${p.roleConfirmed ? '' : ' (default — click to change)'}` : '';
  const roleIcon = p.role
    ? `<span class="role-icon-sm role-clickable ${roleKey(p.role)}" title="${esc(roleTitle)}" data-action="cycle-pool-role" data-pool-id="${p.id}"></span>`
    : '';
  const t2 = p.class === 'Priest' && p.fullT2 ? '<span class="t2-badge">T2</span>' : '';
  return `<div class="pool-chip-row">
    <span class="toon-chip pool-chip" draggable="true" data-source="pool" data-pool-id="${p.id}"
      data-toon-kind="${esc(p.toonKind)}" data-toon-id="${esc(p.toonId || '')}"
      data-pug-name="${esc(p.pugName || '')}" data-pug-class="${esc(p.pugClass || '')}" data-role="${esc(p.role || '')}"
      style="background:${color};color:${contrastText(color)};">${roleIcon}${esc(p.name)}${t2}${tag}</span>
    <button type="button" class="pool-remove" data-pool-id="${p.id}" title="Remove from pool">&times;</button>
  </div>`;
}

function renderPool() {
  const listEl = document.getElementById('poolList');
  if (!listEl) return;

  // Pool entries can outlive the roster member they point at (removed toon/alt), so keep
  // the same membership check the old main->alt grouping used to imply, now done directly
  // against valid ids instead of via that grouping.
  const validMainIds = new Set(roster.map(m => m.id));
  const validAltIds = new Set();
  roster.forEach(m => m.alts.forEach(a => validAltIds.add(a.id)));
  const entries = pool.filter(p => {
    if (p.toonKind === 'main') return validMainIds.has(p.toonId);
    if (p.toonKind === 'alt') return validAltIds.has(p.toonId);
    return true;
  });

  entries.sort((a, b) => {
    const ca = (a.class || '').toLowerCase(), cb = (b.class || '').toLowerCase();
    if (ca !== cb) return ca < cb ? -1 : 1;
    return (a.name || '').localeCompare(b.name || '');
  });

  const html = entries.map(p => poolEntryHtml(p)).join('');

  listEl.innerHTML = html || '<p class="pool-empty">No one in the pool yet. Search or add a PUG below.</p>';

  listEl.querySelectorAll('.pool-chip').forEach(chip => {
    chip.addEventListener('dragstart', e => {
      const payload = {
        source: 'pool',
        poolId: parseInt(chip.dataset.poolId, 10),
        toonKind: chip.dataset.toonKind,
        toonId: chip.dataset.toonId || null,
        pugName: chip.dataset.pugName || null,
        pugClass: chip.dataset.pugClass || null,
        role: chip.dataset.role || null,
      };
      dragPayload = payload;
      e.dataTransfer.setData('text/plain', JSON.stringify(payload));
      e.dataTransfer.effectAllowed = 'copy';
    });
    chip.addEventListener('dragend', () => { dragPayload = null; });
    chip.addEventListener('click', e => {
      if (!e.ctrlKey && !e.metaKey) return;
      e.preventDefault();
      e.stopPropagation();
      const next = {
        toonKind: chip.dataset.toonKind,
        toonId: chip.dataset.toonId || null,
        pugName: chip.dataset.pugName || null,
        pugClass: chip.dataset.pugClass || null,
        role: chip.dataset.role || null,
      };
      const same = stampToon && stampToon.toonKind === next.toonKind
        && stampToon.toonId === next.toonId && stampToon.pugName === next.pugName;
      stampToon = same ? null : next;
      updateStampVisuals();
    });
  });
  listEl.querySelectorAll('[data-action="cycle-pool-role"]').forEach(el => {
    el.addEventListener('click', e => {
      e.stopPropagation();
      poolCall({ action: 'setRole', poolId: parseInt(el.dataset.poolId, 10) });
    });
  });
  listEl.querySelectorAll('.pool-remove').forEach(btn => {
    btn.addEventListener('click', () => {
      poolCall({ action: 'remove', poolId: parseInt(btn.dataset.poolId, 10) });
    });
  });
  updateStampVisuals();
}

function updateStampVisuals() {
  const drawer = document.getElementById('poolDrawer');
  if (drawer) drawer.classList.toggle('stamp-mode', !!stampToon);
  document.querySelectorAll('.toon-chip').forEach(chip => {
    const match = stampToon && chip.dataset.toonKind === stampToon.toonKind
      && (chip.dataset.toonId || null) === (stampToon.toonId || null)
      && (chip.dataset.pugName || null) === (stampToon.pugName || null);
    chip.classList.toggle('stamped', !!match);
  });
}

function poolCall(body) {
  return fetch(POOL_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, ...body }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Pool update failed'); return; }
      pool = d.pool;
      renderPool();
    });
}

// Flattened, searchable view of the whole guild roster (mains + alts) for the pool's
// type-ahead add box; alt names are annotated so a same-named alt/main pair stays distinguishable.
function fullRosterFlat() {
  const out = [];
  roster.forEach(m => {
    out.push({ toonKind: 'main', toonId: m.id, name: m.name, class: m.class });
    m.alts.forEach(a => out.push({ toonKind: 'alt', toonId: a.id, name: `${a.name} (alt of ${m.name})`, class: a.class }));
  });
  return out;
}

// Available Toons is a static sidebar by default; "minimized" is remembered per-raid so a
// reload doesn't keep re-showing it for someone who deliberately tucked it away.
const POOL_MIN_KEY = `raidroster_poolMinimized_${RAID_ID}`;
function setPoolMinimized(min) {
  document.body.classList.toggle('pool-minimized', min);
  try { localStorage.setItem(POOL_MIN_KEY, min ? '1' : '0'); } catch (e) {}
}
function wirePoolControls() {
  let startMinimized = false;
  try { startMinimized = localStorage.getItem(POOL_MIN_KEY) === '1'; } catch (e) {}
  setPoolMinimized(startMinimized);
  const minimizeBtn = document.getElementById('poolMinimizeBtn');
  const reopenTab = document.getElementById('poolReopenTab');
  const drawer = document.getElementById('poolDrawer');
  if (minimizeBtn) minimizeBtn.addEventListener('click', () => setPoolMinimized(true));
  if (reopenTab) reopenTab.addEventListener('click', () => setPoolMinimized(false));

  const searchInput = document.getElementById('poolSearchInput');
  const resultsEl = document.getElementById('poolSearchResults');
  if (searchInput && resultsEl) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim().toLowerCase();
      if (!q) { resultsEl.classList.remove('open'); resultsEl.innerHTML = ''; return; }
      const inPool = new Set(pool.filter(p => p.toonKind !== 'pug').map(p => p.toonKind + ':' + p.toonId));
      const matches = fullRosterFlat().filter(t => t.name.toLowerCase().includes(q) && !inPool.has(t.toonKind + ':' + t.toonId)).slice(0, 20);
      resultsEl.innerHTML = matches.length
        ? matches.map(t => `<div class="pool-search-item" data-toon-kind="${esc(t.toonKind)}" data-toon-id="${esc(t.toonId)}">${esc(t.name)}</div>`).join('')
        : '<div class="pool-search-item pool-search-empty">No matches</div>';
      resultsEl.classList.add('open');
      resultsEl.querySelectorAll('.pool-search-item[data-toon-id]').forEach(item => {
        item.addEventListener('click', () => {
          poolCall({ action: 'add', toonKind: item.dataset.toonKind, toonId: item.dataset.toonId });
          searchInput.value = '';
          resultsEl.classList.remove('open');
          resultsEl.innerHTML = '';
        });
      });
    });
    document.addEventListener('click', e => {
      if (!resultsEl.contains(e.target) && e.target !== searchInput) resultsEl.classList.remove('open');
    });
  }

  const pugAddBtn = document.getElementById('pugAddBtn');
  if (pugAddBtn) {
    pugAddBtn.addEventListener('click', () => {
      const nameInput = document.getElementById('pugNameInput');
      const classInput = document.getElementById('pugClassInput');
      const name = nameInput.value.trim();
      if (!name) return;
      poolCall({ action: 'add', toonKind: 'pug', pugName: name, pugClass: classInput.value || null });
      nameInput.value = '';
      classInput.value = '';
    });
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && stampToon) { stampToon = null; updateStampVisuals(); }
  });
  if (drawer) {
    drawer.addEventListener('click', e => {
      if (e.target === drawer && stampToon) { stampToon = null; updateStampVisuals(); }
    });
  }
}

// Import Raid: fetches Raid-Helper signups (matched against toons/toon_alts server-side),
// previews match status per row, and adds confirmed rows to the pool only -- no auto-placement
// into cells, since RR templates have no fixed notion of e.g. "healer slot 2".
function importItemFor(row) {
  return row.matched
    ? { toonKind: row.toonKind, toonId: row.toonId, role: row.role }
    : { toonKind: 'pug', pugName: row.name, pugClass: row.suggestedPugClass || null, role: row.role };
}

// Bench signups never go to the pool (pool items were never wired to place into cells) --
// they route straight into the raid's Benched table via cells-save.php's bench_import action.
function benchCall(body) {
  return fetch(CELLS_SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, action: 'bench_import', ...body }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { alert(d.error || 'Bench import failed'); return false; }
      sections = groupSectionsByKind(d.sections);
      render();
      return true;
    });
}

function addBenchImportRow(idx) {
  const row = importRows[idx];
  if (!row || row.added) return;
  benchCall(importItemFor(row)).then(ok => {
    if (!ok) return;
    row.added = true;
    renderImportRows();
  });
}

function cycleImportRole(idx) {
  const row = importRows[idx];
  if (!row || row.added) return;
  const roles = classRoles(row.matched ? row.toonClass : row.suggestedPugClass);
  const cur = roles.indexOf(row.role);
  row.role = roles[(cur + 1) % roles.length];
  renderImportRows();
}

function importRowHtml(row, idx) {
  const state = row.added ? 'added' : (row.matched ? 'matched' : 'unmatched');
  const status = row.added ? 'Added' : (row.matched ? `Matched: ${esc(row.toonName)}` : 'Not matched');
  const detail = row.matched ? esc(row.toonClass || '') : esc(row.suggestedPugClass || row.rawClass || '');
  const roleClickable = row.added ? '' : ' role-clickable';
  const roleTitle = row.added ? esc(row.role || '') : `${esc(row.role || '')} (suggested — click to change)`;
  const roleIcon = row.role ? `<span class="role-icon-sm${roleClickable} ${roleKey(row.role)}" title="${roleTitle}" ${row.added ? '' : `onclick="cycleImportRole(${idx})"`}></span>` : '';
  const benchBadge = row.isBench ? '<span class="bench-badge" title="Benched signup — adds to the Benched table, not the pool">Bench</span>' : '';
  const btnLabel = row.isBench ? 'Add to Bench' : (row.matched ? 'Add' : 'Add as PUG');
  return `<div class="import-row ${state}">
    <div>${roleIcon}<span class="name">${esc(row.name)}</span> ${benchBadge}<span class="detail">${detail}</span></div>
    <div class="status">${status}${row.added ? '' : `<button type="button" data-idx="${idx}">${btnLabel}</button>`}</div>
  </div>`;
}

function renderImportRows() {
  const el = document.getElementById('importRows');
  if (!el) return;
  el.innerHTML = importRows.map((r, i) => importRowHtml(r, i)).join('');
  el.querySelectorAll('button[data-idx]').forEach(btn => {
    const idx = parseInt(btn.dataset.idx, 10);
    btn.addEventListener('click', () => (importRows[idx] && importRows[idx].isBench ? addBenchImportRow(idx) : addImportRow(idx)));
  });
  const allBtn = document.getElementById('importAllBtn');
  if (allBtn) allBtn.disabled = !importRows.some(r => !r.added);
}

function addImportRow(idx) {
  const row = importRows[idx];
  if (!row || row.added) return;
  poolCall({ action: 'bulkAdd', items: [importItemFor(row)] }).then(() => {
    row.added = true;
    renderImportRows();
  });
}

function addAllImportRows() {
  const pending = importRows.filter(r => !r.added);
  if (!pending.length) return;
  const benchRows = pending.filter(r => r.isBench);
  const poolRows = pending.filter(r => !r.isBench);
  Promise.all([
    poolRows.length ? poolCall({ action: 'bulkAdd', items: poolRows.map(importItemFor) }) : Promise.resolve(),
    ...benchRows.map(r => benchCall(importItemFor(r))),
  ]).then(() => {
    importRows.forEach(r => { r.added = true; });
    renderImportRows();
  });
}

function fetchImportSignups() {
  const input = document.getElementById('importUrlInput');
  const statusEl = document.getElementById('importStatus');
  const url = input.value.trim();
  if (!url) return;
  statusEl.textContent = 'Fetching…';
  importRows = [];
  renderImportRows();
  fetch(IMPORT_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ raidId: RAID_ID, action: 'fetch', eventUrl: url }) })
    .then(r => r.json()).then(d => {
      if (!d.success) { statusEl.textContent = d.error || 'Import failed'; return; }
      importRows = d.rows.map(r => ({ ...r, added: false }));
      const matched = importRows.filter(r => r.matched).length;
      statusEl.textContent = importRows.length
        ? `${importRows.length} signup(s) found — ${matched} matched, ${importRows.length - matched} unmatched.`
        : 'No signups found on that event.';
      renderImportRows();
    })
    .catch(() => { statusEl.textContent = 'Import failed'; });
}

function wireImportControls() {
  const toggleBtn = document.getElementById('importToggleBtn');
  const backdrop = document.getElementById('importModalBackdrop');
  const closeBtn = document.getElementById('importCloseBtn');
  const fetchBtn = document.getElementById('importFetchBtn');
  const allBtn = document.getElementById('importAllBtn');
  if (toggleBtn && backdrop) toggleBtn.addEventListener('click', () => backdrop.classList.add('open'));
  if (closeBtn && backdrop) closeBtn.addEventListener('click', () => backdrop.classList.remove('open'));
  if (backdrop) backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });
  if (fetchBtn) fetchBtn.addEventListener('click', fetchImportSignups);
  if (allBtn) allBtn.addEventListener('click', addAllImportRows);
}

// Discord post: splits the raid into a sequence of small, readable Discord posts instead
// of one giant image -- mirrors IO's per-row/per-divider publish flow (staging-php's
// dc-utils.js/healer-core.js dcPublish: a divider banner posted first, then one image per
// "row" of content, each posted as its own message with a short delay in between). Here,
// each section (its own colored banner on this page, e.g. "TANK ASSIGNMENTS") becomes a
// divider image followed by its tables packed side-by-side into posts, capped at a
// 9-column-equivalent (1080px) width budget and 8 rows tall each -- wide tables get their
// own post, tall tables split into multiple posts -- so nothing renders too small to read.
function raidCanvasMetrics() {
  return { pad: 20, rowH: 28, groupH: 20, tableTitleH: 26, dividerH: 56 };
}

function flattenSectionTables(tables) {
  const out = [];
  for (const tb of tables) {
    out.push(tb);
    for (const g of tb.columnGroups) {
      if (g.tables.length) out.push(...flattenSectionTables(g.tables));
    }
  }
  return out;
}

// Mirrors colWidthPx's on-screen model (own configured width, or shrink-to-content at
// width 0) instead of evenly dividing a fixed canvas width among columns.
function canvasColWidth(c, tb) {
  if (c.kind === 'spacer') {
    const base = c.width || tb.defaultColumnWidth || 30;
    return Math.max(8, Math.round(base / 3));
  }
  const effWidth = (c.width !== null && c.width !== undefined) ? c.width : tb.defaultColumnWidth;
  if (effWidth === 0) {
    return Math.max(60, Math.round(measureTextPx(longestCellText(c, tb), 'bold 11px Segoe UI, Arial, sans-serif') + 16));
  }
  return effWidth || 120;
}

const DISCORD_MAX_COL_PX = 120;
const DISCORD_MAX_COLS = 9;
const DISCORD_MAX_TOTAL_PX = DISCORD_MAX_COLS * DISCORD_MAX_COL_PX;
const DISCORD_MAX_ROWS = 8;
const DISCORD_GAP = 18;

function discordColWidth(c, tb) { return Math.min(canvasColWidth(c, tb), DISCORD_MAX_COL_PX); }
function discordTableWidth(tb) { return tb.columns.reduce((s, c) => s + discordColWidth(c, tb), 0); }

// Splits one table's rows into <=DISCORD_MAX_ROWS-tall chunks. Spacer rows ride along with
// whichever chunk they land in and don't count against the cap -- they're half-height
// dividers, not content a reader needs to parse.
function chunkRowsForDiscord(rows) {
  const chunks = [];
  let current = [];
  let count = 0;
  for (const r of rows) {
    if (r.kind !== 'spacer' && count >= DISCORD_MAX_ROWS) {
      chunks.push(current);
      current = [];
      count = 0;
    }
    current.push(r);
    if (r.kind !== 'spacer') count++;
  }
  chunks.push(current);
  return chunks;
}

function buildDiscordBlocks(tables) {
  const blocks = [];
  for (const tb of tables) {
    if (!tb.columns.length) { blocks.push({ tb, rows: [], chunkIndex: 0, chunkTotal: 1 }); continue; }
    const rowChunks = chunkRowsForDiscord(tb.rows);
    rowChunks.forEach((rows, i) => blocks.push({ tb, rows, chunkIndex: i, chunkTotal: rowChunks.length }));
  }
  return blocks;
}

// Greedy bin-packing, same algorithm as template-edit.php's Discord preview mode: blocks
// (a whole table, or one row-chunk of a too-tall table) pack side by side until the
// combined width would exceed the budget, then a new post starts. Two spacer columns
// meeting at a block-to-block boundary count as one combined column, not two, since
// they'd visually merge into a single gap once rendered. Packing never crosses a section
// boundary -- buildDiscordPlan() below calls this once per section.
function groupBlocksIntoPosts(blocks) {
  const posts = [];
  let current = [];
  let currentWidth = 0;
  const flush = () => { if (current.length) posts.push(current); };
  for (const block of blocks) {
    const width = discordTableWidth(block.tb);
    let addWidth = width;
    if (current.length) {
      const prevTb = current[current.length - 1].tb;
      const prevLast = prevTb.columns[prevTb.columns.length - 1];
      const nextFirst = block.tb.columns[0];
      if (prevLast && nextFirst && prevLast.kind === 'spacer' && nextFirst.kind === 'spacer') {
        const prevW = discordColWidth(prevLast, prevTb);
        const nextW = discordColWidth(nextFirst, block.tb);
        addWidth = Math.max(0, addWidth - Math.min(prevW, nextW));
      }
    }
    if (current.length && currentWidth + addWidth > DISCORD_MAX_TOTAL_PX) {
      flush();
      current = [block];
      currentWidth = width;
    } else {
      current.push(block);
      currentWidth += addWidth;
    }
  }
  flush();
  return posts;
}

function measureBlockHeight(block, m) {
  const tb = block.tb;
  let h = tb.title ? m.tableTitleH : 0;
  if (!tb.columns.length) return h;
  if (tb.columnGroups.some(g => tb.columns.some(c => c.groupId === g.id))) h += m.groupH;
  for (const r of block.rows) h += r.kind === 'spacer' ? Math.max(6, m.rowH / 2) : m.rowH;
  return h;
}

function measurePostWidth(blocks) {
  return blocks.reduce((s, b) => s + discordTableWidth(b.tb), 0) + DISCORD_GAP * Math.max(0, blocks.length - 1);
}

function drawBlock(ctx, block, m, x0, y0) {
  const tb = block.tb;
  const width = discordTableWidth(tb);
  let y = y0;
  const blockH = measureBlockHeight(block, m);
  if (blockH > 0) {
    ctx.fillStyle = tb.bgColor || '#141a2c';
    ctx.fillRect(x0, y, width, blockH);
  }
  if (tb.title) {
    ctx.fillStyle = tb.headerColor || '#1c2333';
    ctx.fillRect(x0, y, width, m.tableTitleH);
    ctx.fillStyle = contrastText(tb.headerColor || '#1c2333');
    ctx.font = 'bold 13px Segoe UI, Arial, sans-serif';
    const label = tb.title + (block.chunkTotal > 1 ? ` (${block.chunkIndex + 1}/${block.chunkTotal})` : '');
    ctx.fillText(label, x0 + 8, y + m.tableTitleH / 2, width - 16);
    y += m.tableTitleH;
  }
  if (!tb.columns.length) return;

  const chunk = tb.columns;
  const colBoxes = [];
  let cx = x0;
  for (const c of chunk) { const w = discordColWidth(c, tb); colBoxes.push({ c, x: cx, w }); cx += w; }

  if (tb.columnGroups.some(g => chunk.some(c => c.groupId === g.id))) {
    let i = 0;
    while (i < chunk.length) {
      const gid = chunk[i].groupId;
      if (!gid) { i++; continue; }
      let span = 1;
      while (i + span < chunk.length && chunk[i + span].groupId === gid) span++;
      const grp = tb.columnGroups.find(g => g.id === gid);
      const box = colBoxes[i];
      const totalW = colBoxes.slice(i, i + span).reduce((s, b) => s + b.w, 0);
      if (grp) {
        ctx.fillStyle = grp.color || '#2a3350';
        ctx.fillRect(box.x, y, totalW, m.groupH);
        ctx.fillStyle = contrastText(grp.color || '#2a3350');
        ctx.font = 'bold 10px Segoe UI, Arial, sans-serif';
        ctx.fillText(grp.title || '', box.x + 4, y + m.groupH / 2, totalW - 8);
      }
      i += span;
    }
    y += m.groupH;
  }

  for (const r of block.rows) {
    if (r.kind === 'spacer') {
      const spacerH = Math.max(6, m.rowH / 2);
      if (r.bgColor) { ctx.fillStyle = r.bgColor; ctx.fillRect(x0, y, width, spacerH); }
      y += spacerH;
      continue;
    }
    const rowH = m.rowH;
    ctx.strokeStyle = 'rgba(255,255,255,0.06)';
    ctx.strokeRect(x0 + 0.5, y + 0.5, width - 1, rowH - 1);
    colBoxes.forEach(box => {
      const cell = tb.cells[r.id + '_' + box.c.id];
      const eff = effectiveKind(r, box.c, cell);
      if (eff === 'spacer') {
        const spacerColor = (cell && cell.bgColor) || (r.kind === 'spacer' && r.bgColor) || box.c.bgColor || null;
        if (spacerColor) { ctx.fillStyle = spacerColor; ctx.fillRect(box.x, y, box.w, rowH); }
        return;
      }
      if (eff === 'text') {
        if (cell && cell.bgColor) { ctx.fillStyle = cell.bgColor; ctx.fillRect(box.x, y, box.w, rowH); }
        ctx.fillStyle = (cell && cell.textColor) || '#c7cfe8';
        const fontFamily = (cell && CELL_FONT_STACKS[cell.font]) || 'Segoe UI, Arial, sans-serif';
        ctx.font = `${(cell && cell.bold) ? 'bold ' : ''}11px ${fontFamily}`;
        const parts = parseCellText(cell && cell.textContent);
        const iconSize = 13;
        const maxX = box.x + box.w - 4;
        let tx = box.x + 6;
        for (const p of parts) {
          if (tx >= maxX) break;
          if (p.type === 'icon') {
            drawRaidIcon(ctx, p.key, tx, y + rowH / 2 - iconSize / 2, iconSize);
            tx += iconSize + 2;
          } else {
            ctx.fillText(p.value, tx, y + rowH / 2, maxX - tx);
            tx += measureTextPx(p.value, ctx.font);
          }
        }
        return;
      }
      if (eff === 'icon') {
        if (cell && cell.bgColor) { ctx.fillStyle = cell.bgColor; ctx.fillRect(box.x, y, box.w, rowH); }
        if (cell && cell.icon) {
          const size = Math.min(rowH - 8, 26);
          drawRaidIcon(ctx, cell.icon, box.x + (box.w - size) / 2, y + (rowH - size) / 2, size);
        }
        return;
      }
      if (cell && cell.bgColor) { ctx.fillStyle = cell.bgColor; ctx.fillRect(box.x, y, box.w, rowH); }
      if (cell && cell.name) {
        const color = classColor(cell.class);
        const pad = 3;
        ctx.fillStyle = color;
        ctx.fillRect(box.x + pad, y + pad, box.w - pad * 2, rowH - pad * 2);
        ctx.fillStyle = contrastText(color);
        ctx.font = 'bold 11px Segoe UI, Arial, sans-serif';
        ctx.fillText(cell.name.toUpperCase(), box.x + pad + 5, y + rowH / 2, box.w - pad * 2 - 10);
      }
    });
    y += rowH;
  }
}

function buildPostCanvas(blocks) {
  const m = raidCanvasMetrics();
  const widths = blocks.map(b => discordTableWidth(b.tb));
  const heights = blocks.map(b => measureBlockHeight(b, m));
  const totalW = Math.max(200, m.pad * 2 + widths.reduce((a, w) => a + w, 0) + DISCORD_GAP * Math.max(0, blocks.length - 1));
  const totalH = Math.max(60, m.pad * 2 + Math.max(0, ...heights));
  const canvas = document.createElement('canvas');
  const dpr = window.devicePixelRatio || 1;
  canvas.width = totalW * dpr;
  canvas.height = totalH * dpr;
  canvas.style.width = totalW + 'px';
  canvas.style.height = totalH + 'px';
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.textBaseline = 'middle';
  ctx.fillStyle = '#0b0e1a';
  ctx.fillRect(0, 0, totalW, totalH);
  let x = m.pad;
  blocks.forEach((b, i) => {
    drawBlock(ctx, b, m, x, m.pad);
    x += widths[i] + DISCORD_GAP;
  });
  return canvas;
}

function buildDividerCanvas(sec, width) {
  const meta = KIND_META[sec.kind] || { label: sec.kind, color: '#5865f2', icon: '' };
  const headColor = sec.color || meta.color;
  const m = raidCanvasMetrics();
  const w = Math.max(400, width);
  const h = m.dividerH;
  const canvas = document.createElement('canvas');
  const dpr = window.devicePixelRatio || 1;
  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = w + 'px';
  canvas.style.height = h + 'px';
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.fillStyle = headColor;
  ctx.fillRect(0, 0, w, h);
  ctx.fillStyle = 'rgba(255,255,255,0.18)';
  ctx.fillRect(0, 0, w, 3);
  ctx.fillRect(0, h - 3, w, 3);
  ctx.fillStyle = contrastText(headColor);
  ctx.font = 'bold 20px Segoe UI, Arial, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(`${meta.icon} ${sec.title}`.trim(), w / 2, h / 2, w - 24);
  return canvas;
}

// Builds the full ordered posting plan: one divider image + N table-group images per
// non-empty section, in section order (already kind-grouped -- see groupSectionsByKind).
function buildDiscordPlan() {
  const plan = [];
  for (const sec of sections) {
    const tables = flattenSectionTables(sec.tables);
    if (!tables.length) continue;
    const blocks = buildDiscordBlocks(tables);
    const posts = groupBlocksIntoPosts(blocks);
    if (!posts.length) continue;
    const dividerWidth = Math.max(...posts.map(measurePostWidth));
    plan.push({ kind: 'divider', sec, canvas: buildDividerCanvas(sec, dividerWidth) });
    for (const post of posts) plan.push({ kind: 'post', sec, canvas: buildPostCanvas(post) });
  }
  return plan;
}

function wireDiscordControls() {
  const toggleBtn = document.getElementById('discordToggleBtn');
  const backdrop = document.getElementById('discordModalBackdrop');
  if (!toggleBtn || !backdrop) return;
  const closeBtn = document.getElementById('discordCloseBtn');
  const postBtn = document.getElementById('discordPostBtn');
  const select = document.getElementById('discordWebhookSelect');
  const statusEl = document.getElementById('discordStatus');
  const previewWrap = document.getElementById('discordPreviewWrap');
  let plan = [];

  toggleBtn.addEventListener('click', () => {
    plan = buildDiscordPlan();
    previewWrap.innerHTML = plan.length
      ? plan.map((item, i) => `<div class="discord-preview-item"><div class="discord-preview-label">${item.kind === 'divider' ? 'Section header — ' + esc(item.sec.title) : 'Post'}</div></div>`).join('')
      : '<p class="empty">Nothing to post.</p>';
    plan.forEach((item, i) => {
      const holder = previewWrap.children[i];
      if (holder) holder.appendChild(item.canvas);
    });
    statusEl.textContent = plan.length ? `${plan.length} image${plan.length === 1 ? '' : 's'} will be posted, in order.` : '';
    backdrop.classList.add('open');
  });
  if (closeBtn) closeBtn.addEventListener('click', () => backdrop.classList.remove('open'));
  backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.classList.remove('open'); });

  if (postBtn) postBtn.addEventListener('click', async () => {
    const url = select ? select.value : '';
    if (!url) { statusEl.textContent = 'No webhook selected.'; return; }
    if (!plan.length) { statusEl.textContent = 'Nothing to post.'; return; }
    postBtn.disabled = true;
    const message = document.getElementById('discordMessageInput').value.trim();
    try {
      for (let i = 0; i < plan.length; i++) {
        statusEl.textContent = `Posting ${i + 1}/${plan.length}…`;
        const blob = await new Promise(res => plan[i].canvas.toBlob(res, 'image/png'));
        if (!blob) throw new Error('Could not render image ' + (i + 1));
        const form = new FormData();
        form.append('files[0]', blob, (plan[i].kind === 'divider' ? 'divider' : 'post') + i + '.png');
        const payload = { username: 'RaidRoster', content: '' };
        if (i === 0 && message) payload.content = message;
        form.append('payload_json', JSON.stringify(payload));
        const resp = await fetch(url, { method: 'POST', body: form });
        if (!resp.ok) throw new Error('Discord error: HTTP ' + resp.status);
        if (i < plan.length - 1) await new Promise(r => setTimeout(r, 500));
      }
      statusEl.textContent = `Posted ${plan.length} image${plan.length === 1 ? '' : 's'}!`;
    } catch (e) {
      statusEl.textContent = 'Failed: ' + e.message;
    } finally {
      postBtn.disabled = false;
    }
  });
}

// AngryERA export: resolves a tab's pages ({{token}} templates) against this raid's actual
// assignments (same slot-key grammar as template-edit.php's preview -- row label, or
// row|column when a table has more than one data column -- but the value is the assigned
// toon's name instead of a label placeholder), builds the JSON shape AngryERA expects, and
// copies it to the clipboard. Export config is per-tab (TAB_EXPORTS, keyed by kind) and
// available to any readonly+ visitor -- there's no privileged "healer" kind any more.
function walkHealerSlots(secs, cb) {
  function walk(tables) {
    for (const tb of tables) {
      // Benched/Swaps tables have no admin-authored slot labels to export against --
      // skip them so a Benched table sharing a tab with real assignment tables can't
      // accidentally contribute a spurious {{slot}} token.
      if (tb.kind !== 'standard') continue;
      for (const r of tb.rows) {
        if (r.kind === 'spacer' || !r.label) continue;
        const dataCols = tb.columns.filter(c => effectiveKind(r, c, tb.cells[r.id + '_' + c.id]) === 'general');
        if (dataCols.length === 1) {
          cb(r.label, null, r, dataCols[0], tb);
        } else if (dataCols.length > 1) {
          for (const c of dataCols) { if (c.label) cb(r.label, c.label, r, c, tb); }
        }
      }
      for (const g of tb.columnGroups) walk(g.tables);
    }
  }
  walk(secs.flatMap(s => s.tables));
}

function healerSlotMap(secs) {
  const map = {};
  walkHealerSlots(secs, (rowLabel, colLabel, r, c, tb) => {
    const key = (colLabel ? rowLabel + '|' + colLabel : rowLabel).trim().toLowerCase();
    const cell = tb.cells[r.id + '_' + c.id];
    map[key] = cell && cell.name ? cell.name : null;
  });
  return map;
}

function applyExportTemplate(tmpl, resolveFn) {
  return (tmpl || '').replace(/\{\{([^}]+)\}\}/g, (_, expr) => {
    if (expr.charAt(0) === '*') {
      const names = expr.slice(1).split(',').map(k => resolveFn(k.trim())).filter(Boolean);
      return names.join(', ') || '—';
    }
    if (expr.charAt(0) === '#') {
      return expr.slice(1).split(',').map((k, i) => { const nm = resolveFn(k.trim()); return nm ? nm + ' (' + (i + 1) + ')' : ''; }).filter(Boolean).join(', ') || '—';
    }
    return resolveFn(expr.trim()) || '—';
  });
}

function buildEraExportJSON(k) {
  const te = TAB_EXPORTS[k];
  if (!te) return null;
  const secs = sections.filter(s => s.kind === k);
  const map = healerSlotMap(secs);
  const resolve = tmpl => applyExportTemplate(tmpl, key => map[key.trim().toLowerCase()] ?? null);
  const name = te.exportName || k;
  if (te.singlePage) {
    const p = te.pages[0];
    return { content: resolve(p ? p.template : ''), name };
  }
  return { name, pages: te.pages.map(p => ({ name: p.name, content: resolve(p.template) })) };
}

function wireEraExport() {
  document.querySelectorAll('[data-era-kind]').forEach(btn => {
    btn.addEventListener('click', () => {
      const json = buildEraExportJSON(btn.dataset.eraKind);
      if (!json) return;
      const orig = btn.textContent;
      navigator.clipboard.writeText(JSON.stringify(json, null, 2)).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 2000);
      }).catch(() => { alert('Could not copy to clipboard.'); });
    });
  });
}

// MRT (Method Raid Tools) export: per-section, produces a compressed 'MRTRGR1...'
// string importable via MRT's Raid Groups screen. Columns whose own kind is 'general'
// are read as groups 1-8 (in column order across the section's tables, depth-first
// through nested column groups); rows whose own kind is 'general' are read as slots
// 1-5 within their own table. Offered per home server so same-server toons show as a
// bare name and other-server toons get a '-ServerName' suffix.
const MRT_SERVERS = [
  { key: 'PyrewoodVillage',  label: 'MRT (Pyrewood)' },
  { key: 'MirageRaceway',    label: 'MRT (Mirage)'   },
  { key: 'NethergardeKeep',  label: 'MRT (Nethergar)'},
];
const MRT_TIP = 'Use the export that matches the server your character is on. '
  + 'Toons on the same server as you will appear as just their name; '
  + 'toons on the other two servers will include their server name (e.g. Name-MirageRaceway).';

const _MRT6 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789()';

function _mrtEncode(bytes) {
  const out = [];
  const n = bytes.length;
  let i = 0;
  while (i + 2 < n) {
    let c = bytes[i] + bytes[i+1]*256 + bytes[i+2]*65536; i += 3;
    const b1=c%64; c=(c-b1)/64;
    const b2=c%64; c=(c-b2)/64;
    const b3=c%64; const b4=(c-b3)/64;
    out.push(_MRT6[b1],_MRT6[b2],_MRT6[b3],_MRT6[b4]);
  }
  let c=0, bits=0;
  while (i < n) { c += bytes[i]*Math.pow(2,bits); bits+=8; i++; }
  while (bits > 0) { const b=c%64; out.push(_MRT6[b]); c=(c-b)/64; bits-=6; }
  return out.join('');
}

async function _mrtDeflate(str) {
  const raw = new TextEncoder().encode(str);
  const ds = new CompressionStream('deflate-raw');
  const w = ds.writable.getWriter();
  w.write(raw); w.close();
  const chunks = []; const r = ds.readable.getReader();
  while (true) { const {done,value}=await r.read(); if(done)break; chunks.push(value); }
  let len=0; for(const c of chunks) len+=c.length;
  const out=new Uint8Array(len); let off=0;
  for(const c of chunks){out.set(c,off);off+=c.length;}
  return out;
}

function _mrtToonName(cell, homeServer) {
  if (!cell || !cell.name) return null;
  const srv = cell.server || '';
  if (!srv || srv === homeServer) return cell.name;
  return cell.name + '-' + srv;
}

function _mrtBuildSectionNames(sec, homeServer) {
  const names = {};
  let groupIdx = 0;
  function walk(tables) {
    for (const tb of tables) {
      // A Benched table's single 'general' column isn't a raid group -- skip it so it
      // can't consume a groupIdx slot and shift the numbering of the real groups after it.
      if (tb.kind !== 'standard') continue;
      const generalCols = tb.columns.filter(c => c.kind === 'general');
      const generalRows = tb.rows.filter(r => r.kind === 'general');
      for (const c of generalCols) {
        if (groupIdx >= 8) break;
        generalRows.forEach((r, slotIdx) => {
          if (slotIdx >= 5) return;
          const cell = tb.cells[r.id + '_' + c.id];
          if (effectiveKind(r, c, cell) !== 'general') return;
          const nm = _mrtToonName(cell, homeServer);
          if (nm) names[groupIdx * 5 + slotIdx + 1] = nm;
        });
        groupIdx++;
      }
      for (const g of tb.columnGroups) walk(g.tables);
    }
  }
  walk(sec.tables);
  return names;
}

function _mrtBuildTable(sec, homeServer) {
  const names = _mrtBuildSectionNames(sec, homeServer);
  const keys = Object.keys(names).map(Number).sort((a, b) => a - b);
  if (!keys.length) return null;

  let seqEnd = 0;
  while (names[seqEnd + 1] !== undefined) seqEnd++;

  const q = s => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  const parts = [];
  for (let i = 1; i <= seqEnd; i++) parts.push(`"${q(names[i])}"`);
  keys.filter(k => k > seqEnd).forEach(k => parts.push(`[${k}]="${q(names[k])}"`));

  return '0,{' + parts.join(',') + '}';
}

async function doMrtExport(btn) {
  const sectionId = parseInt(btn.dataset.sectionId, 10);
  const homeServer = btn.dataset.mrtServer;
  const sec = sections.find(s => s.id === sectionId);
  if (!sec) return;
  const table = _mrtBuildTable(sec, homeServer);
  if (!table) { alert('No roster assignments to export.'); return; }
  const compressed = await _mrtDeflate(table);
  const result = 'MRTRGR1' + _mrtEncode(compressed);
  try {
    await navigator.clipboard.writeText(result);
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => { btn.textContent = orig; }, 1800);
  } catch {
    prompt('Copy the MRT export string:', result);
  }
}

function wireClearAll() {
  const btn = document.getElementById('clearAllBtn');
  if (!btn) return;
  btn.addEventListener('click', () => {
    if (!confirm('This clears every assignment in this raid, including the Available Toons pool — cannot be undone. Continue?')) return;
    clearCall({ action: 'clear_all' });
  });
}

render();
wireEraExport();
if (CAN_MANAGE) { checkLock(); renderPool(); wirePoolControls(); wireImportControls(); wireDiscordControls(); wireClearAll(); }
</script>
</body>
</html>
