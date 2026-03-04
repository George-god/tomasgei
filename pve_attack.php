<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/PvEBattleService.php';
require_once __DIR__ . '/classes/Service/StatCalculator.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\PvEBattleService;
use Game\Service\StatCalculator;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$npcId = (int)($_POST['npc_id'] ?? $_GET['npc_id'] ?? 0);
if ($npcId <= 0) {
    ApiResponse::error('Invalid NPC.');
}

$service = new PvEBattleService();
$result = $service->simulateBattle($userId, $npcId);

if (!$result['success']) {
    ApiResponse::error($result['error'] ?? 'Battle failed.', 400);
}

$chiReward = (int)$result['chi_reward'];
$userChiAfter = (int)$result['user_chi_after'];

// Centralized stat source: max_chi from StatCalculator
$statCalc = new StatCalculator();
$finalStats = $statCalc->calculateFinalStats($userId);
$userMaxChi = (int)$finalStats['final']['max_chi'];

if ($result['winner'] === 'user' && $chiReward > 0) {
    $newChi = min($userMaxChi, max(0, $userChiAfter + $chiReward));
    $db = \Game\Config\Database::getConnection();
    $db->prepare("UPDATE users SET chi = GREATEST(0, LEAST(?, ?)) WHERE id = ?")
        ->execute([$userMaxChi, $newChi, $userId]);
    $userChiAfter = $newChi;
}

$db = \Game\Config\Database::getConnection();
$db->prepare("UPDATE users SET active_scroll_type = NULL WHERE id = ?")->execute([$userId]);

ApiResponse::success([
    'winner' => $result['winner'],
    'battle_log' => $result['battle_log'],
    'user_chi_after' => $userChiAfter,
    'user_max_chi' => $userMaxChi,
    'npc_hp_max' => (int)$result['npc_hp_max'],
    'chi_reward' => $chiReward,
    'npc_name' => $result['npc_name'],
    'dropped_item' => $result['dropped_item'] ?? null,
    'herb_dropped' => $result['herb_dropped'] ?? null,
    'material_dropped' => $result['material_dropped'] ?? null,
    'rune_fragment_dropped' => $result['rune_fragment_dropped'] ?? null,
    'gold_gained' => (int)($result['gold_gained'] ?? 0),
    'spirit_stone_gained' => (int)($result['spirit_stone_gained'] ?? 0)
]);
