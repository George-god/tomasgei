<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ActivityService.php';

use Game\Helper\SessionHelper;
use Game\Service\ActivityService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$activityService = new ActivityService();
$dash = $activityService->getDashboard($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily & Weekly Activities - The Upper Realms</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-rose-400 to-amber-400 bg-clip-text text-transparent">Daily & Weekly Activities</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <p class="text-gray-400 text-sm mb-6">
            Daily tasks reset each calendar day. Weekly tasks reset every Monday (server time).
            Rewards are sent automatically when you complete a task (gold & spirit stones added to your account).
        </p>

        <section class="mb-10">
            <h2 class="text-lg font-semibold text-amber-300 mb-3">Today — <?php echo htmlspecialchars($dash['today'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="space-y-4">
                <?php foreach ($dash['daily'] as $t): ?>
                <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-4">
                    <div class="flex justify-between items-start gap-4 flex-wrap mb-2">
                        <div>
                            <div class="font-medium text-white"><?php echo htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-500 mt-1">Reward: <?php echo (int)$t['reward_gold']; ?> gold<?php if ((int)$t['reward_spirit_stones'] > 0): ?> · <?php echo (int)$t['reward_spirit_stones']; ?> spirit stones<?php endif; ?></div>
                        </div>
                        <?php if (!empty($t['completed'])): ?>
                            <span class="text-xs px-2 py-1 rounded bg-green-900/50 text-green-300 border border-green-500/40">Complete</span>
                        <?php else: ?>
                            <span class="text-sm text-cyan-300"><?php echo (int)$t['progress']; ?> / <?php echo (int)$t['target']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="w-full bg-gray-900 rounded-full h-2 overflow-hidden border border-gray-700">
                        <div class="h-full bg-gradient-to-r from-amber-500 to-rose-500 rounded-full transition-all" style="width: <?php echo (int)$t['percent']; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($dash['daily'])): ?>
                    <p class="text-gray-500 text-sm">No daily tasks loaded. Run <code class="text-amber-400">database_activity_tasks.sql</code> on your database.</p>
                <?php endif; ?>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-violet-300 mb-3">This week — starts <?php echo htmlspecialchars($dash['week_start'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="space-y-4">
                <?php foreach ($dash['weekly'] as $t): ?>
                <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-4">
                    <div class="flex justify-between items-start gap-4 flex-wrap mb-2">
                        <div>
                            <div class="font-medium text-white"><?php echo htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-500 mt-1">Reward: <?php echo (int)$t['reward_gold']; ?> gold<?php if ((int)$t['reward_spirit_stones'] > 0): ?> · <?php echo (int)$t['reward_spirit_stones']; ?> spirit stones<?php endif; ?></div>
                        </div>
                        <?php if (!empty($t['completed'])): ?>
                            <span class="text-xs px-2 py-1 rounded bg-green-900/50 text-green-300 border border-green-500/40">Complete</span>
                        <?php else: ?>
                            <span class="text-sm text-violet-300"><?php echo number_format((int)$t['progress']); ?> / <?php echo number_format((int)$t['target']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="w-full bg-gray-900 rounded-full h-2 overflow-hidden border border-gray-700">
                        <div class="h-full bg-gradient-to-r from-violet-500 to-fuchsia-500 rounded-full transition-all" style="width: <?php echo (int)$t['percent']; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</body>
</html>
