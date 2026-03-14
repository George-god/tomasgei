<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ProfessionService.php';
require_once dirname(__DIR__) . '/services/AlchemyService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;
use Game\Service\AlchemyService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$professionService = new ProfessionService();
$alchemyService = new AlchemyService();

$professions = $professionService->getProfessions();
$mainProfession = $professionService->getMainProfession($userId);
$alchemistProfession = $professionService->getUserProfession($userId, 1);
$recipes = $alchemyService->getRecipes();
$herbCount = $alchemyService->getHerbCount($userId);

$db = Database::getConnection();
$stmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userGold = (int)($stmt->fetch()['gold'] ?? 0);

$alchemistLevel = 1;
$alchemistExp = 0;
$alchemistRequiredExp = 100;
$alchemistRole = 'main';
if ($alchemistProfession) {
    $alchemistLevel = (int)$alchemistProfession['level'];
    $alchemistExp = (int)$alchemistProfession['experience'];
    $alchemistRequiredExp = 100 * $alchemistLevel;
    $alchemistRole = (string)($alchemistProfession['role'] ?? 'main');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alchemy - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-emerald-400 to-teal-500 bg-clip-text text-transparent">
                ⚗️ Alchemy
            </h1>
            <div class="flex gap-4 items-center">
                <span class="text-amber-300 font-semibold"><?php echo number_format($userGold); ?> Gold</span>
                <span class="text-emerald-300 font-semibold"><?php echo $herbCount; ?> Herbs</span>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div id="alchemy-message" class="mb-4 hidden"></div>

        <?php if (!$mainProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set your main and optional secondary profession first. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php elseif (!$alchemistProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set Alchemist as main or secondary profession to use Alchemy. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php else: ?>
        <!-- Alchemist: show level and recipes -->
        <div class="bg-gray-800/90 backdrop-blur border border-emerald-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-emerald-300 mb-2">Alchemist</h2>
            <p class="text-gray-400 text-sm mb-2">Level <strong class="text-white"><?php echo $alchemistLevel; ?></strong> (<?php echo $alchemistRole; ?>) — Success rate: 1% per effective level, cap 95%.</p>
            <div class="w-full bg-gray-900 rounded-full h-2 overflow-hidden border border-gray-700">
                <div class="h-full bg-emerald-500 rounded-full transition-all" style="width: <?php echo $alchemistRequiredExp > 0 ? min(100, (int)round(100 * $alchemistExp / $alchemistRequiredExp)) : 0; ?>%"></div>
            </div>
            <p class="text-xs text-gray-500 mt-1">EXP: <?php echo $alchemistExp; ?> / <?php echo $alchemistRequiredExp; ?> (next level)</p>
        </div>

        <div class="space-y-4">
            <?php foreach ($recipes as $r): ?>
            <?php
                $requiredHerbs = (int)$r['required_herbs'];
                $goldCost = (int)$r['gold_cost'];
                $baseRate = (float)$r['base_success_rate'];
                $effectiveLevel = \Game\Service\ProfessionService::getEffectiveLevel($alchemistLevel, $alchemistRole);
                $successRate = min(0.95, $baseRate + $effectiveLevel * 0.01);
                $canCraft = $herbCount >= $requiredHerbs && $userGold >= $goldCost;
            ?>
            <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-white"><?php echo htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="text-sm text-gray-400">Result: <?php echo htmlspecialchars($r['result_item_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm text-gray-500 mt-1"><?php echo $requiredHerbs; ?> herbs · <?php echo $goldCost; ?> gold · Success: <?php echo number_format($successRate * 100, 1); ?>%</p>
                </div>
                <button type="button"
                        class="craft-btn px-4 py-2 rounded-lg font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed <?php echo $canCraft ? 'bg-emerald-600 hover:bg-emerald-500 text-white' : 'bg-gray-700 text-gray-400'; ?>"
                        data-recipe-id="<?php echo (int)$r['id']; ?>"
                        <?php if (!$canCraft): ?>disabled<?php endif; ?>>
                    <?php echo $canCraft ? 'Craft' : 'Not enough materials'; ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($recipes)): ?>
            <p class="text-gray-500">No recipes available.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
(function() {
    var msgEl = document.getElementById('alchemy-message');
    function showMsg(text, isError) {
        if (!msgEl) return;
        msgEl.textContent = text;
        msgEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
        msgEl.classList.remove('hidden');
    }

    document.querySelectorAll('.craft-btn').forEach(function(btn) {
        if (btn.disabled) return;
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-recipe-id');
            if (!id) return;
            this.disabled = true;
            var fd = new FormData();
            fd.append('recipe_id', id);
            fetch('../controllers/craft_alchemy.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var d = data.data || {};
                        var line = data.message || 'Done.';
                        if (d.craft_success !== undefined) {
                            line += d.craft_success ? ' You received the pill.' : ' Craft failed (materials consumed).';
                        }
                        if (d.new_level !== undefined) line += ' Level: ' + d.new_level + '.';
                        showMsg(line);
                        window.setTimeout(function() { window.location.reload(); }, 1500);
                    } else {
                        showMsg(data.message || 'Craft failed.', true);
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    showMsg('Request failed.', true);
                    btn.disabled = false;
                });
        });
    });
})();
    </script>
</body>
</html>




