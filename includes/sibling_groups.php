<?php
// Resolves a raid_cells occupant to the stable "real player" identity used by the Swaps
// feature to tell "same person, different toon" apart from "genuinely a different person" --
// a main's own id, or an alt's owning main id. A PUG has no such identity (no row in
// toons/toon_alts to anchor it), so it's never returned here.

function fetch_sibling_groups($pdo, $guildId) {
    $stmt = $pdo->prepare('SELECT id, main_name FROM toons WHERE guild_id = ?');
    $stmt->execute([$guildId]);
    $mainNames = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $mainNames[(int)$t['id']] = $t['main_name'];
    }

    $stmt = $pdo->prepare('SELECT id, main_id FROM toon_alts WHERE guild_id = ?');
    $stmt->execute([$guildId]);
    $altIndex = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $altIndex[(int)$a['id']] = (int)$a['main_id'];
    }

    return ['mainNames' => $mainNames, 'altIndex' => $altIndex];
}

function resolve_to_main_id($toonKind, $toonId, $groups) {
    if ($toonKind === 'main') return $toonId !== null ? (int)$toonId : null;
    if ($toonKind === 'alt' && $toonId !== null) return $groups['altIndex'][(int)$toonId] ?? null;
    return null;
}

function main_player_name($mainId, $groups) {
    return $groups['mainNames'][$mainId] ?? null;
}
