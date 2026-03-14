<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\SectService;

$userId = SessionHelper::requireUserIdForApi();

$sectService = new SectService();
$sect = $sectService->getSectByUserId($userId);
if (!$sect) {
    ApiResponse::success(['messages' => []]);
}

$sectId = (int)$sect['id'];
$lastMessageId = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : (isset($_POST['last_message_id']) ? (int)$_POST['last_message_id'] : 0);

try {
    $db = \Game\Config\Database::getConnection();

    if ($lastMessageId > 0) {
        $stmt = $db->prepare("
            SELECT m.id, m.user_id, m.message, m.created_at, u.username
            FROM sect_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.sect_id = ? AND m.id > ?
            ORDER BY m.id ASC
        ");
        $stmt->execute([$sectId, $lastMessageId]);
    } else {
        $stmt = $db->prepare("
            SELECT m.id, m.user_id, m.message, m.created_at, u.username
            FROM sect_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.sect_id = ?
            ORDER BY m.id DESC
            LIMIT 50
        ");
        $stmt->execute([$sectId]);
    }

    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'username' => (string)$r['username'],
            'message' => (string)$r['message'],
            'created_at' => (string)$r['created_at'],
        ];
    }

    if ($lastMessageId <= 0 && !empty($messages)) {
        $messages = array_reverse($messages);
    }

    ApiResponse::success(['messages' => $messages]);
} catch (\Throwable $e) {
    error_log("sect_chat_fetch " . $e->getMessage());
    ApiResponse::error('Could not fetch messages.', 500);
}


