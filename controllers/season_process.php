<?php
declare(strict_types=1);

/**
 * Cron / admin endpoint: end expired seasons (ranks, rewards, seasonal titles) and open a new season.
 * Example: curl "https://yoursite/controllers/season_process.php?key=YOUR_SECRET"
 * Set env SEASON_CRON_KEY or change the fallback below.
 */

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/services/SeasonService.php';

use Game\Service\SeasonService;

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
$expected = getenv('SEASON_CRON_KEY') ?: 'change-me-season-cron';

if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$svc = new SeasonService();
$result = $svc->processEndedSeasons();
$result['success'] = $result['ok'] ?? false;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
