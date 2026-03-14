<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DungeonService.php';

use Game\Helper\SessionHelper;
use Game\Service\DungeonService;

session_start();
$userId = SessionHelper::requireLoggedIn();
$dungeonId = (int)($_GET['dungeon_id'] ?? $_POST['dungeon_id'] ?? 0);

$service = new DungeonService();
$message = null;
$error = null;
$battleResult = null;

if ($dungeonId < 1) {
    header('Location: dungeons.php');
    exit;
}

$dungeon = $service->getDungeonById($dungeonId);
if (!$dungeon) {
    header('Location: dungeons.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if ($action === 'start_run') {
        $result = $service->startRun($userId, $dungeonId);
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'advance_run') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $result = $service->advanceRun($userId, $runId);
        if ($result['success']) {
            $message = $result['message'];
            $battleResult = $result;
        } else {
            $error = $result['message'];
        }
    }
}

$activeRun = $service->getActiveRunForUser($userId, $dungeonId);
$runsRemaining = $service->getDailyRunsRemaining($userId);
$stagePreview = $service->getStagePreview($activeRun ?: $dungeon);
$locked = ($service->getDungeonsForUser($userId)['user_realm_id'] ?? 1) < (int)$dungeon['min_realm_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dungeon['name'] ?? 'Dungeon', ENT_QUOTES, 'UTF-8'); ?> - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-purple-400 to-red-500 bg-clip-text text-transparent">
                <?php echo htmlspecialchars($dungeon['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <div class="flex gap-2">
                <a href="dungeons.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-purple-500/30 text-purple-300 transition-all">Dungeons</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="bg-gray-800/90 backdrop-blur border border-purple-500/30 rounded-xl p-6 mb-6">
            <p class="text-gray-300 mb-2">Region: <span class="text-white"><?php echo htmlspecialchars($dungeon['region_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></p>
            <p class="text-gray-300 mb-2">Difficulty: <span class="text-white"><?php echo (int)($dungeon['difficulty'] ?? 1); ?></span></p>
            <p class="text-gray-300 mb-2">Boss: <span class="text-white"><?php echo htmlspecialchars($dungeon['boss_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></p>
            <p class="text-sm text-gray-500">Daily runs remaining: <?php echo $runsRemaining; ?> / 3</p>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-gray-700 rounded-xl p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-200 mb-4">Stages</h2>
            <div class="space-y-3">
                <div class="rounded-lg px-4 py-3 <?php echo ($activeRun && (int)$activeRun['progress'] > 0) ? 'bg-green-900/20 border border-green-500/30' : 'bg-gray-900/50 border border-gray-700'; ?>">
                    <div class="font-medium text-white">1. Normal Enemy</div>
                </div>
                <div class="rounded-lg px-4 py-3 <?php echo ($activeRun && (int)$activeRun['progress'] > 1) ? 'bg-green-900/20 border border-green-500/30' : 'bg-gray-900/50 border border-gray-700'; ?>">
                    <div class="font-medium text-white">2. Elite Enemy</div>
                </div>
                <div class="rounded-lg px-4 py-3 <?php echo ($activeRun && (int)$activeRun['progress'] > 2) ? 'bg-green-900/20 border border-green-500/30' : 'bg-gray-900/50 border border-gray-700'; ?>">
                    <div class="font-medium text-white">3. Boss</div>
                </div>
            </div>
        </div>

        <?php if ($battleResult && !empty($battleResult['battle'])): ?>
            <?php $battle = $battleResult['battle']; ?>
            <div class="bg-gray-800/90 backdrop-blur border border-red-500/30 rounded-xl p-6 mb-6">
                <h2 class="text-xl font-semibold text-red-300 mb-3"><?php echo htmlspecialchars($battleResult['stage_name'] ?? 'Battle', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-gray-300 mb-3">Winner: <span class="text-white"><?php echo htmlspecialchars($battle['winner'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></p>
                <div class="space-y-1 text-sm">
                    <?php foreach (($battle['battle_log'] ?? []) as $row): ?>
                        <div class="text-gray-300">
                            <?php echo htmlspecialchars(($row['attacker'] ?? '') === 'user' ? 'You' : ($battle['npc_name'] ?? 'Enemy'), ENT_QUOTES, 'UTF-8'); ?>
                            dealt <?php echo (int)($row['damage'] ?? 0); ?> damage.
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($battleResult['rewards'])): ?>
                    <div class="mt-4 text-amber-300">Rewards: <?php echo (int)$battleResult['rewards']['gold']; ?> gold, <?php echo (int)$battleResult['rewards']['spirit_stones']; ?> spirit stones.</div>
                    <?php if (!empty($battleResult['rewards']['manual'])): ?>
                        <div class="mt-2 text-violet-300">
                            Manual found: <?php echo htmlspecialchars((string)$battleResult['rewards']['manual']['name'], ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars(ucfirst((string)$battleResult['rewards']['manual']['rarity']), ENT_QUOTES, 'UTF-8'); ?>)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="bg-gray-800/90 backdrop-blur border border-gray-700 rounded-xl p-6">
            <?php if ($locked): ?>
                <p class="text-amber-300">You do not meet the realm requirement for this dungeon.</p>
            <?php elseif ($activeRun): ?>
                <h2 class="text-xl font-semibold text-purple-300 mb-2"><?php echo htmlspecialchars($stagePreview['label'] ?? 'Next Stage', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-sm text-gray-400 mb-4">Enemy: <?php echo htmlspecialchars($stagePreview['enemy_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <form method="POST">
                    <input type="hidden" name="dungeon_id" value="<?php echo $dungeonId; ?>">
                    <input type="hidden" name="run_id" value="<?php echo (int)$activeRun['id']; ?>">
                    <input type="hidden" name="action" value="advance_run">
                    <button type="submit" class="px-5 py-3 bg-purple-600 hover:bg-purple-500 text-white font-semibold rounded-lg">Challenge Stage</button>
                </form>
            <?php elseif ($runsRemaining > 0): ?>
                <h2 class="text-xl font-semibold text-purple-300 mb-2">Begin a new run</h2>
                <p class="text-sm text-gray-400 mb-4">Three stages await inside this hidden dungeon.</p>
                <form method="POST">
                    <input type="hidden" name="dungeon_id" value="<?php echo $dungeonId; ?>">
                    <input type="hidden" name="action" value="start_run">
                    <button type="submit" class="px-5 py-3 bg-purple-600 hover:bg-purple-500 text-white font-semibold rounded-lg">Start Dungeon Run</button>
                </form>
            <?php else: ?>
                <p class="text-amber-300">You have used all dungeon runs for today.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>




