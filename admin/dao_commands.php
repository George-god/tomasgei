<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/DaoCommandService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\DaoCommandService;

session_start();
$adminUserId = SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');
$adminLevel = SessionHelper::getAdminLevel() ?? DaoCommandService::LEVEL_OBSERVER;
$service = new DaoCommandService();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $command = trim((string)($_POST['command'] ?? ''));
    $params = $_POST['params'] ?? [];
    if ($command !== '' && is_array($params)) {
        $result = $service->execute($adminUserId, $adminLevel, $command, $params);
        $msg = $result['message'];
        if (!$result['success']) {
            $err = $msg;
            $msg = null;
        }
        header('Location: dao_commands.php?' . ($result['success'] ? 'msg=' : 'err=') . urlencode($result['message']));
        exit;
    }
}

$logLimit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$logCommandFilter = isset($_GET['log_command']) && $_GET['log_command'] !== '' ? (string)$_GET['log_command'] : null;
$log = $service->getLog($logLimit, $logCommandFilter, null);

$bossTemplates = [];
$itemTemplates = [];
try {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT id, name FROM world_boss_templates ORDER BY name ASC");
    $bossTemplates = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $stmt = $db->query("SELECT id, name, type FROM item_templates ORDER BY type, name ASC");
    $itemTemplates = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    // ignore
}

$can = static fn(string $cmd) => $service->canExecuteCommand($adminLevel, $cmd);
$pageTitle = 'Dao Commands';
require __DIR__ . '/includes/header.php';
?>

<p class="mb-6 text-gray-400">Your level: <strong class="text-cyan-300"><?php echo htmlspecialchars($adminLevel, ENT_QUOTES, 'UTF-8'); ?></strong>. Observer = view log only; Executor = spawn, event, grant, adjust, warn; Overseer = + ban, global decree.</p>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
    <section class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-cyan-300 mb-4">Spawn World Boss</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_SPAWN_BOSS, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Boss name (template or custom)</label>
                <input type="text" name="params[boss_name]" placeholder="e.g. Abyssal Sky Serpent" required maxlength="100" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Max HP (custom)</label>
                    <input type="number" name="params[max_hp]" value="100000" min="1" max="100000000" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Duration (min)</label>
                    <input type="number" name="params[duration_minutes]" value="120" min="1" max="10080" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                </div>
            </div>
            <?php if ($can(DaoCommandService::COMMAND_SPAWN_BOSS)): ?>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium">Spawn Boss</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires executor or higher.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-cyan-300 mb-4">Trigger Event</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_TRIGGER_EVENT, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Event name</label>
                <input type="text" name="params[event_name]" placeholder="Double Cultivation" required maxlength="100" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Start (optional)</label>
                    <input type="datetime-local" name="params[start_time]" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">End (optional)</label>
                    <input type="datetime-local" name="params[end_time]" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                </div>
            </div>
            <?php if ($can(DaoCommandService::COMMAND_TRIGGER_EVENT)): ?>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium">Trigger Event</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires executor or higher.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-cyan-300 mb-4">Grant Item</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_GRANT_ITEM, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">User ID</label>
                <input type="number" name="params[user_id]" required min="1" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Item</label>
                <select name="params[item_template_id]" required class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                    <option value="">— Select item —</option>
                    <?php foreach ($itemTemplates as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($t['type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Quantity</label>
                <input type="number" name="params[quantity]" value="1" min="1" max="9999" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <?php if ($can(DaoCommandService::COMMAND_GRANT_ITEM)): ?>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium">Grant Item</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires executor or higher.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-cyan-300 mb-4">Adjust Player</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_ADJUST_PLAYER, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">User ID</label>
                <input type="number" name="params[user_id]" required min="1" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <input type="number" name="params[level]" placeholder="Level" min="1" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[chi]" placeholder="Chi" min="0" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[max_chi]" placeholder="Max Chi" min="1" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[attack]" placeholder="Attack" min="0" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[defense]" placeholder="Defense" min="0" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[gold]" placeholder="Gold" min="0" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
                <input type="number" name="params[spirit_stones]" placeholder="Spirit Stones" min="0" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <?php if ($can(DaoCommandService::COMMAND_ADJUST_PLAYER)): ?>
            <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg font-medium">Adjust Player</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires executor or higher.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-amber-300 mb-4">Warn Player</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_WARN_PLAYER, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">User ID</label>
                <input type="number" name="params[user_id]" required min="1" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Message</label>
                <textarea name="params[message]" rows="2" required minlength="3" maxlength="1000" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white"></textarea>
            </div>
            <?php if ($can(DaoCommandService::COMMAND_WARN_PLAYER)): ?>
            <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-lg font-medium">Issue Warning</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires executor or higher.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-red-500/30 rounded-xl p-6">
        <h2 class="text-xl font-semibold text-red-300 mb-4">Ban Player</h2>
        <form method="POST" class="space-y-3" onsubmit="return confirm('Banish this cultivator from the realm?');">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_BAN_PLAYER, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">User ID</label>
                <input type="number" name="params[user_id]" required min="1" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Reason (min 5 chars)</label>
                <textarea name="params[reason]" rows="2" required minlength="5" maxlength="500" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white"></textarea>
            </div>
            <?php if ($can(DaoCommandService::COMMAND_BAN_PLAYER)): ?>
            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-lg font-medium">Ban Player</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires overseer.</p>
            <?php endif; ?>
        </form>
    </section>

    <section class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 lg:col-span-2">
        <h2 class="text-xl font-semibold text-violet-300 mb-4">Global Decree</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="command" value="<?php echo htmlspecialchars(DaoCommandService::COMMAND_GLOBAL_DECREE, ENT_QUOTES, 'UTF-8'); ?>">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Message (global announcement)</label>
                <textarea name="params[message]" rows="3" required maxlength="2000" class="w-full px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white"></textarea>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Display hours (1–720)</label>
                <input type="number" name="params[hours]" value="24" min="1" max="720" class="w-24 px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            </div>
            <?php if ($can(DaoCommandService::COMMAND_GLOBAL_DECREE)): ?>
            <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white rounded-lg font-medium">Post Decree</button>
            <?php else: ?>
            <p class="text-amber-400 text-sm">Requires overseer.</p>
            <?php endif; ?>
        </form>
    </section>
</div>

<h2 class="text-2xl font-semibold text-cyan-300 mb-4">Command Log</h2>
<form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-sm text-gray-400 mb-1">Command filter</label>
        <select name="log_command" class="px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
            <option value="">All</option>
            <?php foreach (DaoCommandService::getAllCommands() as $cmd): ?>
            <option value="<?php echo htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $logCommandFilter === $cmd ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $cmd), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Limit</label>
        <input type="number" name="limit" value="<?php echo (int)$logLimit; ?>" min="1" max="200" class="w-20 px-4 py-2 bg-gray-900 border border-gray-700 rounded-lg text-white">
    </div>
    <button type="submit" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg">Apply</button>
</form>

<div class="overflow-x-auto">
    <table class="w-full text-left">
        <thead>
            <tr class="border-b border-cyan-500/30 text-cyan-300">
                <th class="py-3 px-2">Time</th>
                <th class="py-3 px-2">Admin</th>
                <th class="py-3 px-2">Command</th>
                <th class="py-3 px-2">Target</th>
                <th class="py-3 px-2">Result</th>
                <th class="py-3 px-2">Message</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($log as $row): ?>
            <tr class="border-b border-gray-700/50 hover:bg-gray-800/50">
                <td class="py-3 px-2 text-gray-400 text-sm"><?php echo htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-3 px-2 text-gray-300"><?php echo htmlspecialchars($row['admin_username'] ?? '#' . ($row['admin_user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-3 px-2 text-white font-medium"><?php echo htmlspecialchars(str_replace('_', ' ', $row['command'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="py-3 px-2 text-gray-300"><?php echo isset($row['target_id']) && $row['target_id'] !== null ? (int)$row['target_id'] : '—'; ?></td>
                <td class="py-3 px-2">
                    <?php if (!empty($row['result_success'])): ?>
                    <span class="px-2 py-1 rounded bg-green-500/20 text-green-300 text-xs">OK</span>
                    <?php else: ?>
                    <span class="px-2 py-1 rounded bg-red-500/20 text-red-300 text-xs">Fail</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 px-2 text-gray-400 text-sm max-w-xs truncate" title="<?php echo htmlspecialchars($row['result_message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['result_message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (empty($log)): ?>
<div class="mt-6 p-6 bg-gray-800/90 border border-gray-700 rounded-xl text-center text-gray-400">No command log entries yet.</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
