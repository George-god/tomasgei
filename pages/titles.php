<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/TitleService.php';

use Game\Helper\SessionHelper;
use Game\Service\TitleService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$titleService = new TitleService();
$page = $titleService->getTitlesPageData($userId);
$titles = $page['titles'];
$stats = $page['stats'];

$typeLabels = [
    'pvp_wins' => 'PvP wins',
    'pve_kills' => 'PvE wins',
    'exploration' => 'Explorations',
    'boss_participation' => 'World boss attacks',
    'sect_contribution' => 'Sect gold donated (total)',
    'tribulation_success' => 'Tribulations survived',
    'season_rank' => 'Season rewards',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Titles - The Upper Realms</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-amber-300 to-yellow-500 bg-clip-text text-transparent">Titles</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <p class="text-gray-400 text-sm mb-6">
            Unlock titles through combat, exploration, sect support, tribulations, and world boss participation.
            Equip <strong class="text-amber-200">one</strong> title for small bonuses to attack, defense, and max chi (shown on each card).
        </p>

        <div id="title-msg" class="mb-4 hidden p-3 rounded-lg text-sm"></div>

        <div class="bg-gray-800/60 border border-amber-500/20 rounded-xl p-4 mb-8 text-sm text-gray-400">
            <span class="text-amber-300 font-semibold">Your progress:</span>
            <?php foreach ($stats as $k => $v): ?>
                <span class="inline-block mr-4 mt-1"><?php echo htmlspecialchars($typeLabels[$k] ?? $k, ENT_QUOTES, 'UTF-8'); ?>: <strong class="text-white"><?php echo $k === 'sect_contribution' ? number_format($v) : (int)$v; ?></strong></span>
            <?php endforeach; ?>
        </div>

        <div class="space-y-4">
            <?php foreach ($titles as $t): ?>
            <div class="bg-gray-800/90 border <?php echo !empty($t['equipped']) ? 'border-amber-400 ring-1 ring-amber-500/40' : 'border-gray-600'; ?> rounded-xl p-5">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold text-white"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="text-gray-400 text-sm mt-1"><?php echo htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-xs text-gray-500 mt-2">
                            Requires: <?php echo htmlspecialchars($typeLabels[$t['unlock_type']] ?? $t['unlock_type'], ENT_QUOTES, 'UTF-8'); ?> ≥ <?php echo (int)$t['unlock_value']; ?>
                            <?php if (empty($t['unlocked'])): ?>
                                — progress <?php echo (int)$t['progress_current']; ?> / <?php echo (int)$t['unlock_value']; ?>
                            <?php endif; ?>
                        </p>
                        <?php
                            $ba = (float)$t['bonus_attack_pct'] * 100;
                            $bd = (float)$t['bonus_defense_pct'] * 100;
                            $bm = (float)$t['bonus_max_chi_pct'] * 100;
                        ?>
                        <p class="text-xs text-amber-200/90 mt-2">
                            Bonuses when equipped:
                            <?php if ($ba > 0): ?> ATK +<?php echo number_format($ba, 2); ?>%<?php endif; ?>
                            <?php if ($bd > 0): ?><?php echo $ba > 0 ? ' ·' : ''; ?> DEF +<?php echo number_format($bd, 2); ?>%<?php endif; ?>
                            <?php if ($bm > 0): ?><?php echo ($ba > 0 || $bd > 0) ? ' ·' : ''; ?> Max Chi +<?php echo number_format($bm, 2); ?>%<?php endif; ?>
                            <?php if ($ba <= 0 && $bd <= 0 && $bm <= 0): ?> — <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <?php if (!empty($t['unlocked'])): ?>
                            <?php if (!empty($t['equipped'])): ?>
                                <span class="text-xs px-2 py-1 rounded bg-amber-900/50 text-amber-200 border border-amber-500/40">Equipped</span>
                                <button type="button" class="unequip-btn text-sm text-gray-400 hover:text-white underline" data-title-id="0">Unequip</button>
                            <?php else: ?>
                                <button type="button" class="equip-btn px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg text-sm" data-title-id="<?php echo (int)$t['id']; ?>">Equip</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-xs text-gray-500">Locked</span>
                            <div class="w-32 bg-gray-900 rounded-full h-1.5 overflow-hidden border border-gray-700">
                                <div class="h-full bg-amber-600/80 rounded-full" style="width: <?php echo (int)$t['progress_pct']; ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($titles)): ?>
            <p class="text-gray-500 text-center py-8">No titles defined. Import <code class="text-amber-400">database_full.sql</code> or core <code class="text-amber-400">database_schema.sql</code>.</p>
        <?php endif; ?>
    </div>
    <script>
(function() {
    var msgEl = document.getElementById('title-msg');
    function showMsg(text, err) {
        msgEl.textContent = text;
        msgEl.className = 'mb-4 p-3 rounded-lg text-sm ' + (err ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
        msgEl.classList.remove('hidden');
    }
    function post(titleId) {
        var fd = new FormData();
        fd.append('title_id', titleId);
        return fetch('../controllers/equip_title.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }
    document.querySelectorAll('.equip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-title-id');
            btn.disabled = true;
            post(id).then(function(data) {
                if (data.success) { window.location.reload(); }
                else { showMsg(data.message || 'Failed.', true); btn.disabled = false; }
            }).catch(function() { showMsg('Request failed.', true); btn.disabled = false; });
        });
    });
    document.querySelectorAll('.unequip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            btn.disabled = true;
            post('0').then(function(data) {
                if (data.success) { window.location.reload(); }
                else { showMsg(data.message || 'Failed.', true); btn.disabled = false; }
            }).catch(function() { showMsg('Request failed.', true); btn.disabled = false; });
        });
    });
})();
    </script>
</body>
</html>
