<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/ProfessionService.php';

use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;

session_start();
$userId = SessionHelper::requireLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['profession_id'])) {
    header('Location: alchemy.php?err=' . urlencode('Invalid request.'));
    exit;
}

$professionId = (int)$_POST['profession_id'];
$service = new ProfessionService();
$result = $service->chooseProfession($userId, $professionId);

if ($result['success']) {
    header('Location: alchemy.php?msg=' . urlencode($result['message'] ?? 'Profession set.'));
} else {
    header('Location: alchemy.php?err=' . urlencode($result['message'] ?? 'Failed.'));
}
exit;
