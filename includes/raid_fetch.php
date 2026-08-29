<?php
// Shared read-path for a raid's full section/table/cell tree. Used by raids/view.php
// for the initial page render and by raids/cells-save.php to return the refreshed
// tree after a bulk clear (clear_section/clear_all), so both stay in the exact same shape.

require_once __DIR__ . '/class_roles.php';
require_once __DIR__ . '/raid_swaps.php';

// Synthesizes a Swaps table's read shape -- 5 fixed text-kind columns (negative ids,
// never persisted) and one row per computed before/after pair -- so the existing DOM
// table renderer and Discord-canvas block builder can draw it with no new code, exactly
// like they draw any other text-kind table. The Before/After pairing itself is never
// stored; it's recomputed live from the two source tables' current cells on every read.
function fetch_swaps_table($pdo, $tb, $guildId) {
    $stmtBt = $pdo->prepare('SELECT * FROM raid_tables WHERE id = ?');
    $beforeTb = null;
    $afterTb = null;
    if ($tb['swap_before_table_id']) {
        $stmtBt->execute([$tb['swap_before_table_id']]);
        $beforeTb = $stmtBt->fetch(PDO::FETCH_ASSOC);
    }
    if ($tb['swap_after_table_id']) {
        $stmtBt->execute([$tb['swap_after_table_id']]);
        $afterTb = $stmtBt->fetch(PDO::FETCH_ASSOC);
    }
    $beforeFull = $beforeTb ? fetch_table_full($pdo, $beforeTb, $guildId) : ['cells' => []];
    $afterFull  = $afterTb  ? fetch_table_full($pdo, $afterTb, $guildId)  : ['cells' => []];
    $swapRows = compute_swap_rows($pdo, $guildId, (int)$tb['id'], $beforeFull, $afterFull);

    $mkCol = fn($id, $label) => ['id' => $id, 'label' => $label, 'kind' => 'text', 'width' => null, 'headerColor' => null, 'bgColor' => null, 'groupId' => null, 'headerColspan' => 1];
    $columns = [$mkCol(-2, 'From'), $mkCol(-3, 'To'), $mkCol(-4, 'When'), $mkCol(-5, 'Boss')];

    $mkCell = fn($text) => [
        'id' => 0, 'toonKind' => null, 'toonId' => null, 'pugName' => null, 'pugClass' => null,
        'name' => null, 'class' => null, 'role' => null, 'roleConfirmed' => false, 'server' => null,
        'marked' => false, 'textContent' => $text, 'bgColor' => null, 'textColor' => null,
        'bold' => false, 'font' => null, 'icon' => null, 'kindOverride' => null,
    ];
    // From/To cells carry the occupant's name/class/role (not just flattened text) so the
    // DOM renderer draws them as filled class-colored chips, matching a normal roster slot.
    $mkToonCell = fn($occ) => [
        'id' => 0, 'toonKind' => null, 'toonId' => null, 'pugName' => null, 'pugClass' => null,
        'name' => $occ['name'] ?: null, 'class' => $occ['class'] ?: null, 'role' => $occ['role'] ?: null,
        'roleConfirmed' => true, 'server' => null,
        'marked' => false, 'textContent' => trim(($occ['name'] ?: '') . ($occ['class'] ? ' (' . $occ['class'] . ')' : '')),
        'bgColor' => null, 'textColor' => null, 'bold' => false, 'font' => null, 'icon' => null, 'kindOverride' => null,
    ];

    $rows = [];
    $cells = [];
    $rowId = -1;
    foreach ($swapRows as $sr) {
        $rows[] = ['id' => $rowId, 'label' => '', 'kind' => 'text', 'height' => null, 'bgColor' => null, 'playerMainToonId' => $sr['playerMainToonId']];
        $cells[$rowId . '_-2'] = $mkToonCell($sr['before']);
        $cells[$rowId . '_-3'] = $mkToonCell($sr['after']);
        $cells[$rowId . '_-4'] = $mkCell($sr['note']);
        $cells[$rowId . '_-5'] = $mkCell($sr['bossLabel']);
        $rowId--;
    }

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'], 'bgColor' => $tb['bg_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'kind' => 'swaps',
        'swapBeforeTableId' => $tb['swap_before_table_id'] !== null ? (int)$tb['swap_before_table_id'] : null,
        'swapAfterTableId' => $tb['swap_after_table_id'] !== null ? (int)$tb['swap_after_table_id'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => [], 'cells' => $cells,
        'cellMerges' => [], 'rules' => [],
    ];
}

// Synthesizes a Counter table's read shape -- one fixed text-kind column per chosen category
// (negative ids, never persisted) and one computed row of live counts -- same technique as
// fetch_swaps_table() above, so the existing DOM renderer and Discord-canvas export draw it
// with no new code. A role category (Tank/Healer/DPS) counts by the effective role (raw
// raid_cells.role, falling back to default_role_for_class()) already resolved by
// fetch_table_full() below; a class category counts by exact class match. count_categories
// is a comma-separated list chosen in the template editor; null/empty falls back to the
// original fixed Tanks/Healers pair so counter tables created before this field existed
// keep behaving exactly as before.
function fetch_counter_table($pdo, $tb, $guildId) {
    $sourceTb = null;
    if ($tb['count_source_table_id']) {
        $stmt = $pdo->prepare('SELECT * FROM raid_tables WHERE id = ?');
        $stmt->execute([$tb['count_source_table_id']]);
        $sourceTb = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $sourceFull = $sourceTb ? fetch_table_full($pdo, $sourceTb, $guildId) : ['cells' => []];

    $categories = !empty($tb['count_categories']) ? explode(',', $tb['count_categories']) : ['Tank', 'Healer'];
    $roleCategories = ['Tank', 'Healer', 'DPS'];

    $counts = array_fill_keys($categories, 0);
    foreach ($sourceFull['cells'] as $cell) {
        if (!$cell['name']) continue;
        foreach ($categories as $cat) {
            if (in_array($cat, $roleCategories, true)) {
                if ($cell['role'] === $cat) $counts[$cat]++;
            } elseif ($cell['class'] === $cat) {
                $counts[$cat]++;
            }
        }
    }

    $mkCol = fn($id, $label) => ['id' => $id, 'label' => $label, 'kind' => 'text', 'width' => null, 'headerColor' => null, 'bgColor' => null, 'groupId' => null, 'headerColspan' => 1];
    $mkCell = fn($text) => [
        'id' => 0, 'toonKind' => null, 'toonId' => null, 'pugName' => null, 'pugClass' => null,
        'name' => null, 'class' => null, 'role' => null, 'roleConfirmed' => false, 'server' => null,
        'marked' => false, 'textContent' => $text, 'bgColor' => null, 'textColor' => null,
        'bold' => true, 'font' => null, 'icon' => null, 'kindOverride' => null,
    ];

    $columns = [];
    $cells = [];
    $colId = -1;
    foreach ($categories as $cat) {
        $columns[] = $mkCol($colId, $cat);
        $cells['-1_' . $colId] = $mkCell((string)$counts[$cat]);
        $colId--;
    }

    $rows = [['id' => -1, 'label' => '', 'kind' => 'text', 'height' => null, 'bgColor' => null]];

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'], 'bgColor' => $tb['bg_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'kind' => 'counter',
        'countSourceTableId' => $tb['count_source_table_id'] !== null ? (int)$tb['count_source_table_id'] : null,
        'countCategories' => $categories,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => [], 'cells' => $cells,
        'cellMerges' => [], 'rules' => [],
    ];
}

function fetch_table_full($pdo, $tb, $guildId = null) {
    if ($tb['kind'] === 'swaps') return fetch_swaps_table($pdo, $tb, $guildId);
    if ($tb['kind'] === 'counter') return fetch_counter_table($pdo, $tb, $guildId);

    $stmtC = $pdo->prepare('SELECT id, label, kind, width, header_color, bg_color, group_id, header_colspan FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'bgColor' => $c['bg_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmtC->fetchAll(PDO::FETCH_ASSOC));

    $stmtR = $pdo->prepare('SELECT id, label, kind, height, bg_color FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null, 'bgColor' => $r['bg_color']], $stmtR->fetchAll(PDO::FETCH_ASSOC));

    $stmtG = $pdo->prepare('SELECT * FROM raid_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $columnGroups = [];
    foreach ($groupRows as $g) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$g['id']]);
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb, $guildId), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
        $columnGroups[] = [
            'id' => (int)$g['id'],
            'parentGroupId' => $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null,
            'title' => $g['title'], 'color' => $g['color'],
            'tables' => $childTables,
        ];
    }

    $stmtCell = $pdo->prepare(
        'SELECT c.id, c.row_id, c.column_id, c.toon_id, c.toon_kind, c.pug_name, c.pug_class, c.role, c.marked,
                c.text_content, c.bg_color, c.text_color, c.bold, c.font, c.icon, c.kind_override,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class,
                COALESCE(t.main_spec, a.main_spec) AS toon_spec,
                COALESCE(t.server, a.server) AS toon_server
         FROM raid_cells c
         LEFT JOIN toons t ON c.toon_kind = \'main\' AND t.id = c.toon_id
         LEFT JOIN toon_alts a ON c.toon_kind = \'alt\' AND a.id = c.toon_id
         WHERE c.table_id = ?'
    );
    $stmtCell->execute([$tb['id']]);
    $cells = [];
    foreach ($stmtCell->fetchAll(PDO::FETCH_ASSOC) as $cell) {
        $isPug = $cell['toon_kind'] === 'pug';
        $class = $isPug ? $cell['pug_class'] : $cell['toon_class'];
        $spec  = $isPug ? null : $cell['toon_spec'];
        $cells[$cell['row_id'] . '_' . $cell['column_id']] = [
            'id'          => (int)$cell['id'],
            'toonKind'    => $cell['toon_kind'],
            'toonId'      => $cell['toon_id'],
            'pugName'     => $cell['pug_name'],
            'pugClass'    => $cell['pug_class'],
            'name'        => $isPug ? $cell['pug_name'] : $cell['toon_name'],
            'class'       => $class,
            'role'        => $class ? ($cell['role'] ?: default_role_for_class($class, $spec)) : null,
            'roleConfirmed' => $cell['role'] !== null,
            'server'      => $isPug ? null : $cell['toon_server'],
            'marked'      => (bool)$cell['marked'],
            'textContent' => $cell['text_content'],
            'bgColor'     => $cell['bg_color'],
            'textColor'   => $cell['text_color'],
            'bold'        => (bool)$cell['bold'],
            'font'        => $cell['font'],
            'icon'        => $cell['icon'],
            'kindOverride' => $cell['kind_override'],
        ];
    }

    $stmtM = $pdo->prepare('SELECT row_id, column_id, colspan, rowspan FROM raid_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'], 'rowspan' => (int)$m['rowspan'],
    ], $stmtM->fetchAll(PDO::FETCH_ASSOC));

    $stmtRule = $pdo->prepare('SELECT id, rule_type, scope, classes, max_count, label, sort_order FROM raid_rules WHERE table_id = ? ORDER BY sort_order, id');
    $stmtRule->execute([$tb['id']]);
    $ruleRows = $stmtRule->fetchAll(PDO::FETCH_ASSOC);
    $ruleCellsByRule = [];
    if ($ruleRows) {
        $ruleIds = array_column($ruleRows, 'id');
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $stmtRC = $pdo->prepare("SELECT rule_id, row_id, column_id FROM raid_rule_cells WHERE rule_id IN ($placeholders)");
        $stmtRC->execute($ruleIds);
        foreach ($stmtRC->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            $ruleCellsByRule[$rc['rule_id']][] = ['rowId' => (int)$rc['row_id'], 'columnId' => (int)$rc['column_id']];
        }
    }
    $rules = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'ruleType' => $r['rule_type'],
        'scope' => $r['scope'],
        'classes' => $r['classes'],
        'maxCount' => $r['max_count'] !== null ? (int)$r['max_count'] : null,
        'label' => $r['label'],
        'cellRefs' => $ruleCellsByRule[$r['id']] ?? [],
    ], $ruleRows);

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'bgColor' => $tb['bg_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'kind' => $tb['kind'],
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups, 'cells' => $cells,
        'cellMerges' => $cellMerges, 'rules' => $rules,
    ];
}

function fetch_raid_structure($pdo, $raidId) {
    $stmtG = $pdo->prepare('SELECT guild_id FROM raids WHERE id = ?');
    $stmtG->execute([$raidId]);
    $guildId = $stmtG->fetchColumn();

    $out = [];
    $stmt = $pdo->prepare(
        'SELECT rs.*, COALESCE(rts.mrt_export_enabled, 0) AS mrt_export_enabled
         FROM raid_sections rs
         LEFT JOIN raid_template_sections rts ON rts.id = rs.source_section_id
         WHERE rs.raid_id = ? ORDER BY rs.sort_order, rs.id'
    );
    $stmt->execute([$raidId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmtT = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb, $guildId), $stmtT->fetchAll(PDO::FETCH_ASSOC));
        $out[] = [
            'id' => (int)$sec['id'], 'kind' => $sec['kind'], 'title' => $sec['title'], 'color' => $sec['color'], 'bgColor' => $sec['bg_color'], 'tables' => $tables,
            'noteEnabled' => (bool)$sec['note_enabled'], 'noteText' => $sec['note_text'],
            'mrtExportEnabled' => (bool)$sec['mrt_export_enabled'],
        ];
    }
    return $out;
}

// Recursively collects every raid_tables.id under $tableId, including tables nested
// inside column groups at any depth (a table's own id, plus every child group's tables).
function collect_table_ids($pdo, $tableId, &$ids) {
    $ids[] = (int)$tableId;
    $stmtG = $pdo->prepare('SELECT id FROM raid_column_groups WHERE table_id = ?');
    $stmtG->execute([$tableId]);
    foreach ($stmtG->fetchAll(PDO::FETCH_COLUMN) as $groupId) {
        $stmtGT = $pdo->prepare('SELECT id FROM raid_tables WHERE parent_group_id = ?');
        $stmtGT->execute([$groupId]);
        foreach ($stmtGT->fetchAll(PDO::FETCH_COLUMN) as $childTableId) {
            collect_table_ids($pdo, $childTableId, $ids);
        }
    }
}

// Blanks every raid_cells row belonging to the given (already-authorized) table ids.
function clear_cells_for_tables($pdo, $tableIds) {
    if (!$tableIds) return;
    $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
    $stmt = $pdo->prepare("UPDATE raid_cells SET toon_id = NULL, toon_kind = 'main', pug_name = NULL, pug_class = NULL, note = NULL WHERE table_id IN ($placeholders)");
    $stmt->execute($tableIds);
}
