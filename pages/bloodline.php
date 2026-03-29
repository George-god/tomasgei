<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/BloodlineService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\BloodlineService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$bloodlineService = new BloodlineService();
$flashOk = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bloodline_action'])) {
    $action = (string)$_POST['bloodline_action'];
    if ($action === 'set_active') {
        $bid = (int)($_POST['bloodline_id'] ?? 0);
        $r = $bloodlineService->setActiveBloodline($userId, $bid);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Updated.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Failed.') : null;
    } elseif ($action === 'awaken') {
        $bid = (int)($_POST['bloodline_id'] ?? 0);
        $r = $bloodlineService->awakenBloodline($userId, $bid);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Awakened.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Failed.') : null;
    } elseif ($action === 'evolve') {
        $bid = (int)($_POST['bloodline_id'] ?? 0);
        $r = $bloodlineService->attemptBloodlineEvolution($userId, $bid);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Evolved.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Evolution failed.') : null;
    }
}

$state = $bloodlineService->getBloodlinePageState($userId);

try {
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT gold, spirit_stones, username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch() ?: ['gold' => 0, 'spirit_stones' => 0, 'username' => ''];
} catch (Throwable $e) {
    $userRow = ['gold' => 0, 'spirit_stones' => 0, 'username' => ''];
}

$gold = (int)($userRow['gold'] ?? 0);
$spiritStones = (int)($userRow['spirit_stones'] ?? 0);
$username = (string)($userRow['username'] ?? 'Cultivator');

$progress = $state['progress'] ?? [];
$catalog = $state['catalog'] ?? [];
$unlocked = $state['unlocked'] ?? [];
$activeId = $state['active_bloodline_id'] ?? null;
$passive = $state['passive_preview'] ?? [];
$evolutionEnabled = !empty($state['evolution_enabled']);
$evolutionPreviews = $state['evolution_previews'] ?? [];
$abilitiesEnabled = !empty($state['abilities_enabled']);
$playerLevelState = (int)($state['player_level'] ?? 1);
$daoElement = isset($state['dao_element']) ? (string)$state['dao_element'] : '';
$activeScaling = $state['active_scaling'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bloodlines - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-red-950/20 to-gray-900 min-h-screen text-gray-200">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold bg-gradient-to-r from-rose-400 to-amber-400 bg-clip-text text-transparent">Bloodlines</h1>
                    <p class="text-gray-500 text-sm mt-1"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> · character level <span class="text-amber-300 font-mono"><?php echo (int)$playerLevelState; ?></span><?php if ($daoElement !== ''): ?> · Dao <span class="text-cyan-300"><?php echo htmlspecialchars(ucfirst($daoElement), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></p>
                </div>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-rose-500/30 text-rose-200 transition-all">← Dashboard</a>
        </div>

        <?php if ($flashOk !== null): ?>
            <div class="mb-6 rounded-xl border border-emerald-500/40 bg-emerald-950/30 px-4 py-3 text-emerald-200 text-sm"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null): ?>
            <div class="mb-6 rounded-xl border border-red-500/40 bg-red-950/30 px-4 py-3 text-red-200 text-sm"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (empty($state['available'])): ?>
            <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-8 text-center">
                <p class="text-gray-300">Bloodlines are not installed. Run <code class="text-amber-200/90">database_full.sql</code> on the database (or <code class="text-amber-200/90">sql/archive/database_bloodlines.sql</code> for the bloodline module only).</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-2 bg-gray-800/90 border border-rose-500/20 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-rose-300 mb-4">Your progress toward awakenings</h2>
                    <ul class="space-y-3 text-sm">
                        <li class="flex justify-between border-b border-gray-700/80 pb-2">
                            <span class="text-gray-400">Lifetime world boss damage</span>
                            <span class="text-amber-300 font-mono"><?php echo number_format((int)($progress['boss_damage'] ?? 0)); ?></span>
                        </li>
                        <li class="flex justify-between border-b border-gray-700/80 pb-2">
                            <span class="text-gray-400">Tribulations survived</span>
                            <span class="text-purple-300 font-mono"><?php echo number_format((int)($progress['tribulation_wins'] ?? 0)); ?></span>
                        </li>
                        <li class="flex justify-between border-b border-gray-700/80 pb-2">
                            <span class="text-gray-400">PvP wins</span>
                            <span class="text-red-300 font-mono"><?php echo number_format((int)($progress['pvp_wins'] ?? 0)); ?></span>
                        </li>
                        <li class="flex justify-between pb-1">
                            <span class="text-gray-400">Dungeons cleared</span>
                            <span class="text-cyan-300 font-mono"><?php echo number_format((int)($progress['dungeon_clears'] ?? 0)); ?></span>
                        </li>
                    </ul>
                    <p class="text-xs text-gray-500 mt-4">When a threshold is met, the matching bloodline awakens automatically. Only <strong class="text-gray-400">one</strong> bloodline may be active at a time—switch freely among those you have unlocked.</p>
                    <?php if ($evolutionEnabled): ?>
                        <p class="text-xs text-violet-400/90 mt-3 border-t border-gray-700/80 pt-3">Evolution tiers (awakened → evolved → transcendent → mythic) require crafting materials, titled achievements, <span class="text-amber-200/90">Lineage Catalysts</span>, spirit stones, and gold. Attempts can fail—backlash drains extra gold, chi, and sometimes awakening depth.</p>
                    <?php endif; ?>
                </div>
                <div class="bg-gray-800/90 border border-amber-500/20 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-amber-300 mb-2">Resources</h2>
                    <div class="text-sm space-y-2">
                        <div><span class="text-gray-500">Gold</span> <span class="text-amber-300 font-semibold float-right"><?php echo number_format($gold); ?></span></div>
                        <div><span class="text-gray-500">Spirit stones</span> <span class="text-cyan-300 font-semibold float-right"><?php echo number_format($spiritStones); ?></span></div>
                    </div>
                    <?php if ($activeId !== null): ?>
                        <div class="mt-6 pt-6 border-t border-gray-700">
                            <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Active lineage passives</h3>
                            <ul class="text-xs text-gray-400 space-y-1">
                                <li>Atk +<?php echo number_format((float)($passive['attack_pct'] ?? 0) * 100, 2); ?>%</li>
                                <li>Def +<?php echo number_format((float)($passive['defense_pct'] ?? 0) * 100, 2); ?>%</li>
                                <li>Max chi +<?php echo number_format((float)($passive['max_chi_pct'] ?? 0) * 100, 2); ?>%</li>
                                <li>Cultivation +<?php echo number_format((float)($passive['cultivation_pct'] ?? 0) * 100, 2); ?>%</li>
                                <li>Breakthrough +<?php echo number_format((float)($passive['breakthrough_pct'] ?? 0) * 100, 2); ?>%</li>
                            </ul>
                        </div>
                        <?php if ($abilitiesEnabled && is_array($activeScaling)): ?>
                            <div class="mt-6 pt-6 border-t border-gray-700">
                                <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Scaling (active lineage)</h3>
                                <ul class="text-[11px] text-gray-400 space-y-1 font-mono">
                                    <li>Awakening × lineage × level: <?php echo number_format((float)$activeScaling['awakening_mult'], 3); ?> × <?php echo number_format((float)$activeScaling['lineage_mult'], 3); ?> × <?php echo number_format((float)$activeScaling['level_scale_mult'], 3); ?></li>
                                    <li>Resonance (Dao element + manuals): <span class="text-violet-300"><?php echo number_format((float)$activeScaling['resonance_mult'], 3); ?>×</span></li>
                                    <li class="text-gray-500">Passive stack mult ≈ <?php echo number_format((float)$activeScaling['passive_total_mult'], 3); ?>×</li>
                                </ul>
                                <?php $ac = $activeScaling['ability_combat'] ?? []; ?>
                                <?php if (!empty($ac) && array_sum(array_map('floatval', $ac)) > 0): ?>
                                    <h4 class="text-[10px] font-medium text-gray-500 uppercase tracking-wide mt-3 mb-1">Combat ability (scaled)</h4>
                                    <ul class="text-[11px] text-gray-500 space-y-0.5">
                                        <?php if ((float)($ac['damage_out_pct'] ?? 0) > 0): ?><li>Outgoing dmg +<?php echo number_format((float)$ac['damage_out_pct'] * 100, 2); ?>%</li><?php endif; ?>
                                        <?php if ((float)($ac['damage_taken_reduction_pct'] ?? 0) > 0): ?><li>Toughness <?php echo number_format((float)$ac['damage_taken_reduction_pct'] * 100, 2); ?>% damage shaved</li><?php endif; ?>
                                        <?php if ((float)($ac['crit_chance_bonus'] ?? 0) > 0): ?><li>Crit chance +<?php echo number_format((float)$ac['crit_chance_bonus'] * 100, 2); ?>%</li><?php endif; ?>
                                        <?php if ((float)($ac['dodge_bonus'] ?? 0) > 0): ?><li>Dodge chance +<?php echo number_format((float)$ac['dodge_bonus'] * 100, 2); ?>%</li><?php endif; ?>
                                        <?php if ((float)($ac['counter_bonus'] ?? 0) > 0): ?><li>Counter chance +<?php echo number_format((float)$ac['counter_bonus'] * 100, 2); ?>%</li><?php endif; ?>
                                        <?php if ((float)($ac['lifesteal_bonus_pct'] ?? 0) > 0): ?><li>Lifesteal potency +<?php echo number_format((float)$ac['lifesteal_bonus_pct'] * 100, 2); ?>%</li><?php endif; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($abilitiesEnabled): ?>
                <div class="mb-8 bg-gray-800/80 border border-cyan-500/25 rounded-xl p-5">
                    <h2 class="text-sm font-semibold text-cyan-300 uppercase tracking-wide mb-2">Bloodline counters (PvP)</h2>
                    <p class="text-xs text-gray-500 mb-3">Certain lineages deal extra damage to specific rivals when both players wield an active bloodline. Cycle: Crimson → Labyrinth → War Buddha → Heaven-Tempered → Crimson.</p>
                    <ul class="text-xs text-gray-400 space-y-1">
                        <li>Crimson Sovereign <span class="text-red-400">strong vs</span> Labyrinth-Born (+6.5% outgoing)</li>
                        <li>Labyrinth-Born <span class="text-red-400">strong vs</span> War Buddha (+6.5%)</li>
                        <li>War Buddha <span class="text-red-400">strong vs</span> Heaven-Tempered (+6.5%)</li>
                        <li>Heaven-Tempered <span class="text-red-400">strong vs</span> Crimson Sovereign (+6.5%)</li>
                    </ul>
                    <p class="text-[11px] text-gray-600 mt-2">Resonance: matching your Dao element and keeping manuals active amplifies both passives and your unique ability.</p>
                </div>
            <?php endif; ?>

            <h2 class="text-sm font-medium text-gray-400 uppercase tracking-wide mb-3">All bloodlines</h2>
            <div class="space-y-4 mb-10">
                <?php foreach ($catalog as $bl): ?>
                    <?php
                    $bid = (int)($bl['id'] ?? 0);
                    $owned = null;
                    foreach ($unlocked as $u) {
                        if ((int)($u['bloodline_id'] ?? 0) === $bid) {
                            $owned = $u;
                            break;
                        }
                    }
                    $isActive = $owned && (int)($owned['is_active'] ?? 0) === 1;
                    $aw = $owned ? max(1, (int)($owned['awakening_level'] ?? 1)) : 0;
                    $awMax = max(1, (int)($bl['awakening_max'] ?? 5));
                    $costPreview = $owned ? $bloodlineService->awakeningCostPreview($bid, $aw, $awMax) : null;
                    ?>
                    <div class="bg-gray-800/80 border <?php echo $isActive ? 'border-rose-500/50 ring-1 ring-rose-500/20' : 'border-gray-700'; ?> rounded-xl p-5">
                        <div class="flex flex-wrap justify-between gap-3 mb-3">
                            <div>
                                <h3 class="text-lg font-semibold text-white"><?php echo htmlspecialchars((string)($bl['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="text-xs text-rose-300/80 mt-1"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($bl['unlock_type'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>:
                                    <span class="text-gray-400"><?php echo number_format((int)($bl['progress_current'] ?? 0)); ?></span>
                                    / <?php echo number_format((int)($bl['unlock_value'] ?? 0)); ?>
                                    <?php if (empty($bl['progress_met']) && !$owned): ?>
                                        <span class="text-gray-600">— locked</span>
                                    <?php elseif ($owned): ?>
                                        <span class="text-emerald-400/90">— awakened</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($owned): ?>
                                <div class="text-right text-sm">
                                    <span class="text-gray-500">Awakening</span>
                                    <span class="text-amber-300 font-semibold ml-1"><?php echo $aw; ?> / <?php echo $awMax; ?></span>
                                    <?php if ($evolutionEnabled): ?>
                                        <?php $et = (string)($owned['evolution_tier'] ?? 'awakened'); ?>
                                        <span class="block text-xs text-violet-300 mt-1">Tier: <?php echo htmlspecialchars(ucfirst($et), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($owned['mutation_display_name'])): ?>
                                            <span class="block text-[11px] text-cyan-300/90">Strain: <?php echo htmlspecialchars((string)$owned['mutation_display_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ((int)($owned['mutation_stack'] ?? 0) > 0): ?>
                                                    <span class="text-gray-500"> +<?php echo (int)$owned['mutation_stack']; ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($isActive): ?>
                                        <span class="block text-xs text-rose-400 mt-1">Active bloodline</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-400 mb-3"><?php echo htmlspecialchars((string)($bl['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (!empty($bl['effect_description']) && (string)($bl['effect_key'] ?? '') !== 'none'): ?>
                            <p class="text-xs text-violet-300/90 mb-3 border-l-2 border-violet-500/40 pl-3">
                                <span class="text-gray-500">Special:</span> <?php echo htmlspecialchars((string)$bl['effect_description'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($abilitiesEnabled && !empty($bl['catalog_ability_name'])): ?>
                            <div class="mb-3 rounded-lg border border-cyan-500/25 bg-cyan-950/15 px-3 py-2">
                                <h4 class="text-[11px] font-semibold text-cyan-300 uppercase tracking-wide">Unique ability</h4>
                                <p class="text-sm text-cyan-100/95 font-medium mt-0.5"><?php echo htmlspecialchars((string)$bl['catalog_ability_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?php echo htmlspecialchars((string)($bl['catalog_ability_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php if (!empty($bl['catalog_resonance_element'])): ?>
                                    <p class="text-[11px] text-violet-400/90 mt-2">Dao resonance: <span class="text-violet-200"><?php echo htmlspecialchars(ucfirst((string)$bl['catalog_resonance_element']), ENT_QUOTES, 'UTF-8'); ?></span> (bonus when your path matches) · manuals amplify further.</p>
                                <?php endif; ?>
                                <?php if ($owned && !empty($owned['unlocked_ability_name'])): ?>
                                    <p class="text-[10px] text-gray-600 mt-1">Unlocked on this character; combat values scale with level, awakening, evolution, mutation, resonance.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($owned): ?>
                            <div class="flex flex-wrap gap-2 items-center">
                                <?php if (!$isActive): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="bloodline_action" value="set_active">
                                        <input type="hidden" name="bloodline_id" value="<?php echo $bid; ?>">
                                        <button type="submit" class="px-4 py-2 rounded-lg bg-rose-900/50 hover:bg-rose-800/60 border border-rose-500/30 text-rose-200 text-sm transition-all">Set active</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($costPreview !== null): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="bloodline_action" value="awaken">
                                        <input type="hidden" name="bloodline_id" value="<?php echo $bid; ?>">
                                        <button type="submit" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 border border-amber-500/30 text-amber-200 text-sm transition-all">
                                            Awaken (<?php echo (int)$costPreview['gold']; ?> gold, <?php echo (int)$costPreview['spirit_stones']; ?> stones)
                                        </button>
                                    </form>
                                <?php elseif ($owned && $aw >= $awMax): ?>
                                    <span class="text-xs text-gray-500">Fully awakened.</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($owned && $evolutionEnabled): ?>
                                <?php
                                $curTier = (string)($owned['evolution_tier'] ?? 'awakened');
                                $evPreview = $evolutionPreviews[$bid] ?? null;
                                ?>
                                <div class="mt-5 pt-4 border-t border-gray-700/90">
                                    <h4 class="text-xs font-semibold text-violet-300 uppercase tracking-wide mb-2">Lineage evolution</h4>
                                    <?php if ($curTier === 'mythic'): ?>
                                        <p class="text-xs text-gray-500">This bloodline has reached <span class="text-violet-400">mythic</span>—no further tier progression.</p>
                                    <?php elseif ($evPreview === null): ?>
                                        <p class="text-xs text-gray-500">Evolution data missing for this lineage. Check database seeds.</p>
                                    <?php else: ?>
                                        <p class="text-xs text-gray-400 mb-2">
                                            Next: <span class="text-violet-200 font-medium"><?php echo htmlspecialchars(ucfirst($curTier), ENT_QUOTES, 'UTF-8'); ?></span>
                                            → <span class="text-fuchsia-200 font-medium"><?php echo htmlspecialchars(ucfirst((string)$evPreview['next_tier']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </p>
                                        <ul class="text-[11px] text-gray-500 space-y-1 mb-3">
                                            <li>Success chance: <span class="text-amber-300"><?php echo number_format((float)$evPreview['success_chance_pct'], 2); ?>%</span>
                                                · Mutation roll on success: <span class="text-cyan-300"><?php echo number_format((float)$evPreview['mutation_chance_pct'], 2); ?>%</span>
                                            </li>
                                            <li>Cost: <?php echo number_format((int)$evPreview['required_gold']); ?> gold + <?php echo number_format((int)$evPreview['required_spirit_stones']); ?> spirit stones (materials &amp; catalyst consumed on attempt)</li>
                                            <li>Materials: tier <?php echo (int)$evPreview['required_material_tier']; ?> × <?php echo (int)$evPreview['required_material_qty']; ?>
                                                <span class="<?php echo (int)$evPreview['material_have'] >= (int)$evPreview['required_material_qty'] ? 'text-emerald-400' : 'text-red-300'; ?>">(have <?php echo (int)$evPreview['material_have']; ?>)</span>
                                            </li>
                                            <?php if ((int)$evPreview['required_item_qty'] > 0): ?>
                                                <li>Special item: <?php echo htmlspecialchars((string)($evPreview['required_item_name'] ?: 'Item'), ENT_QUOTES, 'UTF-8'); ?> × <?php echo (int)$evPreview['required_item_qty']; ?>
                                                    <span class="<?php echo (int)$evPreview['item_have'] >= (int)$evPreview['required_item_qty'] ? 'text-emerald-400' : 'text-red-300'; ?>">(have <?php echo (int)$evPreview['item_have']; ?>)</span>
                                                </li>
                                            <?php endif; ?>
                                            <?php if (($evPreview['required_title_name'] ?? '') !== ''): ?>
                                                <li>Achievement title: <?php echo htmlspecialchars((string)$evPreview['required_title_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($evPreview['title_met'])): ?>
                                                        <span class="text-emerald-400">— met</span>
                                                    <?php else: ?>
                                                        <span class="text-red-300">— not unlocked</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endif; ?>
                                            <li class="text-red-300/80">On failure: +<?php echo number_format((int)$evPreview['failure_extra_gold']); ?> gold lost, <?php echo number_format((float)$evPreview['failure_chi_loss_pct'], 2); ?>% max chi stripped from current chi<?php if ((int)$evPreview['failure_awakening_levels'] > 0): ?>, −<?php echo (int)$evPreview['failure_awakening_levels']; ?> awakening level(s)<?php endif; ?>.</li>
                                            <li class="text-gray-600">Need <?php echo number_format((int)$evPreview['required_gold'] + (int)$evPreview['failure_extra_gold']); ?> gold on hand (includes backlash reserve).</li>
                                        </ul>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="bloodline_action" value="evolve">
                                            <input type="hidden" name="bloodline_id" value="<?php echo $bid; ?>">
                                            <button type="submit" <?php echo empty($evPreview['can_attempt']) ? 'disabled' : ''; ?> class="px-4 py-2 rounded-lg text-sm transition-all border <?php echo !empty($evPreview['can_attempt']) ? 'bg-violet-900/50 hover:bg-violet-800/60 border-violet-500/40 text-violet-100' : 'bg-gray-900/50 border-gray-700 text-gray-600 cursor-not-allowed'; ?>">
                                                Attempt evolution
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
