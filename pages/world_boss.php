<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/WorldBossService.php';

use Game\Helper\SessionHelper;
use Game\Service\WorldBossService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$service = new WorldBossService();
$state = $service->getBossState($userId);
$boss = $state['boss'] ?? null;
$leaderboard = $state['leaderboard'] ?? [];
$cooldownRemaining = (int)($state['cooldown_remaining'] ?? 0);

$bossId = $boss ? (int)$boss['id'] : 0;
$bossName = $boss ? (string)$boss['name'] : '';
$bossRegionName = $boss ? (string)($boss['region_name'] ?? '') : '';
$currentHp = $boss ? (int)$boss['current_hp'] : 0;
$maxHp = $boss ? (int)$boss['max_hp'] : 1;
$endTime = $boss ? (string)$boss['end_time'] : '';
$hpPercent = $maxHp > 0 ? min(100, (int)round(100 * $currentHp / $maxHp)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>World Boss - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-red-500 to-orange-600 bg-clip-text text-transparent">World Boss</h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <div id="boss-message" class="mb-4 hidden"></div>

        <?php if (!$boss): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-8 text-center">
            <p class="text-gray-400 text-lg">No world boss is active.</p>
            <p class="text-gray-500 text-sm mt-2">Check back later or wait for an announcement when a boss spawns.</p>
        </div>
        <?php else: ?>
        <div class="bg-gray-800/90 backdrop-blur border border-red-500/30 rounded-xl p-6 mb-6">
            <h2 id="boss-name" class="text-2xl font-bold text-red-300 mb-4"><?php echo htmlspecialchars($bossName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if ($bossRegionName !== ''): ?>
                <p id="boss-region-name" class="text-sm text-purple-300 mb-3">Region: <?php echo htmlspecialchars($bossRegionName, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="mb-2 flex justify-between text-sm text-gray-400">
                <span>HP</span>
                <span id="boss-time-label" data-end-time="<?php echo htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8'); ?>">Ends: <?php echo htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="w-full bg-gray-900 rounded-full h-6 overflow-hidden border border-gray-700">
                <div id="boss-hp-bar" class="h-full bg-gradient-to-r from-red-600 to-orange-500 transition-all duration-500" style="width: <?php echo $hpPercent; ?>%"></div>
            </div>
            <p class="text-right text-sm text-gray-400 mt-1"><span id="boss-hp-text"><?php echo number_format($currentHp); ?></span> / <?php echo number_format($maxHp); ?></p>
            <div class="mt-4">
                <button type="button" id="boss-attack-btn" class="px-6 py-3 bg-red-600 hover:bg-red-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold rounded-xl transition-all" <?php if ($cooldownRemaining > 0): ?>disabled<?php endif; ?>>
                    <?php echo $cooldownRemaining > 0 ? "Attack (wait {$cooldownRemaining}s)" : 'Attack'; ?>
                </button>
                <span id="cooldown-hint" class="ml-2 text-gray-500 text-sm"><?php echo $cooldownRemaining > 0 ? "Cooldown: {$cooldownRemaining}s" : '30s cooldown per attack'; ?></span>
            </div>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6">
            <h3 class="text-lg font-semibold text-gray-300 mb-4">Top 10 damage leaderboard</h3>
            <div id="boss-leaderboard">
                <ul class="space-y-2">
                    <?php
                    $rank = 0;
                    foreach ($leaderboard as $row):
                        $rank++;
                    ?>
                    <li class="flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2">
                        <span class="text-gray-300">#<?php echo $rank; ?> <?php echo htmlspecialchars($row['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-amber-300 font-semibold"><?php echo number_format((int)$row['damage_dealt']); ?> damage</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($leaderboard)): ?>
                    <p class="text-gray-500 text-sm">No damage dealt yet. Be the first to attack!</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($boss): ?>
    <script>
    (function() {
        var bossId = <?php echo $bossId; ?>;
        var attackBtn = document.getElementById('boss-attack-btn');
        var bossNameEl = document.getElementById('boss-name');
        var bossRegionNameEl = document.getElementById('boss-region-name');
        var hpBar = document.getElementById('boss-hp-bar');
        var hpText = document.getElementById('boss-hp-text');
        var msgEl = document.getElementById('boss-message');
        var cooldownHint = document.getElementById('cooldown-hint');
        var timeLabel = document.getElementById('boss-time-label');
        var maxHp = <?php echo $maxHp; ?>;
        var bossEndTime = <?php echo json_encode($endTime); ?>;
        var cooldownInterval = null;
        var pollIntervalMs = 5000;

        function showMsg(text, isError) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
            msgEl.classList.remove('hidden');
        }

        function renderLeaderboard(rows) {
            var lb = document.getElementById('boss-leaderboard');
            if (!lb) return;
            lb.innerHTML = '';
            if (!rows || !rows.length) {
                var p = document.createElement('p');
                p.className = 'text-gray-500 text-sm';
                p.textContent = 'No damage dealt yet. Be the first to attack!';
                lb.appendChild(p);
                return;
            }
            var ul = document.createElement('ul');
            ul.className = 'space-y-2';
            rows.forEach(function(row, i) {
                var li = document.createElement('li');
                li.className = 'flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2';
                var s1 = document.createElement('span');
                s1.className = 'text-gray-300';
                s1.textContent = '#' + (i + 1) + ' ' + (row.username || 'Unknown');
                var s2 = document.createElement('span');
                s2.className = 'text-amber-300 font-semibold';
                s2.textContent = parseInt(row.damage_dealt, 10).toLocaleString() + ' damage';
                li.appendChild(s1);
                li.appendChild(s2);
                ul.appendChild(li);
            });
            lb.appendChild(ul);
        }

        function setCooldown(seconds) {
            if (!attackBtn || !cooldownHint) return;
            if (seconds <= 0) {
                attackBtn.disabled = false;
                attackBtn.textContent = 'Attack';
                cooldownHint.textContent = '30s cooldown per attack';
                return;
            }
            attackBtn.disabled = true;
            attackBtn.textContent = 'Attack (wait ' + seconds + 's)';
            cooldownHint.textContent = 'Cooldown: ' + seconds + 's';
        }

        function startCooldownTimer(seconds) {
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            setCooldown(seconds);
            var t = seconds;
            cooldownInterval = setInterval(function() {
                t--;
                setCooldown(t);
                if (t <= 0) {
                    clearInterval(cooldownInterval);
                    cooldownInterval = null;
                }
            }, 1000);
        }

        function updateTimerLabel() {
            if (!timeLabel || !bossEndTime) return;
            var endTs = new Date(bossEndTime.replace(' ', 'T')).getTime();
            var remaining = Math.max(0, Math.floor((endTs - Date.now()) / 1000));
            var min = Math.floor(remaining / 60);
            var sec = remaining % 60;
            timeLabel.textContent = remaining > 0 ? ('Time remaining: ' + min + 'm ' + sec + 's') : 'Time remaining: 0m 0s';
        }

        function renderState(data) {
            if (!data || !data.boss) {
                showMsg('The world boss event has ended.', false);
                setTimeout(function() { window.location.reload(); }, 1500);
                return;
            }
            bossId = parseInt(data.boss.id, 10);
            maxHp = parseInt(data.boss.max_hp, 10) || maxHp;
            bossEndTime = data.boss.end_time || bossEndTime;
            if (bossNameEl) bossNameEl.textContent = data.boss.name || 'World Boss';
            if (bossRegionNameEl && data.boss.region_name) bossRegionNameEl.textContent = 'Region: ' + data.boss.region_name;
            var cur = parseInt(data.boss.current_hp, 10) || 0;
            var pct = maxHp > 0 ? Math.min(100, Math.round(100 * cur / maxHp)) : 0;
            if (hpBar) hpBar.style.width = pct + '%';
            if (hpText) hpText.textContent = cur.toLocaleString();
            renderLeaderboard(data.leaderboard || []);
            if (typeof data.cooldown_remaining !== 'undefined') {
                if (data.cooldown_remaining > 0) {
                    startCooldownTimer(data.cooldown_remaining);
                } else if (!cooldownInterval) {
                    setCooldown(0);
                }
            }
            updateTimerLabel();
        }

        function pollState() {
            fetch('../controllers/boss_leaderboard.php', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success && res.data) {
                        renderState(res.data);
                    }
                })
                .catch(function() {});
        }

        if (attackBtn) {
            attackBtn.addEventListener('click', function() {
                if (attackBtn.disabled) return;
                attackBtn.disabled = true;
                var fd = new FormData();
                fetch('../controllers/boss_attack.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data) {
                            var d = data.data;
                            showMsg(data.message || 'Attack!', false);
                            startCooldownTimer(30);
                            if (d.is_dead) {
                                showMsg('The boss has been defeated. Finalizing rewards...', false);
                                setTimeout(function() { pollState(); }, 1000);
                                return;
                            }
                            pollState();
                        } else {
                            showMsg(data.message || 'Attack failed.', true);
                            attackBtn.disabled = false;
                            if (data.data && data.data.cooldown_remaining) {
                                startCooldownTimer(data.data.cooldown_remaining);
                            }
                        }
                    })
                    .catch(function() {
                        showMsg('Request failed.', true);
                        attackBtn.disabled = false;
                    });
            });
        }

        var cd = <?php echo $cooldownRemaining; ?>;
        updateTimerLabel();
        setInterval(updateTimerLabel, 1000);
        if (cd > 0) startCooldownTimer(cd);
        setInterval(pollState, pollIntervalMs);
    })();
    </script>
    <?php endif; ?>
</body>
</html>




