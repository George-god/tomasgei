<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DaoRecord.php';

use Game\Helper\SessionHelper;
use Game\Service\DaoRecord;

session_start();
SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');

$eventType = trim((string)($_GET['event_type'] ?? ''));
$userIdFilter = max(0, (int)($_GET['user_id'] ?? 0));
$filters = [];
if ($eventType !== '') {
    $filters['event_type'] = $eventType;
}
if ($userIdFilter > 0) {
    $filters['user_id'] = $userIdFilter;
}

$records = DaoRecord::getRecords($filters, 250);
$eventTypes = DaoRecord::getEventTypes();
$formatEventType = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$formatContext = static function ($value): string {
    if ($value === null || $value === '') {
        return 'No context data.';
    }
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) {
        return (string)$value;
    }
    $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : (string)$value;
};
$pageTitle = 'Dao Records';
require __DIR__ . '/includes/header.php';
?>

        <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4 bg-gray-800/90 border border-cyan-500/20 rounded-xl p-5">
            <div>
                <label for="event_type" class="block text-sm font-medium text-gray-300 mb-2">Event Type</label>
                <select id="event_type" name="event_type" class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    <option value="">All events</option>
                    <?php foreach ($eventTypes as $type): ?>
                        <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $eventType === $type ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($formatEventType($type), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="user_id" class="block text-sm font-medium text-gray-300 mb-2">User ID</label>
                <input id="user_id" name="user_id" type="number" min="1" value="<?php echo $userIdFilter > 0 ? $userIdFilter : ''; ?>" class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-violet-500">
            </div>
            <div class="flex items-end gap-3">
                <button type="submit" class="px-5 py-3 bg-gradient-to-r from-violet-600 to-cyan-600 hover:from-violet-500 hover:to-cyan-500 text-white font-semibold rounded-lg">Filter Records</button>
                <a href="dao_records.php" class="px-5 py-3 bg-gray-900/70 hover:bg-gray-800 rounded-lg border border-gray-700 text-gray-300">Reset</a>
            </div>
        </form>

        <div class="space-y-4">
            <?php foreach ($records as $record): ?>
                <div class="bg-gray-800/90 border border-violet-500/20 rounded-xl p-5">
                    <div class="flex flex-wrap justify-between gap-4 mb-3">
                        <div>
                            <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars((string)$record['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-sm text-gray-400 mt-1">
                                User: <?php echo htmlspecialchars((string)$record['username'], ENT_QUOTES, 'UTF-8'); ?> (#<?php echo (int)$record['user_id']; ?>)
                                · Event: <?php echo htmlspecialchars($formatEventType((string)$record['event_type']), ENT_QUOTES, 'UTF-8'); ?>
                                · Target: <?php echo $record['target_id'] !== null ? (int)$record['target_id'] : 'None'; ?>
                            </div>
                        </div>
                        <div class="text-sm text-cyan-300"><?php echo htmlspecialchars((string)$record['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-300 bg-gray-900/70 border border-gray-700 rounded-lg p-4 overflow-x-auto"><?php echo htmlspecialchars($formatContext($record['context_data'] ?? null), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            <?php endforeach; ?>

            <?php if (!$records): ?>
                <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-8 text-center text-gray-400">
                    No Heavenly Dao records match the current filters.
                </div>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/includes/footer.php'; ?>



