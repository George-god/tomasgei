<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Service/NotificationService.php';

use Game\Config\Database;
use Game\Service\NotificationService;

Database::setConfig([
    'host' => 'localhost',
    'dbname' => 'cultivation_rpg',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
]);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$notificationService = new NotificationService();

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId > 0) {
        $notificationService->markAsRead($notificationId, $userId);
    }
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $notificationService->markAllAsRead($userId);
}

$notifications = $notificationService->getNotifications($userId, false, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent">
                🔔 Notifications
            </h1>
            <div class="flex gap-4">
                <?php if (!empty($notifications)): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all text-sm">
                            Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">
                    ← Back to Dashboard
                </a>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($notifications as $notification): ?>
                <div class="bg-gray-800/90 backdrop-blur-lg border <?php echo $notification['is_read'] ? 'border-gray-700' : 'border-cyan-500/50'; ?> rounded-xl p-4">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <?php if (!$notification['is_read']): ?>
                                <span class="inline-block w-2 h-2 bg-cyan-400 rounded-full mr-2"></span>
                            <?php endif; ?>
                            <div class="font-semibold text-white mb-1"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-sm text-gray-400"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-500 mt-2"><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></div>
                        </div>
                        <?php if (!$notification['is_read']): ?>
                            <form method="POST" class="ml-4">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                <button type="submit" class="text-xs text-cyan-400 hover:text-cyan-300">Mark Read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($notifications)): ?>
                <div class="text-center text-gray-400 py-12">
                    <div class="text-4xl mb-4">📭</div>
                    <div>No notifications yet</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
