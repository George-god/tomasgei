<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/AnomalyReportService.php';

use Game\Helper\SessionHelper;
use Game\Service\AnomalyReportService;

session_start();
$userId = SessionHelper::requireLoggedIn();
$service = new AnomalyReportService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $service->submitReport(
        $userId,
        (string)($_POST['title'] ?? ''),
        (string)($_POST['description'] ?? ''),
        (string)($_POST['location'] ?? '')
    );

    $query = $result['success']
        ? '?msg=' . urlencode((string)$result['message'])
        : '?err=' . urlencode((string)$result['message']);
    header('Location: report_anomaly.php' . $query);
    exit;
}

$reports = $service->getReportsForUser($userId);
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$formatStatus = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Anomaly - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-fuchsia-400 to-cyan-400 bg-clip-text text-transparent">Heavenly Dao Anomaly Reports</h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-4 bg-fuchsia-900/20 border border-fuchsia-500/40 rounded-xl text-fuchsia-200"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-4 bg-red-900/20 border border-red-500/40 rounded-xl text-red-200"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="bg-gray-800/90 border border-fuchsia-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-fuchsia-300 mb-4">Submit Anomaly</h2>
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-300 mb-2">Title</label>
                        <input id="title" name="title" type="text" maxlength="150" required class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-fuchsia-500">
                    </div>
                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-300 mb-2">Location</label>
                        <input id="location" name="location" type="text" maxlength="150" required placeholder="Example: World Map, Ruins of the Fallen Sect" class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-fuchsia-500">
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                        <textarea id="description" name="description" rows="7" required class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-fuchsia-500"></textarea>
                    </div>
                    <button type="submit" class="px-5 py-3 bg-gradient-to-r from-fuchsia-600 to-cyan-600 hover:from-fuchsia-500 hover:to-cyan-500 text-white font-semibold rounded-lg">Offer Petition To The Heavenly Dao</button>
                </form>
            </div>

            <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-cyan-300 mb-4">Your Recorded Anomalies</h2>
                <div class="space-y-4 max-h-[700px] overflow-y-auto pr-1">
                    <?php foreach ($reports as $report): ?>
                        <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                            <div class="flex justify-between items-start gap-3">
                                <div>
                                    <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$report['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars((string)$report['location'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)$report['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="px-2 py-1 rounded text-xs border <?php echo match ((string)$report['status']) {
                                    'resolved' => 'bg-green-500/10 border-green-500/30 text-green-300',
                                    'investigating' => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
                                    default => 'bg-cyan-500/10 border-cyan-500/30 text-cyan-300',
                                }; ?>">
                                    <?php echo htmlspecialchars($formatStatus((string)$report['status']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-300 mt-3"><?php echo nl2br(htmlspecialchars((string)$report['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                            <?php if (!empty($report['admin_reply'])): ?>
                                <div class="mt-4 p-3 bg-fuchsia-900/20 border border-fuchsia-500/30 rounded-lg">
                                    <div class="text-xs uppercase tracking-wide text-fuchsia-300 mb-1">Heavenly Dao Decree</div>
                                    <div class="text-sm text-fuchsia-100"><?php echo nl2br(htmlspecialchars((string)$report['admin_reply'], ENT_QUOTES, 'UTF-8')); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$reports): ?>
                        <div class="text-gray-400">No anomaly reports have been submitted yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>




