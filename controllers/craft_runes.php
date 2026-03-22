<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/RuneService.php';
require_once dirname(__DIR__) . '/services/ActivityService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\RuneService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$recipeId = (int)($_POST['recipe_id'] ?? $_GET['recipe_id'] ?? 0);
if ($recipeId < 1) {
    ApiResponse::error('Invalid recipe.');
}

$service = new RuneService();
$result = $service->craft($userId, $recipeId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Craft failed.', 400);
}

if (!empty($result['craft_success'])) {
    try {
        (new ActivityService())->recordCraft($userId);
    } catch (\Throwable $e) {
        error_log('Activity craft: ' . $e->getMessage());
    }
}

$data = $result['data'] ?? [];
$data['craft_success'] = $result['craft_success'] ?? false;
ApiResponse::success($data, $result['message'] ?? 'Craft complete.');


