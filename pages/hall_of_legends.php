<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/services/EraService.php';

use Game\Config\Database;
use Game\Service\EraService;


session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$eraService = new EraService();
$hallOfLegends = $eraService->getHallOfLegends(10);
$allEras = $eraService->getAllEras(20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hall of Legends - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-yellow-400 to-orange-400 bg-clip-text text-transparent">
                ⭐ Hall of Legends
            </h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Top Players by Rating -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-yellow-300 mb-4">🏆 Top Players by Rating (All Eras)</h2>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-yellow-500/30 rounded-xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-900/50">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-yellow-300">Rank</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-yellow-300">Player</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-yellow-300">Era</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-yellow-300">Final Rating</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-yellow-300">Record</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hallOfLegends['top_players_by_rating'] as $index => $player): ?>
                            <tr class="border-t border-gray-700 hover:bg-gray-700/30 transition-colors">
                                <td class="px-6 py-4">
                                    <span class="font-bold <?php echo $index < 3 ? 'text-yellow-400' : 'text-gray-300'; ?>">
                                        <?php echo $index < 3 ? ['🥇', '🥈', '🥉'][$index] : '#' . ($index + 1); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-semibold text-white"><?php echo htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($player['era_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-right font-bold text-yellow-300"><?php echo number_format((float)$player['final_rating'], 0); ?></td>
                                <td class="px-6 py-4 text-right text-gray-300"><?php echo $player['wins']; ?>W - <?php echo $player['losses']; ?>L</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Sects by Rating -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-purple-300 mb-4">👑 Top Sects by Rating (All Eras)</h2>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-purple-500/30 rounded-xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-900/50">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-purple-300">Rank</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-purple-300">Sect</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-purple-300">Era</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-purple-300">Final Rating</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-purple-300">Territories</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hallOfLegends['top_sects_by_rating'] as $index => $sect): ?>
                            <tr class="border-t border-gray-700 hover:bg-gray-700/30 transition-colors">
                                <td class="px-6 py-4">
                                    <span class="font-bold <?php echo $index < 3 ? 'text-yellow-400' : 'text-gray-300'; ?>">
                                        <?php echo $index < 3 ? ['🥇', '🥈', '🥉'][$index] : '#' . ($index + 1); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-semibold text-white"><?php echo htmlspecialchars($sect['sect_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($sect['era_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-right font-bold text-purple-300"><?php echo number_format((float)$sect['final_rating'], 0); ?></td>
                                <td class="px-6 py-4 text-right text-gray-300"><?php echo $sect['territories_controlled']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Era History -->
        <div>
            <h2 class="text-2xl font-semibold text-cyan-300 mb-4">📜 Era History</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($allEras as $era): ?>
                    <a href="era_details.php?id=<?php echo $era['id']; ?>" class="block bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-4 hover:border-cyan-500/50 transition-all">
                        <div class="font-semibold text-white mb-2"><?php echo htmlspecialchars($era['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-sm text-gray-400">
                            <?php echo date('M j, Y', strtotime($era['start_date'])); ?> - 
                            <?php echo date('M j, Y', strtotime($era['end_date'])); ?>
                        </div>
                        <?php if ($era['is_active']): ?>
                            <div class="mt-2 text-xs text-green-400 font-semibold">● Active Era</div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>




