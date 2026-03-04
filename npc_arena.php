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

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
    header('Location: login.php');
    exit;
}

$pveService = new PvEBattleService();
$npcs = $pveService->getAllNpcs();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NPC Arena - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-400 to-orange-400 bg-clip-text text-transparent">
                👹 NPC Arena
            </h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <p class="text-gray-400 mb-6">Fight NPCs for Chi. No rating changes; PvE never reduces your Chi on defeat.</p>

        <!-- NPC list -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-amber-300 mb-4">Available Enemies</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="npc-list">
                <?php foreach ($npcs as $npc): ?>
                    <div class="bg-gray-900/80 border border-gray-700 rounded-lg p-4">
                        <div class="font-semibold text-white text-lg"><?php echo htmlspecialchars($npc['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-sm text-gray-400 mt-1">Level <?php echo (int)$npc['level']; ?></div>
                        <div class="grid grid-cols-2 gap-2 text-sm mt-3 text-gray-300">
                            <span>HP: <?php echo number_format((int)$npc['base_hp']); ?></span>
                            <span>ATK: <?php echo number_format((int)$npc['base_attack']); ?></span>
                            <span>DEF: <?php echo number_format((int)$npc['base_defense']); ?></span>
                            <span>Reward: +<?php echo number_format((int)$npc['reward_chi']); ?> Chi</span>
                        </div>
                        <button type="button" class="pve-fight-btn mt-4 w-full py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                            data-npc-id="<?php echo (int)$npc['id']; ?>"
                            data-npc-name="<?php echo htmlspecialchars($npc['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-npc-hp="<?php echo (int)$npc['base_hp']; ?>">
                            Fight
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($npcs)): ?>
                <p class="text-gray-400">No NPCs available.</p>
            <?php endif; ?>
        </div>

        <!-- Battle panel (shown when fighting) -->
        <div id="battle-panel" class="bg-gray-800/90 backdrop-blur-lg border border-gray-700 rounded-xl p-6 hidden">
            <h2 class="text-xl font-semibold text-cyan-300 mb-4">Battle: <span id="battle-npc-name"></span></h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <div class="text-sm text-gray-400 mb-1">You (Chi)</div>
                    <div class="w-full bg-gray-900 rounded-full h-4 overflow-hidden border border-gray-700">
                        <div id="battle-user-bar" class="h-full bg-cyan-500 transition-all duration-300" style="width: 100%"></div>
                    </div>
                    <div id="battle-user-text" class="text-xs text-gray-400 mt-1">— / —</div>
                </div>
                <div>
                    <div class="text-sm text-gray-400 mb-1"><span id="battle-npc-label"></span> (HP)</div>
                    <div class="w-full bg-gray-900 rounded-full h-4 overflow-hidden border border-gray-700">
                        <div id="battle-npc-bar" class="h-full bg-amber-500 transition-all duration-300" style="width: 100%"></div>
                    </div>
                    <div id="battle-npc-text" class="text-xs text-gray-400 mt-1">— / —</div>
                </div>
            </div>
            <div id="battle-log" class="space-y-2 max-h-64 overflow-y-auto mb-4 text-sm"></div>
            <div id="battle-result" class="text-lg font-semibold hidden"></div>
            <div id="battle-chi-display" class="text-cyan-300 mt-2 hidden">Chi: <span id="battle-chi-value"></span></div>
        </div>
    </div>
    <script src="pve.js"></script>
</body>
</html>
