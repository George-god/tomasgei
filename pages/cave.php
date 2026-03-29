<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/CaveService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\CaveService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$caveService = new CaveService();
$flashOk = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cave_action'])) {
    $action = (string)$_POST['cave_action'];
    if ($action === 'unlock') {
        $r = $caveService->unlock($userId);
        if (!empty($r['success'])) {
            $flashOk = (string)($r['message'] ?? 'Done.');
        } else {
            $flashErr = (string)($r['error'] ?? 'Failed.');
        }
    } elseif ($action === 'upgrade') {
        $r = $caveService->upgradeLevel($userId);
        if (!empty($r['success'])) {
            $flashOk = (string)($r['message'] ?? 'Done.');
        } else {
            $flashErr = (string)($r['error'] ?? 'Failed.');
        }
    } elseif ($action === 'set_environment') {
        $r = $caveService->setEnvironment($userId, (string)($_POST['environment_key'] ?? ''));
        if (!empty($r['success'])) {
            $flashOk = (string)($r['message'] ?? 'Done.');
        } else {
            $flashErr = (string)($r['error'] ?? 'Failed.');
        }
    } elseif ($action === 'set_formation') {
        $slot = (int)($_POST['slot'] ?? 0);
        $rawFk = $_POST['formation_key'] ?? '';
        $fk = ($rawFk === '' || $rawFk === '__none__') ? null : (string)$rawFk;
        $r = $caveService->setFormationSlot($userId, $slot, $fk);
        if (!empty($r['success'])) {
            $flashOk = (string)($r['message'] ?? 'Done.');
        } else {
            $flashErr = (string)($r['error'] ?? 'Failed.');
        }
    }
}

$state = $caveService->getCaveState($userId);

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

$unlocked = !empty($state['unlocked']);
$caveLevel = (int)($state['cave_level'] ?? 0);
$nextUpgrade = $state['next_upgrade'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cultivation Cave - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen text-gray-200">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold bg-gradient-to-r from-teal-400 to-cyan-400 bg-clip-text text-transparent">Cultivation Cave</h1>
                    <p class="text-gray-500 text-sm mt-1"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?> · private sanctuary</p>
                </div>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($flashOk !== null): ?>
            <div class="mb-6 rounded-xl border border-emerald-500/40 bg-emerald-950/40 px-4 py-3 text-emerald-200 text-sm"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null): ?>
            <div class="mb-6 rounded-xl border border-red-500/40 bg-red-950/30 px-4 py-3 text-red-200 text-sm"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (empty($state['available'])): ?>
            <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-8 text-center">
                <p class="text-gray-300 text-lg mb-2">The cave system is not installed on this server yet.</p>
                <p class="text-gray-500 text-sm">Ask the administrator to run <code class="text-amber-200/90">database_full.sql</code> (or <code class="text-amber-200/90">sql/archive/database_caves.sql</code>) on the game database.</p>
            </div>
        <?php elseif (!$unlocked): ?>
            <div class="bg-gray-800/90 border border-teal-500/30 rounded-xl p-8">
                <h2 class="text-xl font-semibold text-teal-300 mb-3">Claim your cave</h2>
                <p class="text-gray-400 text-sm mb-6 max-w-xl">
                    A secluded grotto where qi gathers. Unlocking it passively improves every cultivation session and steadies your dao heart during breakthrough tribulations.
                </p>
                <ul class="text-sm text-gray-400 space-y-2 mb-6">
                    <li>· +% chi each time you cultivate (scales with cave level, environment, and formations)</li>
                    <li>· +% breakthrough success chance (same bonuses, capped with other sources at 98%)</li>
                    <li>· Extra damage reduction during tribulation phases from cave resonance</li>
                </ul>
                <div class="flex flex-wrap items-center gap-4 mb-6">
                    <div class="text-sm">
                        <span class="text-gray-500">Your gold:</span>
                        <span class="text-amber-300 font-semibold ml-1"><?php echo number_format($gold); ?></span>
                        <span class="text-gray-600 mx-2">|</span>
                        <span class="text-gray-500">Spirit stones:</span>
                        <span class="text-cyan-300 font-semibold ml-1"><?php echo number_format($spiritStones); ?></span>
                    </div>
                </div>
                <div class="text-sm text-gray-500 mb-4">
                    Cost: <span class="text-amber-200"><?php echo (int)($state['unlock_cost_gold'] ?? 1200); ?> gold</span>
                    and <span class="text-cyan-200"><?php echo (int)($state['unlock_cost_spirit_stones'] ?? 12); ?> spirit stones</span>
                </div>
                <form method="post" class="inline">
                    <input type="hidden" name="cave_action" value="unlock">
                    <button type="submit" class="px-6 py-3 rounded-lg bg-gradient-to-r from-teal-600 to-cyan-600 hover:from-teal-500 hover:to-cyan-500 text-white font-semibold shadow-lg shadow-teal-500/20 transition-all">
                        Unlock cultivation cave
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-2 bg-gray-800/90 border border-teal-500/25 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-teal-300 mb-4">Active bonuses</h2>
                    <p class="text-xs text-gray-500 mb-4">Bonuses are fractional (e.g. 3% = 0.03) and stack additively before being applied to cultivation and breakthrough flows.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-gray-500 border-b border-gray-700">
                                    <th class="py-2 pr-4">Source</th>
                                    <th class="py-2 pr-4">Cultivation</th>
                                    <th class="py-2">Breakthrough</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4">Cave level (<?php echo $caveLevel; ?>)</td>
                                    <td class="text-cyan-300">+<?php echo number_format((float)($state['level_cultivation_bonus'] ?? 0) * 100, 2); ?>%</td>
                                    <td class="text-purple-300">+<?php echo number_format((float)($state['level_breakthrough_bonus'] ?? 0) * 100, 2); ?>%</td>
                                </tr>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4">Environment</td>
                                    <td class="text-cyan-300">+<?php echo number_format((float)($state['environment_cultivation_bonus'] ?? 0) * 100, 2); ?>%</td>
                                    <td class="text-purple-300">+<?php echo number_format((float)($state['environment_breakthrough_bonus'] ?? 0) * 100, 2); ?>%</td>
                                </tr>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4">Formations (3 slots)</td>
                                    <td class="text-cyan-300">+<?php echo number_format((float)($state['formations_cultivation_bonus'] ?? 0) * 100, 2); ?>%</td>
                                    <td class="text-purple-300">+<?php echo number_format((float)($state['formations_breakthrough_bonus'] ?? 0) * 100, 2); ?>%</td>
                                </tr>
                                <tr class="font-semibold text-white">
                                    <td class="py-3 pr-4">Total</td>
                                    <td class="text-teal-300">+<?php echo number_format((float)($state['total_cultivation_bonus'] ?? 0) * 100, 2); ?>%</td>
                                    <td class="text-fuchsia-300">+<?php echo number_format((float)($state['total_breakthrough_bonus'] ?? 0) * 100, 2); ?>%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="bg-gray-800/90 border border-cyan-500/25 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-cyan-300 mb-2">Resources</h2>
                    <div class="text-sm space-y-2">
                        <div><span class="text-gray-500">Gold</span> <span class="text-amber-300 font-semibold float-right"><?php echo number_format($gold); ?></span></div>
                        <div><span class="text-gray-500">Spirit stones</span> <span class="text-cyan-300 font-semibold float-right"><?php echo number_format($spiritStones); ?></span></div>
                    </div>
                    <div class="mt-6 pt-6 border-t border-gray-700">
                        <h3 class="text-sm font-medium text-gray-400 mb-2">Refine cave</h3>
                        <?php if ($nextUpgrade === null): ?>
                            <p class="text-sm text-gray-500">Maximum cave level reached (<?php echo (int)CaveService::MAX_CAVE_LEVEL; ?>).</p>
                        <?php else: ?>
                            <p class="text-xs text-gray-500 mb-3">
                                Next: level <?php echo (int)$nextUpgrade['to_level']; ?> —
                                <?php echo (int)$nextUpgrade['gold']; ?> gold,
                                <?php echo (int)$nextUpgrade['spirit_stones']; ?> spirit stones
                            </p>
                            <form method="post">
                                <input type="hidden" name="cave_action" value="upgrade">
                                <button type="submit" class="w-full py-2 rounded-lg bg-gray-700 hover:bg-gray-600 border border-teal-500/30 text-teal-200 text-sm font-medium transition-all">
                                    Upgrade cave
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-gray-800/90 border border-violet-500/25 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-violet-300 mb-2">Environment</h2>
                    <p class="text-xs text-gray-500 mb-4">Shift ambient qi to favor cultivation speed, breakthrough stability, or a middle path.</p>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="cave_action" value="set_environment">
                        <select name="environment_key" class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                            <?php foreach ($state['environments'] ?? [] as $env): ?>
                                <?php $ek = (string)($env['env_key'] ?? ''); ?>
                                <option value="<?php echo htmlspecialchars($ek, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $ek === ($state['environment_key'] ?? '') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)($env['display_name'] ?? $ek), ENT_QUOTES, 'UTF-8'); ?>
                                    (+<?php echo number_format((float)($env['cultivation_bonus_pct'] ?? 0) * 100, 1); ?>% cult /
                                    +<?php echo number_format((float)($env['breakthrough_bonus_pct'] ?? 0) * 100, 1); ?>% break)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-violet-700/80 hover:bg-violet-600 border border-violet-500/40 text-sm font-medium transition-all">
                            Apply environment
                        </button>
                    </form>
                    <?php if (!empty($state['environment']['description'])): ?>
                        <p class="mt-4 text-xs text-gray-500"><?php echo htmlspecialchars((string)$state['environment']['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="bg-gray-800/90 border border-amber-500/25 rounded-xl p-6">
                    <h2 class="text-lg font-semibold text-amber-300 mb-2">Formation arrays</h2>
                    <p class="text-xs text-gray-500 mb-4">Up to three inscribed formations. Each formation can only occupy one slot. Higher-tier diagrams require a deeper cave.</p>
                    <?php
                    $slots = $state['slots'] ?? [1 => null, 2 => null, 3 => null];
                    $catalog = $state['formation_catalog'] ?? [];
                    ?>
                    <?php for ($s = 1; $s <= 3; $s++): ?>
                        <form method="post" class="mb-4 last:mb-0 flex flex-col sm:flex-row sm:items-end gap-2">
                            <input type="hidden" name="cave_action" value="set_formation">
                            <input type="hidden" name="slot" value="<?php echo $s; ?>">
                            <div class="flex-1">
                                <label class="block text-xs text-gray-500 mb-1">Slot <?php echo $s; ?></label>
                                <select name="formation_key" class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                                    <option value="__none__">— None —</option>
                                    <?php foreach ($catalog as $f): ?>
                                        <?php
                                        $fk = (string)($f['formation_key'] ?? '');
                                        $req = (int)($f['required_cave_level'] ?? 1);
                                        $otherSlot = false;
                                        foreach ($slots as $num => $v) {
                                            if ((int)$num !== $s && $v === $fk) {
                                                $otherSlot = true;
                                                break;
                                            }
                                        }
                                        $allowed = $caveLevel >= $req && !$otherSlot;
                                        ?>
                                        <option value="<?php echo htmlspecialchars($fk, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo ($slots[$s] ?? null) === $fk ? 'selected' : ''; ?>
                                            <?php echo !$allowed ? 'disabled' : ''; ?>>
                                            <?php echo htmlspecialchars((string)($f['display_name'] ?? $fk), ENT_QUOTES, 'UTF-8'); ?>
                                            (Lv≥<?php echo $req; ?>)
                                            <?php if (!$allowed && $caveLevel < $req): ?> — cave too shallow<?php endif; ?>
                                            <?php if (!$allowed && $caveLevel >= $req && $otherSlot): ?> — in use<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="px-4 py-2 rounded-lg bg-amber-900/50 hover:bg-amber-800/60 border border-amber-500/30 text-amber-200 text-sm whitespace-nowrap transition-all">
                                Save slot <?php echo $s; ?>
                            </button>
                        </form>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-5 text-xs text-gray-500">
                <strong class="text-gray-400">Reference</strong> — Formation catalog bonuses:
                <ul class="mt-2 space-y-1 list-disc list-inside">
                    <?php foreach ($catalog as $f): ?>
                        <li>
                            <?php echo htmlspecialchars((string)($f['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>:
                            +<?php echo number_format((float)($f['cultivation_bonus_pct'] ?? 0) * 100, 2); ?>% cult,
                            +<?php echo number_format((float)($f['breakthrough_bonus_pct'] ?? 0) * 100, 2); ?>% break
                            (cave Lv <?php echo (int)($f['required_cave_level'] ?? 1); ?>+)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
