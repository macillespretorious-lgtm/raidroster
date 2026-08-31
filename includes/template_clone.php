<?php
// Template-to-template deep copy for the "starter template" flow (design.php "Start from:
// Naxx (default)"): a guild clones a public is_starter=1 template into its own raid_templates,
// then owns and edits it freely. Deliberately NOT sync-tracked against its origin -- no
// source_*_id columns are written anywhere, unlike copy_table_recursive() in raid_structure.php
// (template -> raid instance), which this mirrors structurally but not semantically.

function clone_template_to_guild($pdo, $sourceTemplateId, $targetGuildId, $newName) {
    $stmt = $pdo->prepare('SELECT * FROM raid_templates WHERE id = ?');
    $stmt->execute([$sourceTemplateId]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) return null;

    $ins = $pdo->prepare('INSERT INTO raid_templates (guild_id, name, description, size) VALUES (?, ?, ?, ?)');
    $ins->execute([$targetGuildId, $newName, $src['description'], $src['size']]);
    $newTemplateId = (int)$pdo->lastInsertId();

    // Swaps tables link to sibling *template* tables by id, which only exist once every table
    // in the source has been copied -- collect old->new table ids as we go and resolve the
    // links in one final pass, same rationale as backfill_swap_links() in raid_structure.php.
    $tableIdMap = [];
    $swapLinks  = [];
    $countLinks = [];

    $stmtSec = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmtSec->execute([$sourceTemplateId]);
    foreach ($stmtSec->fetchAll(PDO::FETCH_ASSOC) as $sec) {
        $insSec = $pdo->prepare(
            'INSERT INTO raid_template_sections (template_id, kind, title, sort_order, note_enabled, note_text, mrt_export_enabled, color, bg_color)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insSec->execute([$newTemplateId, $sec['kind'], $sec['title'], $sec['sort_order'], $sec['note_enabled'], $sec['note_text'], $sec['mrt_export_enabled'], $sec['color'], $sec['bg_color']]);
        $newSectionId = (int)$pdo->lastInsertId();

        $stmtT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
        $stmtT->execute([$sec['id']]);
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $tb) {
            clone_template_table_recursive($pdo, $tb, $newSectionId, null, $tableIdMap, $swapLinks, $countLinks);
        }
    }

    foreach ($swapLinks as $newTableId => $link) {
        [$beforeSrcId, $afterSrcId] = $link;
        $beforeId = $beforeSrcId !== null ? ($tableIdMap[$beforeSrcId] ?? null) : null;
        $afterId  = $afterSrcId  !== null ? ($tableIdMap[$afterSrcId]  ?? null) : null;
        $pdo->prepare('UPDATE raid_template_tables SET swap_before_table_id = ?, swap_after_table_id = ? WHERE id = ?')
            ->execute([$beforeId, $afterId, $newTableId]);
    }

    foreach ($countLinks as $newTableId => $srcId) {
        $countSourceId = $srcId !== null ? ($tableIdMap[$srcId] ?? null) : null;
        $pdo->prepare('UPDATE raid_template_tables SET count_source_table_id = ? WHERE id = ?')
            ->execute([$countSourceId, $newTableId]);
    }

    // Tab-export config is template-scoped and never referenced elsewhere by id, so a wholesale
    // copy (ignoring the source's own export-page ids) is simplest and correct.
    $stmtTE = $pdo->prepare('SELECT * FROM raid_template_tab_exports WHERE template_id = ?');
    $stmtTE->execute([$sourceTemplateId]);
    foreach ($stmtTE->fetchAll(PDO::FETCH_ASSOC) as $te) {
        $insTE = $pdo->prepare('INSERT INTO raid_template_tab_exports (template_id, kind, enabled, single_page, export_name) VALUES (?, ?, ?, ?, ?)');
        $insTE->execute([$newTemplateId, $te['kind'], $te['enabled'], $te['single_page'], $te['export_name']]);
        $newTabExportId = (int)$pdo->lastInsertId();

        $stmtP = $pdo->prepare('SELECT * FROM raid_template_export_pages WHERE tab_export_id = ? ORDER BY sort_order, id');
        $stmtP->execute([$te['id']]);
        $insP = $pdo->prepare('INSERT INTO raid_template_export_pages (tab_export_id, name, template, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $insP->execute([$newTabExportId, $p['name'], $p['template'], $p['sort_order']]);
        }
    }

    return $newTemplateId;
}

// Copies one template table (and, recursively, any nested boss-tables living inside its column
// groups) into a new raid_template_tables row under a different template. Exactly one of
// $newSectionId/$newParentGroupId is non-null, matching the section_id/parent_group_id
// invariant enforced elsewhere (see copy_table_recursive() in raid_structure.php).
function clone_template_table_recursive($pdo, $tb, $newSectionId, $newParentGroupId, &$tableIdMap, &$swapLinks, &$countLinks) {
    $insT = $pdo->prepare(
        'INSERT INTO raid_template_tables (section_id, parent_group_id, title, header_color, default_column_width, row_label_width, sort_order, bg_color, kind, count_categories, mark_style, mark_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insT->execute([$newSectionId, $newParentGroupId, $tb['title'], $tb['header_color'], $tb['default_column_width'], $tb['row_label_width'], $tb['sort_order'], $tb['bg_color'], $tb['kind'], $tb['count_categories'], $tb['mark_style'] ?? 'star', $tb['mark_enabled'] ?? 0]);
    $newTableId = (int)$pdo->lastInsertId();
    $tableIdMap[$tb['id']] = $newTableId;
    if ($tb['kind'] === 'swaps') {
        $swapLinks[$newTableId] = [$tb['swap_before_table_id'], $tb['swap_after_table_id']];
    }
    if ($tb['kind'] === 'counter') {
        $countLinks[$newTableId] = $tb['count_source_table_id'];
    }

    $stmtG = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmtG->execute([$tb['id']]);
    $groupRows = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $groupIdMap = [];
    $insG = $pdo->prepare('INSERT INTO raid_template_column_groups (table_id, parent_group_id, title, color, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($groupRows as $grp) {
        $insG->execute([$newTableId, $grp['parent_group_id'] ? ($groupIdMap[$grp['parent_group_id']] ?? null) : null, $grp['title'], $grp['color'], $grp['sort_order']]);
        $groupIdMap[$grp['id']] = (int)$pdo->lastInsertId();
    }

    $stmtC = $pdo->prepare('SELECT * FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmtC->execute([$tb['id']]);
    $columnIdMap = [];
    $insC = $pdo->prepare(
        'INSERT INTO raid_template_columns (table_id, label, kind, width, header_color, group_id, header_colspan, sort_order, bg_color)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $newGroupId = $col['group_id'] ? ($groupIdMap[$col['group_id']] ?? null) : null;
        $insC->execute([$newTableId, $col['label'], $col['kind'], $col['width'], $col['header_color'], $newGroupId, $col['header_colspan'], $col['sort_order'], $col['bg_color']]);
        $columnIdMap[$col['id']] = (int)$pdo->lastInsertId();
    }

    $stmtR = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmtR->execute([$tb['id']]);
    $rowIdMap = [];
    $insR = $pdo->prepare('INSERT INTO raid_template_rows (table_id, label, kind, height, sort_order, bg_color) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $insR->execute([$newTableId, $row['label'], $row['kind'], $row['height'], $row['sort_order'], $row['bg_color']]);
        $rowIdMap[$row['id']] = (int)$pdo->lastInsertId();
    }

    $stmtCells = $pdo->prepare('SELECT * FROM raid_template_cells WHERE table_id = ?');
    $stmtCells->execute([$tb['id']]);
    $insCell = $pdo->prepare(
        'INSERT INTO raid_template_cells (table_id, row_id, column_id, text_content, bg_color, text_color, kind_override, bold, font, icon)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($stmtCells->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!isset($rowIdMap[$c['row_id']]) || !isset($columnIdMap[$c['column_id']])) continue;
        $insCell->execute([$newTableId, $rowIdMap[$c['row_id']], $columnIdMap[$c['column_id']], $c['text_content'], $c['bg_color'], $c['text_color'], $c['kind_override'], $c['bold'], $c['font'], $c['icon']]);
    }

    $stmtM = $pdo->prepare('SELECT * FROM raid_template_cell_merges WHERE table_id = ?');
    $stmtM->execute([$tb['id']]);
    $insM = $pdo->prepare('INSERT INTO raid_template_cell_merges (table_id, row_id, column_id, colspan, rowspan) VALUES (?, ?, ?, ?, ?)');
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (!isset($rowIdMap[$m['row_id']]) || !isset($columnIdMap[$m['column_id']])) continue;
        $insM->execute([$newTableId, $rowIdMap[$m['row_id']], $columnIdMap[$m['column_id']], $m['colspan'], $m['rowspan']]);
    }

    $stmtRule = $pdo->prepare('SELECT * FROM raid_template_rules WHERE table_id = ? ORDER BY sort_order, id');
    $stmtRule->execute([$tb['id']]);
    $insRule = $pdo->prepare('INSERT INTO raid_template_rules (table_id, rule_type, scope, classes, max_count, label, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insRuleCell = $pdo->prepare('INSERT INTO raid_template_rule_cells (rule_id, row_id, column_id) VALUES (?, ?, ?)');
    foreach ($stmtRule->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $insRule->execute([$newTableId, $rule['rule_type'], $rule['scope'], $rule['classes'], $rule['max_count'], $rule['label'], $rule['sort_order']]);
        $newRuleId = (int)$pdo->lastInsertId();

        $stmtRC = $pdo->prepare('SELECT row_id, column_id FROM raid_template_rule_cells WHERE rule_id = ?');
        $stmtRC->execute([$rule['id']]);
        foreach ($stmtRC->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            if (!isset($rowIdMap[$rc['row_id']]) || !isset($columnIdMap[$rc['column_id']])) continue;
            $insRuleCell->execute([$newRuleId, $rowIdMap[$rc['row_id']], $columnIdMap[$rc['column_id']]]);
        }
    }

    foreach ($groupRows as $grp) {
        $stmtGT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmtGT->execute([$grp['id']]);
        foreach ($stmtGT->fetchAll(PDO::FETCH_ASSOC) as $childTb) {
            clone_template_table_recursive($pdo, $childTb, null, $groupIdMap[$grp['id']], $tableIdMap, $swapLinks, $countLinks);
        }
    }
}
