<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Service/RankingService.php';

use Game\Config\Database;
use Game\Service\RankingService;

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

$userId = (int)$_SESSION['user_id'];
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$rankingService = new RankingService();
$leaderboard = $rankingService->getLeaderboard($limit, $offset);
$userRank = $rankingService->getUserRank($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent">
                🏆 Leaderboard
            </h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to Dashboard
            </a>
        </div>

        <!-- User's Rank Highlight -->
        <div class="bg-gradient-to-r from-cyan-500/20 to-blue-500/20 border-2 border-cyan-500/50 rounded-xl p-4 mb-8">
            <div class="text-center">
                <div class="text-lg text-cyan-300 font-semibold">Your Rank</div>
                <div class="text-3xl font-bold text-white">#<?php echo $userRank; ?></div>
            </div>
        </div>

        <!-- Leaderboard Table -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-900/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-cyan-300">Rank</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-cyan-300">Player</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-cyan-300">Realm</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-cyan-300">Level</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-cyan-300">Rating</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-cyan-300">Record</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard as $index => $player): ?>
                        <?php $rank = $offset + $index + 1; ?>
                        <?php $isCurrentUser = (int)$player['id'] === $userId; ?>
                        <tr class="<?php echo $isCurrentUser ? 'bg-cyan-500/10' : ''; ?> border-t border-gray-700 hover:bg-gray-700/30 transition-colors">
                            <td class="px-6 py-4">
                                <span class="font-bold <?php echo $rank <= 3 ? 'text-yellow-400' : 'text-gray-300'; ?>">
                                    <?php echo $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank - 1] : '#' . $rank; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-semibold <?php echo $isCurrentUser ? 'text-cyan-300' : 'text-white'; ?>">
                                    <?php echo htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-300"><?php echo htmlspecialchars($player['realm_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-6 py-4 text-gray-300"><?php echo $player['level']; ?></td>
                            <td class="px-6 py-4 text-right font-bold text-yellow-300"><?php echo number_format((float)$player['rating'], 0); ?></td>
                            <td class="px-6 py-4 text-right text-gray-300">
                                <?php echo $player['wins']; ?>W - <?php echo $player['losses']; ?>L
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($page > 1 || count($leaderboard) === $limit): ?>
            <div class="mt-6 flex justify-center gap-4">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                        ← Previous
                    </a>
                <?php endif; ?>
                <?php if (count($leaderboard) === $limit): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                        Next →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
