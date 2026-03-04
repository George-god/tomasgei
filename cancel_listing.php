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

$listingId = (int)($_POST['listing_id'] ?? $_GET['listing_id'] ?? 0);
if ($listingId < 1) {
    ApiResponse::error('Invalid listing.');
}

$service = new MarketplaceService();
$result = $service->cancelListing($userId, $listingId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Cancel failed.', 400);
}

ApiResponse::success(null, $result['message'] ?? 'Listing cancelled.');
