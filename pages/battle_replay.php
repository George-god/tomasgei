<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/services/BattleService.php';

use Game\Config\Database;
use Game\Service\BattleService;


session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$battleId = (int)($_GET['id'] ?? 0);
if ($battleId === 0) {
    header('Location: battles.php');
    exit;
}

$battleService = new BattleService();
$battle = $battleService->getBattle($battleId);
$logs = $battleService->getBattleLogs($battleId);

$formatActionLabel = static function (string $actionType): string {
    return ucwords(str_replace('_', ' ', $actionType));
};

if (!$battle) {
    header('Location: battles.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battle Replay - Cultivation Journey</title>
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
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-red-400 to-orange-400 bg-clip-text text-transparent">
                    ⚔️ Battle Replay
                </h1>
            </div>
            <a href="battles.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to Battles
            </a>
        </div>

        <!-- Battle Summary -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <div class="text-sm text-gray-400 mb-1">Attacker</div>
                    <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars($battle['attacker_username'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-sm text-gray-400">Rating: <?php echo number_format((float)$battle['attacker_rating_before'], 0); ?> → <?php echo number_format((float)$battle['attacker_rating_after'], 0); ?></div>
                </div>
                <div class="text-center">
                    <div class="text-sm text-gray-400 mb-1">Winner</div>
                    <div class="text-2xl font-bold <?php echo (int)$battle['winner_id'] === (int)$battle['attacker_id'] ? 'text-green-400' : 'text-red-400'; ?>">
                        <?php echo htmlspecialchars($battle['winner_username'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="text-sm text-gray-400 mt-1"><?php echo $battle['turns']; ?> turns</div>
                </div>
                <div>
                    <div class="text-sm text-gray-400 mb-1">Defender</div>
                    <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars($battle['defender_username'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-sm text-gray-400">Rating: <?php echo number_format((float)$battle['defender_rating_before'], 0); ?> → <?php echo number_format((float)$battle['defender_rating_after'], 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Battle Logs -->
        <div class="space-y-4">
            <?php
            $currentTurn = 0;
            foreach ($logs as $log):
                if ($log['turn_number'] != $currentTurn):
                    $currentTurn = $log['turn_number'];
            ?>
                <div class="text-center text-xl font-bold text-cyan-300 my-6">Turn <?php echo $currentTurn; ?></div>
            <?php endif; ?>
            
            <div class="turn-log bg-gray-800/90 backdrop-blur-lg border border-gray-700 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-semibold text-white">
                            <?php echo htmlspecialchars($battle['attacker_id'] == $log['attacker_id'] ? $battle['attacker_username'] : $battle['defender_username'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="text-sm text-gray-400 mt-1">
                            <?php
                            $actionType = $log['action_type'];
                            $icons = [
                                'attack' => '⚔️',
                                'critical_attack' => '💥',
                                'dodge' => '💨',
                                'lifesteal' => '🩸',
                                'counterattack' => '🔄',
                                'revival' => '✨'
                            ];
                            echo $icons[$actionType] ?? '⚔️';
                            ?>
                            <span><?php echo htmlspecialchars($formatActionLabel((string)$actionType), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($log['is_critical']): ?>
                                <span class="text-yellow-400 font-bold">CRITICAL!</span>
                            <?php endif; ?>
                            <?php if ($log['is_dodge']): ?>
                                <span class="text-blue-400 font-bold">DODGED!</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <?php if ($log['damage_dealt'] > 0): ?>
                            <div class="damage-number text-2xl font-bold text-red-400">
                                -<?php echo number_format($log['damage_dealt']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="text-sm text-gray-400 mt-1">
                            Chi: <?php echo number_format($log['defender_chi_after']); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>




