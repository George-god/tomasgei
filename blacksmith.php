<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/ProfessionService.php';
require_once __DIR__ . '/classes/Service/CraftingService.php';
require_once __DIR__ . '/classes/Config/Database.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;
use Game\Service\CraftingService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$professionService = new ProfessionService();
$craftingService = new CraftingService();

$professions = $professionService->getProfessions();
$mainProfession = $professionService->getMainProfession($userId);
$blacksmithProfession = $professionService->getUserProfession($userId, 2);
$recipes = $craftingService->getRecipes();
$materialCount = $craftingService->getMaterialCount($userId);
$materialTier1 = $craftingService->getMaterialCountByTier($userId, 1);
$materialTier2 = $craftingService->getMaterialCountByTier($userId, 2);
$materialTier3 = $craftingService->getMaterialCountByTier($userId, 3);

$db = Database::getConnection();
$stmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userGold = (int)($stmt->fetch()['gold'] ?? 0);

$blacksmithLevel = 1;
$blacksmithExp = 0;
$blacksmithRequiredExp = 100;
$blacksmithRole = 'main';
if ($blacksmithProfession) {
    $blacksmithLevel = (int)$blacksmithProfession['level'];
    $blacksmithExp = (int)$blacksmithProfession['experience'];
    $blacksmithRequiredExp = 100 * $blacksmithLevel;
    $blacksmithRole = (string)($blacksmithProfession['role'] ?? 'main');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacksmith - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-orange-400 to-amber-600 bg-clip-text text-transparent">
                🔨 Blacksmith
            </h1>
            <div class="flex flex-wrap gap-4 items-center">
                <span class="text-amber-300 font-semibold"><?php echo number_format($userGold); ?> Gold</span>
                <span class="text-gray-300">Iron: <strong><?php echo $materialTier1; ?></strong></span>
                <span class="text-gray-300">Refined: <strong><?php echo $materialTier2; ?></strong></span>
                <span class="text-gray-300">Spirit Steel: <strong><?php echo $materialTier3; ?></strong></span>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div id="blacksmith-message" class="mb-4 hidden"></div>

        <?php if (!$mainProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set your main and optional secondary profession first. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php elseif (!$blacksmithProfession): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6 mb-8">
            <p class="text-gray-400">Set Blacksmith as main or secondary profession to craft. <a href="professions.php" class="text-cyan-400 hover:underline">Professions</a></p>
        </div>
        <?php else: ?>
        <div class="bg-gray-800/90 backdrop-blur border border-orange-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-orange-300 mb-2">Blacksmith</h2>
            <p class="text-gray-400 text-sm mb-2">Level <strong class="text-white"><?php echo $blacksmithLevel; ?></strong> (<?php echo $blacksmithRole; ?>) — Success rate: 1% per effective level, cap 95%.</p>
            <div class="w-full bg-gray-900 rounded-full h-2 overflow-hidden border border-gray-700">
                <div class="h-full bg-orange-500 rounded-full transition-all" style="width: <?php echo $blacksmithRequiredExp > 0 ? min(100, (int)round(100 * $blacksmithExp / $blacksmithRequiredExp)) : 0; ?>%"></div>
            </div>
            <p class="text-xs text-gray-500 mt-1">EXP: <?php echo $blacksmithExp; ?> / <?php echo $blacksmithRequiredExp; ?> (next level)</p>
        </div>

        <p class="text-gray-500 text-sm mb-4">Quality on success: 80% Normal, 20% Excellent (+10% stats). Level 1 → Tier 1, Level 5 → Tier 2, Level 10 → Tier 3.</p>
        <div class="space-y-4">
            <?php
                $tierNames = [1 => 'Iron Ore', 2 => 'Refined Iron', 3 => 'Spirit Steel'];
                $materialByTier = [1 => $materialTier1, 2 => $materialTier2, 3 => $materialTier3];
            ?>
            <?php foreach ($recipes as $r): ?>
            <?php
                $requiredMaterialTier = (int)($r['required_material_tier'] ?? 1);
                $requiredMaterials = (int)$r['required_materials'];
                $requiredLevel = (int)($r['required_profession_level'] ?? 1);
                $goldCost = (int)$r['gold_cost'];
                $baseRate = (float)$r['base_success_rate'];
                $effectiveLevel = \Game\Service\ProfessionService::getEffectiveLevel($blacksmithLevel, $blacksmithRole);
                $successRate = min(0.95, $baseRate + $effectiveLevel * 0.01);
                $hasLevel = $blacksmithLevel >= $requiredLevel;
                $hasMaterials = ($materialByTier[$requiredMaterialTier] ?? 0) >= $requiredMaterials;
                $canCraft = $hasLevel && $hasMaterials && $userGold >= $goldCost;
                $reason = '';
                if (!$hasLevel) $reason = 'Level ' . $requiredLevel . ' required';
                elseif (!$hasMaterials) $reason = 'Need ' . $requiredMaterials . ' ' . ($tierNames[$requiredMaterialTier] ?? 'materials');
                elseif ($userGold < $goldCost) $reason = 'Not enough gold';
                $stats = [];
                if ((int)($r['attack_bonus'] ?? 0) > 0) $stats[] = '+' . $r['attack_bonus'] . ' ATK';
                if ((int)($r['defense_bonus'] ?? 0) > 0) $stats[] = '+' . $r['defense_bonus'] . ' DEF';
                if ((int)($r['hp_bonus'] ?? 0) > 0) $stats[] = '+' . $r['hp_bonus'] . ' HP';
            ?>
            <div class="bg-gray-800/90 backdrop-blur border border-gray-600 rounded-xl p-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-white"><?php echo htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> <span class="text-gray-500 text-sm">(Tier <?php echo (int)($r['gear_tier'] ?? 1); ?>)</span></h3>
                    <p class="text-sm text-gray-400"><?php echo htmlspecialchars($r['result_item_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> — <?php echo implode(', ', $stats); ?></p>
                    <p class="text-sm text-gray-500 mt-1"><?php echo $requiredMaterials; ?> <?php echo $tierNames[$requiredMaterialTier] ?? 'materials'; ?> · <?php echo $goldCost; ?> gold · Level <?php echo $requiredLevel; ?> · Success: <?php echo number_format($successRate * 100, 1); ?>%</p>
                </div>
                <button type="button"
                        class="craft-btn px-4 py-2 rounded-lg font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed <?php echo $canCraft ? 'bg-orange-600 hover:bg-orange-500 text-white' : 'bg-gray-700 text-gray-400'; ?>"
                        data-recipe-id="<?php echo (int)$r['id']; ?>"
                        <?php if (!$canCraft): ?>disabled<?php endif; ?> title="<?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo $canCraft ? 'Craft' : ($reason ?: 'Craft'); ?>
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
    var msgEl = document.getElementById('blacksmith-message');
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
            fetch('craft_blacksmith.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var d = data.data || {};
                        var line = data.message || 'Done.';
                        if (d.craft_success !== undefined) {
                            if (d.craft_success) {
                                line += d.quality === 'excellent' ? ' You received an Excellent item!' : ' You received the item.';
                            } else {
                                line += ' Craft failed (materials consumed).';
                            }
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
