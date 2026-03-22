<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/TitleService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\TitleService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$raw = $_POST['title_id'] ?? '';
$titleId = $raw === '' || $raw === '0' ? null : (int)$raw;

$service = new TitleService();
$result = $service->equipTitle($userId, $titleId);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Could not update title.', 400);
}

ApiResponse::success(null, $result['message'] ?? 'OK');
