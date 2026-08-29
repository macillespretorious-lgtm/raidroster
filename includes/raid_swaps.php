<?php
require_once __DIR__ . '/sibling_groups.php';

// Builds an identity(mainId) -> occupant-cell map for one already-fetched
// (fetch_table_full-shaped) table, skipping empty cells and PUGs -- a PUG has no
// stable identity to diff against, so it can never participate in a Swaps pairing.
function _swap_occupants($tableFull, $groups) {
    $out = [];
    foreach ($tableFull['cells'] as $cell) {
        if (!$cell['name']) continue;
        $mainId = resolve_to_main_id($cell['toonKind'], $cell['toonId'], $groups);
        if ($mainId === null) continue;
        $out[$mainId] = $cell;
    }
    return $out;
}

// Diffs two already-fetched tables for "same real player, different toon" pairs and
// annotates each with any persisted note/boss for this Swaps table. Recomputed fresh on
// every read -- nothing here is persisted except the note/boss annotations themselves,
// keyed by player identity so they survive the pairing being rebuilt from scratch.
function compute_swap_rows($pdo, $guildId, $swapsTableId, $beforeTableFull, $afterTableFull) {
    $groups = fetch_sibling_groups($pdo, $guildId);
    $before = _swap_occupants($beforeTableFull, $groups);
    $after  = _swap_occupants($afterTableFull, $groups);

    $stmt = $pdo->prepare('SELECT player_main_toon_id, note, boss_label FROM raid_swap_notes WHERE table_id = ?');
    $stmt->execute([$swapsTableId]);
    $notes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $notes[$n['player_main_toon_id']] = $n;
    }

    $rows = [];
    foreach ($before as $mainId => $beforeCell) {
        if (!isset($after[$mainId])) continue;
        $afterCell = $after[$mainId];
        if ($beforeCell['toonKind'] === $afterCell['toonKind'] && (string)$beforeCell['toonId'] === (string)$afterCell['toonId']) continue;

        $note = $notes[(string)$mainId] ?? null;
        $rows[] = [
            'playerMainToonId' => (string)$mainId,
            'playerName' => main_player_name($mainId, $groups) ?? $beforeCell['name'],
            'before' => ['name' => $beforeCell['name'], 'class' => $beforeCell['class'], 'role' => $beforeCell['role']],
            'after'  => ['name' => $afterCell['name'], 'class' => $afterCell['class'], 'role' => $afterCell['role']],
            'note' => $note['note'] ?? null,
            'bossLabel' => $note['boss_label'] ?? null,
        ];
    }
    usort($rows, fn($a, $b) => strcasecmp($a['playerName'], $b['playerName']));
    return $rows;
}
