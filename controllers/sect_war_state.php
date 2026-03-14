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
$service = new SectWarService();

$warId = (int)($_GET['war_id'] ?? 0);
$state = $warId > 0 ? $service->getWarState($userId, $warId) : $service->getActiveWarForUser($userId);

ApiResponse::success($state ?? [
    'war' => null,
    'attackers' => [],
    'defenders' => [],
    'cooldown_remaining' => $service->getCooldownRemaining($userId),
    'user_side' => null,
]);


