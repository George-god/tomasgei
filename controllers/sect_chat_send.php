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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$raw = trim((string)($_POST['message'] ?? ''));
if ($raw === '') {
    ApiResponse::error('Message cannot be empty.');
}

if (strlen($raw) > 300) {
    ApiResponse::error('Message too long (max 300 characters).');
}

$sectService = new SectService();
$sect = $sectService->getSectByUserId($userId);
if (!$sect) {
    ApiResponse::error('You must be in a sect to send messages.');
}

try {
    $db = \Game\Config\Database::getConnection();

    $stmt = $db->prepare("SELECT last_chat_message_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $lastAt = $stmt->fetchColumn();
    if ($lastAt !== null && $lastAt !== '') {
        $elapsed = time() - (int)strtotime($lastAt);
        if ($elapsed < 2) {
            ApiResponse::error('Wait at least 2 seconds between messages.', 429, ['retry_after' => 2 - $elapsed]);
        }
    }

    $sectId = (int)$sect['id'];
    $stmt = $db->prepare("INSERT INTO sect_messages (sect_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$sectId, $userId, $raw]);
    $messageId = (int)$db->lastInsertId();

    $db->prepare("UPDATE users SET last_chat_message_at = NOW() WHERE id = ?")->execute([$userId]);

    $stmt = $db->prepare("SELECT created_at FROM sect_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $createdAt = (string)$stmt->fetchColumn();

    ApiResponse::success(
        ['id' => $messageId, 'created_at' => $createdAt],
        'Sent'
    );
} catch (\Throwable $e) {
    error_log("sect_chat_send " . $e->getMessage());
    ApiResponse::error('Could not send message.', 500);
}


