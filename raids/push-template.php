<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/raid_structure.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$slug   = $_GET['slug'] ?? '';
$tenant = guild_by_slug($slug);
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['error' => 'No such guild']);
    exit;
}

auth_session_start();
if (!auth_is_authed()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user = auth_user();
$role = resolve_guild_role($tenant, $user['id']);
// Structural resync is gated at admin, same as template editing itself -- not the lower
// raid_management tier that governs ordinary cell assignment / reorder on this raid.
if (!role_at_least($role, 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
$pdo    = db_connect();

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function fetch_raid_owned($pdo, $guildId, $raidId) {
    $stmt = $pdo->prepare('SELECT * FROM raids WHERE id = ? AND guild_id = ?');
    $stmt->execute([$raidId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetch_template_owned($pdo, $guildId, $templateId) {
    $stmt = $pdo->prepare('SELECT * FROM raid_templates WHERE id = ? AND guild_id = ?');
    $stmt->execute([$templateId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// All raid_tables ids belonging to a raid, top-level and nested (inside column groups),
// via a recursive CTE -- needed to scope the null-source-id check below across the whole tree.
function all_raid_table_ids($pdo, $raidId) {
    $sql = "WITH RECURSIVE tree AS (
        SELECT t.id FROM raid_tables t JOIN raid_sections s ON s.id = t.section_id WHERE s.raid_id = ?
        UNION ALL
        SELECT t.id FROM raid_tables t JOIN raid_column_groups g ON g.id = t.parent_group_id JOIN tree ON tree.id = g.table_id
    ) SELECT id FROM tree";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$raidId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

// Sync only works for raids created after source_*_id tracking was added (see
// includes/raid_structure.php). A raid with any untracked node -- or no linked template at
// all -- can't be matched against the template tree, so bail with a clear reason.
function raid_sync_blocked_reason($pdo, $templateId, $raidId) {
    if ($templateId === null) return 'This raid has no linked template (it was created before template sync was supported).';

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM raid_sections WHERE raid_id = ? AND source_section_id IS NULL');
    $stmt->execute([$raidId]);
    if ((int)$stmt->fetchColumn() > 0) return 'This raid predates template-sync tracking and cannot be auto-synced.';

    $tableIds = all_raid_table_ids($pdo, $raidId);
    if ($tableIds) {
        $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
        foreach ([
            ['raid_tables', 'id', 'source_table_id'],
            ['raid_column_groups', 'table_id', 'source_group_id'],
            ['raid_columns', 'table_id', 'source_column_id'],
            ['raid_rows', 'table_id', 'source_row_id'],
        ] as [$table, $col, $srcCol]) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col IN ($placeholders) AND $srcCol IS NULL");
            $stmt->execute($tableIds);
            if ((int)$stmt->fetchColumn() > 0) return 'This raid predates template-sync tracking and cannot be auto-synced.';
        }
    }
    return null;
}

// Matches raid-side rows (each carrying a 'sourceId' pointing at the template row it was
// copied from) against the template's current rows (keyed by their own id). Anything on the
// raid side whose source id no longer exists in the template is "raidOnly" (a removal
// candidate); anything on the template side never matched is "templateOnly" (an addition).
function match_by_source(array $raidItems, array $templateItems) {
    $templateById = [];
    foreach ($templateItems as $t) $templateById[(int)$t['id']] = $t;

    $matched = [];
    $raidOnly = [];
    $matchedTemplateIds = [];
    foreach ($raidItems as $r) {
        $src = $r['sourceId'];
        if ($src !== null && isset($templateById[(int)$src])) {
            $matched[(int)$r['id']] = $templateById[(int)$src];
            $matchedTemplateIds[(int)$src] = true;
        } else {
            $raidOnly[] = $r;
        }
    }

    $templateOnly = [];
    foreach ($templateItems as $t) {
        if (!isset($matchedTemplateIds[(int)$t['id']])) $templateOnly[] = $t;
    }

    return [$matched, $raidOnly, $templateOnly];
}

function diff_scalar_fields(array $raidRow, array $tplRow, array $fields) {
    $changes = [];
    foreach ($fields as $f) {
        if ((string)$raidRow[$f] !== (string)$tplRow[$f]) $changes[$f] = $tplRow[$f];
    }
    return $changes;
}

// Deletes a raid table and everything under it: nested tables (recursively, via its column
// groups), columns, rows, cells, and cell merges. Used both for outright removed tables and
// for the contents of a removed group -- no assumption made about ON DELETE CASCADE.
function delete_table_recursive($pdo, $tableId) {
    $stmt = $pdo->prepare('SELECT id FROM raid_column_groups WHERE table_id = ?');
    $stmt->execute([$tableId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $grp) {
        $stmt2 = $pdo->prepare('SELECT id FROM raid_tables WHERE parent_group_id = ?');
        $stmt2->execute([$grp['id']]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $childTb) {
            delete_table_recursive($pdo, (int)$childTb['id']);
        }
    }
    $pdo->prepare('DELETE FROM raid_cells WHERE table_id = ?')->execute([$tableId]);
    $pdo->prepare('DELETE FROM raid_cell_merges WHERE table_id = ?')->execute([$tableId]);
    $pdo->prepare('DELETE FROM raid_rows WHERE table_id = ?')->execute([$tableId]);
    $pdo->prepare('DELETE FROM raid_columns WHERE table_id = ?')->execute([$tableId]);
    $pdo->prepare('DELETE FROM raid_column_groups WHERE table_id = ?')->execute([$tableId]);
    $pdo->prepare('DELETE FROM raid_tables WHERE id = ?')->execute([$tableId]);
}

function delete_section_recursive($pdo, $sectionId) {
    $stmt = $pdo->prepare('SELECT id FROM raid_tables WHERE section_id = ?');
    $stmt->execute([$sectionId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tb) delete_table_recursive($pdo, (int)$tb['id']);
    $pdo->prepare('DELETE FROM raid_sections WHERE id = ?')->execute([$sectionId]);
}

// Recursively diffs (and, when $apply, mutates) one matched raid<->template table pair:
// its own label/color/width/sort_order fields, its column groups, columns, rows, any nested
// tables living inside those groups, and finally raid_cells/raid_cell_merges. Matched nodes
// only ever get the "safe" scalar fields overwritten (never reparented); additions are
// inserted fresh via copy_table_recursive() (the same helper raid creation uses); removals
// are only deleted when $confirmRemovals is true, so a first pass can preview without risk.
function sync_table($pdo, $raidTableId, $tplTableId, &$diff, $apply, $confirmRemovals) {
    $stmt = $pdo->prepare('SELECT * FROM raid_tables WHERE id = ?');
    $stmt->execute([$raidTableId]);
    $raidTb = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT * FROM raid_template_tables WHERE id = ?');
    $stmt->execute([$tplTableId]);
    $tplTb = $stmt->fetch(PDO::FETCH_ASSOC);

    $changes = diff_scalar_fields($raidTb, $tplTb, ['title', 'header_color', 'bg_color', 'default_column_width', 'sort_order']);
    if ($changes) {
        $diff['tables']['changed'][] = ['id' => $raidTableId, 'label' => $raidTb['title'] ?: '(untitled table)', 'changes' => array_keys($changes)];
        if ($apply) {
            $pdo->prepare('UPDATE raid_tables SET title = ?, header_color = ?, bg_color = ?, default_column_width = ?, sort_order = ? WHERE id = ?')
                ->execute([$tplTb['title'], $tplTb['header_color'], $tplTb['bg_color'], $tplTb['default_column_width'], $tplTb['sort_order'], $raidTableId]);
        }
    }

    // --- Column groups ---
    $stmt = $pdo->prepare('SELECT * FROM raid_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidTableId]);
    $raidGroups = array_map(fn($g) => $g + ['sourceId' => $g['source_group_id'] !== null ? (int)$g['source_group_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_column_groups WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tplTableId]);
    $tplGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    [$matchedGroups, $groupRaidOnly, $groupTplOnly] = match_by_source($raidGroups, $tplGroups);

    $groupIdMap = []; // template group id => raid group id (only entries with a real raid id)
    foreach ($matchedGroups as $raidGroupId => $tplGroup) {
        $groupIdMap[(int)$tplGroup['id']] = $raidGroupId;
        $raidGroup = null;
        foreach ($raidGroups as $g) { if ((int)$g['id'] === $raidGroupId) { $raidGroup = $g; break; } }
        $gChanges = diff_scalar_fields($raidGroup, $tplGroup, ['title', 'color', 'sort_order']);
        if ($gChanges) {
            $diff['groups']['changed'][] = ['id' => $raidGroupId, 'label' => $raidGroup['title'] ?: '(untitled group)', 'changes' => array_keys($gChanges)];
            if ($apply) {
                $pdo->prepare('UPDATE raid_column_groups SET title = ?, color = ?, sort_order = ? WHERE id = ?')
                    ->execute([$tplGroup['title'], $tplGroup['color'], $tplGroup['sort_order'], $raidGroupId]);
            }
        }
    }
    foreach ($groupRaidOnly as $rg) {
        $diff['groups']['removed'][] = ['id' => (int)$rg['id'], 'label' => $rg['title'] ?: '(untitled group)'];
        if ($apply && $confirmRemovals) {
            $stmt2 = $pdo->prepare('SELECT id FROM raid_tables WHERE parent_group_id = ?');
            $stmt2->execute([$rg['id']]);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $childTb) delete_table_recursive($pdo, (int)$childTb['id']);
            $pdo->prepare('DELETE FROM raid_column_groups WHERE id = ?')->execute([$rg['id']]);
        }
    }
    foreach ($groupTplOnly as $tg) {
        $diff['groups']['added'][] = ['id' => (int)$tg['id'], 'label' => $tg['title'] ?: '(untitled group)'];
        if ($apply) {
            $parentGroupId = $tg['parent_group_id'] !== null ? ($groupIdMap[(int)$tg['parent_group_id']] ?? null) : null;
            $ins = $pdo->prepare('INSERT INTO raid_column_groups (table_id, parent_group_id, title, color, sort_order, source_group_id) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$raidTableId, $parentGroupId, $tg['title'], $tg['color'], $tg['sort_order'], $tg['id']]);
            $groupIdMap[(int)$tg['id']] = (int)$pdo->lastInsertId();
        }
    }

    // --- Columns ---
    $stmt = $pdo->prepare('SELECT * FROM raid_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidTableId]);
    $raidCols = array_map(fn($c) => $c + ['sourceId' => $c['source_column_id'] !== null ? (int)$c['source_column_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tplTableId]);
    $tplCols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    [$matchedCols, $colRaidOnly, $colTplOnly] = match_by_source($raidCols, $tplCols);

    $columnIdMap = []; // template column id => raid column id
    foreach ($matchedCols as $raidColId => $tplCol) {
        $columnIdMap[(int)$tplCol['id']] = $raidColId;
        $raidCol = null;
        foreach ($raidCols as $c) { if ((int)$c['id'] === $raidColId) { $raidCol = $c; break; } }
        $cChanges = diff_scalar_fields($raidCol, $tplCol, ['label', 'width', 'header_color', 'bg_color', 'header_colspan', 'sort_order']);
        if ($cChanges) {
            $diff['columns']['changed'][] = ['id' => $raidColId, 'label' => $raidCol['label'] ?: '(unlabeled column)', 'changes' => array_keys($cChanges)];
            if ($apply) {
                $pdo->prepare('UPDATE raid_columns SET label = ?, width = ?, header_color = ?, bg_color = ?, header_colspan = ?, sort_order = ? WHERE id = ?')
                    ->execute([$tplCol['label'], $tplCol['width'], $tplCol['header_color'], $tplCol['bg_color'], $tplCol['header_colspan'], $tplCol['sort_order'], $raidColId]);
            }
        }
    }
    foreach ($colRaidOnly as $rc) {
        $diff['columns']['removed'][] = ['id' => (int)$rc['id'], 'label' => $rc['label'] ?: '(unlabeled column)'];
        if ($apply && $confirmRemovals) {
            $pdo->prepare('DELETE FROM raid_cells WHERE table_id = ? AND column_id = ?')->execute([$raidTableId, $rc['id']]);
            $pdo->prepare('DELETE FROM raid_cell_merges WHERE table_id = ? AND column_id = ?')->execute([$raidTableId, $rc['id']]);
            $pdo->prepare('DELETE FROM raid_columns WHERE id = ?')->execute([$rc['id']]);
        }
    }
    foreach ($colTplOnly as $tc) {
        $diff['columns']['added'][] = ['id' => (int)$tc['id'], 'label' => $tc['label'] ?: '(unlabeled column)'];
        if ($apply) {
            $groupId = $tc['group_id'] !== null ? ($groupIdMap[(int)$tc['group_id']] ?? null) : null;
            $ins = $pdo->prepare('INSERT INTO raid_columns (table_id, label, sort_order, kind, width, header_color, bg_color, group_id, header_colspan, source_column_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$raidTableId, $tc['label'], $tc['sort_order'], $tc['kind'], $tc['width'], $tc['header_color'], $tc['bg_color'], $groupId, $tc['header_colspan'], $tc['id']]);
            $columnIdMap[(int)$tc['id']] = (int)$pdo->lastInsertId();
        }
    }

    // --- Rows ---
    $stmt = $pdo->prepare('SELECT * FROM raid_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidTableId]);
    $raidRows = array_map(fn($r) => $r + ['sourceId' => $r['source_row_id'] !== null ? (int)$r['source_row_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tplTableId]);
    $tplRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    [$matchedRows, $rowRaidOnly, $rowTplOnly] = match_by_source($raidRows, $tplRows);

    $rowIdMap = []; // template row id => raid row id
    foreach ($matchedRows as $raidRowId => $tplRow) {
        $rowIdMap[(int)$tplRow['id']] = $raidRowId;
        $raidRow = null;
        foreach ($raidRows as $r) { if ((int)$r['id'] === $raidRowId) { $raidRow = $r; break; } }
        $rChanges = diff_scalar_fields($raidRow, $tplRow, ['label', 'height', 'bg_color', 'sort_order']);
        if ($rChanges) {
            $diff['rows']['changed'][] = ['id' => $raidRowId, 'label' => $raidRow['label'] ?: '(unlabeled row)', 'changes' => array_keys($rChanges)];
            if ($apply) {
                $pdo->prepare('UPDATE raid_rows SET label = ?, height = ?, bg_color = ?, sort_order = ? WHERE id = ?')
                    ->execute([$tplRow['label'], $tplRow['height'], $tplRow['bg_color'], $tplRow['sort_order'], $raidRowId]);
            }
        }
    }
    foreach ($rowRaidOnly as $rr) {
        $diff['rows']['removed'][] = ['id' => (int)$rr['id'], 'label' => $rr['label'] ?: '(unlabeled row)'];
        if ($apply && $confirmRemovals) {
            $pdo->prepare('DELETE FROM raid_cells WHERE table_id = ? AND row_id = ?')->execute([$raidTableId, $rr['id']]);
            $pdo->prepare('DELETE FROM raid_cell_merges WHERE table_id = ? AND row_id = ?')->execute([$raidTableId, $rr['id']]);
            $pdo->prepare('DELETE FROM raid_rows WHERE id = ?')->execute([$rr['id']]);
        }
    }
    foreach ($rowTplOnly as $tr) {
        $diff['rows']['added'][] = ['id' => (int)$tr['id'], 'label' => $tr['label'] ?: '(unlabeled row)'];
        if ($apply) {
            $ins = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order, kind, height, bg_color, source_row_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$raidTableId, $tr['label'], $tr['sort_order'], $tr['kind'], $tr['height'], $tr['bg_color'], $tr['id']]);
            $rowIdMap[(int)$tr['id']] = (int)$pdo->lastInsertId();
        }
    }

    // --- Nested tables living inside each surviving group (matched or newly added) ---
    foreach ($groupIdMap as $tplGroupId => $raidGroupId) {
        $stmt = $pdo->prepare('SELECT * FROM raid_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmt->execute([$raidGroupId]);
        $raidChildTables = array_map(fn($t) => $t + ['sourceId' => $t['source_table_id'] !== null ? (int)$t['source_table_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->prepare('SELECT * FROM raid_template_tables WHERE parent_group_id = ? ORDER BY sort_order, id');
        $stmt->execute([$tplGroupId]);
        $tplChildTables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$m, $ro, $to] = match_by_source($raidChildTables, $tplChildTables);
        foreach ($m as $raidChildId => $tplChild) sync_table($pdo, $raidChildId, (int)$tplChild['id'], $diff, $apply, $confirmRemovals);
        foreach ($ro as $rt) {
            $diff['tables']['removed'][] = ['id' => (int)$rt['id'], 'label' => $rt['title'] ?: '(untitled table)'];
            if ($apply && $confirmRemovals) delete_table_recursive($pdo, (int)$rt['id']);
        }
        foreach ($to as $tt) {
            $diff['tables']['added'][] = ['id' => (int)$tt['id'], 'label' => $tt['title'] ?: '(untitled table)'];
            if ($apply) copy_table_recursive($pdo, $tt, null, $raidGroupId);
        }
    }

    if ($apply) {
        // Fill raid_cells for any row/column pair that doesn't have one yet -- covers new
        // rows crossed with old columns and vice versa, not just brand-new tables. Text
        // rows/columns also need a raid_cells row (to hold synced text content), so only
        // spacers are excluded.
        $stmt = $pdo->prepare("SELECT id FROM raid_columns WHERE table_id = ? AND kind != 'spacer'");
        $stmt->execute([$raidTableId]);
        $dataColIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $stmt = $pdo->prepare("SELECT id FROM raid_rows WHERE table_id = ? AND kind != 'spacer'");
        $stmt->execute([$raidTableId]);
        $dataRowIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $stmt = $pdo->prepare('SELECT row_id, column_id FROM raid_cells WHERE table_id = ?');
        $stmt->execute([$raidTableId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $existing[$c['row_id'] . '_' . $c['column_id']] = true;

        $insCell = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)');
        foreach ($dataRowIds as $r) {
            foreach ($dataColIds as $c) {
                if (isset($existing[$r . '_' . $c])) continue;
                $insCell->execute([$raidTableId, $r, $c]);
            }
        }

        // Sync template-authored cell text/colors down onto their matched raid cells, using
        // the row/column id maps built above (covers matched and newly-added rows/columns).
        $stmt = $pdo->prepare('SELECT row_id, column_id, text_content, bg_color, text_color, bold, font, icon, kind_override FROM raid_template_cells WHERE table_id = ?');
        $stmt->execute([$tplTableId]);
        $updCell = $pdo->prepare('UPDATE raid_cells SET text_content = ?, bg_color = ?, text_color = ?, bold = ?, font = ?, icon = ?, kind_override = ? WHERE table_id = ? AND row_id = ? AND column_id = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tc) {
            $rId = $rowIdMap[(int)$tc['row_id']] ?? null;
            $cId = $columnIdMap[(int)$tc['column_id']] ?? null;
            if ($rId === null || $cId === null) continue;
            $updCell->execute([$tc['text_content'], $tc['bg_color'], $tc['text_color'], $tc['bold'], $tc['font'], $tc['icon'], $tc['kind_override'], $raidTableId, $rId, $cId]);
        }

        // Cell merges carry no assignment data, so just wholesale-resync them from the
        // template using the row/column id maps built above (matched + newly added ids).
        $pdo->prepare('DELETE FROM raid_cell_merges WHERE table_id = ?')->execute([$raidTableId]);
        $stmt = $pdo->prepare('SELECT row_id, column_id, colspan FROM raid_template_cell_merges WHERE table_id = ?');
        $stmt->execute([$tplTableId]);
        $insMerge = $pdo->prepare('INSERT INTO raid_cell_merges (table_id, row_id, column_id, colspan) VALUES (?, ?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $rId = $rowIdMap[(int)$m['row_id']] ?? null;
            $cId = $columnIdMap[(int)$m['column_id']] ?? null;
            if ($rId === null || $cId === null) continue;
            $insMerge->execute([$raidTableId, $rId, $cId, $m['colspan']]);
        }
    }
}

function sync_section_tables($pdo, $raidSectionId, $tplSectionId, &$diff, $apply, $confirmRemovals) {
    $stmt = $pdo->prepare('SELECT * FROM raid_tables WHERE section_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidSectionId]);
    $raidTables = array_map(fn($t) => $t + ['sourceId' => $t['source_table_id'] !== null ? (int)$t['source_table_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
    $stmt->execute([$tplSectionId]);
    $tplTables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    [$matched, $raidOnly, $tplOnly] = match_by_source($raidTables, $tplTables);

    foreach ($matched as $raidTableId => $tplTable) sync_table($pdo, $raidTableId, (int)$tplTable['id'], $diff, $apply, $confirmRemovals);
    foreach ($raidOnly as $rt) {
        $diff['tables']['removed'][] = ['id' => (int)$rt['id'], 'label' => $rt['title'] ?: '(untitled table)'];
        if ($apply && $confirmRemovals) delete_table_recursive($pdo, (int)$rt['id']);
    }
    foreach ($tplOnly as $tt) {
        $diff['tables']['added'][] = ['id' => (int)$tt['id'], 'label' => $tt['title'] ?: '(untitled table)'];
        if ($apply) copy_table_recursive($pdo, $tt, $raidSectionId, null);
    }
}

function sync_raid_from_template($pdo, $raid, $template, $apply, $confirmRemovals) {
    $raidId = (int)$raid['id'];
    $templateId = (int)$template['id'];
    $diff = [
        'sections' => ['added' => [], 'removed' => [], 'changed' => []],
        'tables'   => ['added' => [], 'removed' => [], 'changed' => []],
        'groups'   => ['added' => [], 'removed' => [], 'changed' => []],
        'columns'  => ['added' => [], 'removed' => [], 'changed' => []],
        'rows'     => ['added' => [], 'removed' => [], 'changed' => []],
    ];

    $stmt = $pdo->prepare('SELECT * FROM raid_sections WHERE raid_id = ? ORDER BY sort_order, id');
    $stmt->execute([$raidId]);
    $raidSections = array_map(fn($s) => $s + ['sourceId' => $s['source_section_id'] !== null ? (int)$s['source_section_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare('SELECT * FROM raid_template_sections WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$templateId]);
    $tplSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    [$matched, $raidOnly, $tplOnly] = match_by_source($raidSections, $tplSections);

    foreach ($matched as $raidSectionId => $tplSection) {
        $raidSection = null;
        foreach ($raidSections as $rs) { if ((int)$rs['id'] === $raidSectionId) { $raidSection = $rs; break; } }
        $changes = diff_scalar_fields($raidSection, $tplSection, ['title', 'sort_order', 'note_enabled', 'note_text', 'color', 'bg_color']);
        if ($changes) {
            $diff['sections']['changed'][] = ['id' => $raidSectionId, 'label' => $raidSection['title'] ?: '(untitled section)', 'changes' => array_keys($changes)];
            if ($apply) {
                $pdo->prepare('UPDATE raid_sections SET title = ?, sort_order = ?, note_enabled = ?, note_text = ?, color = ?, bg_color = ? WHERE id = ?')
                    ->execute([$tplSection['title'], $tplSection['sort_order'], $tplSection['note_enabled'], $tplSection['note_text'], $tplSection['color'], $tplSection['bg_color'], $raidSectionId]);
            }
        }
        sync_section_tables($pdo, $raidSectionId, (int)$tplSection['id'], $diff, $apply, $confirmRemovals);
    }

    foreach ($raidOnly as $rs) {
        $diff['sections']['removed'][] = ['id' => (int)$rs['id'], 'label' => $rs['title'] ?: '(untitled section)'];
        if ($apply && $confirmRemovals) delete_section_recursive($pdo, (int)$rs['id']);
    }

    foreach ($tplOnly as $ts) {
        $diff['sections']['added'][] = ['id' => (int)$ts['id'], 'label' => $ts['title'] ?: '(untitled section)'];
        if ($apply) {
            $ins = $pdo->prepare('INSERT INTO raid_sections (raid_id, kind, title, sort_order, note_enabled, note_text, color, bg_color, source_section_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$raidId, $ts['kind'], $ts['title'], $ts['sort_order'], $ts['note_enabled'], $ts['note_text'], $ts['color'], $ts['bg_color'], $ts['id']]);
            $newSectionId = (int)$pdo->lastInsertId();
            $stmtT = $pdo->prepare('SELECT * FROM raid_template_tables WHERE section_id = ? ORDER BY sort_order, id');
            $stmtT->execute([$ts['id']]);
            foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $tplTb) {
                copy_table_recursive($pdo, $tplTb, $newSectionId, null);
            }
        }
    }

    return $diff;
}

if ($action === 'diff' || $action === 'apply') {
    $raidId = (int)($body['raidId'] ?? 0);
    $raid = fetch_raid_owned($pdo, $tenant['id'], $raidId);
    if (!$raid) fail(404, 'Raid not found');

    $templateId = $raid['template_id'] !== null ? (int)$raid['template_id'] : null;
    $blocked = raid_sync_blocked_reason($pdo, $templateId, $raidId);
    if ($blocked) fail(400, $blocked);

    $template = fetch_template_owned($pdo, $tenant['id'], $templateId);
    if (!$template) fail(404, 'Linked template no longer exists');

    $apply = $action === 'apply';
    $confirmRemovals = $apply && !empty($body['confirmRemovals']);

    if ($apply) $pdo->beginTransaction();
    try {
        $diff = sync_raid_from_template($pdo, $raid, $template, $apply, $confirmRemovals);
        if ($apply) $pdo->commit();
    } catch (Exception $e) {
        if ($apply) $pdo->rollBack();
        fail(500, 'Sync failed: ' . $e->getMessage());
    }

    $hasRemovals = !empty($diff['sections']['removed']) || !empty($diff['tables']['removed'])
        || !empty($diff['groups']['removed']) || !empty($diff['columns']['removed']) || !empty($diff['rows']['removed']);

    echo json_encode([
        'success' => true,
        'applied' => $apply,
        'needsRemovalConfirm' => $hasRemovals && !$confirmRemovals,
        'diff' => $diff,
    ]);
    exit;
}

fail(400, 'Unknown action');
