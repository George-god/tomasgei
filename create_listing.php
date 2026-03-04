<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/MarketplaceService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\MarketplaceService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$inventoryId = (int)($_POST['inventory_id'] ?? $_GET['inventory_id'] ?? 0);
$price = (int)($_POST['price'] ?? $_GET['price'] ?? 0);

if ($inventoryId < 1) {
    ApiResponse::error('Invalid inventory item.');
}
if ($price < 1) {
    ApiResponse::error('Price must be at least 1 gold.');
}

$service = new MarketplaceService();
$result = $service->createListing($userId, $inventoryId, $price);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Failed to create listing.', 400);
}

ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Listing created.');
