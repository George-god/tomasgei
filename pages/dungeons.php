<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DungeonService.php';

use Game\Helper\SessionHelper;
use Game\Service\DungeonService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$service = new DungeonService();
$data = $service->getDungeonsForUser($userId);
$dungeons = $data['dungeons'] ?? [];
$runsRemaining = (int)($data['daily_runs_remaining'] ?? 0);
$highlightId = (int)($_GET['highlight'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dungeons - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-purple-400 to-red-500 bg-clip-text text-transparent">Hidden Dungeons</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-purple-500/30 rounded-xl p-6 mb-6">
            <p class="text-gray-300">Daily dungeon runs remaining: <span class="text-white font-semibold"><?php echo $runsRemaining; ?> / 3</span></p>
            <p class="text-sm text-gray-500 mt-1">Each run has 3 stages: normal enemy, elite enemy, and a boss.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($dungeons as $dungeon): ?>
                <?php $locked = !empty($dungeon['locked']); ?>
                <?php $activeRun = $dungeon['active_run'] ?? null; ?>
                <div class="bg-gray-800/90 backdrop-blur border <?php echo (int)$dungeon['id'] === $highlightId ? 'border-purple-400' : ($locked ? 'border-gray-700 opacity-70' : 'border-purple-500/30'); ?> rounded-xl p-6">
                    <div class="flex justify-between items-start gap-3 mb-3">
                        <div>
                            <h3 class="text-xl font-semibold <?php echo $locked ? 'text-gray-400' : 'text-white'; ?>">
                                <?php echo htmlspecialchars($dungeon['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($dungeon['region_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> | Difficulty <?php echo (int)($dungeon['difficulty'] ?? 1); ?></p>
                        </div>
                        <?php if ($activeRun): ?>
                            <span class="px-2 py-1 rounded bg-purple-500/20 border border-purple-500/40 text-purple-300 text-xs">In Progress</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-400 mb-2">Boss: <?php echo htmlspecialchars($dungeon['boss_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm <?php echo $locked ? 'text-amber-300' : 'text-gray-500'; ?> mb-4">Requires <?php echo htmlspecialchars($dungeon['min_realm_name'] ?? 'Qi Refining', ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if ($activeRun): ?>
                        <p class="text-sm text-cyan-300 mb-4">Progress: Stage <?php echo (int)$activeRun['progress'] + 1; ?> / 3</p>
                    <?php endif; ?>
                    <a href="dungeon.php?dungeon_id=<?php echo (int)$dungeon['id']; ?>"
                       class="block text-center w-full py-2 rounded-lg font-semibold transition-all <?php echo $locked ? 'bg-gray-700 text-gray-400 pointer-events-none' : 'bg-purple-600 hover:bg-purple-500 text-white'; ?>">
                        <?php echo $locked ? 'Locked' : ($activeRun ? 'Continue Run' : 'Enter Dungeon'); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>




