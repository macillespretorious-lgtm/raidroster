<?php
// Snapshots a template's section/table/column/row structure into raid-scoped rows at
// creation time, same rationale as raids.name being copied from the template: later
// template edits shouldn't reshape a raid that already happened. Cells are pre-created
// empty for every row x column pair so cells-save.php only ever needs to UPDATE.
function copy_template_structure_to_raid($pdo, $templateId, $raidId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $ins = $pdo->prepare('INSERT INTO raid_sections (raid_id, kind, title, sort_order) VALUES (?, ?, ?, ?)');
        $ins->execute([$raidId, $sec['kind'], $sec['title'], $sec['sort_order']]);
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
        'INSERT INTO raid_tables (section_id, parent_group_id, title, sort_order, header_color, default_column_width, row_label_width)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insT->execute([$newSectionId, $newParentGroupId, $tb['title'], $tb['sort_order'], $tb['header_color'], $tb['default_column_width'], $tb['row_label_width']]);
    $newTableId = (int)$pdo->lastInsertId();

    // Column groups first (columns FK into them), preserving parent_group_id links via an old->new id map.
    $stmtG = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $groupIdMap = [];
    $insG = $pdo->prepare('INSERT INTO raid_column_groups (table_id, parent_group_id, title, color, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($groupRows as $grp) {
        $insG->execute([$newTableId, $grp['parent_group_id'] ? ($groupIdMap[$grp['parent_group_id']] ?? null) : null, $grp['title'], $grp['color'], $grp['sort_order']]);
        $groupIdMap[$grp['id']] = (int)$pdo->lastInsertId();
    }

    $stmtC = $pdo->prepare('SELECT * FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columns = [];
    $insC = $pdo->prepare(
        'INSERT INTO raid_columns (table_id, label, sort_order, kind, width, header_color, group_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $newGroupId = $col['group_id'] ? ($groupIdMap[$col['group_id']] ?? null) : null;
        $insC->execute([$newTableId, $col['label'], $col['sort_order'], $col['kind'], $col['width'], $col['header_color'], $newGroupId]);
        $columns[] = ['id' => (int)$pdo->lastInsertId(), 'kind' => $col['kind']];
    }

    $stmtR = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = [];
    $insR = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $insR->execute([$newTableId, $row['label'], $row['sort_order'], $row['kind']]);
        $rows[] = ['id' => (int)$pdo->lastInsertId(), 'kind' => $row['kind']];
    }

    // Spacer rows/columns never hold data, so no raid_cells row is created for either side.
    $insCell = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)');
    foreach ($rows as $r) {
        if ($r['kind'] === 'spacer') continue;
        foreach ($columns as $c) {
            if ($c['kind'] === 'spacer') continue;
            $insCell->execute([$newTableId, $r['id'], $c['id']]);
        }
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
