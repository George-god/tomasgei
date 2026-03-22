<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ExplorationService.php';
require_once dirname(__DIR__) . '/services/ActivityService.php';
require_once dirname(__DIR__) . '/services/TitleService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\ExplorationService;
use Game\Service\ActivityService;
use Game\Service\TitleService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$regionId = (int)($_POST['region_id'] ?? $_GET['region_id'] ?? 0);
if ($regionId < 1) {
    ApiResponse::error('Invalid region.');
}

$service = new ExplorationService();
$result = $service->exploreRegion($userId, $regionId);

if (!$result['success']) {
    $extra = [];
    if (isset($result['cooldown_remaining'])) {
        $extra['cooldown_remaining'] = (int)$result['cooldown_remaining'];
    }
    ApiResponse::error($result['message'] ?? 'Exploration failed.', 400, $extra);
}

try {
    (new ActivityService())->recordExploration($userId);
} catch (\Throwable $e) {
    error_log('Activity exploration: ' . $e->getMessage());
}
try {
    (new TitleService())->onExplore($userId);
} catch (\Throwable $e) {
    error_log('Title exploration: ' . $e->getMessage());
}

$payload = [
    'event_type' => $result['event_type'] ?? 'nothing',
    'cooldown_remaining' => (int)($result['cooldown_remaining'] ?? 60),
    'region_name' => $result['region_name'] ?? '',
];
if (isset($result['data'])) {
    $payload['data'] = $result['data'];
}

ApiResponse::success($payload, $result['message'] ?? 'Exploration complete.');


