<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/AdminUserService.php';

use Game\Helper\SessionHelper;
use Game\Service\AdminUserService;

session_start();
$adminUserId = SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');
$service = new AdminUserService();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$search = trim((string)($_GET['search'] ?? ''));
$bannedOnly = isset($_GET['banned']) && $_GET['banned'] === '1';
$detailUserId = max(0, (int)($_GET['user_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'warn' && $targetId > 0) {
        $result = $service->issueWarning($adminUserId, $targetId, (string)($_POST['message'] ?? ''));
        $msg = $result['message'];
        if (!$result['success']) {
            $err = $msg;
            $msg = null;
        }
        header('Location: users.php?user_id=' . $targetId . '&' . ($result['success'] ? 'msg=' : 'err=') . urlencode($msg ?? $err));
        exit;
    }
    if ($action === 'ban' && $targetId > 0) {
        $result = $service->banUser($adminUserId, $targetId, (string)($_POST['reason'] ?? ''));
        $msg = $result['message'];
        if (!$result['success']) {
            $err = $msg;
            $msg = null;
        }
        header('Location: users.php?user_id=' . $targetId . '&' . ($result['success'] ? 'msg=' : 'err=') . urlencode($msg ?? $err));
        exit;
    }
    if ($action === 'unban' && $targetId > 0) {
        $result = $service->unbanUser($adminUserId, $targetId);
        $msg = $result['message'];
        if (!$result['success']) {
            $err = $msg;
            $msg = null;
        }
        header('Location: users.php?' . ($result['success'] ? 'msg=' : 'err=') . urlencode($msg ?? $err));
        exit;
    }
}

$users = $service->getUsersForAdmin($search !== '' ? $search : null);
if ($bannedOnly) {
    $users = array_filter($users, fn($u) => !empty($u['is_banned']));
}
$detailUser = $detailUserId > 0 ? $service->getUserWithWarnings($detailUserId) : null;

$pageTitle = 'Cultivator Registry';
require __DIR__ . '/includes/header.php';
?>

        <form method="GET" class="mb-6 flex flex-wrap gap-4">
            <input type="text" name="search" placeholder="Search by username or email..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                   class="px-4 py-2 bg-gray-900/60 border border-gray-700 rounded-lg text-white w-64">
            <label class="flex items-center gap-2 text-gray-300">
                <input type="checkbox" name="banned" value="1" <?php echo $bannedOnly ? 'checked' : ''; ?> onchange="this.form.submit()">
                Banished only
            </label>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium">Search</button>
        </form>

        <?php if ($detailUser): ?>
        <div class="mb-8 bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-cyan-300 mb-4">Cultivator Details: <?php echo htmlspecialchars($detailUser['username'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-6">
                <div><span class="text-gray-400">Realm:</span> <?php echo htmlspecialchars($detailUser['realm_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                <div><span class="text-gray-400">Level:</span> <?php echo (int)$detailUser['level']; ?></div>
                <div><span class="text-gray-400">Rating:</span> <?php echo number_format((float)$detailUser['rating'], 1); ?></div>
                <div><span class="text-gray-400">Joined:</span> <?php echo htmlspecialchars($detailUser['created_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (!empty($detailUser['is_banned'])): ?>
                <div class="md:col-span-2"><span class="text-red-400">Banished:</span> <?php echo htmlspecialchars($detailUser['ban_reason'] ?? 'No reason', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($detailUser['banned_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</div>
                <?php endif; ?>
            </div>

            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-300 mb-2">Heavenly Admonitions (<?php echo count($detailUser['warnings'] ?? []); ?>)</h3>
                <?php foreach ($detailUser['warnings'] ?? [] as $w): ?>
                <div class="p-3 bg-amber-900/20 border border-amber-500/30 rounded-lg mb-2 text-sm">
                    <?php echo nl2br(htmlspecialchars($w['message'], ENT_QUOTES, 'UTF-8')); ?>
                    <div class="text-gray-500 text-xs mt-1">By <?php echo htmlspecialchars($w['admin_username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($w['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="flex flex-wrap gap-4">
                <?php if (empty($detailUser['is_banned'])): ?>
                <form method="POST" class="flex gap-2 items-end">
                    <input type="hidden" name="action" value="warn">
                    <input type="hidden" name="user_id" value="<?php echo (int)$detailUser['id']; ?>">
                    <input type="text" name="message" placeholder="Heavenly Dao admonition..." required class="px-4 py-2 bg-gray-900/60 border border-gray-700 rounded-lg text-white w-64">
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-lg font-medium">Issue Warning</button>
                </form>
                <form method="POST" class="flex gap-2 items-end" onsubmit="return confirm('The Heavenly Dao shall banish this cultivator from the realm. Proceed?');">
                    <input type="hidden" name="action" value="ban">
                    <input type="hidden" name="user_id" value="<?php echo (int)$detailUser['id']; ?>">
                    <input type="text" name="reason" placeholder="Reason for banishment..." required class="px-4 py-2 bg-gray-900/60 border border-gray-700 rounded-lg text-white w-64">
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-lg font-medium">Banish</button>
                </form>
                <?php else: ?>
                <form method="POST" onsubmit="return confirm('Welcome this cultivator back to the realm?');">
                    <input type="hidden" name="action" value="unban">
                    <input type="hidden" name="user_id" value="<?php echo (int)$detailUser['id']; ?>">
                    <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-500 text-white rounded-lg font-medium">Lift Banishment</button>
                </form>
                <?php endif; ?>
                <a href="users.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded-lg">Close</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-cyan-500/30 text-cyan-300">
                        <th class="py-3 px-2">ID</th>
                        <th class="py-3 px-2">Username</th>
                        <th class="py-3 px-2">Realm</th>
                        <th class="py-3 px-2">Level</th>
                        <th class="py-3 px-2">Rating</th>
                        <th class="py-3 px-2">Joined</th>
                        <th class="py-3 px-2">Status</th>
                        <th class="py-3 px-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr class="border-b border-gray-700/50 hover:bg-gray-800/50">
                        <td class="py-3 px-2 text-gray-300"><?php echo (int)$u['id']; ?></td>
                        <td class="py-3 px-2 text-white font-medium"><?php echo htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-3 px-2 text-gray-300"><?php echo htmlspecialchars($u['realm_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-3 px-2 text-gray-300"><?php echo (int)$u['level']; ?></td>
                        <td class="py-3 px-2 text-gray-300"><?php echo number_format((float)($u['rating'] ?? 0), 1); ?></td>
                        <td class="py-3 px-2 text-gray-400 text-sm"><?php echo htmlspecialchars($u['created_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-3 px-2">
                            <?php if (!empty($u['is_banned'])): ?>
                            <span class="px-2 py-1 rounded bg-red-500/20 text-red-300 text-xs">Banished</span>
                            <?php else: ?>
                            <span class="px-2 py-1 rounded bg-green-500/20 text-green-300 text-xs">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-2">
                            <a href="users.php?user_id=<?php echo (int)$u['id']; ?>" class="text-cyan-400 hover:text-cyan-300 text-sm">Manage</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$users): ?>
        <div class="mt-8 p-8 bg-gray-800/90 border border-gray-700 rounded-xl text-center text-gray-400">
            No cultivators match the current search. The celestial ledger is empty.
        </div>
        <?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
