<?php
// Shared read-path for a raid's full section/table/cell tree. Used by raids/view.php
// for the initial page render and by raids/cells-save.php to return the refreshed
// tree after a bulk clear (clear_section/clear_all), so both stay in the exact same shape.

function fetch_table_full($pdo, $tb) {
    $stmtC = $pdo->prepare('SELECT id, label, kind, width, header_color, group_id, header_colspan FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = array_map(fn($c) => [
        'id' => (int)$c['id'], 'label' => $c['label'], 'kind' => $c['kind'],
        'width' => $c['width'] !== null ? (int)$c['width'] : null,
        'headerColor' => $c['header_color'],
        'groupId' => $c['group_id'] !== null ? (int)$c['group_id'] : null,
        'headerColspan' => (int)$c['header_colspan'],
    ], $stmtC->fetchAll(PDO::FETCH_ASSOC));

    $stmtR = $pdo->prepare('SELECT id, label, kind, height FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'kind' => $r['kind'], 'height' => $r['height'] !== null ? (int)$r['height'] : null], $stmtR->fetchAll(PDO::FETCH_ASSOC));

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
        'SELECT c.id, c.row_id, c.column_id, c.toon_id, c.toon_kind, c.pug_name, c.pug_class, c.marked,
                c.text_content, c.bg_color, c.text_color,
                COALESCE(t.main_name, a.name) AS toon_name,
                COALESCE(t.class, a.class) AS toon_class
         FROM raid_cells c
         LEFT JOIN toons t ON c.toon_kind = \'main\' AND t.id = c.toon_id
         LEFT JOIN toon_alts a ON c.toon_kind = \'alt\' AND a.id = c.toon_id
         WHERE c.table_id = ?'
    );
    $stmtCell->execute([$tb['id']]);
    $cells = [];
    foreach ($stmtCell->fetchAll(PDO::FETCH_ASSOC) as $cell) {
        $isPug = $cell['toon_kind'] === 'pug';
        $cells[$cell['row_id'] . '_' . $cell['column_id']] = [
            'id'          => (int)$cell['id'],
            'toonKind'    => $cell['toon_kind'],
            'toonId'      => $cell['toon_id'],
            'pugName'     => $cell['pug_name'],
            'pugClass'    => $cell['pug_class'],
            'name'        => $isPug ? $cell['pug_name'] : $cell['toon_name'],
            'class'       => $isPug ? $cell['pug_class'] : $cell['toon_class'],
            'marked'      => (bool)$cell['marked'],
            'textContent' => $cell['text_content'],
            'bgColor'     => $cell['bg_color'],
            'textColor'   => $cell['text_color'],
        ];
    }

    $stmtM = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $cellMerges = array_map(fn($m) => [
        'rowId' => (int)$m['row_id'], 'columnId' => (int)$m['column_id'], 'colspan' => (int)$m['colspan'],
    ], $stmtM->fetchAll(PDO::FETCH_ASSOC));

    return [
        'id' => (int)$tb['id'], 'title' => $tb['title'],
        'headerColor' => $tb['header_color'],
        'defaultColumnWidth' => $tb['default_column_width'] !== null ? (int)$tb['default_column_width'] : null,
        'columns' => $columns, 'rows' => $rows, 'columnGroups' => $columnGroups, 'cells' => $cells,
        'cellMerges' => $cellMerges,
    ];
}

function fetch_raid_structure($pdo, $raidId) {
    $out = [];
    $stmt = $pdo->prepare('SELECT * FROM raid_sections WHERE raid_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $stmtT = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        $tables = array_map(fn($tb) => fetch_table_full($pdo, $tb), $stmtT->fetchAll(PDO::FETCH_ASSOC));
        $out[] = [
            'id' => (int)$sec['id'], 'kind' => $sec['kind'], 'title' => $sec['title'], 'tables' => $tables,
            'noteEnabled' => (bool)$sec['note_enabled'], 'noteText' => $sec['note_text'],
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
