<?php
// Snapshots a template's section/table/column/row structure into raid-scoped rows at
// creation time, same rationale as raids.name being copied from the template: later
// template edits shouldn't reshape a raid that already happened. Cells are pre-created
// empty for every row x column pair so cells-save.php only ever needs to UPDATE.
function copy_template_structure_to_raid($pdo, $templateId, $raidId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $ins = $pdo->prepare('INSERT INTO raid_sections (raid_id, kind, title, sort_order, note_enabled, note_text, source_section_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$raidId, $sec['kind'], $sec['title'], $sec['sort_order'], $sec['note_enabled'], $sec['note_text'], $sec['id']]);
        $newSectionId = (int)$pdo->lastInsertId();

        $stmtT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $tb) {
            copy_table_recursive($pdo, $tb, $newSectionId, null);
        }
    }
}

// Copies one template table (and, recursively, any nested boss-tables living inside its
// column-groups) into a raid-scoped raid_tables row. Exactly one of $newSectionId /
// $newParentGroupId is non-null, matching the section_id/parent_group_id invariant.
function copy_table_recursive($pdo, $tb, $newSectionId, $newParentGroupId) {
    $insT = $pdo->prepare(
        'INSERT INTO raid_tables (section_id, parent_group_id, title, sort_order, header_color, default_column_width, source_table_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insT->execute([$newSectionId, $newParentGroupId, $tb['title'], $tb['sort_order'], $tb['header_color'], $tb['default_column_width'], $tb['id']]);
    $newTableId = (int)$pdo->lastInsertId();

    // Column groups first (columns FK into them), preserving parent_group_id links via an old->new id map.
    $stmtG = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $groupIdMap = [];
    $insG = $pdo->prepare('INSERT INTO raid_column_groups (table_id, parent_group_id, title, color, sort_order, source_group_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($groupRows as $grp) {
        $insG->execute([$newTableId, $grp['parent_group_id'] ? ($groupIdMap[$grp['parent_group_id']] ?? null) : null, $grp['title'], $grp['color'], $grp['sort_order'], $grp['id']]);
        $groupIdMap[$grp['id']] = (int)$pdo->lastInsertId();
    }

    $stmtC = $pdo->prepare('SELECT * FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = [];
    $columnIdMap = [];
    $insC = $pdo->prepare(
        'INSERT INTO raid_columns (table_id, label, sort_order, kind, width, header_color, group_id, header_colspan, source_column_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $newGroupId = $col['group_id'] ? ($groupIdMap[$col['group_id']] ?? null) : null;
        $insC->execute([$newTableId, $col['label'], $col['sort_order'], $col['kind'], $col['width'], $col['header_color'], $newGroupId, $col['header_colspan'], $col['id']]);
        $newColId = (int)$pdo->lastInsertId();
        $columns[] = ['id' => $newColId, 'kind' => $col['kind'], 'srcId' => $col['id']];
        $columnIdMap[$col['id']] = $newColId;
    }

    $stmtR = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = [];
    $rowIdMap = [];
    $insR = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind, height, source_row_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $insR->execute([$newTableId, $row['label'], $row['sort_order'], $row['kind'], $row['height'], $row['id']]);
        $newRowId = (int)$pdo->lastInsertId();
        $rows[] = ['id' => $newRowId, 'kind' => $row['kind'], 'srcId' => $row['id']];
        $rowIdMap[$row['id']] = $newRowId;
    }

    // Template-authored cell text/colors, keyed by source row/column id so they can be
    // carried onto the freshly-created raid_cells rows below.
    $stmtCells = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color FROM raid_template_cells WHERE table_id = ?');
    $stmtCells->execute([$tb['id']]);
    $tplCells = [];
    foreach ($stmtCells->fetchAll(PDO::FETCH_ASSOC) as $tc) {
        $tplCells[$tc['row_id'] . '_' . $tc['column_id']] = $tc;
    }

    // Spacer rows/columns never hold data, so no raid_cells row is created for either side.
    $insCell = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id, text_content, bg_color, text_color) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        if ($r['kind'] === 'spacer') continue;
        foreach ($columns as $c) {
            if ($c['kind'] === 'spacer') continue;
            $tc = $tplCells[$r['srcId'] . '_' . $c['srcId']] ?? null;
            $insCell->execute([$newTableId, $r['id'], $c['id'], $tc['text_content'] ?? null, $tc['bg_color'] ?? null, $tc['text_color'] ?? null]);
        }
    }

    // Cell merges (horizontal colspan within one row) reference specific row/column ids,
    // so remap them the same way group nesting does above.
    $stmtM = $pdo->prepare('SELECT * FROM raid_template_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $insM = $pdo->prepare('INSERT INTO raid_cell_merges (table_id, row_id, column_id, colspan) VALUES (?, ?, ?, ?)');
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (!isset($rowIdMap[$m['row_id']]) || !isset($columnIdMap[$m['column_id']])) continue;
        $insM->execute([$newTableId, $rowIdMap[$m['row_id']], $columnIdMap[$m['column_id']], $m['colspan']]);
    }

    // Nested boss-tables: for each column-group on this table, recurse into any template
    // tables parented to it (parent_group_id), attaching them under the newly-copied group.
    foreach ($groupRows as $grp) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$grp['id']]);
        foreach ($stmtGT->fetchAll(PDO::FETCH_ASSOC) as $childTb) {
            copy_table_recursive($pdo, $childTb, null, $groupIdMap[$grp['id']]);
        }
    }
}
