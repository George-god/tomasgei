<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Service/TerritoryService.php';

use Game\Config\Database;
use Game\Service\TerritoryService;

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

$territoryService = new TerritoryService();
$territoryMap = $territoryService->getTerritoryMap();
$statistics = $territoryService->getTerritoryStatistics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Territory Map - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-green-400 to-emerald-400 bg-clip-text text-transparent">
                🗺️ Territory Map
            </h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-800/90 backdrop-blur-lg border border-green-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Total Territories</div>
                <div class="text-2xl font-bold text-green-300"><?php echo $statistics['total_territories']; ?></div>
            </div>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Controlled</div>
                <div class="text-2xl font-bold text-cyan-300"><?php echo $statistics['controlled_territories']; ?></div>
            </div>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-gray-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Neutral</div>
                <div class="text-2xl font-bold text-gray-300"><?php echo $statistics['neutral_territories']; ?></div>
            </div>
            <div class="bg-gray-800/90 backdrop-blur-lg border border-yellow-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Control Rate</div>
                <div class="text-2xl font-bold text-yellow-300"><?php echo number_format($statistics['control_percentage'], 1); ?>%</div>
            </div>
        </div>

        <!-- Territory Map by Realm -->
        <div class="space-y-8">
            <?php foreach ($territoryMap as $realmData): ?>
                <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-2xl font-semibold text-cyan-300">
                            <?php echo htmlspecialchars($realmData['realm']['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                        <div class="text-sm text-gray-400">
                            <?php echo $realmData['controlled_territories']; ?> / <?php echo $realmData['total_territories']; ?> controlled
                        </div>
                    </div>

                    <!-- Global Influence -->
                    <div class="mb-4 p-3 bg-gray-900/50 rounded-lg">
                        <div class="text-sm text-gray-400 mb-1">Global Realm Influence</div>
                        <div class="flex items-center gap-4">
                            <div class="flex-1">
                                <div class="w-full bg-gray-800 rounded-full h-3 mb-2">
                                    <div class="h-full bg-gradient-to-r from-green-500 to-emerald-500 rounded-full transition-all" 
                                         style="width: <?php echo $realmData['global_influence']['influence_percentage']; ?>%"></div>
                                </div>
                                <div class="text-xs text-gray-400"><?php echo number_format($realmData['global_influence']['influence_percentage'], 1); ?>% Influence</div>
                            </div>
                            <div class="text-right text-xs">
                                <div class="text-green-400">+<?php echo number_format($realmData['global_influence']['stat_modifier'], 1); ?>% Stats</div>
                                <div class="text-blue-400">+<?php echo number_format($realmData['global_influence']['cultivation_modifier'], 1); ?>% Cultivation</div>
                            </div>
                        </div>
                    </div>

                    <!-- Territories Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($realmData['territories'] as $territory): ?>
                            <div class="bg-gray-900/50 border <?php echo $territory['sect_id'] ? 'border-green-500/30' : 'border-gray-700'; ?> rounded-lg p-4">
                                <div class="font-semibold text-white mb-2">
                                    <?php echo htmlspecialchars($territory['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if ($territory['sect_id']): ?>
                                    <div class="text-sm text-green-400 mb-1">
                                        Controlled by: <?php echo htmlspecialchars($territory['sect_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        +<?php echo number_format((float)$territory['stat_bonus_percentage'], 1); ?>% Stats
                                        +<?php echo number_format((float)$territory['cultivation_bonus_percentage'], 1); ?>% Cultivation
                                    </div>
                                <?php else: ?>
                                    <div class="text-sm text-gray-400">Neutral Territory</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
