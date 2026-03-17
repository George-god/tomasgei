<?php
declare(strict_types=1);

/**
 * Minimal test endpoint. Open in browser or use: curl -X POST http://localhost/Cultivation/controllers/cultivate_test.php
 * Returns JSON with status. Use this to verify the path and PHP are working.
 */
header('Content-Type: application/json; charset=utf-8');

$result = ['success' => true, 'message' => 'Test OK', 'data' => ['test' => 1]];

try {
    require_once dirname(__DIR__) . '/core/bootstrap.php';
    require_once dirname(__DIR__) . '/services/CultivationService.php';
    $result['data']['loaded'] = 'CultivationService loaded';
} catch (\Throwable $e) {
    $result = [
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => ['file' => basename($e->getFile()), 'line' => $e->getLine()]
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
