<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ProfessionService.php';

use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;

session_start();
$userId = SessionHelper::requireLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['profession_id'])) {
    header('Location: ../pages/alchemy.php?err=' . urlencode('Invalid request.'));
    exit;
}

$professionId = (int)$_POST['profession_id'];
$service = new ProfessionService();
$result = $service->chooseProfession($userId, $professionId);

if ($result['success']) {
    header('Location: ../pages/alchemy.php?msg=' . urlencode($result['message'] ?? 'Profession set.'));
} else {
    header('Location: ../pages/alchemy.php?err=' . urlencode($result['message'] ?? 'Failed.'));
}
exit;



