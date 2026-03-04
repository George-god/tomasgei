<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/CultivationService.php';
require_once __DIR__ . '/classes/Service/StatCalculator.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\CultivationService;
use Game\Service\StatCalculator;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$service = new CultivationService();
$result = $service->cultivate($userId);

if (!$result['success']) {
    $cooldown = (int)($result['cooldown_remaining'] ?? 0);
    ApiResponse::error(
        $result['error'] ?? 'Cultivation failed.',
        400,
        $cooldown > 0 ? ['cooldown_remaining' => $cooldown] : null
    );
}

// Centralized stats: use StatCalculator for final display values if needed; here we return DB base + result
$db = \Game\Config\Database::getConnection();
$stmt = $db->prepare("SELECT chi, max_chi, level, attack, defense FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$payload = [
    'chi_gained' => (int)$result['chi_gained'],
    'chi' => $user ? max(0, (int)$user['chi']) : (int)$result['chi_after'],
    'max_chi' => $user ? max(0, (int)$user['max_chi']) : (int)$result['max_chi'],
    'level' => $user ? (int)$user['level'] : (int)($result['new_level'] ?? 1),
    'attack' => $user ? max(0, (int)$user['attack']) : 0,
    'defense' => $user ? max(0, (int)$user['defense']) : 0,
    'level_up' => !empty($result['level_up']),
    'new_level' => isset($result['new_level']) ? (int)$result['new_level'] : null,
    'new_max_chi' => isset($result['new_max_chi']) ? (int)$result['new_max_chi'] : null,
    'cooldown_remaining' => 0
];

ApiResponse::success($payload);