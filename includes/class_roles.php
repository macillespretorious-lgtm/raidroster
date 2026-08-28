<?php
// Which raid roles each class can hold, per the guild leader's explicit spec (2026-08-28):
// Druid/Paladin any; Warrior Tank/DPS; Priest Healer/DPS; everyone else DPS-only. Shaman
// isn't a spec set the leader named, but Raid-Helper signups can arrive as raw "Shaman"
// before being resolved to a real toon (see raids/import-signups.php), so it needs an
// entry too -- given classic WoW's actual design (no tank spec), Healer/DPS.
const CLASS_ROLES = [
    'Druid'   => ['Tank', 'Healer', 'DPS'],
    'Paladin' => ['Tank', 'Healer', 'DPS'],
    'Warrior' => ['Tank', 'DPS'],
    'Rogue'   => ['DPS'],
    'Warlock' => ['DPS'],
    'Mage'    => ['DPS'],
    'Priest'  => ['Healer', 'DPS'],
    'Hunter'  => ['DPS'],
    'Shaman'  => ['Healer', 'DPS'],
];

function class_roles($class) {
    return CLASS_ROLES[$class] ?? ['Tank', 'Healer', 'DPS'];
}

function default_role_for_class($class, $spec = null) {
    $roles = class_roles($class);
    if ($spec) {
        $s = strtolower(trim(preg_replace('/\d+/', '', $spec)));
        if (($s === 'protection' || $s === 'guardian') && in_array('Tank', $roles, true)) return 'Tank';
        if (in_array($s, ['holy', 'discipline', 'restoration'], true) && in_array('Healer', $roles, true)) return 'Healer';
    }
    return in_array('DPS', $roles, true) ? 'DPS' : $roles[0];
}

function valid_role_for_class($class, $role) {
    if (!$role) return true; // no role assigned yet is always fine -- a default fills the gap
    return in_array($role, class_roles($class), true);
}

// Advances $currentRole to the next role in this class's allowed list, wrapping around.
// $currentRole may be null/invalid (starts from the front of the list in that case).
function cycle_role_for_class($class, $currentRole) {
    $roles = class_roles($class);
    if (!$roles) return null;
    $idx = array_search($currentRole, $roles, true);
    return $idx === false ? $roles[0] : $roles[($idx + 1) % count($roles)];
}
