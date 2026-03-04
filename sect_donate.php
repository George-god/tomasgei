<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/SectService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\SectService;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$amount = (int)($_POST['amount'] ?? $_GET['amount'] ?? 0);
if ($amount < 100) {
    ApiResponse::error('Minimum donation is 100 gold (1 contribution, 2 sect EXP).');
}

$service = new SectService();
$result = $service->donate($userId, $amount);

if (!$result['success']) {
    ApiResponse::error($result['message'] ?? 'Donation failed.', 400);
}

ApiResponse::success([
    'contribution_gain' => $result['contribution_gain'] ?? 0,
    'sect_exp_gain' => $result['sect_exp_gain'] ?? 0,
], $result['message'] ?? 'Donation complete.');
