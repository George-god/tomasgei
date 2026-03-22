<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DaoPathService.php';

use Game\Helper\SessionHelper;
use Game\Service\DaoPathService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$daoPathService = new DaoPathService();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'select_dao_path') {
    $pathId = (int)($_POST['dao_path_id'] ?? 0);
    $result = $daoPathService->selectPath($userId, $pathId);
    if (!empty($result['success'])) {
        $message = $result['message'] ?? 'Dao Path chosen.';
    } else {
        $error = $result['message'] ?? 'Could not choose Dao Path.';
    }
}

$state = $daoPathService->getSelectionState($userId);
$currentPath = $state['current_path'] ?? null;
$paths = $state['paths'] ?? [];
$unlocked = !empty($state['unlocked']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dao Paths - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-fuchsia-400 bg-clip-text text-transparent">Dao Paths</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-2xl font-semibold text-violet-300 mb-2">Path Awakening</h2>
            <?php if ($unlocked): ?>
                <p class="text-gray-300">Foundation Building has opened the way to Dao comprehension. Choose carefully: Dao Paths are a permanent bond.</p>
            <?php else: ?>
                <p class="text-gray-400">Dao Paths unlock once you reach <span class="text-violet-300 font-semibold"><?php echo htmlspecialchars((string)($state['foundation_realm_name'] ?? 'Foundation Building'), ENT_QUOTES, 'UTF-8'); ?></span>.</p>
            <?php endif; ?>
            <?php if ($currentPath): ?>
                <div class="mt-4 bg-gray-900/50 border border-violet-500/20 rounded-lg p-4">
                    <div class="text-sm text-gray-500 mb-1">Current Dao</div>
                    <div class="text-xl font-bold text-violet-300"><?php echo htmlspecialchars((string)$currentPath['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-sm text-gray-400 mt-1"><?php echo htmlspecialchars((string)($currentPath['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($paths as $path): ?>
                <div class="bg-gray-800/90 border <?php echo ($currentPath && (int)$currentPath['id'] === (int)$path['id']) ? 'border-violet-500/50' : (((string)$path['alignment'] ?? '') === 'demonic' ? 'border-red-500/30' : 'border-cyan-500/30'); ?> rounded-xl p-6">
                    <div class="flex justify-between gap-4 items-start mb-3">
                        <div>
                            <h3 class="text-2xl font-semibold <?php echo ((string)$path['alignment'] ?? '') === 'demonic' ? 'text-red-300' : 'text-cyan-300'; ?>">
                                <?php echo htmlspecialchars((string)$path['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <div class="text-xs uppercase tracking-[0.2em] text-gray-500 mt-1">
                                <?php echo htmlspecialchars((string)$path['alignment'] . ' · ' . (string)$path['element'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-1 rounded border <?php echo ((string)$path['alignment'] ?? '') === 'demonic' ? 'border-red-500/40 text-red-300 bg-red-500/10' : 'border-cyan-500/40 text-cyan-300 bg-cyan-500/10'; ?>">
                            Favored: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$path['favored_tribulation'])), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <p class="text-sm text-gray-400 mb-4"><?php echo htmlspecialchars((string)($path['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

                    <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">ATK: +<?php echo number_format((float)$path['attack_bonus_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">DEF: +<?php echo number_format((float)$path['defense_bonus_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">Max Chi: +<?php echo number_format((float)$path['max_chi_bonus_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">Dodge: +<?php echo number_format((float)$path['dodge_bonus_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">Bonus damage: +<?php echo number_format((float)$path['bonus_damage_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">On-hit heal: <?php echo number_format((float)$path['heal_on_hit_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">Reflect: <?php echo number_format((float)$path['reflect_damage_pct'] * 100, 1); ?>%</div>
                        <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-3 text-gray-300">Self-cost: <?php echo number_format((float)$path['self_damage_pct'] * 100, 1); ?>%</div>
                    </div>

                    <?php if (!empty($path['drawback_text'])): ?>
                        <div class="mb-4 p-3 bg-red-900/20 border border-red-500/30 rounded-lg text-sm text-red-200">
                            <?php echo htmlspecialchars((string)$path['drawback_text'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($currentPath && (int)$currentPath['id'] === (int)$path['id']): ?>
                        <div class="text-sm text-violet-300 font-semibold">Current Dao Path</div>
                    <?php elseif (!$unlocked): ?>
                        <div class="text-sm text-gray-500">Reach Foundation Building to unlock this choice.</div>
                    <?php elseif ($currentPath): ?>
                        <div class="text-sm text-gray-500">Dao Paths are permanent once chosen.</div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="select_dao_path">
                            <input type="hidden" name="dao_path_id" value="<?php echo (int)$path['id']; ?>">
                            <button type="submit" class="w-full py-3 <?php echo ((string)$path['alignment'] ?? '') === 'demonic' ? 'bg-red-700 hover:bg-red-600' : 'bg-violet-600 hover:bg-violet-500'; ?> text-white font-semibold rounded-lg transition-all">
                                Choose <?php echo htmlspecialchars((string)$path['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>




