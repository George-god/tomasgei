<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Service/ChallengeService.php';
require_once __DIR__ . '/classes/Service/BattleService.php';
require_once __DIR__ . '/classes/Service/PvpStaminaService.php';

use Game\Config\Database;
use Game\Service\ChallengeService;
use Game\Service\BattleService;
use Game\Service\PvpStaminaService;

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$challengeService = new ChallengeService();
$battleService = new BattleService();

// PvP stamina (regen on load)
$pvpStaminaService = new PvpStaminaService();
$pvpStaminaData = $pvpStaminaService->getStamina($userId);
$canPvP = $pvpStaminaData['can_fight'];
$pvpStamina = $pvpStaminaData['stamina'];
$pvpStaminaMax = $pvpStaminaData['max_stamina'];

// Handle challenge creation
$challengeResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'challenge') {
    $defenderId = (int)($_POST['defender_id'] ?? 0);
    if ($defenderId > 0) {
        $challengeResult = $challengeService->createChallenge($userId, $defenderId);
    }
}

// Handle challenge acceptance/decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $challengeId = (int)($_POST['challenge_id'] ?? 0);
    if ($_POST['action'] === 'accept' && $challengeId > 0) {
        $result = $challengeService->acceptChallenge($challengeId, $userId);
        if ($result['success']) {
            header('Location: battle_replay.php?id=' . $result['battle_id']);
            exit;
        }
    } elseif ($_POST['action'] === 'decline' && $challengeId > 0) {
        $challengeService->declineChallenge($challengeId, $userId);
    }
}

// Get pending challenges
$pendingChallenges = $challengeService->getPendingChallenges($userId);

// Get available opponents
$availableOpponents = $challengeService->getAvailableOpponents($userId, 20);

// Get recent battles
try {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT b.*, 
                         u1.username as attacker_username,
                         u2.username as defender_username,
                         u3.username as winner_username
                         FROM battles b
                         LEFT JOIN users u1 ON b.attacker_id = u1.id
                         LEFT JOIN users u2 ON b.defender_id = u2.id
                         LEFT JOIN users u3 ON b.winner_id = u3.id
                         WHERE b.attacker_id = ? OR b.defender_id = ?
                         ORDER BY b.created_at DESC
                         LIMIT 10");
    $stmt->execute([$userId, $userId]);
    $recentBattles = $stmt->fetchAll();
} catch (Exception $e) {
    $recentBattles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battles - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-red-400 to-orange-400 bg-clip-text text-transparent">
                ⚔️ Battles
            </h1>
            <div class="flex items-center gap-4">
                <span class="text-sm text-red-300">PvP Stamina: <strong><?php echo $pvpStamina; ?>/<?php echo $pvpStaminaMax; ?></strong></span>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                    ← Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Challenge Result Message -->
        <?php if ($challengeResult): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $challengeResult['success'] ? 'bg-green-500/20 border border-green-500/50 text-green-300' : 'bg-red-500/20 border border-red-500/50 text-red-300'; ?>">
                <?php echo htmlspecialchars($challengeResult['success'] ? 'Challenge sent successfully!' : $challengeResult['error'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Pending Challenges -->
        <?php if (!empty($pendingChallenges)): ?>
            <div class="mb-8">
                <h2 class="text-2xl font-semibold text-yellow-300 mb-4">Pending Challenges</h2>
                <div class="space-y-4">
                    <?php foreach ($pendingChallenges as $challenge): ?>
                        <?php $isDefender = (int)$challenge['defender_id'] === $userId; ?>
                        <div class="bg-gray-800/90 backdrop-blur-lg border border-yellow-500/30 rounded-xl p-6">
                            <div class="flex justify-between items-center">
                                <div>
                                    <div class="text-lg font-semibold text-white">
                                        <?php if ($isDefender): ?>
                                            Challenge from: <span class="text-cyan-300"><?php echo htmlspecialchars($challenge['challenger_username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php else: ?>
                                            Challenge to: <span class="text-cyan-300"><?php echo htmlspecialchars($challenge['defender_username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm text-gray-400 mt-1">
                                        Rating: <?php echo number_format((float)$challenge[$isDefender ? 'challenger_rating' : 'defender_rating'], 0); ?>
                                    </div>
                                </div>
                                <?php if ($isDefender): ?>
                                    <div class="flex gap-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="accept">
                                            <input type="hidden" name="challenge_id" value="<?php echo $challenge['id']; ?>">
                                            <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg text-white font-semibold transition-all">
                                                Accept
                                            </button>
                                        </form>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="decline">
                                            <input type="hidden" name="challenge_id" value="<?php echo $challenge['id']; ?>">
                                            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-white font-semibold transition-all">
                                                Decline
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400">Waiting for response...</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Available Opponents -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-cyan-300 mb-4">Available Opponents (Rating Range: ±<?php echo $challengeService->getRatingRange(); ?>)</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($availableOpponents as $opponent): ?>
                    <?php
                    $rateLimitStatus = $challengeService->getRateLimitStatus($userId, (int)$opponent['id']);
                    ?>
                    <div class="bg-gray-800/90 backdrop-blur-lg border border-cyan-500/30 rounded-xl p-4">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <div class="font-semibold text-white"><?php echo htmlspecialchars($opponent['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-sm text-gray-400"><?php echo htmlspecialchars($opponent['realm_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?> Lv.<?php echo $opponent['level']; ?></div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-yellow-300"><?php echo number_format((float)$opponent['rating'], 0); ?></div>
                                <div class="text-xs text-gray-400"><?php echo $opponent['wins']; ?>W-<?php echo $opponent['losses']; ?>L</div>
                            </div>
                        </div>
                        <?php if ($rateLimitStatus['can_challenge'] && $canPvP): ?>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="action" value="challenge">
                                <input type="hidden" name="defender_id" value="<?php echo $opponent['id']; ?>">
                                <button type="submit" class="w-full py-2 bg-gradient-to-r from-red-500 to-orange-500 hover:from-red-600 hover:to-orange-600 text-white font-semibold rounded-lg transition-all">
                                    Challenge
                                </button>
                            </form>
                        <?php elseif (!$canPvP): ?>
                            <button disabled class="w-full py-2 bg-gray-700 text-gray-400 font-semibold rounded-lg cursor-not-allowed">
                                No PvP stamina
                            </button>
                        <?php else: ?>
                            <button disabled class="w-full py-2 bg-gray-700 text-gray-400 font-semibold rounded-lg cursor-not-allowed">
                                Rate Limited (<?php echo ceil($rateLimitStatus['cooldown_remaining'] / 60); ?>m)
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Battles -->
        <div>
            <h2 class="text-2xl font-semibold text-purple-300 mb-4">Recent Battles</h2>
            <div class="space-y-3">
                <?php foreach ($recentBattles as $battle): ?>
                    <?php $isWinner = (int)$battle['winner_id'] === $userId; ?>
                    <a href="battle_replay.php?id=<?php echo $battle['id']; ?>" class="block bg-gray-800/90 backdrop-blur-lg border <?php echo $isWinner ? 'border-green-500/30' : 'border-red-500/30'; ?> rounded-xl p-4 hover:bg-gray-700/50 transition-all">
                        <div class="flex justify-between items-center">
                            <div>
                                <div class="font-semibold text-white">
                                    <?php echo htmlspecialchars($battle['attacker_username'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($battle['defender_username'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="text-sm text-gray-400">
                                    <?php echo $battle['turns']; ?> turns • <?php echo date('M j, Y H:i', strtotime($battle['created_at'])); ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold <?php echo $isWinner ? 'text-green-400' : 'text-red-400'; ?>">
                                    <?php echo $isWinner ? 'Victory' : 'Defeat'; ?>
                                </div>
                                <div class="text-xs text-gray-400">
                                    Rating: <?php echo $isWinner ? '+' : ''; ?><?php echo number_format((float)($isWinner ? $battle['attacker_rating_after'] - $battle['attacker_rating_before'] : $battle['defender_rating_after'] - $battle['defender_rating_before']), 0); ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($recentBattles)): ?>
                    <div class="text-center text-gray-400 py-8">No battles yet. Challenge an opponent to get started!</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
