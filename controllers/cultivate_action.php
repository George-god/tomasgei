<?php
declare(strict_types=1);

ob_start();
// Ensure all errors return JSON so the frontend can display them
set_exception_handler(function (\Throwable $e) {
    if (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'debug' => ['file' => basename($e->getFile()), 'line' => $e->getLine()]
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal: ' . $err['message'],
            'debug' => ['file' => basename($err['file']), 'line' => $err['line']]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/Validator.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/CultivationService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Core\Validator;
use Game\Service\CultivationService;

$userId = SessionHelper::requireUserIdForApi();
Validator::requirePost();

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

$cooldownRemaining = (int)($result['cooldown_remaining'] ?? 0);

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
    'cooldown_remaining' => $cooldownRemaining
];

ApiResponse::success($payload);

