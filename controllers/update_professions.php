<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ProfessionService.php';

use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;

session_start();
$userId = SessionHelper::requireLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['main_profession_id'])) {
    header('Location: ../pages/professions.php?err=' . urlencode('Invalid request.'));
    exit;
}

$mainId = (int)$_POST['main_profession_id'];
$secondaryId = !empty($_POST['secondary_profession_id']) ? (int)$_POST['secondary_profession_id'] : 0;
if ($secondaryId === $mainId) {
    $secondaryId = 0;
}

$service = new ProfessionService();
$r1 = $service->setMainProfession($userId, $mainId);
if (!$r1['success']) {
    header('Location: ../pages/professions.php?err=' . urlencode($r1['message'] ?? 'Failed.'));
    exit;
}
$service->clearSecondary($userId);
if ($secondaryId > 0) {
    $r2 = $service->setSecondaryProfession($userId, $secondaryId);
    if (!$r2['success']) {
        header('Location: ../pages/professions.php?err=' . urlencode($r2['message'] ?? 'Failed to set secondary.'));
        exit;
    }
}

header('Location: ../pages/professions.php?msg=' . urlencode('Professions updated.'));
exit;



