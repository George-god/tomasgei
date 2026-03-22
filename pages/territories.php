<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';
require_once dirname(__DIR__) . '/services/SectWarService.php';

use Game\Helper\SessionHelper;
use Game\Service\SectService;
use Game\Service\SectWarService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$warService = new SectWarService();
$mySect = $sectService->getSectByUserId($userId);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'declare_war') {
    $territoryId = (int)($_POST['territory_id'] ?? 0);
    $result = $warService->declareWar($userId, $territoryId);
    if (!empty($result['success'])) {
        $message = $result['message'] ?? 'Sect war declared.';
    } else {
        $error = $result['message'] ?? 'Could not declare war.';
    }
}

$territories = $warService->getTerritoriesOverview($userId);
$activeWar = $warService->getActiveWarForUser($userId);
$canDeclareWar = $mySect && in_array((string)($mySect['rank'] ?? $mySect['role'] ?? ''), ['leader', 'elder'], true);
$activeWarId = $activeWar['war']['id'] ?? 0;
$userSide = $activeWar['user_side'] ?? null;
$initialCooldown = (int)($activeWar['cooldown_remaining'] ?? 0);
$totalTerritories = count($territories);
$controlledTerritories = (int)count(array_filter($territories, static fn(array $t): bool => !empty($t['owner_sect_id'])));
$neutralTerritories = $totalTerritories - $controlledTerritories;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Territories - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-green-400 to-emerald-400 bg-clip-text text-transparent">Territories</h1>
            </div>
            <div class="flex gap-2">
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="alliance.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-sky-500/30 text-sky-300 transition-all">Alliance</a>
                <a href="diplomacy.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Diplomacy</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-gray-800/90 border border-green-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Captureable Territories</div>
                <div class="text-2xl font-bold text-green-300"><?php echo $totalTerritories; ?></div>
            </div>
            <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Controlled</div>
                <div class="text-2xl font-bold text-cyan-300"><?php echo $controlledTerritories; ?></div>
            </div>
            <div class="bg-gray-800/90 border border-gray-500/30 rounded-xl p-4">
                <div class="text-sm text-gray-400 mb-1">Unclaimed</div>
                <div class="text-2xl font-bold text-gray-300"><?php echo $neutralTerritories; ?></div>
            </div>
        </div>

        <?php if ($activeWar): ?>
        <div class="bg-gray-800/90 border border-red-500/30 rounded-xl p-6 mb-8">
            <div class="flex flex-wrap justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-red-300"><?php echo htmlspecialchars($activeWar['war']['region_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="text-sm text-gray-400"><?php echo htmlspecialchars($activeWar['war']['attacker_sect_name'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($activeWar['war']['defender_sect_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($activeWar['war']['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="text-right">
                    <div id="war-time-label" class="text-sm text-amber-300" data-end-time="<?php echo htmlspecialchars($activeWar['war']['end_time'], ENT_QUOTES, 'UTF-8'); ?>">Ends: <?php echo htmlspecialchars($activeWar['war']['end_time'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-xs text-gray-500 mt-1">You are fighting as <span class="font-semibold text-white"><?php echo htmlspecialchars((string)$userSide, ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
            </div>

            <div id="war-message" class="mb-4 hidden"></div>

            <div class="mb-2 flex justify-between text-sm text-gray-400">
                <span>War Crystal</span>
                <span id="war-crystal-text"><?php echo number_format((int)$activeWar['war']['crystal_current_hp']); ?> / <?php echo number_format((int)$activeWar['war']['crystal_max_hp']); ?></span>
            </div>
            <div class="w-full bg-gray-900 rounded-full h-6 overflow-hidden border border-gray-700 mb-4">
                <div id="war-crystal-bar" class="h-full bg-gradient-to-r from-red-600 to-orange-500 transition-all duration-500" style="width: <?php echo (int)$activeWar['war']['crystal_percent']; ?>%"></div>
            </div>

            <div class="flex flex-wrap items-center gap-3 mb-6">
                <button type="button" id="war-action-btn" class="px-6 py-3 bg-red-600 hover:bg-red-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold rounded-xl transition-all" <?php if ($initialCooldown > 0): ?>disabled<?php endif; ?>>
                    <?php if ($userSide === 'attacker'): ?>
                        <?php echo $initialCooldown > 0 ? "Attack Crystal ({$initialCooldown}s)" : 'Attack Crystal'; ?>
                    <?php else: ?>
                        <?php echo $initialCooldown > 0 ? "Repel Invaders ({$initialCooldown}s)" : 'Repel Invaders'; ?>
                    <?php endif; ?>
                </button>
                <span id="war-cooldown-hint" class="text-sm text-gray-500"><?php echo $initialCooldown > 0 ? "Cooldown: {$initialCooldown}s" : '30s cooldown per action'; ?></span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-gray-900/40 rounded-lg border border-gray-700 p-4">
                    <h3 class="text-lg font-semibold text-red-300 mb-3"><?php echo htmlspecialchars($activeWar['war']['attacker_sect_name'], ENT_QUOTES, 'UTF-8'); ?> Attackers</h3>
                    <div id="war-attackers-board"></div>
                </div>
                <div class="bg-gray-900/40 rounded-lg border border-gray-700 p-4">
                    <h3 class="text-lg font-semibold text-cyan-300 mb-3"><?php echo htmlspecialchars($activeWar['war']['defender_sect_name'], ENT_QUOTES, 'UTF-8'); ?> Defenders</h3>
                    <div id="war-defenders-board"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-gray-800/90 border border-green-500/30 rounded-xl p-6">
            <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
                <h2 class="text-xl font-semibold text-green-300">Territory Fronts</h2>
                <?php if (!$mySect): ?>
                    <span class="text-sm text-gray-400">Join a sect to participate in sect wars.</span>
                <?php elseif (!$canDeclareWar): ?>
                    <span class="text-sm text-gray-400">Only leaders and elders can declare wars.</span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($territories as $territory): ?>
                    <div class="bg-gray-900/60 border <?php echo !empty($territory['active_war_id']) ? 'border-red-500/40' : 'border-gray-700'; ?> rounded-lg p-4">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div>
                                <h3 class="font-semibold text-white"><?php echo htmlspecialchars($territory['region_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="text-xs text-gray-500">Difficulty <?php echo (int)$territory['difficulty']; ?></p>
                            </div>
                            <?php if (!empty($territory['owner_sect_name'])): ?>
                                <span class="px-2 py-1 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs"><?php echo htmlspecialchars($territory['owner_sect_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                                <span class="px-2 py-1 rounded bg-gray-500/10 border border-gray-500/30 text-gray-300 text-xs">Unclaimed</span>
                            <?php endif; ?>
                        </div>

                        <p class="text-sm text-gray-400 mb-3"><?php echo htmlspecialchars($territory['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>

                        <?php if (!empty($territory['active_war_id'])): ?>
                            <div class="mb-3 p-3 bg-red-900/20 border border-red-500/30 rounded-lg">
                                <div class="text-sm text-red-300 font-semibold">Active sect war</div>
                                <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($territory['attacker_sect_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($territory['defender_sect_name'] ?? 'Unclaimed Land', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="w-full bg-gray-800 rounded-full h-2 mt-3 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-red-500 to-orange-400" style="width: <?php echo (int)$territory['crystal_percent']; ?>%"></div>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Crystal HP: <?php echo number_format((int)($territory['crystal_current_hp'] ?? 0)); ?> / <?php echo number_format((int)($territory['crystal_max_hp'] ?? 0)); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($canDeclareWar && !empty($territory['my_sect_can_challenge']) && !$activeWar): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="declare_war">
                                <input type="hidden" name="territory_id" value="<?php echo (int)$territory['id']; ?>">
                                <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg">
                                    Declare War
                                </button>
                            </form>
                        <?php elseif (!empty($territory['is_owned_by_my_sect'])): ?>
                            <div class="text-sm text-emerald-300">Controlled by your sect</div>
                        <?php elseif (!empty($territory['owner_is_allied'])): ?>
                            <div class="text-sm text-sky-300">Held by an allied sect — you cannot declare war here.</div>
                        <?php elseif (!empty($territory['owner_has_nap'])): ?>
                            <div class="text-sm text-violet-300">Non-aggression pact with the holder — you cannot declare war here.</div>
                        <?php elseif ($activeWar): ?>
                            <div class="text-sm text-gray-500">Your sect is already fighting another war.</div>
                        <?php else: ?>
                            <div class="text-sm text-gray-500">Unavailable</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($activeWar): ?>
    <script>
    (function() {
        var warId = <?php echo (int)$activeWarId; ?>;
        var userSide = <?php echo json_encode($userSide); ?>;
        var actionBtn = document.getElementById('war-action-btn');
        var crystalBar = document.getElementById('war-crystal-bar');
        var crystalText = document.getElementById('war-crystal-text');
        var msgEl = document.getElementById('war-message');
        var cooldownHint = document.getElementById('war-cooldown-hint');
        var timeLabel = document.getElementById('war-time-label');
        var attackerBoard = document.getElementById('war-attackers-board');
        var defenderBoard = document.getElementById('war-defenders-board');
        var crystalMaxHp = <?php echo (int)$activeWar['war']['crystal_max_hp']; ?>;
        var endTime = <?php echo json_encode($activeWar['war']['end_time']); ?>;
        var cooldownInterval = null;

        function showMsg(text, isError) {
            if (!msgEl) return;
            msgEl.textContent = text || '';
            msgEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
            msgEl.classList.remove('hidden');
        }

        function renderBoard(target, rows, emptyText) {
            if (!target) return;
            target.innerHTML = '';
            if (!rows || !rows.length) {
                var empty = document.createElement('p');
                empty.className = 'text-sm text-gray-500';
                empty.textContent = emptyText;
                target.appendChild(empty);
                return;
            }

            var list = document.createElement('ul');
            list.className = 'space-y-2';
            rows.forEach(function(row, index) {
                var item = document.createElement('li');
                item.className = 'flex justify-between items-center bg-gray-950/50 rounded-lg px-3 py-2';

                var left = document.createElement('span');
                left.className = 'text-gray-300';
                left.textContent = '#' + (index + 1) + ' ' + (row.username || 'Unknown');

                var right = document.createElement('span');
                right.className = 'text-amber-300 text-sm font-semibold';
                right.textContent = parseInt(row.damage_dealt || 0, 10).toLocaleString() + ' dmg • ' + parseInt(row.kills || 0, 10) + ' kills';

                item.appendChild(left);
                item.appendChild(right);
                list.appendChild(item);
            });
            target.appendChild(list);
        }

        function setCooldown(seconds) {
            if (!actionBtn || !cooldownHint) return;
            var label = userSide === 'attacker' ? 'Attack Crystal' : 'Repel Invaders';
            if (seconds <= 0) {
                actionBtn.disabled = false;
                actionBtn.textContent = label;
                cooldownHint.textContent = '30s cooldown per action';
                return;
            }
            actionBtn.disabled = true;
            actionBtn.textContent = label + ' (' + seconds + 's)';
            cooldownHint.textContent = 'Cooldown: ' + seconds + 's';
        }

        function startCooldown(seconds) {
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            setCooldown(seconds);
            var remaining = seconds;
            cooldownInterval = setInterval(function() {
                remaining--;
                setCooldown(remaining);
                if (remaining <= 0) {
                    clearInterval(cooldownInterval);
                    cooldownInterval = null;
                }
            }, 1000);
        }

        function updateTimer() {
            if (!timeLabel || !endTime) return;
            var endTs = new Date(endTime.replace(' ', 'T')).getTime();
            var remaining = Math.max(0, Math.floor((endTs - Date.now()) / 1000));
            var min = Math.floor(remaining / 60);
            var sec = remaining % 60;
            timeLabel.textContent = remaining > 0 ? ('Time remaining: ' + min + 'm ' + sec + 's') : 'Time remaining: 0m 0s';
        }

        function renderState(payload) {
            if (!payload || !payload.war) {
                showMsg('This sect war has ended. Refreshing...', false);
                setTimeout(function() { window.location.reload(); }, 1500);
                return;
            }

            endTime = payload.war.end_time || endTime;
            crystalMaxHp = parseInt(payload.war.crystal_max_hp || crystalMaxHp, 10);
            var currentHp = parseInt(payload.war.crystal_current_hp || 0, 10);
            var pct = crystalMaxHp > 0 ? Math.min(100, Math.round(100 * currentHp / crystalMaxHp)) : 0;
            if (crystalBar) crystalBar.style.width = pct + '%';
            if (crystalText) crystalText.textContent = currentHp.toLocaleString() + ' / ' + crystalMaxHp.toLocaleString();

            renderBoard(attackerBoard, payload.attackers || [], 'No attacker contribution yet.');
            renderBoard(defenderBoard, payload.defenders || [], 'No defender contribution yet.');

            if (typeof payload.cooldown_remaining !== 'undefined') {
                if (payload.cooldown_remaining > 0) {
                    startCooldown(payload.cooldown_remaining);
                } else if (!cooldownInterval) {
                    setCooldown(0);
                }
            }
            updateTimer();
        }

        function pollState() {
            fetch('../controllers/sect_war_state.php?war_id=' + encodeURIComponent(warId), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        renderState(res.data || null);
                    }
                })
                .catch(function() {});
        }

        if (actionBtn) {
            actionBtn.addEventListener('click', function() {
                if (actionBtn.disabled) return;
                actionBtn.disabled = true;
                var fd = new FormData();
                fd.append('war_id', String(warId));
                fetch('../controllers/sect_war_attack.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data) {
                            showMsg(res.message || 'Action resolved.', false);
                            startCooldown(30);
                            if (res.data.war_ended) {
                                setTimeout(function() { pollState(); }, 800);
                                return;
                            }
                            pollState();
                        } else {
                            showMsg(res.message || 'Action failed.', true);
                            actionBtn.disabled = false;
                            if (res.data && res.data.cooldown_remaining) {
                                startCooldown(res.data.cooldown_remaining);
                            }
                        }
                    })
                    .catch(function() {
                        showMsg('Request failed.', true);
                        actionBtn.disabled = false;
                    });
            });
        }

        renderBoard(attackerBoard, <?php echo json_encode($activeWar['attackers']); ?>, 'No attacker contribution yet.');
        renderBoard(defenderBoard, <?php echo json_encode($activeWar['defenders']); ?>, 'No defender contribution yet.');
        updateTimer();
        setInterval(updateTimer, 1000);
        if (<?php echo $initialCooldown; ?> > 0) {
            startCooldown(<?php echo $initialCooldown; ?>);
        }
        setInterval(pollState, 5000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>




