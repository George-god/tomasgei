<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';

use Game\Helper\SessionHelper;
use Game\Service\SectService;
use Game\Config\Database;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$mySect = $sectService->getSectByUserId($userId);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if ($action === 'create_sect') {
        $name = trim((string)($_POST['name'] ?? ''));
        $result = $sectService->createSect($userId, $name);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = $sectService->getSectByUserId($userId);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'join_sect') {
        $sectId = (int)($_POST['sect_id'] ?? 0);
        $result = $sectService->joinSect($userId, $sectId);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = $sectService->getSectByUserId($userId);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'leave_sect') {
        $result = $sectService->leaveSect($userId);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = null;
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'disband_sect') {
        $result = $sectService->disbandSect($userId);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = null;
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'promote_member') {
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $result = $sectService->promoteMember($userId, $memberUserId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'demote_member') {
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $result = $sectService->demoteMember($userId, $memberUserId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'transfer_leadership') {
        $newLeaderUserId = (int)($_POST['user_id'] ?? 0);
        $result = $sectService->transferLeadership($userId, $newLeaderUserId);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = $sectService->getSectByUserId($userId);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'donate') {
        $amount = (int)($_POST['amount'] ?? 0);
        $result = $sectService->donate($userId, $amount);
        if ($result['success']) {
            $message = $result['message'];
            $mySect = $sectService->getSectByUserId($userId);
        } else {
            $error = $result['message'];
        }
    }
}

$sectsList = $sectService->listSects();
$db = Database::getConnection();
$stmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userGold = (int)($stmt->fetch()['gold'] ?? 0);
$members = $mySect ? $sectService->getMembers((int)$mySect['id']) : [];
$isLeader = $mySect && (string)($mySect['rank'] ?? $mySect['role'] ?? '') === 'leader';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sect - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-500 to-orange-600 bg-clip-text text-transparent">Sect</h1>
            </div>
            <div class="flex gap-2">
                <a href="sect_leaderboard.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Leaderboard</a>
                <a href="sect_base.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-emerald-500/30 text-emerald-300 transition-all">Base</a>
                <a href="sect_library.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Library</a>
                <a href="sect_missions.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-teal-500/30 text-teal-300 transition-all">Missions</a>
                <a href="territories.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-green-500/30 text-green-300 transition-all">Territories</a>
                <a href="alliance.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-sky-500/30 text-sky-300 transition-all">Alliance</a>
                <a href="diplomacy.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Diplomacy</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($mySect): ?>
        <!-- My sect -->
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-amber-300 mb-2"><?php echo htmlspecialchars($mySect['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="text-gray-400 text-sm mb-1">Tier: <strong class="text-white"><?php echo htmlspecialchars(ucfirst((string)$mySect['tier']), ENT_QUOTES, 'UTF-8'); ?></strong> · Sect EXP: <?php echo number_format((int)($mySect['sect_exp'] ?? 0)); ?> · Diplomatic reputation: <strong class="text-violet-300"><?php echo number_format((int)($mySect['sect_reputation'] ?? 1000)); ?></strong></p>
            <p class="text-gray-500 text-xs mb-4">Your rank: <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($mySect['rank'] ?? $mySect['role'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></strong>. Bonuses: +<?php echo number_format((float)($mySect['bonuses']['cultivation_speed'] ?? 0) * 100, 1); ?>% cultivation, +<?php echo number_format((float)($mySect['bonuses']['gold_gain'] ?? 0) * 100, 1); ?>% gold<?php if ((float)($mySect['bonuses']['breakthrough'] ?? 0) > 0): ?>, +<?php echo number_format((float)($mySect['bonuses']['breakthrough']) * 100, 1); ?>% breakthrough<?php endif; ?>.</p>
            <p class="text-gray-500 text-xs mb-4">Base NPC support: +<?php echo number_format((float)($mySect['base_bonuses']['cultivation_speed'] ?? 0) * 100, 1); ?>% cultivation, +<?php echo number_format((float)($mySect['base_bonuses']['gold_gain'] ?? 0) * 100, 1); ?>% gold, +<?php echo number_format((float)($mySect['base_bonuses']['breakthrough'] ?? 0) * 100, 1); ?>% breakthrough.</p>
            <p class="text-gray-500 text-xs mb-4">Rank ladder: Outer Disciple -> Inner Disciple -> Core Disciple -> Elder -> Leader. Promotion requirements: Inner (100 contribution, realm 2), Core (300 contribution, realm 3), Elder (700 contribution, realm 4).</p>

            <div class="mb-4 p-3 bg-gray-900/50 rounded-lg border border-amber-500/20">
                <h3 class="text-sm font-semibold text-amber-300 mb-2">Donate to sect</h3>
                <p class="text-gray-400 text-xs mb-2">100 gold = 1 contribution, 2 sect EXP. Minimum 100 gold.</p>
                <form method="POST" class="flex gap-2 flex-wrap items-center">
                    <input type="hidden" name="action" value="donate">
                    <input type="number" name="amount" min="100" step="100" value="100" placeholder="Gold" class="w-24 bg-gray-800 border border-gray-600 rounded px-2 py-1 text-white text-sm">
                    <button type="submit" class="px-3 py-1 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded">Donate</button>
                </form>
            </div>

            <h3 class="text-sm font-semibold text-gray-300 mt-4 mb-2">Members <span class="text-gray-500 font-normal">(ranked by contribution)</span></h3>
            <ul class="space-y-2 mb-4">
                <?php foreach ($members as $m): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 bg-gray-900/50 rounded-lg px-3 py-2">
                    <span class="text-white"><?php echo htmlspecialchars($m['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-gray-400 text-sm"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($m['rank'] ?? $m['role']))), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-cyan-300 text-sm"><?php echo htmlspecialchars((string)($m['realm_name'] ?? 'Unknown Realm'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-amber-300 text-sm"><?php echo number_format((int)($m['contribution'] ?? 0)); ?> contrib.</span>
                    <?php if ($isLeader && (int)$m['user_id'] !== $userId): ?>
                        <?php if (!in_array((string)($m['rank'] ?? $m['role']), ['leader', 'elder'], true)): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="promote_member">
                            <input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
                            <button type="submit" class="text-amber-400 hover:underline text-sm">Promote</button>
                        </form>
                        <?php endif; ?>
                        <?php if ((string)($m['rank'] ?? $m['role']) !== 'outer_disciple' && (string)($m['rank'] ?? $m['role']) !== 'leader'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="demote_member">
                            <input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
                            <button type="submit" class="text-gray-400 hover:underline text-sm">Demote</button>
                        </form>
                        <?php endif; ?>
                        <?php if ((string)($m['rank'] ?? $m['role']) === 'elder'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="transfer_leadership">
                            <input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
                            <button type="submit" class="text-amber-400 hover:underline text-sm">Transfer leadership</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isLeader): ?>
            <form method="POST" onsubmit="return confirm('Disband the sect? All members will be removed.');" class="inline">
                <input type="hidden" name="action" value="disband_sect">
                <button type="submit" class="px-3 py-1 bg-red-900/50 hover:bg-red-800 border border-red-500/50 rounded text-red-300 text-sm">Disband sect</button>
            </form>
            <?php else: ?>
            <form method="POST" onsubmit="return confirm('Leave this sect?');" class="inline">
                <input type="hidden" name="action" value="leave_sect">
                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-gray-300 text-sm">Leave sect</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Sect chat (Phase 2.5): only for members -->
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-amber-300 mb-2">Sect chat</h2>
            <div id="sect-chat-messages" class="h-64 overflow-y-auto bg-gray-900/50 rounded-lg p-3 mb-3 text-sm space-y-2 border border-gray-700"></div>
            <form id="sect-chat-form" class="flex gap-2">
                <input type="text" id="sect-chat-input" placeholder="Message (max 300 chars)..." maxlength="300" class="flex-1 bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                <button type="submit" id="sect-chat-send" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-lg">Send</button>
            </form>
            <p id="sect-chat-error" class="mt-2 text-red-400 text-xs hidden"></p>
        </div>
        <?php else: ?>
        <!-- Not in a sect: create or join -->
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-amber-300 mb-4">Create a sect</h2>
            <p class="text-gray-400 text-sm mb-2">Cost: 5000 gold. You have <?php echo number_format($userGold); ?> gold.</p>
            <form method="POST" class="flex gap-2 flex-wrap">
                <input type="hidden" name="action" value="create_sect">
                <input type="text" name="name" placeholder="Sect name" maxlength="100" required class="flex-1 min-w-[160px] bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white">
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg" <?php if ($userGold < 5000): ?>disabled<?php endif; ?>><?php echo $userGold >= 5000 ? 'Create (5000 gold)' : 'Not enough gold'; ?></button>
            </form>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-gray-300 mb-4">Join a sect</h2>
            <?php if (empty($sectsList)): ?>
                <p class="text-gray-500">No sects yet. Create one above.</p>
            <?php else: ?>
            <ul class="space-y-2">
                <?php foreach ($sectsList as $s): ?>
                <li class="flex flex-wrap items-center justify-between gap-4 bg-gray-900/50 rounded-lg px-4 py-3">
                    <div>
                        <span class="font-medium text-white"><?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-gray-400 text-sm ml-2"><?php echo ucfirst((string)$s['tier']); ?> · <?php echo (int)$s['member_count']; ?> members · <?php echo number_format((int)($s['sect_exp'] ?? 0)); ?> EXP</span>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="join_sect">
                        <input type="hidden" name="sect_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-lg">Join</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($mySect): ?>
    <script>
    (function() {
        var messagesEl = document.getElementById('sect-chat-messages');
        var form = document.getElementById('sect-chat-form');
        var input = document.getElementById('sect-chat-input');
        var sendBtn = document.getElementById('sect-chat-send');
        var errorEl = document.getElementById('sect-chat-error');
        var lastMessageId = 0;
        var pollInterval = 5000;

        function showError(msg) {
            if (!errorEl) return;
            errorEl.textContent = msg || '';
            errorEl.classList.toggle('hidden', !msg);
        }

        function appendMessage(msg) {
            if (!messagesEl || !msg.id) return;
            var row = document.createElement('div');
            row.className = 'flex flex-wrap gap-2 items-baseline';
            row.dataset.id = String(msg.id);
            var user = document.createElement('span');
            user.className = 'font-medium text-amber-300';
            user.textContent = (msg.username || '') + ':';
            var text = document.createElement('span');
            text.className = 'text-gray-300';
            text.textContent = msg.message || '';
            var time = document.createElement('span');
            time.className = 'text-gray-500 text-xs';
            time.textContent = msg.created_at || '';
            row.appendChild(user);
            row.appendChild(text);
            row.appendChild(time);
            messagesEl.appendChild(row);
            messagesEl.scrollTop = messagesEl.scrollHeight;
            if (msg.id > lastMessageId) lastMessageId = msg.id;
        }

        function fetchMessages(afterId) {
            var url = '../controllers/sect_chat_fetch.php' + (afterId ? '?last_message_id=' + afterId : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.data || !data.data.messages) return;
                    var list = data.data.messages;
                    list.forEach(function(msg) { appendMessage(msg); });
                })
                .catch(function() {});
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var text = (input && input.value) ? input.value.trim() : '';
            if (!text) return;
            sendBtn.disabled = true;
            showError('');
            var fd = new FormData();
            fd.append('message', text);
            fetch('../controllers/sect_chat_send.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        input.value = '';
                        appendMessage({
                            id: data.data.id,
                            user_id: <?php echo (int)$userId; ?>,
                            username: <?php echo json_encode($_SESSION['username'] ?? 'You'); ?>,
                            message: text,
                            created_at: data.data.created_at || ''
                        });
                    } else {
                        showError(data.message || 'Send failed.');
                    }
                })
                .catch(function() { showError('Send failed.'); })
                .then(function() { sendBtn.disabled = false; });
        });

        fetchMessages(0);
        setInterval(function() { fetchMessages(lastMessageId); }, pollInterval);
    })();
    </script>
    <?php endif; ?>
</body>
</html>




