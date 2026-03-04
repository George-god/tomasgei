<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Service/EraService.php';

use Game\Config\Database;
use Game\Service\EraService;

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

$eraId = (int)($_GET['id'] ?? 0);
if ($eraId === 0) {
    header('Location: hall_of_legends.php');
    exit;
}

$eraService = new EraService();
$era = $eraService->getEra($eraId);
$playerRankings = $eraService->getEraRankings($eraId, 100);
$sectRankings = $eraService->getEraSectRankings($eraId, 50);

if (!$era) {
    header('Location: hall_of_legends.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($era['name'], ENT_QUOTES, 'UTF-8'); ?> - Hall of Legends</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-yellow-400 to-orange-400 bg-clip-text text-transparent">
                    <?php echo htmlspecialchars($era['name'], ENT_QUOTES, 'UTF-8'); ?>
                </h1>
                <p class="text-gray-400 mt-2">
                    <?php echo date('M j, Y', strtotime($era['start_date'])); ?> - 
                    <?php echo date('M j, Y', strtotime($era['end_date'])); ?>
                </p>
            </div>
            <a href="hall_of_legends.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to Hall of Legends
            </a>
        </div>

        <?php if ($era['description']): ?>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6 mb-8">
                <p class="text-gray-300"><?php echo htmlspecialchars($era['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Player Rankings -->
            <div>
                <h2 class="text-2xl font-semibold text-cyan-300 mb-4">Top Players</h2>
                <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-cyan-300">Rank</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-cyan-300">Player</th>
                                <th class="px-4 py-3 text-right text-sm font-semibold text-cyan-300">Rating</th>
                                <th class="px-4 py-3 text-right text-sm font-semibold text-cyan-300">Record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($playerRankings as $player): ?>
                                <tr class="border-t border-gray-700 hover:bg-gray-700/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <span class="font-bold <?php echo $player['rank_position'] <= 3 ? 'text-yellow-400' : 'text-gray-300'; ?>">
                                            <?php echo $player['rank_position'] <= 3 ? ['🥇', '🥈', '🥉'][$player['rank_position'] - 1] : '#' . $player['rank_position']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-white"><?php echo htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-yellow-300"><?php echo number_format((float)$player['final_rating'], 0); ?></td>
                                    <td class="px-4 py-3 text-right text-gray-300"><?php echo $player['wins']; ?>W - <?php echo $player['losses']; ?>L</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sect Rankings -->
            <div>
                <h2 class="text-2xl font-semibold text-purple-300 mb-4">Top Sects</h2>
                <div class="bg-gray-800/90 backdrop-blur-lg border border-purple-500/30 rounded-xl overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-purple-300">Rank</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-purple-300">Sect</th>
                                <th class="px-4 py-3 text-right text-sm font-semibold text-purple-300">Rating</th>
                                <th class="px-4 py-3 text-right text-sm font-semibold text-purple-300">Territories</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sectRankings as $sect): ?>
                                <tr class="border-t border-gray-700 hover:bg-gray-700/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <span class="font-bold <?php echo $sect['rank_position'] <= 3 ? 'text-yellow-400' : 'text-gray-300'; ?>">
                                            <?php echo $sect['rank_position'] <= 3 ? ['🥇', '🥈', '🥉'][$sect['rank_position'] - 1] : '#' . $sect['rank_position']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-white"><?php echo htmlspecialchars($sect['sect_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-purple-300"><?php echo number_format((float)$sect['final_rating'], 0); ?></td>
                                    <td class="px-4 py-3 text-right text-gray-300"><?php echo $sect['territories_controlled']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
