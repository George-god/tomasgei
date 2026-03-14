<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DaoPetitionService.php';

use Game\Helper\SessionHelper;
use Game\Service\DaoPetitionService;

session_start();
$adminUserId = SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');
$service = new DaoPetitionService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $service->updatePetition(
        $adminUserId,
        (int)($_POST['petition_id'] ?? 0),
        (string)($_POST['status'] ?? 'observing'),
        (string)($_POST['heavenly_response'] ?? '')
    );

    $statusFilter = isset($_GET['status']) ? '&status=' . urlencode((string)$_GET['status']) : '';
    $query = $result['success']
        ? '?msg=' . urlencode((string)$result['message']) . $statusFilter
        : '?err=' . urlencode((string)$result['message']) . $statusFilter;
    header('Location: dao_petitions.php' . $query);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$petitions = $service->getAllPetitions($statusFilter !== '' ? (string)$statusFilter : null);
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
    <title>Heavenly Dao Petitions - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-indigo-950 to-slate-950 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-cyan-400 bg-clip-text text-transparent">Heavenly Dao Petition Review</h1>
            <a href="../pages/game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-4 bg-green-900/20 border border-green-500/40 rounded-xl text-green-200"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-4 bg-red-900/20 border border-red-500/40 rounded-xl text-red-200"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 mb-6">
            <a href="dao_petitions.php" class="px-3 py-2 rounded-lg border <?php echo $statusFilter === '' ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-gray-700 text-gray-300 bg-gray-900/60'; ?>">All</a>
            <?php foreach ($statuses as $status): ?>
                <a href="dao_petitions.php?status=<?php echo urlencode($status); ?>" class="px-3 py-2 rounded-lg border <?php echo $statusFilter === $status ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-gray-700 text-gray-300 bg-gray-900/60'; ?>">
                    <?php echo htmlspecialchars($formatStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="space-y-5">
            <?php foreach ($petitions as $petition): ?>
                <div class="bg-gray-800/90 border border-violet-500/20 rounded-xl p-6">
                    <div class="flex flex-wrap justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-white"><?php echo htmlspecialchars((string)$petition['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <div class="text-sm text-gray-400 mt-1">
                                Petitioner: <?php echo htmlspecialchars((string)$petition['reporter_username'], ENT_QUOTES, 'UTF-8'); ?>
                                · Category: <?php echo htmlspecialchars((string)$petition['category'], ENT_QUOTES, 'UTF-8'); ?>
                                · Submitted: <?php echo htmlspecialchars((string)$petition['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <span class="px-3 py-1 rounded-lg text-xs border <?php echo match ((string)$petition['status']) {
                            'accepted' => 'bg-green-500/10 border-green-500/30 text-green-300',
                            'denied' => 'bg-red-500/10 border-red-500/30 text-red-300',
                            'contemplating' => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
                            default => 'bg-cyan-500/10 border-cyan-500/30 text-cyan-300',
                        }; ?>">
                            <?php echo htmlspecialchars($formatStatus((string)$petition['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="mb-4 p-4 bg-gray-900/60 border border-gray-700 rounded-lg text-gray-300">
                        <?php echo nl2br(htmlspecialchars((string)$petition['description'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>

                    <?php if (!empty($petition['heavenly_response'])): ?>
                        <div class="mb-4 p-4 bg-cyan-900/20 border border-cyan-500/30 rounded-lg">
                            <div class="text-xs uppercase tracking-wide text-cyan-300 mb-1">Current Heavenly Dao Message</div>
                            <div class="text-cyan-100"><?php echo nl2br(htmlspecialchars((string)$petition['heavenly_response'], ENT_QUOTES, 'UTF-8')); ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="petition_id" value="<?php echo (int)$petition['id']; ?>">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2" for="status-<?php echo (int)$petition['id']; ?>">Status</label>
                            <select id="status-<?php echo (int)$petition['id']; ?>" name="status" class="w-full md:w-64 px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $status === $petition['status'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($formatStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2" for="response-<?php echo (int)$petition['id']; ?>">Heavenly Dao Message</label>
                            <textarea id="response-<?php echo (int)$petition['id']; ?>" name="heavenly_response" rows="4" class="w-full px-4 py-3 bg-gray-900/60 border border-gray-700 rounded-lg text-white focus:outline-none focus:border-violet-500"><?php echo htmlspecialchars((string)($petition['heavenly_response'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <button type="submit" class="px-5 py-3 bg-gradient-to-r from-violet-600 to-cyan-600 hover:from-violet-500 hover:to-cyan-500 text-white font-semibold rounded-lg">Issue Heavenly Message</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if (!$petitions): ?>
                <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-8 text-center text-gray-400">
                    No petitions currently await the Heavenly Dao's judgment.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



