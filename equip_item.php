<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/ItemService.php';
require_once __DIR__ . '/classes/Service/StatCalculator.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\ItemService;
use Game\Service\StatCalculator;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$inventoryId = (int)($_POST['inventory_id'] ?? 0);
if ($inventoryId <= 0) {
    ApiResponse::error('Invalid item.');
}

$itemService = new ItemService();
$result = $itemService->equipItem($userId, $inventoryId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Could not equip.', 400);
}

$statCalc = new StatCalculator();
$stats = $statCalc->calculateFinalStats($userId);
$final = $stats['final'];

ApiResponse::success([
    'stats' => [
        'attack' => (int)$final['attack'],
        'defense' => (int)$final['defense'],
        'max_chi' => (int)$final['max_chi']
    ]
], $result['message'] ?? null);
