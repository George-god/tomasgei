<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DaoRecord.php';

use Game\Helper\SessionHelper;
use Game\Service\DaoRecord;

session_start();
$userId = SessionHelper::requireLoggedIn();
$records = DaoRecord::getRecordsForUser($userId, 120);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fate Records - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-indigo-950 to-slate-950 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-400 to-cyan-400 bg-clip-text text-transparent">Fate Records</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <div class="space-y-4">
            <?php foreach ($records as $record): ?>
                <div class="bg-gray-800/90 border border-amber-500/20 rounded-xl p-5">
                    <div class="flex flex-wrap justify-between gap-4 mb-3">
                        <div>
                            <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars((string)$record['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-sm text-gray-400 mt-1">
                                <?php echo htmlspecialchars($formatEventType((string)$record['event_type']), ENT_QUOTES, 'UTF-8'); ?>
                                · Target: <?php echo $record['target_id'] !== null ? (int)$record['target_id'] : 'None'; ?>
                            </div>
                        </div>
                        <div class="text-sm text-amber-300"><?php echo htmlspecialchars((string)$record['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-300 bg-gray-900/70 border border-gray-700 rounded-lg p-4 overflow-x-auto"><?php echo htmlspecialchars($formatContext($record['context_data'] ?? null), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            <?php endforeach; ?>

            <?php if (!$records): ?>
                <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-8 text-center text-gray-400">
                    Your fate records are still unwritten.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>




