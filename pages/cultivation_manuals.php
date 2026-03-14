<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/CultivationManualService.php';
require_once dirname(__DIR__) . '/services/SectService.php';

use Game\Helper\SessionHelper;
use Game\Service\CultivationManualService;
use Game\Service\SectService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$manualService = new CultivationManualService();
$sectService = new SectService();
$mySect = $sectService->getSectByUserId($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $result = null;
    if ($action === 'craft_manual') {
        $recipeId = (int)($_POST['recipe_id'] ?? 0);
        $result = $manualService->craftCustomManual($userId, $recipeId);
    }

    if ($result !== null) {
        $query = $result['success']
            ? '?msg=' . urlencode((string)$result['message'])
            : '?err=' . urlencode((string)$result['message']);
        header('Location: cultivation_manuals.php' . $query);
        exit;
    }
}

$pageData = $manualService->getManualPageData($userId);
$ownedManuals = $pageData['owned_manuals'] ?? [];
$borrowedManuals = $pageData['borrowed_manuals'] ?? [];
$recipes = $pageData['recipes'] ?? [];
$effects = $pageData['active_effects'] ?? [];
$daoProfile = $pageData['dao_profile'] ?? null;
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$formatPercent = static fn(float $value): string => number_format($value * 100, 1) . '%';
$formatTier = static fn(string $tier): string => ucwords(str_replace('_', ' ', $tier));
$formatSource = static fn(string $source): string => ucwords(str_replace('_', ' ', $source));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cultivation Manuals - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-cyan-400 bg-clip-text text-transparent">Cultivation Manuals</h1>
            <div class="flex gap-2">
                <?php if ($mySect): ?>
                    <a href="sect_library.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Sect Library</a>
                <?php endif; ?>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
            <div class="xl:col-span-2 bg-gray-800/90 border border-violet-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-violet-300 mb-4">Active Effects</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                        <div class="text-gray-400 mb-2">Passive bonuses</div>
                        <div class="text-white">ATK: +<?php echo $formatPercent((float)($effects['passive_attack_pct'] ?? 0.0)); ?></div>
                        <div class="text-white">DEF: +<?php echo $formatPercent((float)($effects['passive_defense_pct'] ?? 0.0)); ?></div>
                        <div class="text-white">Max Chi: +<?php echo $formatPercent((float)($effects['passive_max_chi_pct'] ?? 0.0)); ?></div>
                        <div class="text-white">Dodge: +<?php echo $formatPercent((float)($effects['passive_dodge_pct'] ?? 0.0)); ?></div>
                    </div>
                    <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                        <div class="text-gray-400 mb-2">Technique support</div>
                        <div class="text-white">Upgrade power: +<?php echo $formatPercent((float)($effects['technique_upgrade_pct'] ?? 0.0)); ?></div>
                        <div class="text-white">Cooldown reduction: <?php echo (int)($effects['cooldown_reduction_turns'] ?? 0); ?> turns</div>
                        <div class="text-white">Unlocked tiers:
                            <?php echo !empty($effects['unlocked_tiers']) ? htmlspecialchars(implode(', ', array_map($formatTier, $effects['unlocked_tiers'])), ENT_QUOTES, 'UTF-8') : 'None'; ?>
                        </div>
                    </div>
                </div>
                <div class="mt-4 text-sm text-gray-400">
                    Current Dao Path:
                    <span class="text-white font-medium"><?php echo htmlspecialchars((string)($daoProfile['dao_path_name'] ?? 'Unchosen'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-cyan-300 mb-4">Collection</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between text-gray-300"><span>Owned manuals</span><span class="font-semibold text-white"><?php echo count($ownedManuals); ?></span></div>
                    <div class="flex justify-between text-gray-300"><span>Borrowed manuals</span><span class="font-semibold text-white"><?php echo count($borrowedManuals); ?></span></div>
                    <div class="flex justify-between text-gray-300"><span>Applicable manuals</span><span class="font-semibold text-white"><?php echo count($effects['manuals'] ?? []); ?></span></div>
                </div>
                <p class="text-xs text-gray-500 mt-4">Advanced and ultimate Dao techniques now require manual support. Basic techniques remain available with your Dao Path.</p>
            </div>
        </div>

        <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-amber-300 mb-4">Custom Manual Crafting</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <?php foreach ($recipes as $recipe): ?>
                    <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                        <div class="flex justify-between items-center gap-3 mb-2">
                            <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$recipe['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <span class="text-xs px-2 py-1 rounded bg-amber-500/10 border border-amber-500/30 text-amber-300"><?php echo htmlspecialchars(ucfirst((string)$recipe['rarity']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="text-sm text-gray-400 mb-2">Requires level <?php echo (int)$recipe['required_level']; ?></div>
                        <div class="text-sm text-gray-300 mb-3">
                            Materials tier <?php echo (int)$recipe['required_material_tier']; ?> x<?php echo (int)$recipe['required_materials']; ?>,
                            Rune Fragments x<?php echo (int)$recipe['required_rune_fragments']; ?>,
                            Gold <?php echo (int)$recipe['required_gold']; ?>,
                            Spirit Stones <?php echo (int)$recipe['required_spirit_stones']; ?>
                        </div>
                        <div class="text-xs text-violet-300 mb-3">
                            Unlocks: <?php echo htmlspecialchars($formatTier((string)$recipe['unlock_tier']), ENT_QUOTES, 'UTF-8'); ?> |
                            Upgrade: +<?php echo $formatPercent((float)$recipe['technique_upgrade_pct']); ?>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="craft_manual">
                            <input type="hidden" name="recipe_id" value="<?php echo (int)$recipe['id']; ?>">
                            <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg">Craft Manual</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-violet-300 mb-4">Owned Manuals</h2>
                <div class="space-y-3">
                    <?php foreach ($ownedManuals as $manual): ?>
                        <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                            <div class="flex justify-between items-start gap-3">
                                <div>
                                    <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$manual['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($formatSource((string)$manual['acquired_from']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(ucfirst((string)$manual['rarity']), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="text-xs px-2 py-1 rounded bg-violet-500/10 border border-violet-500/30 text-violet-300"><?php echo htmlspecialchars(ucfirst((string)$manual['rarity']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <p class="text-sm text-gray-400 mt-3"><?php echo htmlspecialchars((string)$manual['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$ownedManuals): ?>
                        <p class="text-gray-400">No manuals owned yet. Explore ruins, clear dungeons, defeat world bosses, or craft one.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-cyan-300 mb-4">Borrowed Manuals</h2>
                <div class="space-y-3">
                    <?php foreach ($borrowedManuals as $manual): ?>
                        <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                            <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$manual['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                Borrowed from <?php echo htmlspecialchars((string)$manual['sect_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($manual['due_at'])): ?>
                                    · Due <?php echo htmlspecialchars((string)$manual['due_at'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-400 mt-3"><?php echo htmlspecialchars((string)$manual['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$borrowedManuals): ?>
                        <p class="text-gray-400">No sect library manuals are currently borrowed.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>




