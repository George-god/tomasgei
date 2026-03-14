<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/Validator.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ItemService.php';
require_once dirname(__DIR__) . '/services/StatService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Core\Validator;
use Game\Service\ItemService;
use Game\Service\StatService;

$userId = SessionHelper::requireUserIdForApi();
Validator::requirePost();

$inventoryId = Validator::intParam($_POST, 'inventory_id', 0);
if ($inventoryId <= 0) {
    ApiResponse::error('Invalid item.');
}

$itemService = new ItemService();
$result = $itemService->equipItem($userId, $inventoryId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Could not equip.', 400);
}

$statService = new StatService();
$stats = $statService->calculateFinalStats($userId);
$final = $stats['final'];

ApiResponse::success([
    'stats' => [
        'attack' => (int)$final['attack'],
        'defense' => (int)$final['defense'],
        'max_chi' => (int)$final['max_chi']
    ]
], $result['message'] ?? null);


