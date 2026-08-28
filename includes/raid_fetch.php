<?php
// Shared read-path for a raid's full section/table/cell tree. Used by raids/view.php
// for the initial page render and by raids/cells-save.php to return the refreshed
// tree after a bulk clear (clear_section/clear_all), so both stay in the exact same shape.

require_once __DIR__ . '/class_roles.php';

function fetch_table_full($pdo, $tb) {
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
        $childTables = array_map(fn($ctb) => fetch_table_full($pdo, $ctb), $stmtGT->fetchAll(PDO::FETCH_ASSOC));
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
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups, 'cells' => $cells,
        'cellMerges' => $cellMerges, 'rules' => $rules,
    ];
}

function fetch_raid_structure($pdo, $raidId) {
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
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb), $stmtT->fetchAll(PDO::FETCH_ASSOC));
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
