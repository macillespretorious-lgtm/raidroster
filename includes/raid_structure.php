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
            $insT = $pdo->prepare('INSERT INTO raid_tables (section_id, title, sort_order) VALUES (?, ?, ?)');
            $insT->execute([$newSectionId, $tb['title'], $tb['sort_order']]);
            $newTableId = (int)$pdo->lastInsertId();

            $stmtC = $pdo->prepare('SELECT * FROM raid_template_columns WHERE table_id = ? ORDER BY sort_order, id');
            $stmtC->execute([$tb['id']]);
            $columnIds = [];
            $insC = $pdo->prepare('INSERT INTO raid_columns (table_id, label, sort_order) VALUES (?, ?, ?)');
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $insC->execute([$newTableId, $col['label'], $col['sort_order']]);
                $columnIds[] = (int)$pdo->lastInsertId();
            }

            $stmtR = $pdo->prepare('SELECT * FROM raid_template_rows WHERE table_id = ? ORDER BY sort_order, id');
            $stmtR->execute([$tb['id']]);
            $rowIds = [];
            $insR = $pdo->prepare('INSERT INTO raid_rows (table_id, label, sort_order) VALUES (?, ?, ?)');
            foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $insR->execute([$newTableId, $row['label'], $row['sort_order']]);
                $rowIds[] = (int)$pdo->lastInsertId();
            }

            $insCell = $pdo->prepare('INSERT INTO raid_cells (table_id, row_id, column_id) VALUES (?, ?, ?)');
            foreach ($rowIds as $rid) {
                foreach ($columnIds as $cid) {
                    $insCell->execute([$newTableId, $rid, $cid]);
                }
            }
        }
    }
}
