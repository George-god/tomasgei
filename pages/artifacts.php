<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ArtifactService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\ArtifactService;

session_start();
$userId = SessionHelper::requireLoggedIn();

const ARTIFACT_EVOLUTION_SCALE = 0.055;

/**
 * @param array<string, mixed> $row
 */
function artifact_evolution_multiplier(array $row): float
{
    if (empty($row['is_evolving'])) {
        return 1.0;
    }
    $max = max(1, (int)($row['evolution_max_tier'] ?? 1));
    $t = max(1, min($max, (int)($row['evolution_tier'] ?? 1)));
    return 1.0 + ($t - 1) * ARTIFACT_EVOLUTION_SCALE;
}

function artifact_rarity_classes(string $rarity): string
{
    return match (strtolower($rarity)) {
        'uncommon' => 'text-emerald-400 border-emerald-500/35',
        'rare' => 'text-sky-400 border-sky-500/35',
        'epic' => 'text-violet-400 border-violet-500/35',
        'legendary' => 'text-amber-400 border-amber-500/40',
        'mythic' => 'text-fuchsia-400 border-fuchsia-500/40',
        default => 'text-gray-400 border-gray-600/50',
    };
}

$artifactService = new ArtifactService();
$flashOk = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['artifact_action'])) {
    $action = (string)$_POST['artifact_action'];
    $uaId = (int)($_POST['user_artifact_id'] ?? 0);
    if ($action === 'equip') {
        $raw = isset($_POST['equip_slot']) ? (string)$_POST['equip_slot'] : '';
        $slot = ($raw === '' || $raw === '0') ? null : (int)$raw;
        $r = $artifactService->setEquipSlot($userId, $uaId, $slot);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Updated.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Failed.') : null;
    } elseif ($action === 'active') {
        $raw = isset($_POST['active_slot']) ? (string)$_POST['active_slot'] : '';
        $slot = ($raw === '' || $raw === '0') ? null : (int)$raw;
        $r = $artifactService->setActiveSlot($userId, $uaId, $slot);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Updated.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Failed.') : null;
    } elseif ($action === 'evolve') {
        $r = $artifactService->evolveUserArtifact($userId, $uaId);
        $flashOk = !empty($r['success']) ? (string)($r['message'] ?? 'Evolved.') : null;
        $flashErr = empty($r['success']) ? (string)($r['error'] ?? 'Failed.') : null;
    }
}

$state = $artifactService->getArtifactsPageState($userId);

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
$inventory = $state['inventory'] ?? [];
$maxEquip = (int)($state['max_equip'] ?? ArtifactService::MAX_EQUIP_SLOTS);
$maxActive = (int)($state['max_active'] ?? ArtifactService::MAX_ACTIVE_SLOTS);
$preview = $state['modifiers_preview'] ?? [];
$available = !empty($state['available']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artifacts - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-amber-950/15 to-gray-900 min-h-screen text-gray-200">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold bg-gradient-to-r from-amber-300 to-violet-400 bg-clip-text text-transparent">Artifacts</h1>
                    <p class="text-gray-500 text-sm mt-1"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> · <?php echo number_format($gold); ?> gold · <?php echo number_format($spiritStones); ?> spirit stones</p>
                </div>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-100 transition-all">← Dashboard</a>
        </div>

        <?php if ($flashOk !== null): ?>
            <div class="mb-6 rounded-xl border border-emerald-500/40 bg-emerald-950/30 px-4 py-3 text-emerald-200 text-sm"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null): ?>
            <div class="mb-6 rounded-xl border border-red-500/40 bg-red-950/30 px-4 py-3 text-red-200 text-sm"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!$available): ?>
            <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-8 text-center">
                <p class="text-gray-300">Artifacts are not installed. Run <code class="text-amber-200/90">database_full.sql</code> (or import the artifacts section from <code class="text-amber-200/90">sql/archive/database_artifacts.sql</code>).</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-1 bg-gray-800/90 border border-amber-500/25 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-amber-200 mb-3">Sockets</h2>
                    <p class="text-sm text-gray-400 mb-4">Up to <span class="text-amber-300 font-mono"><?php echo $maxEquip; ?></span> equip relics and <span class="text-violet-300 font-mono"><?php echo $maxActive; ?></span> active auras. Each relic is either equipped <em>or</em> set active—not both.</p>
                    <h3 class="text-sm font-medium text-gray-400 mb-2">Combined combat (socketed)</h3>
                    <ul class="text-xs text-gray-500 space-y-1 font-mono">
                        <li>Outgoing +<?php echo number_format((float)($preview['out_pct'] ?? 0) * 100, 2); ?>%</li>
                        <li>Taken red. <?php echo number_format((float)($preview['taken_reduction_pct'] ?? 0) * 100, 2); ?>%</li>
                        <li>Crit +<?php echo number_format((float)($preview['crit'] ?? 0) * 100, 2); ?>%</li>
                        <li>Dodge +<?php echo number_format((float)($preview['dodge'] ?? 0) * 100, 2); ?>%</li>
                        <li>Counter +<?php echo number_format((float)($preview['counter'] ?? 0) * 100, 2); ?>%</li>
                        <li>Lifesteal +<?php echo number_format((float)($preview['lifesteal'] ?? 0) * 100, 2); ?>%</li>
                    </ul>
                    <p class="text-xs text-gray-600 mt-4">Drops: world bosses, dungeon bosses, and some scheduled events (one event roll per day when the event name matches a relic tag).</p>
                </div>
                <div class="lg:col-span-2 space-y-4">
                    <?php if ($inventory === []): ?>
                        <div class="bg-gray-800/60 border border-gray-700 rounded-xl p-8 text-center text-gray-500 text-sm">No relics yet. Slay world bosses and dungeon bosses for a chance at drops.</div>
                    <?php endif; ?>
                    <?php foreach ($inventory as $row): ?>
                        <?php
                        $uaId = (int)($row['user_artifact_id'] ?? 0);
                        $m = artifact_evolution_multiplier($row);
                        $rare = (string)($row['rarity'] ?? 'common');
                        $rareCls = artifact_rarity_classes($rare);
                        $tier = (int)($row['evolution_tier'] ?? 1);
                        $tierMax = max(1, (int)($row['evolution_max_tier'] ?? 1));
                        $flags = [];
                        if (!empty($row['can_equip'])) {
                            $flags[] = 'Equip';
                        }
                        if (!empty($row['can_active'])) {
                            $flags[] = 'Active';
                        }
                        if (!empty($row['is_unique'])) {
                            $flags[] = 'Unique';
                        }
                        if (!empty($row['is_evolving'])) {
                            $flags[] = 'Evolving';
                        }
                        ?>
                        <article class="bg-gray-800/90 border rounded-xl p-5 <?php echo $rareCls; ?>">
                            <div class="flex flex-wrap justify-between gap-3 mb-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-100"><?php echo htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p class="text-xs uppercase tracking-wide <?php echo $rareCls; ?>"><?php echo htmlspecialchars($rare, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($flags !== []): ?> · <?php echo htmlspecialchars(implode(' · ', $flags), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                                </div>
                                <?php if (!empty($row['is_evolving'])): ?>
                                    <div class="text-sm text-amber-200/90 font-mono">Tier <?php echo $tier; ?> / <?php echo $tierMax; ?></div>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-400 mb-3"><?php echo htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php if ((string)($row['ability_name'] ?? '') !== ''): ?>
                                <div class="mb-3 pl-3 border-l-2 border-violet-500/50">
                                    <div class="text-violet-300 text-sm font-medium"><?php echo htmlspecialchars((string)$row['ability_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string)($row['ability_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="grid sm:grid-cols-2 gap-3 text-xs font-mono text-gray-500 mb-4">
                                <div>
                                    <div class="text-gray-600 mb-1">Passives (× evolution)</div>
                                    ATK +<?php echo number_format((float)($row['passive_attack_pct'] ?? 0) * $m * 100, 2); ?>%
                                    · DEF +<?php echo number_format((float)($row['passive_defense_pct'] ?? 0) * $m * 100, 2); ?>%
                                    · Max chi +<?php echo number_format((float)($row['passive_max_chi_pct'] ?? 0) * $m * 100, 2); ?>%
                                </div>
                                <div>
                                    <div class="text-gray-600 mb-1">Combat (× evolution)</div>
                                    Out +<?php echo number_format((float)($row['combat_out_pct'] ?? 0) * $m * 100, 2); ?>%
                                    · Taken −<?php echo number_format((float)($row['combat_taken_reduction_pct'] ?? 0) * $m * 100, 2); ?>%
                                    · Crit +<?php echo number_format((float)($row['combat_crit_bonus'] ?? 0) * $m * 100, 2); ?>
                                    · Dodge +<?php echo number_format((float)($row['combat_dodge_bonus'] ?? 0) * $m * 100, 2); ?>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-3 items-end">
                                <?php if (!empty($row['can_equip'])): ?>
                                    <form method="post" class="flex items-end gap-2">
                                        <input type="hidden" name="artifact_action" value="equip">
                                        <input type="hidden" name="user_artifact_id" value="<?php echo $uaId; ?>">
                                        <label class="text-xs text-gray-500">Equip
                                            <select name="equip_slot" class="ml-1 bg-gray-900 border border-gray-600 rounded px-2 py-1 text-gray-200 text-sm">
                                                <option value="0" <?php echo $row['equip_slot'] === null ? ' selected' : ''; ?>>—</option>
                                                <?php for ($s = 1; $s <= $maxEquip; $s++): ?>
                                                    <option value="<?php echo $s; ?>" <?php echo (int)($row['equip_slot'] ?? 0) === $s ? ' selected' : ''; ?>><?php echo $s; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </label>
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-900/40 border border-amber-500/40 text-amber-200 text-sm hover:bg-amber-900/60">Apply</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($row['can_active'])): ?>
                                    <form method="post" class="flex items-end gap-2">
                                        <input type="hidden" name="artifact_action" value="active">
                                        <input type="hidden" name="user_artifact_id" value="<?php echo $uaId; ?>">
                                        <label class="text-xs text-gray-500">Active
                                            <select name="active_slot" class="ml-1 bg-gray-900 border border-gray-600 rounded px-2 py-1 text-gray-200 text-sm">
                                                <option value="0" <?php echo $row['active_slot'] === null ? ' selected' : ''; ?>>—</option>
                                                <?php for ($s = 1; $s <= $maxActive; $s++): ?>
                                                    <option value="<?php echo $s; ?>" <?php echo (int)($row['active_slot'] ?? 0) === $s ? ' selected' : ''; ?>><?php echo $s; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </label>
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-violet-900/40 border border-violet-500/40 text-violet-200 text-sm hover:bg-violet-900/60">Apply</button>
                                    </form>
                                <?php endif; ?>
                                <?php
                                $nextTier = $tier + 1;
                                $nextGold = 900 + $tier * 550;
                                $nextStone = 4 + $tier * 2;
                                ?>
                                <?php if (!empty($row['is_evolving']) && $tier < $tierMax): ?>
                                    <form method="post" class="flex items-end" onsubmit="return confirm('Spend <?php echo $nextGold; ?> gold and <?php echo $nextStone; ?> spirit stones to evolve?');">
                                        <input type="hidden" name="artifact_action" value="evolve">
                                        <input type="hidden" name="user_artifact_id" value="<?php echo $uaId; ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-fuchsia-950/50 border border-fuchsia-500/35 text-fuchsia-200 text-sm hover:bg-fuchsia-900/50">
                                            Evolve → <?php echo $nextTier; ?> (<?php echo $nextGold; ?>g, <?php echo $nextStone; ?> ss)
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ((string)($row['acquired_source'] ?? '') !== ''): ?>
                                <p class="text-[10px] text-gray-600 mt-3">Source: <?php echo htmlspecialchars((string)$row['acquired_source'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
