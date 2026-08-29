<?php
require_once __DIR__ . '/raid_fetch.php';

// Snapshots a template's section/table/column/row structure into raid-scoped rows at
// creation time, same rationale as raids.name being copied from the template: later
// template edits shouldn't reshape a raid that already happened. Cells are pre-created
// empty for every row x column pair so cells-save.php only ever needs to UPDATE.
function copy_template_structure_to_raid($pdo, $templateId, $raidId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $ins = $pdo->prepare('INSERT INTO raid_sections (raid_id, kind, title, sort_order, note_enabled, note_text, color, bg_color, source_section_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$raidId, $sec['kind'], $sec['title'], $sec['sort_order'], $sec['note_enabled'], $sec['note_text'], $sec['color'], $sec['bg_color'], $sec['id']]);
        $newSectionId = (int)$pdo->lastInsertId();

        $stmtT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $tb) {
            copy_table_recursive($pdo, $tb, $newSectionId, null);
        }
    }

    backfill_swap_links($pdo, $raidId);
}

// Swaps tables carry swap_before_table_id/swap_after_table_id pointing at *template* table
// ids. Run once after the whole section/table tree has been copied (or resynced), so every
// referenced table is guaranteed to already have its raid-side counterpart in place. Uses
// collect_table_ids() (raid_fetch.php) per top-level table so nested boss-tables (which hang
// off column groups, not section_id) are included at any depth.
function backfill_swap_links($pdo, $raidId) {
    $stmtTop = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id IN (SELECT id FROM raid_sections WHERE raid_id = ?)');
    $stmtTop->execute([$raidId]);
    $allIds = [];
    foreach ($stmtTop->fetchAll(PDO::FETCH_COLUMN) as $topId) {
        collect_table_ids($pdo, $topId, $allIds);
    }
    if (!$allIds) return;

    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $stmt = $pdo->prepare("SELECT id, source_table_id FROM raid_tables WHERE id IN ($placeholders)");
    $stmt->execute($allIds);
    $bySource = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['source_table_id'] !== null) $bySource[(int)$row['source_table_id']] = (int)$row['id'];
    }

    $stmtSwaps = $pdo->prepare(
        "SELECT rt.id, tt.swap_before_table_id, tt.swap_after_table_id
         FROM raid_tables rt
         JOIN raid_template_tables tt ON tt.id = rt.source_table_id
         WHERE rt.kind = 'swaps' AND rt.id IN ($placeholders)"
    );
    $stmtSwaps->execute($allIds);
    $upd = $pdo->prepare('UPDATE raid_tables SET swap_before_table_id = ?, swap_after_table_id = ? WHERE id = ?');
    foreach ($stmtSwaps->fetchAll(PDO::FETCH_ASSOC) as $sw) {
        $beforeId = $sw['swap_before_table_id'] !== null ? ($bySource[(int)$sw['swap_before_table_id']] ?? null) : null;
        $afterId  = $sw['swap_after_table_id']  !== null ? ($bySource[(int)$sw['swap_after_table_id']]  ?? null) : null;
        $upd->execute([$beforeId, $afterId, $sw['id']]);
    }
}

// Copies one template table (and, recursively, any nested boss-tables living inside its
// column-groups) into a raid-scoped raid_tables row. Exactly one of $newSectionId /
// $newParentGroupId is non-null, matching the section_id/parent_group_id invariant.
function copy_table_recursive($pdo, $tb, $newSectionId, $newParentGroupId) {
    $insT = $pdo->prepare(
        'INSERT INTO raid_tables (section_id, parent_group_id, title, sort_order, header_color, bg_color, default_column_width, source_table_id, kind)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insT->execute([$newSectionId, $newParentGroupId, $tb['title'], $tb['sort_order'], $tb['header_color'], $tb['bg_color'], $tb['default_column_width'], $tb['id'], $tb['kind'] ?? 'standard']);
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
        'INSERT INTO raid_columns (table_id, label, sort_order, kind, width, header_color, bg_color, group_id, header_colspan, source_column_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $newGroupId = $col['group_id'] ? ($groupIdMap[$col['group_id']] ?? null) : null;
        $insC->execute([$newTableId, $col['label'], $col['sort_order'], $col['kind'], $col['width'], $col['header_color'], $col['bg_color'], $newGroupId, $col['header_colspan'], $col['id']]);
        $newColId = (int)$pdo->lastInsertId();
        $columns[] = ['id' => $newColId, 'kind' => $col['kind'], 'srcId' => $col['id']];
        $columnIdMap[$col['id']] = $newColId;
    }

    $stmtR = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rows = [];
    $rowIdMap = [];
    $insR = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind, height, bg_color, source_row_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $insR->execute([$newTableId, $row['label'], $row['sort_order'], $row['kind'], $row['height'], $row['bg_color'], $row['id']]);
        $newRowId = (int)$pdo->lastInsertId();
        $rows[] = ['id' => $newRowId, 'kind' => $row['kind'], 'srcId' => $row['id']];
        $rowIdMap[$row['id']] = $newRowId;
    }

    // Template-authored cell text/colors, keyed by source row/column id so they can be
    // carried onto the freshly-created raid_cells rows below.
    $stmtCells = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override FROM raid_template_cells WHERE table_id = ?');
    $stmtCells->execute([$tb['id']]);
    $tplCells = [];
    foreach ($stmtCells->fetchAll(PDO::FETCH_ASSOC) as $tc) {
        $tplCells[$tc['row_id'] . '_' . $tc['column_id']] = $tc;
    }

    // Spacer rows/columns never hold data, so no raid_cells row is created for either side.
    $insCell = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        if ($r['kind'] === 'spacer') continue;
        foreach ($columns as $c) {
            if ($c['kind'] === 'spacer') continue;
            $tc = $tplCells[$r['srcId'] . '_' . $c['srcId']] ?? null;
            $insCell->execute([$newTableId, $r['id'], $c['id'], $tc['text_content'] ?? null, $tc['bg_color'] ?? null, $tc['text_color'] ?? null, $tc['bold'] ?? 0, $tc['font'] ?? null, $tc['icon'] ?? null, $tc['kind_override'] ?? null]);
        }
    }

    // Cell merges (horizontal colspan within one row) reference specific row/column ids,
    // so remap them the same way group nesting does above.
    $stmtM = $pdo->prepare('SELECT * FROM raid_template_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $insM = $pdo->prepare('INSERT INTO raid_cell_merges (table_id, row_id, column_id, colspan, rowspan) VALUES (?, ?, ?, ?, ?)');
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (!isset($rowIdMap[$m['row_id']]) || !isset($columnIdMap[$m['column_id']])) continue;
        $insM->execute([$newTableId, $rowIdMap[$m['row_id']], $columnIdMap[$m['column_id']], $m['colspan'], $m['rowspan']]);
    }

    // Assignment rules (class-restrict / max-count), remapped the same way cell merges are above.
    $stmtRule = $pdo->prepare('SELECT * FROM raid_template_rules WHERE table_id = ? ORDER BY sort_order, id');
    $stmtRule->execute([$tb['id']]);
    $insRule = $pdo->prepare('INSERT INTO raid_rules (table_id, rule_type, scope, classes, max_count, label, sort_order, source_rule_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insRuleCell = $pdo->prepare('INSERT INTO raid_rule_cells (rule_id, row_id, column_id) VALUES (?, ?, ?)');
    foreach ($stmtRule->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $insRule->execute([$newTableId, $rule['rule_type'], $rule['scope'], $rule['classes'], $rule['max_count'], $rule['label'], $rule['sort_order'], $rule['id']]);
        $newRuleId = (int)$pdo->lastInsertId();

        $stmtRC = $pdo->prepare('SELECT row_id, column_id FROM raid_template_rule_cells WHERE rule_id = ?');
        $stmtRC->execute([$rule['id']]);
        foreach ($stmtRC->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            if (!isset($rowIdMap[$rc['row_id']]) || !isset($columnIdMap[$rc['column_id']])) continue;
            $insRuleCell->execute([$newRuleId, $rowIdMap[$rc['row_id']], $columnIdMap[$rc['column_id']]]);
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

// Force-checked after every raid creation (template-based or blank) so a raid can never end up
// without somewhere to build a starting roster. Idempotent and additive only: it looks for a
// STANDARD table inside a roster-kind section (a roster-kind section can hold more than one
// standard table -- the reference Naxx template's "Swaps and Benched" section is also
// kind='roster' -- so the section's mere existence isn't enough to skip) and for a benched-kind
// table anywhere in the raid, and builds whichever is missing. Shape mirrors the hand-built
// reference Naxx tables (raid_template_tables id 1 & 55) exactly -- same '#1c234b' header tint,
// same one-player-once rule -- so a force-created pair reads identically to a template-authored one.
function ensure_starting_roster($pdo, $raidId, $size) {
    $numCols = $size === '20' ? 4 : 8;

    $stmt = $pdo->prepare(
        "SELECT rt.id FROM raid_tables rt JOIN raid_sections rs ON rs.id = rt.section_id
         WHERE rs.raid_id = ? AND rs.kind = 'roster' AND rt.kind = 'standard' LIMIT 1"
    );
    $stmt->execute([$raidId]);
    $hasRosterTable = (bool)$stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT rt.id FROM raid_tables rt JOIN raid_sections rs ON rs.id = rt.section_id
         WHERE rs.raid_id = ? AND rt.kind = 'benched' LIMIT 1"
    );
    $stmt->execute([$raidId]);
    $hasBenchedTable = (bool)$stmt->fetch();

    if ($hasRosterTable && $hasBenchedTable) return;

    $stmt = $pdo->prepare("SELECT id FROM raid_sections WHERE raid_id = ? AND kind = 'roster' ORDER BY sort_order, id LIMIT 1");
    $stmt->execute([$raidId]);
    $sectionId = $stmt->fetchColumn();

    if (!$sectionId) {
        $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_sections WHERE raid_id = ' . (int)$raidId)->fetchColumn();
        $pdo->prepare('INSERT INTO raid_sections (raid_id, kind, title, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$raidId, 'roster', 'Starting Roster', $order]);
        $sectionId = (int)$pdo->lastInsertId();
    } else {
        $sectionId = (int)$sectionId;
    }

    if (!$hasRosterTable) build_roster_table($pdo, $sectionId, $numCols);
    if (!$hasBenchedTable) build_benched_table($pdo, $sectionId);
}

// Builds a live 'standard' roster table sized to $numCols groups (4 for 20-man, 8 for 40-man):
// a 'text' header row of "Grp 1".."Grp N" cells tinted '#1c234b', 5 empty 'general' data rows,
// and the table-scoped max_count(1) rule every roster table in this app carries.
function build_roster_table($pdo, $sectionId, $numCols) {
    $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_tables WHERE section_id = ' . (int)$sectionId)->fetchColumn();
    $pdo->prepare('INSERT INTO raid_tables (section_id, title, sort_order, default_column_width, kind) VALUES (?, ?, ?, 120, ?)')
        ->execute([$sectionId, '', $order, 'standard']);
    $tableId = (int)$pdo->lastInsertId();

    $colStmt = $pdo->prepare('INSERT INTO raid_columns (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    $colIds = [];
    for ($i = 0; $i < $numCols; $i++) {
        $colStmt->execute([$tableId, 'Group ' . ($i + 1), $i, 'general']);
        $colIds[] = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, '', 'text']);
    $headerRowId = (int)$pdo->lastInsertId();

    $headerCellStmt = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id, text_content, bold, bg_color) VALUES (?, ?, ?, ?, 0, ?)');
    foreach ($colIds as $i => $colId) {
        $headerCellStmt->execute([$tableId, $headerRowId, $colId, 'Grp ' . ($i + 1), '#1c234b']);
    }

    $rowStmt = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    $emptyCellStmt = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)');
    for ($r = 1; $r <= 5; $r++) {
        $rowStmt->execute([$tableId, '', $r, 'general']);
        $rowId = (int)$pdo->lastInsertId();
        foreach ($colIds as $colId) {
            $emptyCellStmt->execute([$tableId, $rowId, $colId]);
        }
    }

    $pdo->prepare('INSERT INTO raid_rules (table_id, rule_type, scope, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$tableId, 'max_count', 'table', 1, 'A player can only be assigned once']);
}

// Template-side counterpart of ensure_starting_roster(): run once when a brand-new template is
// created (templates-save.php's 'save' action, insert branch) so an admin lands on a template
// that already has a 'roster' tab with a roster + benched table to edit, instead of an empty
// "no tabs yet" editor with no discoverable way to add the first table.
function seed_starting_template_roster($pdo, $templateId, $size) {
    $numCols = $size === '20' ? 4 : 8;

    $pdo->prepare('INSERT INTO raid_template_sections (template_id, kind, title, sort_order) VALUES (?, ?, ?, 0)')
        ->execute([$templateId, 'roster', 'Roster']);
    $sectionId = (int)$pdo->lastInsertId();

    build_template_roster_table($pdo, $sectionId, $numCols);
    build_template_benched_table($pdo, $sectionId);
}

// Template-side counterpart of build_roster_table() above, writing into raid_template_* tables.
function build_template_roster_table($pdo, $sectionId, $numCols) {
    $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order, default_column_width, kind) VALUES (?, ?, 0, 120, ?)')
        ->execute([$sectionId, '', 'standard']);
    $tableId = (int)$pdo->lastInsertId();

    $colStmt = $pdo->prepare('INSERT INTO raid_template_columns (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    $colIds = [];
    for ($i = 0; $i < $numCols; $i++) {
        $colStmt->execute([$tableId, 'Group ' . ($i + 1), $i, 'general']);
        $colIds[] = (int)$pdo->lastInsertId();
    }

    $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, '', 'text']);
    $headerRowId = (int)$pdo->lastInsertId();
    $headerCellStmt = $pdo->prepare('INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bold, bg_color) VALUES (?, ?, ?, ?, 0, ?)');
    foreach ($colIds as $i => $colId) {
        $headerCellStmt->execute([$tableId, $headerRowId, $colId, 'Grp ' . ($i + 1), '#1c234b']);
    }

    $rowStmt = $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, ?, ?)');
    for ($r = 1; $r <= 5; $r++) {
        $rowStmt->execute([$tableId, '', $r, 'general']);
    }

    $pdo->prepare('INSERT INTO raid_template_rules (table_id, rule_type, scope, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$tableId, 'max_count', 'table', 1, 'A player can only be assigned once']);
}

// Template-side counterpart of build_benched_table() above / template-structure-save.php's
// seed_benched_table(), writing into raid_template_* tables for a section instead of an
// already-inserted table row.
function build_template_benched_table($pdo, $sectionId) {
    $pdo->prepare('INSERT INTO raid_template_tables (section_id, title, sort_order, kind) VALUES (?, ?, ?, ?)')
        ->execute([$sectionId, '', 1, 'benched']);
    $tableId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO raid_template_columns (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, 'Bench', 'general']);
    $colId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, '', 'text']);
    $headerRowId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bold, bg_color) VALUES (?, ?, ?, ?, 0, ?)')
        ->execute([$tableId, $headerRowId, $colId, 'BENCHED', '#1c234b']);

    $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, sort_order, kind) VALUES (?, ?, 1, ?)')
        ->execute([$tableId, '', 'general']);

    $pdo->prepare('INSERT INTO raid_template_rules (table_id, rule_type, scope, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$tableId, 'max_count', 'table', 1, 'A player can only be assigned once']);
}

// Live-raid counterpart of template-structure-save.php's seed_benched_table(): one 'general'
// Bench column, a 'BENCHED' 'text' header row tinted to match the roster table, one starting
// 'general' data row (cells-save.php's grow-on-full logic adds more as it fills), and the same
// table-scoped max_count(1) rule.
function build_benched_table($pdo, $sectionId) {
    $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM raid_tables WHERE section_id = ' . (int)$sectionId)->fetchColumn();
    $pdo->prepare('INSERT INTO raid_tables (section_id, title, sort_order, kind) VALUES (?, ?, ?, ?)')
        ->execute([$sectionId, '', $order, 'benched']);
    $tableId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO raid_columns (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, 'Bench', 'general']);
    $colId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, ?, 0, ?)')
        ->execute([$tableId, '', 'text']);
    $headerRowId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id, text_content, bold, bg_color) VALUES (?, ?, ?, ?, 0, ?)')
        ->execute([$tableId, $headerRowId, $colId, 'BENCHED', '#1c234b']);

    $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind) VALUES (?, ?, 1, ?)')
        ->execute([$tableId, '', 'general']);
    $dataRowId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)')
        ->execute([$tableId, $dataRowId, $colId]);

    $pdo->prepare('INSERT INTO raid_rules (table_id, rule_type, scope, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$tableId, 'max_count', 'table', 1, 'A player can only be assigned once']);
}
