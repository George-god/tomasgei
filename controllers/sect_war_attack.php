<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectWarService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\SectWarService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$warId = (int)($_POST['war_id'] ?? 0);
if ($warId <= 0) {
    ApiResponse::error('Invalid war.', 400);
}

$service = new SectWarService();
$result = $service->attack($userId, $warId);

if (empty($result['success'])) {
    $data = isset($result['cooldown_remaining']) ? ['cooldown_remaining' => $result['cooldown_remaining']] : null;
    ApiResponse::error($result['message'] ?? 'Sect war action failed.', 400, $data);
}

ApiResponse::success([
    'damage_dealt' => $result['damage_dealt'] ?? 0,
    'kills_gained' => $result['kills_gained'] ?? 0,
    'crystal_current_hp' => $result['crystal_current_hp'] ?? 0,
    'crystal_max_hp' => $result['crystal_max_hp'] ?? 0,
    'war_ended' => $result['war_ended'] ?? false,
    'user_side' => $result['user_side'] ?? null,
], $result['message'] ?? 'Action resolved.');


