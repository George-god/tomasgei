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

$service = new WorldBossService();
$state = $service->getBossState($userId);
ApiResponse::success($state);


