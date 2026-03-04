<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/ProfessionService.php';
require_once __DIR__ . '/classes/Service/HerbalistService.php';

use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;
use Game\Service\HerbalistService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$professionService = new ProfessionService();
$herbalistService = new HerbalistService();

$mainProfession = $professionService->getMainProfession($userId);
$herbalistProfession = $professionService->getUserProfession($userId, 3);
$plot = $herbalistService->getPlot($userId);

$plotStatus = 'empty';
$readyAt = null;
if ($plot && (int)$plot['is_harvested'] === 0) {
    $readyAt = strtotime($plot['ready_at']);
    $plotStatus = $readyAt <= time() ? 'ready' : 'growing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Herb Plot - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-green-400 to-emerald-600 bg-clip-text text-transparent">Herb Plot</h1>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div id="herbalist-message" class="mb-4 hidden"></div>

        <?php if (!$mainProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set a main profession first. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php elseif (!$herbalistProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set Spirit Herbalist as main or secondary to use the herb plot. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php else: ?>
        <?php
            $level = (int)$herbalistProfession['level'];
            $role = (string)($herbalistProfession['role'] ?? 'main');
            $effectiveLevel = ProfessionService::getEffectiveLevel($level, $role);
            $yield = 2 + (int)floor($effectiveLevel / 3);
        ?>
        <div class="bg-gray-800/90 backdrop-blur border border-green-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-green-300 mb-2">Spirit Herbalist</h2>
            <p class="text-gray-400 text-sm">Level <?php echo $level; ?> (<?php echo $role; ?>) — Harvest yield: <strong class="text-white"><?php echo $yield; ?></strong> herbs (2 + floor(effective_level/3)). Growth: 30 min.</p>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Plot status</h2>
            <p id="plot-status-text" class="text-gray-300 mb-4">
                <?php if ($plotStatus === 'empty'): ?>
                    No active plot. Plant to start.
                <?php elseif ($plotStatus === 'growing'): ?>
                    Growing. Ready at <span id="ready-at"><?php echo date('M j, g:i A', $readyAt); ?></span>.
                <?php else: ?>
                    Ready to harvest!
                <?php endif; ?>
            </p>
            <div class="flex gap-4">
                <button type="button" id="plant-btn" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $plotStatus === 'empty' ? 'bg-green-600 hover:bg-green-500 text-white' : 'bg-gray-700 text-gray-400 cursor-not-allowed'; ?>" <?php echo $plotStatus !== 'empty' ? 'disabled' : ''; ?>>Plant</button>
                <button type="button" id="harvest-btn" class="px-4 py-2 rounded-lg font-semibold transition-all <?php echo $plotStatus === 'ready' ? 'bg-amber-600 hover:bg-amber-500 text-white' : 'bg-gray-700 text-gray-400 cursor-not-allowed'; ?>" <?php echo $plotStatus !== 'ready' ? 'disabled' : ''; ?>>Harvest</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <script>
(function() {
    var msgEl = document.getElementById('herbalist-message');
    function showMsg(text, isError) {
        if (!msgEl) return;
        msgEl.textContent = text;
        msgEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
        msgEl.classList.remove('hidden');
    }
    var plantBtn = document.getElementById('plant-btn');
    var harvestBtn = document.getElementById('harvest-btn');
    var statusText = document.getElementById('plot-status-text');
    function setPlotEmpty() {
        if (statusText) statusText.textContent = 'No active plot. Plant to start.';
        if (plantBtn) { plantBtn.disabled = false; plantBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-green-600 hover:bg-green-500 text-white'; }
        if (harvestBtn) { harvestBtn.disabled = true; harvestBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-gray-700 text-gray-400 cursor-not-allowed'; }
    }
    function setPlotGrowing(readyAtStr) {
        if (statusText) statusText.innerHTML = 'Growing. Ready at <span id="ready-at">' + readyAtStr + '</span>.';
        if (plantBtn) { plantBtn.disabled = true; plantBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-gray-700 text-gray-400 cursor-not-allowed'; }
        if (harvestBtn) { harvestBtn.disabled = true; harvestBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-gray-700 text-gray-400 cursor-not-allowed'; }
    }
    function setPlotReady() {
        if (statusText) statusText.textContent = 'Ready to harvest!';
        if (plantBtn) { plantBtn.disabled = true; plantBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-gray-700 text-gray-400 cursor-not-allowed'; }
        if (harvestBtn) { harvestBtn.disabled = false; harvestBtn.className = 'px-4 py-2 rounded-lg font-semibold transition-all bg-amber-600 hover:bg-amber-500 text-white'; }
    }
    if (plantBtn && !plantBtn.disabled) {
        plantBtn.addEventListener('click', function() {
            this.disabled = true;
            fetch('herb_plant.php', { method: 'POST', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showMsg(data.message || 'Planted.');
                        var readyAt = data.data && data.data.ready_at ? new Date(data.data.ready_at).toLocaleString() : '30 min';
                        setPlotGrowing(readyAt);
                    } else {
                        showMsg(data.message || 'Failed.', true);
                        plantBtn.disabled = false;
                    }
                })
                .catch(function() { showMsg('Request failed.', true); plantBtn.disabled = false; });
        });
    }
    if (harvestBtn && !harvestBtn.disabled) {
        harvestBtn.addEventListener('click', function() {
            this.disabled = true;
            fetch('herb_harvest.php', { method: 'POST', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showMsg(data.message || 'Harvested.');
                        setPlotEmpty();
                    } else {
                        showMsg(data.message || 'Failed.', true);
                        harvestBtn.disabled = false;
                    }
                })
                .catch(function() { showMsg('Request failed.', true); harvestBtn.disabled = false; });
        });
    }
})();
    </script>
</body>
</html>
