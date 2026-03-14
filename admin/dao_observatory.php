<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/AnomalyReportService.php';

use Game\Helper\SessionHelper;
use Game\Service\AnomalyReportService;

session_start();
$adminUserId = SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');
$service = new AnomalyReportService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $service->updateReport(
        $adminUserId,
        (int)($_POST['report_id'] ?? 0),
        (string)($_POST['status'] ?? 'observing'),
        (string)($_POST['admin_reply'] ?? '')
    );

    $statusFilter = isset($_GET['status']) ? '&status=' . urlencode((string)$_GET['status']) : '';
    $query = $result['success']
        ? '?msg=' . urlencode((string)$result['message']) . $statusFilter
        : '?err=' . urlencode((string)$result['message']) . $statusFilter;
    header('Location: dao_observatory.php' . $query);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$reports = $service->getAllReports($statusFilter !== '' ? (string)$statusFilter : null);
$statuses = $service->getStatusOptions();
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$formatStatus = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dao Observatory - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-slate-950 to-indigo-950 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 to-fuchsia-400 bg-clip-text text-transparent">Dao Observatory</h1>
            <a href="../pages/game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-4 bg-green-900/20 border border-green-500/40 rounded-xl text-green-200"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-4 bg-red-900/20 border border-red-500/40 rounded-xl text-red-200"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 mb-6">
            <a href="dao_observatory.php" class="px-3 py-2 rounded-lg border <?php echo $statusFilter === '' ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-gray-700 text-gray-300 bg-gray-900/60'; ?>">All</a>
            <?php foreach ($statuses as $status): ?>
                <a href="dao_observatory.php?status=<?php echo urlencode($status); ?>" class="px-3 py-2 rounded-lg border <?php echo $statusFilter === $status ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-gray-700 text-gray-300 bg-gray-900/60'; ?>">
                    <?php echo htmlspecialchars($formatStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="space-y-5">
            <?php foreach ($reports as $report): ?>
                <div class="bg-gray-800/90 border border-indigo-500/20 rounded-xl p-6">
                    <div class="flex flex-wrap justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-white"><?php echo htmlspecialchars((string)$report['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <div class="text-sm text-gray-400 mt-1">
                                Reporter: <?php echo htmlspecialchars((string)$report['reporter_username'], ENT_QUOTES, 'UTF-8'); ?>
                                · Location: <?php echo htmlspecialchars((string)$report['location'], ENT_QUOTES, 'UTF-8'); ?>
                                · Submitted: <?php echo htmlspecialchars((string)$report['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-lg text-xs border <?php echo match ((string)$report['status']) {
                            'resolved' => 'bg-green-500/10 border-green-500/30 text-green-300',
                            'investigating' => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
                            default => 'bg-cyan-500/10 border-cyan-500/30 text-cyan-300',
                        }; ?>">
                            <?php echo htmlspecialchars($formatStatus((string)$report['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="mb-4 p-4 bg-gray-900/60 border border-gray-700 rounded-lg text-gray-300">
                        <?php echo nl2br(htmlspecialchars((string)$report['description'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>

                    <?php if (!empty($report['admin_reply'])): ?>
                        <div class="mb-4 p-4 bg-fuchsia-900/20 border border-fuchsia-500/30 rounded-lg">
                            <div class="text-xs uppercase tracking-wide text-fuchsia-300 mb-1">Current Heavenly Dao Decree</div>
                            <div class="text-fuchsia-100"><?php echo nl2br(htmlspecialchars((string)$report['admin_reply'], ENT_QUOTES, 'UTF-8')); ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2" for="status-<?php echo (int)$report['id']; ?>">Status</label>
                            <select id="status-<?php echo (int)$report['id']; ?>" name="status" class="w-full md:w-64 px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $status === $report['status'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($formatStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2" for="reply-<?php echo (int)$report['id']; ?>">Heavenly Dao Decree</label>
                            <textarea id="reply-<?php echo (int)$report['id']; ?>" name="admin_reply" rows="4" class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-fuchsia-500"><?php echo htmlspecialchars((string)($report['admin_reply'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <button type="submit" class="px-5 py-3 bg-gradient-to-r from-cyan-600 to-fuchsia-600 hover:from-cyan-500 hover:to-fuchsia-500 text-white font-semibold rounded-lg">Issue Decree</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if (!$reports): ?>
                <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-8 text-center text-gray-400">
                    No anomalies are currently recorded beneath the Heavenly Dao.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



