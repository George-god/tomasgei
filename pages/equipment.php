<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/services/ItemService.php';
require_once dirname(__DIR__) . '/services/StatCalculator.php';

use Game\Service\ItemService;
use Game\Service\StatCalculator;

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '' || $_SESSION['user_id'] === null) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$itemService = new ItemService();
$statCalculator = new StatCalculator();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'unequip' && !empty($_POST['slot'])) {
        $res = $itemService->unequipItem($userId, (string)$_POST['slot']);
        $message = $res['success'] ? $res['message'] : null;
        $error = $res['success'] ? null : $res['message'];
    } elseif (isset($_POST['action']) && $_POST['action'] === 'equip' && !empty($_POST['inventory_id'])) {
        $res = $itemService->equipItem($userId, (int)$_POST['inventory_id']);
        $message = $res['success'] ? $res['message'] : null;
        $error = $res['success'] ? null : $res['message'];
    }
    if ($message || $error) {
        header('Location: equipment.php?' . ($message ? 'msg=' . urlencode($message) : 'err=' . urlencode($error ?? '')));
        exit;
    }
}

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$equipment = $itemService->getUserEquipment($userId);
$bonuses = $itemService->getEquippedItemBonuses($userId);
$stats = $statCalculator->calculateFinalStats($userId);
$inventory = $itemService->getUserInventory($userId, true);
$unequippedEquippable = array_filter($inventory, function ($row) {
    $template = $row['template'] ?? null;
    return $template && (int)($row['is_equipped'] ?? 0) === 0 && in_array($template['type'], ['weapon', 'armor', 'accessory'], true);
});

$slotLabels = ['weapon' => 'Weapon', 'armor' => 'Armor', 'accessory_1' => 'Accessory 1', 'accessory_2' => 'Accessory 2'];
$slotKeys = ['weapon', 'armor', 'accessory_1', 'accessory_2'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-purple-400 bg-clip-text text-transparent">
                🛡️ Equipment
            </h1>
            <div class="flex gap-4">
                <a href="inventory.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-emerald-500/30 text-emerald-300 transition-all">Inventory</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300">
                <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300">
                <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Stat comparison: base vs final -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-violet-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-violet-300 mb-4">Combat Stats (with equipment)</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-gray-400 text-sm">Attack</div>
                    <div class="text-2xl font-bold text-white"><?php echo number_format($stats['final']['attack']); ?></div>
                    <?php if ($bonuses['attack'] > 0): ?>
                        <div class="text-xs text-green-400">+<?php echo $bonuses['attack']; ?> from equipment</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-gray-400 text-sm">Defense</div>
                    <div class="text-2xl font-bold text-white"><?php echo number_format($stats['final']['defense']); ?></div>
                    <?php if ($bonuses['defense'] > 0): ?>
                        <div class="text-xs text-green-400">+<?php echo $bonuses['defense']; ?> from equipment</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-gray-400 text-sm">Max Chi</div>
                    <div class="text-2xl font-bold text-white"><?php echo number_format($stats['final']['max_chi']); ?></div>
                    <?php if ($bonuses['hp'] > 0): ?>
                        <div class="text-xs text-green-400">+<?php echo $bonuses['hp']; ?> from equipment</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-gray-400 text-sm">Crit / Lifesteal</div>
                    <div class="text-lg font-bold text-white">+<?php echo number_format($bonuses['crit_bonus'] * 100, 1); ?>% / +<?php echo number_format($bonuses['lifesteal_bonus'] * 100, 1); ?>%</div>
                </div>
            </div>
        </div>

        <!-- Equipment slots -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-violet-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-violet-300 mb-4">Slots</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($slotKeys as $slotKey):
                    $data = $equipment[$slotKey] ?? null;
                ?>
                    <div class="bg-gray-900/80 border border-gray-700 rounded-lg p-4">
                        <div class="text-sm text-gray-400 mb-2"><?php echo $slotLabels[$slotKey]; ?></div>
                        <?php if (!empty($data) && !empty($data['template'])): ?>
                            <?php $t = $data['template']; ?>
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-400 mt-1">+<?php echo (int)($t['attack_bonus'] ?? 0); ?> ATK, +<?php echo (int)($t['defense_bonus'] ?? 0); ?> DEF, +<?php echo (int)($t['hp_bonus'] ?? 0); ?> HP</div>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="action" value="unequip">
                                <input type="hidden" name="slot" value="<?php echo htmlspecialchars($slotKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-sm">Unequip</button>
                            </form>
                        <?php else: ?>
                            <div class="text-gray-500">Empty</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Equip from inventory -->
        <div class="bg-gray-800/90 backdrop-blur-lg border border-gray-700 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-cyan-300 mb-4">Equip from inventory</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($unequippedEquippable as $row): ?>
                    <?php $t = $row['template'] ?? null; if (!$t) continue; ?>
                    <div class="bg-gray-900/80 border border-gray-600 rounded-lg p-4">
                        <div class="font-semibold text-white"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-sm text-gray-400 capitalize"><?php echo htmlspecialchars($t['type'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-xs text-gray-300 mt-1">+<?php echo (int)($t['attack_bonus'] ?? 0); ?> ATK, +<?php echo (int)($t['defense_bonus'] ?? 0); ?> DEF, +<?php echo (int)($t['hp_bonus'] ?? 0); ?> HP</div>
                        <form method="POST" class="mt-3">
                            <input type="hidden" name="action" value="equip">
                            <input type="hidden" name="inventory_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="px-3 py-1 bg-violet-600 hover:bg-violet-500 rounded text-sm text-white">Equip</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($unequippedEquippable)): ?>
                <p class="text-gray-400">No unequipped gear in inventory. <a href="npc_arena.php" class="text-cyan-400 hover:underline">Fight NPCs</a> for loot.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>




