<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/StatCalculator.php';
require_once dirname(__DIR__) . '/services/SectService.php';
require_once dirname(__DIR__) . '/services/BreakthroughService.php';
require_once dirname(__DIR__) . '/services/TitleService.php';
require_once dirname(__DIR__) . '/services/ItemService.php';

use Game\Helper\SessionHelper;
use Game\Service\StatCalculator;
use Game\Service\SectService;
use Game\Service\BreakthroughService;
use Game\Service\TitleService;
use Game\Service\ItemService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$statCalculator = new StatCalculator();
$breakdown = $statCalculator->getCombatStatBreakdown($userId);
if ($breakdown === null) {
    header('Location: login.php');
    exit;
}

$sectService = new SectService();
$mySect = $sectService->getSectByUserId($userId);
$breakthroughStatus = (new BreakthroughService())->getBreakthroughStatus($userId);
$equippedTitle = (new TitleService())->getEquippedTitleDisplay($userId);
$equipment = (new ItemService())->getUserEquipment($userId);

$fmtPct = static function (float $v): string {
    return number_format($v * 100, 2) . '%';
};

$slotLabels = [
    'weapon' => 'Weapon',
    'armor' => 'Armor',
    'accessory_1' => 'Accessory 1',
    'accessory_2' => 'Accessory 2',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Character details - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen text-gray-200">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-cyan-400 to-violet-400 bg-clip-text text-transparent">Character details</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <p class="text-gray-400 text-sm mb-8">Full breakdown of combat stats, gear, Dao, manuals, titles, and sect modifiers (as the server applies them).</p>

        <!-- Combat pipeline -->
        <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-cyan-300 mb-4">Combat stat pipeline</h2>
            <p class="text-xs text-gray-500 mb-4">Order matches <code class="text-gray-400">StatCalculator</code>: equipment → realm tier → rune scroll → Dao Path → manuals → title. Current chi is capped by max chi at each step.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left text-gray-400 border-b border-gray-700">
                            <th class="py-2 pr-4">Stage</th>
                            <th class="py-2 pr-4">Attack</th>
                            <th class="py-2 pr-4">Defense</th>
                            <th class="py-2 pr-4">Max chi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breakdown['steps'] as $step): ?>
                        <tr class="border-b border-gray-700/80">
                            <td class="py-3 pr-4">
                                <div class="font-medium text-white"><?php echo htmlspecialchars((string)$step['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars((string)$step['note'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="py-3 pr-4 tabular-nums text-amber-200"><?php echo number_format((int)($step['stats']['attack'] ?? 0)); ?></td>
                            <td class="py-3 pr-4 tabular-nums text-blue-200"><?php echo number_format((int)($step['stats']['defense'] ?? 0)); ?></td>
                            <td class="py-3 pr-4 tabular-nums text-cyan-200"><?php echo number_format((int)($step['stats']['max_chi'] ?? 0)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 mt-3">Realm multiplier on core stats: <strong class="text-white">×<?php echo htmlspecialchars(number_format((float)$breakdown['realm_multiplier'], 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?></strong></p>
        </div>

        <!-- Equipped items -->
        <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-amber-300 mb-4">Equipped gear (flat bonuses)</h2>
            <p class="text-xs text-gray-500 mb-4">Totals: +<?php echo number_format((int)($breakdown['equipment_flat']['attack'] ?? 0)); ?> ATK, +<?php echo number_format((int)($breakdown['equipment_flat']['defense'] ?? 0)); ?> DEF, +<?php echo number_format((int)($breakdown['equipment_flat']['hp'] ?? 0)); ?> max chi (as HP on items).</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($slotLabels as $slotKey => $slotName): ?>
                    <?php $piece = $equipment[$slotKey] ?? null; ?>
                    <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                        <div class="text-xs text-gray-500 mb-1"><?php echo htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($piece && !empty($piece['template'])): ?>
                            <?php $t = $piece['template']; ?>
                            <div class="font-semibold text-white"><?php echo htmlspecialchars((string)($t['name'] ?? 'Item'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-sm text-gray-400 mt-1">
                                +<?php echo (int)($t['attack_bonus'] ?? 0); ?> ATK · +<?php echo (int)($t['defense_bonus'] ?? 0); ?> DEF · +<?php echo (int)($t['hp_bonus'] ?? 0); ?> HP
                            </div>
                        <?php else: ?>
                            <div class="text-gray-500 text-sm">Empty</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Active scroll -->
        <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-violet-300 mb-2">Active rune scroll</h2>
            <p class="text-gray-300"><?php echo htmlspecialchars((string)$breakdown['active_scroll_label'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <!-- Dao -->
        <div class="bg-gray-800/90 border border-fuchsia-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-fuchsia-300 mb-4">Dao Path (combat)</h2>
            <?php $dao = $breakdown['dao_path']; ?>
            <?php if (($dao['name'] ?? '') !== ''): ?>
                <p class="text-white font-medium mb-3"><?php echo htmlspecialchars((string)$dao['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <ul class="text-sm text-gray-300 space-y-1 list-disc list-inside">
                    <li>Attack / Defense / Max chi (multiplicative on core): <?php echo $fmtPct((float)$dao['attack_pct']); ?> / <?php echo $fmtPct((float)$dao['defense_pct']); ?> / <?php echo $fmtPct((float)$dao['max_chi_pct']); ?></li>
                    <li>Dodge chance bonus: <?php echo $fmtPct((float)$dao['dodge_bonus']); ?></li>
                    <li>Bonus damage: <?php echo $fmtPct((float)$dao['bonus_damage_pct']); ?> · Heal on hit: <?php echo $fmtPct((float)$dao['heal_on_hit_pct']); ?></li>
                    <li>Reflect: <?php echo $fmtPct((float)$dao['reflect_damage_pct']); ?> · Self-damage (drawback): <?php echo $fmtPct((float)$dao['self_damage_pct']); ?></li>
                    <?php if (!empty($dao['favored_tribulation'])): ?>
                    <li>Favored tribulation type: <span class="text-fuchsia-200"><?php echo htmlspecialchars((string)$dao['favored_tribulation'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                    <?php endif; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-500 text-sm">No Dao Path selected yet.</p>
            <?php endif; ?>
        </div>

        <!-- Manuals -->
        <div class="bg-gray-800/90 border border-emerald-500/25 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-emerald-300 mb-4">Cultivation manuals (passive)</h2>
            <?php $me = $breakdown['manual_effects'] ?? []; ?>
            <ul class="text-sm text-gray-300 space-y-1">
                <li>Passive attack / defense / max chi: <?php echo $fmtPct((float)($me['passive_attack_pct'] ?? 0)); ?> / <?php echo $fmtPct((float)($me['passive_defense_pct'] ?? 0)); ?> / <?php echo $fmtPct((float)($me['passive_max_chi_pct'] ?? 0)); ?></li>
                <li>Passive dodge: <?php echo $fmtPct((float)($me['passive_dodge_pct'] ?? 0)); ?></li>
                <li>Technique damage upgrade (total): <?php echo $fmtPct((float)($me['technique_upgrade_pct'] ?? 0)); ?> · Cooldown reduction (turns): <?php echo (int)($me['cooldown_reduction_turns'] ?? 0); ?></li>
            </ul>
            <?php if (!empty($me['manuals']) && is_array($me['manuals'])): ?>
                <p class="text-xs text-gray-500 mt-3">Contributing manuals: <?php echo count($me['manuals']); ?> (see <a href="cultivation_manuals.php" class="text-emerald-400 hover:underline">Manuals</a> for names).</p>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <div class="bg-gray-800/90 border border-yellow-500/25 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-yellow-300 mb-4">Equipped title</h2>
            <?php if ($equippedTitle): ?>
                <p class="text-white font-medium"><?php echo htmlspecialchars($equippedTitle['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-sm text-gray-400 mt-2">Combat: <?php echo $fmtPct((float)$equippedTitle['attack_pct']); ?> ATK · <?php echo $fmtPct((float)$equippedTitle['defense_pct']); ?> DEF · <?php echo $fmtPct((float)$equippedTitle['max_chi_pct']); ?> max chi</p>
                <a href="titles.php" class="text-xs text-yellow-400/80 hover:underline mt-2 inline-block">Manage titles</a>
            <?php else: ?>
                <p class="text-gray-500 text-sm">No title equipped.</p>
                <a href="titles.php" class="text-xs text-yellow-400/80 hover:underline mt-2 inline-block">Titles</a>
            <?php endif; ?>
        </div>

        <!-- Sect & breakthrough -->
        <div class="bg-gray-800/90 border border-amber-500/25 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-amber-300 mb-4">Sect &amp; breakthrough modifiers</h2>
            <?php if ($mySect): ?>
                <p class="text-white font-medium mb-2"><?php echo htmlspecialchars((string)$mySect['name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(ucfirst((string)($mySect['tier'] ?? '')), ENT_QUOTES, 'UTF-8'); ?> tier · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($mySect['rank'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-xs text-gray-500 mb-3">Sect bonuses apply to cultivation, gold, and breakthrough where noted in game systems (not flat combat stats).</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="bg-gray-900/50 rounded-lg p-3 border border-gray-700">
                        <div class="text-gray-400 text-xs mb-1">Tier + rank + base (merged)</div>
                        <ul class="text-gray-300 space-y-1">
                            <li>Cultivation speed: <strong class="text-emerald-300"><?php echo $fmtPct((float)($mySect['bonuses']['cultivation_speed'] ?? 0)); ?></strong></li>
                            <li>Gold gain: <strong class="text-amber-300"><?php echo $fmtPct((float)($mySect['bonuses']['gold_gain'] ?? 0)); ?></strong></li>
                            <li>Breakthrough (tribulation): <strong class="text-violet-300"><?php echo $fmtPct((float)($mySect['bonuses']['breakthrough'] ?? 0)); ?></strong></li>
                        </ul>
                    </div>
                    <div class="bg-gray-900/50 rounded-lg p-3 border border-gray-700">
                        <div class="text-gray-400 text-xs mb-1">Breakthrough panel (sect component)</div>
                        <p class="text-gray-300">Extra tribulation success from sect: <strong class="text-emerald-300"><?php echo $fmtPct((float)($breakthroughStatus['sect_breakthrough_bonus'] ?? 0)); ?></strong></p>
                        <p class="text-xs text-gray-500 mt-2">Shown on the dashboard when breakthrough is available; stacks with pills and runes.</p>
                    </div>
                </div>
                <a href="sect.php" class="text-xs text-amber-400/80 hover:underline mt-4 inline-block">Sect hall</a>
            <?php else: ?>
                <p class="text-gray-500 text-sm">You are not in a sect. Sect tier, rank, and stronghold bonuses do not apply.</p>
                <a href="sect.php" class="text-xs text-amber-400/80 hover:underline mt-2 inline-block">Join a sect</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
