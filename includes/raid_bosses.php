<?php
// Static Classic Era raid zone + boss reference data. No admin UI for this -- it's fixed
// game content, not per-guild configurable, so it lives here rather than in a DB table.

const RAID_ZONE_LABELS = [
    'zg'         => "Zul'Gurub",
    'aq20'       => "Ruins of Ahn'Qiraj",
    'mc'         => 'Molten Core',
    'bwl'        => 'Blackwing Lair',
    'aq40'       => "Temple of Ahn'Qiraj",
    'naxx'       => 'Naxxramas',
    'bwl_mc'     => 'BWL + MC',
    'world_tour' => 'World Tour (BWL + MC + AQ40)',
];

function raid_zone_boss_lists() {
    return [
        'zg' => [
            "High Priestess Jeklik", "High Priest Venoxis", "High Priestess Mar'li",
            "High Priest Thekal", "High Priestess Arlokk", "Bloodlord Mandokir",
            "Gahz'rilla", "Renataki", "Wushoolay", "Gri'lek", "Hazza'rah",
            "Jin'do the Hexxer", "Hakkar the Soulflayer",
        ],
        'aq20' => [
            'Kurinnaxx', 'General Rajaxx', 'Moam', 'Buru the Gorger',
            'Ayamiss the Hunter', 'Ossirian the Unscarred',
        ],
        'mc' => [
            'Lucifron', 'Magmadar', 'Gehennas', 'Garr', 'Baron Geddon', 'Shazzrah',
            'Sulfuron Harbinger', 'Golemagg the Incinerator', 'Majordomo Executus', 'Ragnaros',
        ],
        'bwl' => [
            'Razorgore the Untamed', 'Vaelastrasz the Corrupt', 'Broodlord Lashlayer',
            'Firemaw', 'Ebonroc', 'Flamegor', 'Chromaggus', 'Nefarian',
        ],
        'aq40' => [
            'The Prophet Skeram', 'Lord Kri', 'Princess Yauj', 'Vem',
            'Battleguard Sartura', 'Fankriss the Unyielding', 'Viscidus', 'Princess Huhuran',
            "Vek'lor", "Vek'nilash", 'Ouro', "C'Thun",
        ],
        'naxx' => [
            "Anub'Rekhan", 'Grand Widow Faerlina', 'Maexxna', 'Noth the Plaguebringer',
            'Heigan the Unclean', 'Loatheb', 'Instructor Razuvious', 'Gothik the Harvester',
            'The Four Horsemen', 'Patchwerk', 'Grobbulus', 'Gluth', 'Thaddius',
            'Sapphiron', "Kel'Thuzad",
        ],
    ];
}

function raid_zone_bosses($zone) {
    $lists = raid_zone_boss_lists();
    if ($zone === 'bwl_mc') return array_merge($lists['mc'], $lists['bwl']);
    if ($zone === 'world_tour') return array_merge($lists['mc'], $lists['bwl'], $lists['aq40']);
    return $lists[$zone] ?? [];
}
