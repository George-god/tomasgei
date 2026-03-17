<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ExplorationService.php';

use Game\Helper\SessionHelper;
use Game\Service\ExplorationService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$service = new ExplorationService();
$mapData = $service->getRegionsForUser($userId);
$regions = $mapData['regions'] ?? [];
$currentLocation = $mapData['current_location'] ?? null;
$cooldownRemaining = (int)($mapData['cooldown_remaining'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>World Map - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-green-400 to-cyan-500 bg-clip-text text-transparent">World Map</h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-cyan-500/30 rounded-xl p-6 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-cyan-300">Exploration status</h2>
                    <p class="text-gray-400 text-sm">
                        Current location:
                        <span class="text-white font-medium"><?php echo htmlspecialchars($currentLocation['name'] ?? 'Uncharted', ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                </div>
                <div class="text-sm text-gray-400">
                    Next explore:
                    <span id="explore-cooldown" class="text-white font-semibold"><?php echo $cooldownRemaining > 0 ? $cooldownRemaining . 's' : 'Ready'; ?></span>
                </div>
            </div>
        </div>

        <div id="explore-message" class="mb-4 hidden"></div>
        <div id="explore-result" class="mb-6 hidden bg-gray-800/90 backdrop-blur border border-gray-700 rounded-xl p-6"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($regions as $region): ?>
                <?php $locked = !empty($region['locked']); ?>
                <div class="bg-gray-800/90 backdrop-blur border <?php echo $locked ? 'border-gray-700 opacity-70' : 'border-green-500/30'; ?> rounded-xl p-6">
                    <div class="flex justify-between items-start gap-3 mb-3">
                        <div>
                            <h3 class="text-xl font-semibold <?php echo $locked ? 'text-gray-400' : 'text-white'; ?>">
                                <?php echo htmlspecialchars($region['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <p class="text-sm text-gray-500">Difficulty <?php echo (int)($region['difficulty'] ?? 1); ?></p>
                        </div>
                        <?php if (!empty($region['is_current'])): ?>
                            <span class="px-2 py-1 rounded bg-cyan-500/20 border border-cyan-500/40 text-cyan-300 text-xs">Current</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-400 mb-4"><?php echo htmlspecialchars($region['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm text-cyan-300 mb-1">Resources: <?php echo htmlspecialchars($region['resource_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm text-gray-500 mb-1">Encounters: <?php echo htmlspecialchars($region['exploration_encounters'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm text-purple-300 mb-4">Hidden dungeon chance: <?php echo number_format((float)($region['hidden_dungeon_chance'] ?? 1.0), 2); ?>%</p>
                    <p class="text-sm <?php echo $locked ? 'text-amber-300' : 'text-gray-500'; ?> mb-4">
                        Requires <?php echo htmlspecialchars($region['min_realm_name'] ?? 'Qi Refining', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <button
                        type="button"
                        class="explore-btn w-full py-2 rounded-lg font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed <?php echo $locked ? 'bg-gray-700 text-gray-400' : 'bg-green-600 hover:bg-green-500 text-white'; ?>"
                        data-region-id="<?php echo (int)$region['id']; ?>"
                        data-region-name="<?php echo htmlspecialchars($region['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $locked ? 'disabled' : ''; ?>
                    >
                        <?php echo $locked ? 'Locked' : 'Explore'; ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    (function() {
        var messageEl = document.getElementById('explore-message');
        var resultEl = document.getElementById('explore-result');
        var cooldownEl = document.getElementById('explore-cooldown');
        var cooldownRemaining = <?php echo $cooldownRemaining; ?>;
        var cooldownInterval = null;

        function showMessage(text, isError) {
            if (!messageEl) return;
            messageEl.textContent = text;
            messageEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
            messageEl.classList.remove('hidden');
        }

        function setCooldown(seconds) {
            cooldownRemaining = Math.max(0, seconds || 0);
            if (cooldownEl) {
                cooldownEl.textContent = cooldownRemaining > 0 ? (cooldownRemaining + 's') : 'Ready';
            }
            document.querySelectorAll('.explore-btn').forEach(function(btn) {
                if (btn.textContent === 'Locked') return;
                btn.disabled = cooldownRemaining > 0;
            });
        }

        function startCooldown(seconds) {
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            setCooldown(seconds);
            if (seconds <= 0) return;
            cooldownInterval = window.setInterval(function() {
                cooldownRemaining -= 1;
                setCooldown(cooldownRemaining);
                if (cooldownRemaining <= 0) {
                    clearInterval(cooldownInterval);
                    cooldownInterval = null;
                }
            }, 1000);
        }

        function renderItem(item) {
            if (!item) return '';
            return '<div class="text-sm text-emerald-300">Found: ' + item.name + ' x' + (item.quantity || 1) + '</div>';
        }

        function renderEncounter(data) {
            if (!data) return '<p class="text-gray-400">Battle data unavailable.</p>';
            var html = '';
            html += '<h3 class="text-lg font-semibold text-red-300 mb-2">Encounter: ' + (data.npc_name || 'Enemy') + '</h3>';
            html += '<p class="text-sm text-gray-400 mb-2">Winner: <span class="text-white">' + (data.winner || 'unknown') + '</span></p>';
            html += '<p class="text-sm text-gray-400 mb-2">Chi reward: ' + (data.chi_reward || 0) + ' | Gold: ' + (data.gold_gained || 0) + '</p>';
            if (data.battle_log && data.battle_log.length) {
                html += '<div class="space-y-1 text-sm">';
                data.battle_log.forEach(function(row) {
                    var actor = row.attacker === 'user' ? 'You' : (data.npc_name || 'Enemy');
                    html += '<div class="text-gray-300">' + actor + ' dealt ' + row.damage + ' damage.</div>';
                });
                html += '</div>';
            }
            if (data.herb_dropped) html += '<div class="text-green-300 mt-2">Herb drop: ' + data.herb_dropped.name + '</div>';
            if (data.material_dropped) html += '<div class="text-orange-300 mt-1">Material drop: ' + data.material_dropped.name + '</div>';
            if (data.rune_fragment_dropped) html += '<div class="text-purple-300 mt-1">Rune Fragment discovered.</div>';
            return html;
        }

        function renderResult(payload) {
            if (!resultEl) return;
            var html = '';
            var eventType = payload.event_type || 'nothing';
            var data = payload.data || null;

            if (eventType === 'encounter') {
                html = renderEncounter(data);
            } else if (eventType === 'dungeon_discovery' && data && data.dungeon) {
                html = '<h3 class="text-lg font-semibold text-purple-300 mb-2">Hidden Dungeon Discovered</h3>';
                html += '<p class="text-gray-300 mb-2">' + data.dungeon.name + ' | Difficulty ' + data.dungeon.difficulty + '</p>';
                html += '<p class="text-gray-400 mb-3">Boss: ' + data.dungeon.boss_name + '</p>';
                if (data.dungeon.locked) {
                    html += '<p class="text-amber-300 text-sm">Requires ' + (data.dungeon.min_realm_name || 'Qi Refining') + ' to enter.</p>';
                } else {
                    html += '<a href="dungeon.php?dungeon_id=' + data.dungeon.id + '" class="inline-block px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-lg font-semibold">Enter Dungeon</a>';
                }
            } else if (eventType === 'manual_discovery' && data && data.manual) {
                html = '<h3 class="text-lg font-semibold text-violet-300 mb-2">Forgotten Manual Unearthed</h3>';
                html += '<div class="text-violet-200">' + data.manual.name + ' <span class="text-gray-400">(' + data.manual.rarity + ')</span></div>';
                html += '<a href="cultivation_manuals.php" class="inline-block mt-3 px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white rounded-lg font-semibold">Open Manuals</a>';
            } else if (data && data.item) {
                html = '<h3 class="text-lg font-semibold text-cyan-300 mb-2">' + (payload.region_name || 'Region') + '</h3>' + renderItem(data.item);
            } else {
                html = '<h3 class="text-lg font-semibold text-cyan-300 mb-2">' + (payload.region_name || 'Region') + '</h3><p class="text-gray-400">Nothing unusual happened.</p>';
            }

            resultEl.innerHTML = html;
            resultEl.classList.remove('hidden');
        }

        document.querySelectorAll('.explore-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (btn.disabled) return;
                var regionId = btn.getAttribute('data-region-id');
                if (!regionId) return;
                btn.disabled = true;
                var fd = new FormData();
                fd.append('region_id', regionId);

                fetch('../controllers/explore_region.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data) {
                            showMessage(data.message || 'Exploration complete.', false);
                            renderResult(data.data);
                            startCooldown(parseInt(data.data.cooldown_remaining || 60, 10));
                        } else {
                            showMessage(data.message || 'Exploration failed.', true);
                            if (data.data && data.data.cooldown_remaining) {
                                startCooldown(parseInt(data.data.cooldown_remaining, 10));
                            } else {
                                btn.disabled = false;
                            }
                        }
                    })
                    .catch(function() {
                        showMessage('Request failed.', true);
                        btn.disabled = false;
                    });
            });
        });

        startCooldown(cooldownRemaining);
    })();
    </script>
</body>
</html>




