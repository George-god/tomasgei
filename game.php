<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Service/StatCalculator.php';
require_once __DIR__ . '/classes/Service/CultivationService.php';
require_once __DIR__ . '/classes/Service/BreakthroughService.php';
require_once __DIR__ . '/classes/Service/NotificationService.php';
require_once __DIR__ . '/classes/Service/PvpStaminaService.php';
require_once __DIR__ . '/classes/Service/RealmService.php';
require_once __DIR__ . '/classes/Service/EventService.php';

use Game\Config\Database;
use Game\Service\StatCalculator;
use Game\Service\CultivationService;
use Game\Service\BreakthroughService;
use Game\Service\NotificationService;
use Game\Service\PvpStaminaService;
use Game\Service\RealmService;
use Game\Service\EventService;

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Handle breakthrough attempt (with optional pill)
$breakthroughResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'breakthrough') {
    $breakthroughService = new BreakthroughService();
    $pillId = !empty($_POST['pill_inventory_id']) ? (int)$_POST['pill_inventory_id'] : null;
    $breakthroughResult = $breakthroughService->attemptBreakthrough($userId, $pillId);
    if ($breakthroughResult['success']) {
        header('Location: game.php?breakthrough=1');
        exit;
    }
}

// Fetch user data from database (server-authoritative)
try {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT u.*, r.name AS realm_name, r.description AS realm_description FROM users u LEFT JOIN realms r ON u.realm_id = r.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
    
    if (!$userData) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    $username = $userData['username'] ?? 'Cultivator';
    $realmName = $userData['realm_name'] ?? 'Qi Refining';
    $realmDescription = isset($userData['realm_description']) ? (string)$userData['realm_description'] : '';
    $realmId = (int)($userData['realm_id'] ?? 1);
    $level = (int)($userData['level'] ?? 1);

    $realmService = new RealmService();
    $realmBreakthrough = $realmService->getBreakthroughAvailable($userId);
    $nextRequiredLevel = $realmBreakthrough['next_realm']['required_level'] ?? null;
    $realmProgressPercent = $nextRequiredLevel > 0 ? min(100, (int)round(100 * $level / $nextRequiredLevel)) : 100;

    $chi = (int)($userData['chi'] ?? 100);
    $maxChi = (int)($userData['max_chi'] ?? 100);
    $attack = (int)($userData['attack'] ?? 10);
    $defense = (int)($userData['defense'] ?? 10);
    $wins = (int)($userData['wins'] ?? 0);
    $losses = (int)($userData['losses'] ?? 0);
    $rating = (float)($userData['rating'] ?? 1000.0);
    $gold = (int)($userData['gold'] ?? 0);
    $spiritStones = (int)($userData['spirit_stones'] ?? 0);

    // Get final stats using StatCalculator
    $statCalculator = new StatCalculator();
    $finalStats = $statCalculator->calculateFinalStats($userId);
    
    // Get cultivation cooldown status
    $cultivationService = new CultivationService();
    $cooldownStatus = $cultivationService->getCooldownStatus($userId);
    $cultivationEfficiency = $cultivationService->getCultivationEfficiency($userId);
    
    // Get breakthrough status
    $breakthroughService = new BreakthroughService();
    $breakthroughStatus = $breakthroughService->getBreakthroughStatus($userId);
    
    // Get unread notifications count
    $notificationService = new NotificationService();
    $unreadCount = $notificationService->getUnreadCount($userId);

    // PvP stamina (regen on load, then display)
    $pvpStaminaService = new PvpStaminaService();
    $pvpStaminaData = $pvpStaminaService->getStamina($userId);
    $pvpStamina = $pvpStaminaData['stamina'];
    $pvpStaminaMax = $pvpStaminaData['max_stamina'];
    $canPvP = $pvpStaminaData['can_fight'];

    // Phase 3: active world event and announcements (syncs on load)
    $eventService = new EventService();
    $activeWorldEvent = $eventService->getActiveEvent();
    $activeAnnouncements = $eventService->getActiveAnnouncements();

} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $username = $_SESSION['username'] ?? 'Cultivator';
    $realmName = 'Qi Refining';
    $realmDescription = '';
    $realmId = 1;
    $level = $_SESSION['level'] ?? 1;
    $realmBreakthrough = ['available' => false, 'next_realm' => null];
    $nextRequiredLevel = null;
    $realmProgressPercent = 100;
    $chi = 100;
    $maxChi = 100;
    $attack = 10;
    $defense = 10;
    $wins = 0;
    $losses = 0;
    $rating = 1000.0;
    $gold = 0;
    $spiritStones = 0;
    $finalStats = ['final' => ['attack' => 10, 'defense' => 10, 'chi' => 100, 'max_chi' => 100]];
    $cooldownStatus = ['can_cultivate' => true, 'cooldown_remaining' => 0];
    $cultivationEfficiency = ['min_gain' => 10, 'max_gain' => 50, 'average_gain' => 30];
    $breakthroughStatus = ['can_attempt' => false];
    $unreadCount = 0;
    $pvpStamina = 5;
    $pvpStaminaMax = 5;
    $canPvP = true;
    $activeWorldEvent = null;
    $activeAnnouncements = [];
}

$chiPercentage = $maxChi > 0 ? ($chi / $maxChi) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(100, 200, 255, 0.3); }
            50% { box-shadow: 0 0 30px rgba(100, 200, 255, 0.6); }
        }
        .realm-badge { animation: pulse-glow 3s ease-in-out infinite; }
        .realm-badge-1 { background: linear-gradient(90deg, rgba(34,211,238,0.2), rgba(59,130,246,0.2)); border-color: rgba(34,211,238,0.5); color: rgb(103 232 249); }
        .realm-badge-2 { background: linear-gradient(90deg, rgba(168,85,247,0.2), rgba(139,92,246,0.2)); border-color: rgba(168,85,247,0.5); color: rgb(216 180 254); }
        .realm-badge-3 { background: linear-gradient(90deg, rgba(251,146,60,0.2), rgba(249,115,22,0.2)); border-color: rgba(251,146,60,0.5); color: rgb(253 186 116); }
        .realm-badge-4 { background: linear-gradient(90deg, rgba(236,72,153,0.2), rgba(219,39,119,0.2)); border-color: rgba(236,72,153,0.5); color: rgb(244 114 182); }
        .realm-badge-5 { background: linear-gradient(90deg, rgba(250,204,21,0.2), rgba(234,179,8,0.2)); border-color: rgba(250,204,21,0.5); color: rgb(253 224 71); }
        @keyframes progress-fill { from { width: 0%; } }
        .chi-progress { animation: progress-fill 1s ease-out; }

        /* Realm-based aura (lightweight) */
        .player-card-aura { transition: box-shadow 0.4s ease; }
        body.realm-1 .player-card-aura { box-shadow: 0 0 40px rgba(34, 211, 238, 0.15); }
        body.realm-2 .player-card-aura { box-shadow: 0 0 40px rgba(168, 85, 247, 0.15); }
        body.realm-3 .player-card-aura { box-shadow: 0 0 40px rgba(251, 146, 60, 0.15); }
        body.realm-4 .player-card-aura { box-shadow: 0 0 40px rgba(236, 72, 153, 0.15); }
        body.realm-5 .player-card-aura { box-shadow: 0 0 40px rgba(250, 204, 21, 0.18); }

        /* Realm progress bar highlight when breakthrough available */
        .realm-progress-wrap.breakthrough-available .realm-progress-fill { animation: realm-ready-pulse 2s ease-in-out infinite; }
        @keyframes realm-ready-pulse {
            0%, 100% { opacity: 1; filter: brightness(1); }
            50% { opacity: 0.95; filter: brightness(1.15); }
        }

        /* Breakthrough toast animation (subtle) */
        @keyframes breakthrough-success-in {
            0% { opacity: 0; transform: scale(0.96); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes breakthrough-failure-shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }
        .breakthrough-toast.success { animation: breakthrough-success-in 0.4s ease-out; }
        .breakthrough-toast.failure { animation: breakthrough-failure-shake 0.35s ease-out; }

        /* Subtle background accent by realm */
        body.realm-1 .realm-bg-accent .realm-blur-1 { background: rgba(34, 211, 238, 0.08); }
        body.realm-1 .realm-bg-accent .realm-blur-2 { background: rgba(59, 130, 246, 0.08); }
        body.realm-2 .realm-bg-accent .realm-blur-1 { background: rgba(168, 85, 247, 0.08); }
        body.realm-2 .realm-bg-accent .realm-blur-2 { background: rgba(139, 92, 246, 0.08); }
        body.realm-3 .realm-bg-accent .realm-blur-1 { background: rgba(251, 146, 60, 0.08); }
        body.realm-3 .realm-bg-accent .realm-blur-2 { background: rgba(249, 115, 22, 0.08); }
        body.realm-4 .realm-bg-accent .realm-blur-1 { background: rgba(236, 72, 153, 0.08); }
        body.realm-4 .realm-bg-accent .realm-blur-2 { background: rgba(219, 39, 119, 0.08); }
        body.realm-5 .realm-bg-accent .realm-blur-1 { background: rgba(250, 204, 21, 0.1); }
        body.realm-5 .realm-bg-accent .realm-blur-2 { background: rgba(234, 179, 8, 0.1); }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen realm-<?php echo (int)$realmId; ?>">
    <!-- Background accent by realm (subtle) -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none realm-bg-accent" aria-hidden="true">
        <div class="realm-blur-1 absolute top-1/4 left-1/4 w-96 h-96 rounded-full blur-3xl animate-pulse"></div>
        <div class="realm-blur-2 absolute bottom-1/4 right-1/4 w-96 h-96 rounded-full blur-3xl animate-pulse" style="animation-delay: 1s;"></div>
    </div>

    <div class="relative z-10 container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent">
                    🌌 Cultivation Journey
                </h1>
                <p class="text-gray-400 mt-1">Welcome back, <span class="text-cyan-300 font-semibold"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span></p>
            </div>
            <div class="flex gap-4">
                <a href="notifications.php" class="relative px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 transition-all">
                    🔔 Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="logout.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-red-500/30 text-red-300 transition-all">Logout</a>
            </div>
        </div>

        <?php if ($activeWorldEvent !== null || !empty($activeAnnouncements)): ?>
        <!-- Phase 3: active world event and announcements -->
        <div class="mb-6 space-y-2">
            <?php if ($activeWorldEvent !== null): ?>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-center">
                <span class="text-amber-300 font-semibold">⚡ World event:</span>
                <span class="text-white"><?php echo htmlspecialchars($activeWorldEvent, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($activeAnnouncements as $ann): ?>
            <div class="rounded-xl border border-cyan-500/30 bg-cyan-500/5 px-4 py-2 text-sm text-gray-200">
                <?php echo nl2br(htmlspecialchars($ann['message'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Player card (realm badge + chi + stamina + stats) with aura -->
        <div class="player-card-aura rounded-2xl border border-gray-700/50 bg-gray-900/20 p-6 mb-8">
        <!-- Realm Badge: name only (no ID), dynamic class by realm_id -->
        <div class="mb-6 flex justify-center">
            <div class="realm-badge realm-badge-<?php echo (int)$realmId; ?> border-2 rounded-xl px-8 py-4">
                <div class="text-center">
                    <div class="text-2xl font-bold mb-1"><?php echo htmlspecialchars($realmName, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($realmDescription !== ''): ?>
                        <p class="text-sm text-gray-400 max-w-md mt-1"><?php echo htmlspecialchars($realmDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <div class="text-sm text-gray-500 mt-2">Level <span id="stat-level"><?php echo $level; ?></span></div>
                </div>
            </div>
        </div>

        <!-- Realm progress bar: required level, highlight when breakthrough available -->
        <div class="mb-6 realm-progress-wrap <?php echo !empty($realmBreakthrough['available']) ? 'breakthrough-available' : ''; ?>">
            <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-4">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-400">Progress to next realm</span>
                    <?php if ($nextRequiredLevel !== null): ?>
                        <span class="text-sm font-medium text-gray-300">Level <span id="realm-current-level"><?php echo $level; ?></span> / <?php echo $nextRequiredLevel; ?> required</span>
                    <?php else: ?>
                        <span class="text-sm text-amber-400">Max realm</span>
                    <?php endif; ?>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-2 overflow-hidden border border-gray-700">
                    <div class="realm-progress-fill h-full bg-gradient-to-r from-cyan-500 to-purple-500 transition-all duration-500 rounded-full" style="width: <?php echo $realmProgressPercent; ?>%"></div>
                </div>
                <?php if (!empty($realmBreakthrough['available'])): ?>
                    <div class="text-xs text-green-400 mt-1 text-right">Breakthrough available</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['breakthrough'])): ?>
        <div class="breakthrough-toast success mb-6 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300 text-sm">Breakthrough successful. You have ascended to a higher realm.</div>
        <?php endif; ?>
        <?php if (!empty($breakthroughResult) && !$breakthroughResult['success']): ?>
        <div class="breakthrough-toast failure mb-6 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300 text-sm"><?php echo htmlspecialchars($breakthroughResult['error'] ?? 'Breakthrough failed.', ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- Chi Progress Bar -->
        <div class="mb-8">
            <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-lg font-semibold text-cyan-300">Spiritual Energy (Chi)</span>
                    <span id="chi-value" class="text-lg font-bold text-white"><?php echo number_format($chi); ?> / <?php echo number_format($maxChi); ?></span>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-6 overflow-hidden border border-gray-700">
                    <div id="chi-bar-fill" class="chi-progress h-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300" 
                         style="width: <?php echo $chiPercentage; ?>%">
                        <div class="h-full bg-gradient-to-r from-transparent via-white/20 to-transparent animate-pulse"></div>
                    </div>
                </div>
                <div class="text-xs text-gray-400 mt-2 text-right"><span id="chi-percent"><?php echo number_format($chiPercentage, 1); ?>%</span></div>
            </div>
        </div>

        <!-- PvP Stamina -->
        <div class="mb-8">
            <div class="bg-gray-800/90 backdrop-blur-lg border border-red-500/30 rounded-xl p-4">
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-red-300">PvP Stamina</span>
                    <span class="text-lg font-bold text-white"><?php echo $pvpStamina; ?> / <?php echo $pvpStaminaMax; ?></span>
                </div>
                <div class="w-full bg-gray-900 rounded-full h-3 mt-2 overflow-hidden border border-gray-700">
                    <div class="h-full bg-gradient-to-r from-red-500 to-orange-500 transition-all duration-300" style="width: <?php echo $pvpStaminaMax > 0 ? round($pvpStamina / $pvpStaminaMax * 100) : 0; ?>%"></div>
                </div>
                <div class="text-xs text-gray-400 mt-1">Regenerates 1 every 30 minutes</div>
            </div>
        </div>

        <!-- Stat Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Attack Card -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6 hover:border-cyan-500/50 transition-all">
                <div class="text-gray-400 text-sm mb-2">Attack Power</div>
                <div id="stat-attack" class="text-3xl font-bold text-cyan-300 mb-1"><?php echo number_format($finalStats['final']['attack']); ?></div>
                <?php if ($finalStats['final']['attack'] > $attack): ?>
                    <div class="text-xs text-green-400">+<?php echo number_format($finalStats['final']['attack'] - $attack); ?> from modifiers</div>
                <?php endif; ?>
            </div>

            <!-- Defense Card -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-blue-500/30 rounded-xl p-6 hover:border-blue-500/50 transition-all">
                <div class="text-gray-400 text-sm mb-2">Defense Power</div>
                <div id="stat-defense" class="text-3xl font-bold text-blue-300 mb-1"><?php echo number_format($finalStats['final']['defense']); ?></div>
                <?php if ($finalStats['final']['defense'] > $defense): ?>
                    <div class="text-xs text-green-400">+<?php echo number_format($finalStats['final']['defense'] - $defense); ?> from modifiers</div>
                <?php endif; ?>
            </div>

            <!-- Rating Card -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-yellow-500/30 rounded-xl p-6 hover:border-yellow-500/50 transition-all">
                <div class="text-gray-400 text-sm mb-2">ELO Rating</div>
                <div class="text-3xl font-bold text-yellow-300 mb-1"><?php echo number_format($rating, 0); ?></div>
                <div class="text-xs text-gray-400">Rank: #<?php echo number_format($rating, 0); ?></div>
            </div>

            <!-- Currency Card -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-6 hover:border-amber-500/50 transition-all">
                <div class="text-gray-400 text-sm mb-2">Currency</div>
                <div class="text-2xl font-bold text-amber-300 mb-1"><?php echo number_format(max(0, $gold)); ?> Gold</div>
                <div class="text-lg font-semibold text-indigo-300"><?php echo number_format(max(0, $spiritStones)); ?> Spirit Stones</div>
            </div>

            <!-- Battle Record Card -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-purple-500/30 rounded-xl p-6 hover:border-purple-500/50 transition-all">
                <div class="text-gray-400 text-sm mb-2">Battle Record</div>
                <div class="text-2xl font-bold text-purple-300 mb-1"><?php echo $wins; ?>W - <?php echo $losses; ?>L</div>
                <?php $totalBattles = $wins + $losses; ?>
                <?php if ($totalBattles > 0): ?>
                    <div class="text-xs text-gray-400"><?php echo number_format(($wins / $totalBattles) * 100, 1); ?>% Win Rate</div>
                <?php else: ?>
                    <div class="text-xs text-gray-400">No battles yet</div>
                <?php endif; ?>
            </div>
        </div>
        </div>
        <!-- /player-card-aura -->

        <!-- Action Buttons -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Cultivation Button -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-6">
                <h3 class="text-xl font-semibold text-cyan-300 mb-4">Cultivation</h3>
                <button type="button" id="cultivate-btn" class="w-full py-3 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white font-semibold rounded-lg shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/50 transition-all transform hover:-translate-y-0.5 disabled:opacity-60 disabled:cursor-not-allowed disabled:transform-none"
                    data-cooldown-remaining="<?php echo (int)$cooldownStatus['cooldown_remaining']; ?>"
                    <?php if (!$cooldownStatus['can_cultivate']): ?>disabled<?php endif; ?>>
                    <?php echo $cooldownStatus['can_cultivate'] ? '⚡ Cultivate Now' : '⏳ Cooldown: ' . $cooldownStatus['cooldown_remaining'] . 's'; ?>
                </button>
                <div id="cultivate-status" class="mt-3 text-sm text-gray-400 text-center" style="<?php echo !$cooldownStatus['can_cultivate'] ? '' : 'display:none'; ?>">
                    <?php if (!$cooldownStatus['can_cultivate']): ?>Wait <?php echo $cooldownStatus['cooldown_remaining']; ?>s to cultivate again.<?php endif; ?>
                </div>
                <div class="mt-2 text-sm text-gray-400 text-center">
                    Expected gain: <?php echo $cultivationEfficiency['min_gain']; ?>-<?php echo $cultivationEfficiency['max_gain']; ?> chi
                </div>
            </div>

            <!-- Realm Breakthrough (failure chance, optional pill) -->
            <div class="bg-gray-800/90 backdrop-blur-lg border border-purple-500/30 rounded-xl p-6">
                <h3 class="text-xl font-semibold text-purple-300 mb-4">Realm Breakthrough</h3>
                <?php if (!empty($breakthroughStatus['can_attempt'])): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="breakthrough">
                        <div class="mb-3">
                            <label class="block text-sm text-gray-400 mb-1">Use pill (optional)</label>
                            <select name="pill_inventory_id" class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white">
                                <option value="">None (85% base chance)</option>
                                <?php foreach ($breakthroughStatus['pills'] ?? [] as $pill): ?>
                                    <?php $t = $pill['template'] ?? []; $bonus = (int)($t['breakthrough_bonus'] ?? 0); ?>
                                    <option value="<?php echo (int)$pill['id']; ?>"><?php echo htmlspecialchars($t['name'] ?? 'Pill', ENT_QUOTES, 'UTF-8'); ?> (+<?php echo $bonus; ?>%, cap 98%) — Qty: <?php echo (int)$pill['quantity']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white font-semibold rounded-lg shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all transform hover:-translate-y-0.5">
                            🌟 Attempt Breakthrough
                        </button>
                    </form>
                    <div class="mt-3 text-sm text-gray-400 text-center">
                        Base success: 85%. Pill adds bonus (max 98%). Failure: chi −15%, attempt counter +1.
                    </div>
                <?php else: ?>
                    <button disabled class="w-full py-3 bg-gray-700 text-gray-400 font-semibold rounded-lg cursor-not-allowed">
                        <?php echo htmlspecialchars($breakthroughStatus['error'] ?? 'Not Available', ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Navigation Links -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
            <a href="leaderboard.php" class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-4 hover:border-cyan-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🏆</div>
                <div class="text-sm font-semibold text-cyan-300">Leaderboard</div>
            </a>
            <?php if ($canPvP): ?>
            <a href="battles.php" class="bg-gray-800/90 backdrop-blur-lg border border-red-500/30 rounded-xl p-4 hover:border-red-500/50 transition-all text-center">
                <div class="text-2xl mb-2">⚔️</div>
                <div class="text-sm font-semibold text-red-300">Battles</div>
            </a>
            <?php else: ?>
            <span class="bg-gray-800/90 backdrop-blur-lg border border-red-500/30 rounded-xl p-4 text-center block opacity-60 cursor-not-allowed">
                <div class="text-2xl mb-2">⚔️</div>
                <div class="text-sm font-semibold text-red-300">Battles</div>
                <div class="text-xs text-gray-400 mt-1">No PvP stamina</div>
            </span>
            <?php endif; ?>
            <a href="npc_arena.php" class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-4 hover:border-amber-500/50 transition-all text-center">
                <div class="text-2xl mb-2">👹</div>
                <div class="text-sm font-semibold text-amber-300">NPC Arena</div>
            </a>
            <a href="inventory.php" class="bg-gray-800/90 backdrop-blur-lg border border-emerald-500/30 rounded-xl p-4 hover:border-emerald-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🎒</div>
                <div class="text-sm font-semibold text-emerald-300">Inventory</div>
            </a>
            <a href="marketplace.php" class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-4 hover:border-amber-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🏪</div>
                <div class="text-sm font-semibold text-amber-300">Marketplace</div>
            </a>
            <a href="alchemy.php" class="bg-gray-800/90 backdrop-blur-lg border border-emerald-500/30 rounded-xl p-4 hover:border-emerald-500/50 transition-all text-center">
                <div class="text-2xl mb-2">⚗️</div>
                <div class="text-sm font-semibold text-emerald-300">Alchemy</div>
            </a>
            <a href="blacksmith.php" class="bg-gray-800/90 backdrop-blur-lg border border-orange-500/30 rounded-xl p-4 hover:border-orange-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🔨</div>
                <div class="text-sm font-semibold text-orange-300">Blacksmith</div>
            </a>
            <a href="herbalist.php" class="bg-gray-800/90 backdrop-blur-lg border border-green-500/30 rounded-xl p-4 hover:border-green-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🌿</div>
                <div class="text-sm font-semibold text-green-300">Herb Plot</div>
            </a>
            <a href="runes.php" class="bg-gray-800/90 backdrop-blur-lg border border-purple-500/30 rounded-xl p-4 hover:border-purple-500/50 transition-all text-center">
                <div class="text-2xl mb-2">✒️</div>
                <div class="text-sm font-semibold text-purple-300">Runes</div>
            </a>
            <a href="professions.php" class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-4 hover:border-amber-500/50 transition-all text-center">
                <div class="text-2xl mb-2">📜</div>
                <div class="text-sm font-semibold text-amber-300">Professions</div>
            </a>
            <a href="sect.php" class="bg-gray-800/90 backdrop-blur-lg border border-amber-500/30 rounded-xl p-4 hover:border-amber-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🏛️</div>
                <div class="text-sm font-semibold text-amber-300">Sect</div>
            </a>
            <a href="equipment.php" class="bg-gray-800/90 backdrop-blur-lg border border-violet-500/30 rounded-xl p-4 hover:border-violet-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🛡️</div>
                <div class="text-sm font-semibold text-violet-300">Equipment</div>
            </a>
            <a href="territories.php" class="bg-gray-800/90 backdrop-blur-lg border border-green-500/30 rounded-xl p-4 hover:border-green-500/50 transition-all text-center">
                <div class="text-2xl mb-2">🗺️</div>
                <div class="text-sm font-semibold text-green-300">Territories</div>
            </a>
            <a href="hall_of_legends.php" class="bg-gray-800/90 backdrop-blur-lg border border-yellow-500/30 rounded-xl p-4 hover:border-yellow-500/50 transition-all text-center">
                <div class="text-2xl mb-2">⭐</div>
                <div class="text-sm font-semibold text-yellow-300">Hall of Legends</div>
            </a>
        </div>
    </div>
    <script src="cultivation.js"></script>
</body>
</html>
