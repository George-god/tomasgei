<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/HerbalistService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\HerbalistService;

$userId = SessionHelper::requireUserIdForApi();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$service = new HerbalistService();
$result = $service->plant($userId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Plant failed.', 400);
}
ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Planted.');


