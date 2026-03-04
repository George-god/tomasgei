<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Service/PvEBattleService.php';

use Game\Config\Database;
use Game\Service\PvEBattleService;

Database::setConfig([
    'host' => 'localhost',
    'dbname' => 'cultivation_rpg',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
]);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pveBattleId = (int)($_GET['id'] ?? 0);
if ($pveBattleId === 0) {
    header('Location: npc_arena.php');
    exit;
}

$pveService = new PvEBattleService();
$battle = $pveService->getPveBattle($pveBattleId);
$logs = $pveService->getPveBattleLogs($pveBattleId);

if (!$battle) {
    header('Location: npc_arena.php');
    exit;
}

$username = $battle['username'] ?? 'You';
$npcName = $battle['npc_name'] ?? 'NPC';
$winner = $battle['winner'] ?? 'npc';
$turns = (int)($battle['turns'] ?? 0);
$chiReward = (int)($battle['chi_reward'] ?? 0);

$actionIcons = [
    'attack' => '⚔️',
    'critical_attack' => '💥',
    'dodge' => '💨',
    'lifesteal' => '🩸',
    'counterattack' => '🔄',
    'revival' => '✨'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PvE Battle Replay - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .turn-log {
            animation: fadeIn 0.5s ease-out;
        }
        @keyframes damage {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); color: #ef4444; }
        }
        .damage-number {
            animation: damage 0.3s ease-out;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-400 to-orange-400 bg-clip-text text-transparent">
                👹 PvE Battle Replay
            </h1>
            <a href="npc_arena.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to NPC Arena
            </a>
        </div>

        <!-- Battle Summary -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <div class="text-sm text-gray-400 mb-1">You</div>
                    <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="text-center">
                    <div class="text-sm text-gray-400 mb-1">Winner</div>
                    <div class="text-2xl font-bold <?php echo $winner === 'user' ? 'text-green-400' : 'text-red-400'; ?>">
                        <?php echo $winner === 'user' ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : htmlspecialchars($npcName, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="text-sm text-gray-400 mt-1"><?php echo $turns; ?> turns</div>
                    <?php if ($winner === 'user' && $chiReward > 0): ?>
                        <div class="text-sm text-green-400 mt-1">+<?php echo number_format($chiReward); ?> Chi</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-sm text-gray-400 mb-1">Enemy</div>
                    <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars($npcName, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>

        <!-- Battle Logs -->
        <div class="space-y-4">
            <?php
            $currentTurn = 0;
            foreach ($logs as $log):
                if ((int)$log['turn_number'] != $currentTurn):
                    $currentTurn = (int)$log['turn_number'];
            ?>
                <div class="text-center text-xl font-bold text-cyan-300 my-6">Turn <?php echo $currentTurn; ?></div>
            <?php endif;
                $attackerName = isset($log['attacker_id']) && $log['attacker_id'] !== null
                    ? $username
                    : $npcName;
            ?>
            <div class="turn-log bg-gray-800/90 backdrop-blur-lg border border-gray-700 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-semibold text-white">
                            <?php echo htmlspecialchars($attackerName, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="text-sm text-gray-400 mt-1">
                            <?php
                            $actionType = $log['action_type'] ?? 'attack';
                            echo $actionIcons[$actionType] ?? '⚔️';
                            ?>
                            <span class="capitalize"><?php echo str_replace('_', ' ', $actionType); ?></span>
                            <?php if (!empty($log['is_critical'])): ?>
                                <span class="text-yellow-400 font-bold">CRITICAL!</span>
                            <?php endif; ?>
                            <?php if (!empty($log['is_dodge'])): ?>
                                <span class="text-blue-400 font-bold">DODGED!</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <?php if (!empty($log['damage_dealt']) && (int)$log['damage_dealt'] > 0): ?>
                            <div class="damage-number text-2xl font-bold text-red-400">
                                -<?php echo number_format((int)$log['damage_dealt']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="text-sm text-gray-400 mt-1">
                            HP/Chi: <?php echo number_format((int)($log['defender_chi_after'] ?? 0)); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
