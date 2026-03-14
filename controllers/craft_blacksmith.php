<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/CraftingService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\CraftingService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$recipeId = (int)($_POST['recipe_id'] ?? $_GET['recipe_id'] ?? 0);
if ($recipeId < 1) {
    ApiResponse::error('Invalid recipe.');
}

$service = new CraftingService();
$result = $service->craft($userId, $recipeId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Craft failed.', 400);
}

ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Craft complete.');


