<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/WorldBossService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\WorldBossService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$service = new WorldBossService();
$result = $service->attack($userId);

if (!$result['success']) {
    $data = isset($result['cooldown_remaining']) ? ['cooldown_remaining' => $result['cooldown_remaining']] : null;
    ApiResponse::error($result['message'] ?? 'Attack failed.', 400, $data);
}

$payload = [
    'damage_dealt' => $result['damage_dealt'] ?? 0,
    'current_hp' => $result['current_hp'] ?? 0,
    'max_hp' => $result['max_hp'] ?? 0,
    'is_dead' => $result['is_dead'] ?? false,
];
ApiResponse::success($payload, $result['message'] ?? 'Attack!');


