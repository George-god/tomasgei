<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/MarketplaceService.php';

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
$result = $service->buyListing($userId, $listingId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Purchase failed.', 400);
}

ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Purchase complete.');


