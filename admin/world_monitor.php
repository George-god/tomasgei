<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';

use Game\Helper\SessionHelper;
use Game\Config\Database;

session_start();
SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');

$pageTitle = 'World Monitor';
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$worldState = [];
$realms = [];
try {
    $db = Database::getConnection();
    $stmt = $db->query("
        SELECT ws.*, r.name AS realm_name
        FROM world_state ws
        LEFT JOIN realms r ON r.id = ws.realm_id
        ORDER BY COALESCE(ws.realm_id, 999) ASC, ws.id ASC
    ");
    $worldState = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $worldState = [];
}

try {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT id, name, min_level, max_level FROM realms ORDER BY id ASC");
    $realms = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $realms = [];
}

require __DIR__ . '/includes/header.php';
?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-gray-800/90 border border-cyan-500/20 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-cyan-300 mb-4">Realm Pressure & Influence</h2>
                <p class="text-sm text-gray-400 mb-4">The Heavenly Dao observes the balance of qi across realms. Pressure and influence affect cultivation efficiency.</p>
                <div class="space-y-4">
                    <?php foreach ($worldState as $ws): ?>
                    <div class="p-4 bg-gray-900/60 border border-gray-700 rounded-lg">
                        <div class="font-medium text-white mb-2">
                            <?php echo htmlspecialchars($ws['realm_name'] ?? $ws['key_name'] ?? 'Global', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div><span class="text-gray-400">Pressure:</span> <span class="text-amber-300"><?php echo number_format((float)($ws['pressure_level'] ?? 0), 2); ?></span></div>
                            <div><span class="text-gray-400">Influence:</span> <span class="text-cyan-300"><?php echo number_format((float)($ws['influence_percentage'] ?? 0) * 100, 2); ?>%</span></div>
                            <div><span class="text-gray-400">Stat Mod:</span> <?php echo number_format((float)($ws['stat_modifier_percentage'] ?? 0) * 100, 2); ?>%</div>
                            <div><span class="text-gray-400">Cultivation Mod:</span> <?php echo number_format((float)($ws['cultivation_modifier_percentage'] ?? 0) * 100, 2); ?>%</div>
                            <div><span class="text-gray-400">Tribulation Mod:</span> <?php echo number_format((float)($ws['tribulation_modifier_percentage'] ?? 0) * 100, 2); ?>%</div>
                            <?php if (!empty($ws['value'])): ?>
                            <div class="col-span-2"><span class="text-gray-400">Value:</span> <?php echo htmlspecialchars($ws['value'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-2">Updated: <?php echo htmlspecialchars($ws['updated_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$worldState): ?>
                <div class="p-6 text-center text-gray-400">No world state recorded. The Heavenly Dao has not yet inscribed the realm's balance.</div>
                <?php endif; ?>
            </div>

            <div class="bg-gray-800/90 border border-violet-500/20 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-violet-300 mb-4">Realm Tiers</h2>
                <p class="text-sm text-gray-400 mb-4">The cultivation path ascends through these realms.</p>
                <div class="space-y-3">
                    <?php foreach ($realms as $r): ?>
                    <div class="flex justify-between items-center p-3 bg-gray-900/60 border border-gray-700 rounded-lg">
                        <span class="text-white font-medium"><?php echo htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-gray-400 text-sm">Level <?php echo (int)$r['min_level']; ?>–<?php echo (int)$r['max_level']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="bg-gray-800/50 border border-gray-700 rounded-xl p-6 text-gray-400 text-sm">
            <p class="italic">"The World Monitor reveals the celestial balance. Realm pressure and influence shape the fate of cultivators. Observe, but do not disturb the natural order."</p>
        </div>
<?php require __DIR__ . '/includes/footer.php'; ?>
