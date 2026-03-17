<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';

use Game\Helper\SessionHelper;
use Game\Config\Database;

session_start();
SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');

$pageTitle = 'Heavenly Observatory';
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$stats = ['users' => 0, 'bug_reports' => 0, 'petitions' => 0, 'banned' => 0];
try {
    $db = Database::getConnection();
    $stats['users'] = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['bug_reports'] = (int)$db->query("SELECT COUNT(*) FROM bug_reports WHERE status != 'resolved'")->fetchColumn();
    $stats['petitions'] = (int)$db->query("SELECT COUNT(*) FROM dao_petitions WHERE status IN ('observing', 'contemplating')")->fetchColumn();
    try {
        $stats['banned'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_banned = 1')->fetchColumn();
    } catch (\Throwable $e) {
        $stats['banned'] = 0;
    }
} catch (\Throwable $e) {
    $stats['banned'] = 0;
}

require __DIR__ . '/includes/header.php';
?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <a href="users.php" class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6 hover:border-cyan-500/50 transition-all group">
                <div class="text-4xl mb-2">👥</div>
                <h2 class="text-xl font-semibold text-cyan-300 group-hover:text-cyan-200">Cultivator Registry</h2>
                <p class="text-3xl font-bold text-white mt-2"><?php echo (int)$stats['users']; ?></p>
                <p class="text-sm text-gray-400 mt-1">Souls inscribed upon the celestial ledger</p>
            </a>
            <a href="bug_reports.php" class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6 hover:border-amber-500/50 transition-all group">
                <div class="text-4xl mb-2">🔭</div>
                <h2 class="text-xl font-semibold text-amber-300 group-hover:text-amber-200">Anomaly Reports</h2>
                <p class="text-3xl font-bold text-white mt-2"><?php echo (int)$stats['bug_reports']; ?></p>
                <p class="text-sm text-gray-400 mt-1">Awaiting Heavenly Dao observation</p>
            </a>
            <a href="dao_petitions.php" class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 hover:border-violet-500/50 transition-all group">
                <div class="text-4xl mb-2">📜</div>
                <h2 class="text-xl font-semibold text-violet-300 group-hover:text-violet-200">Dao Petitions</h2>
                <p class="text-3xl font-bold text-white mt-2"><?php echo (int)$stats['petitions']; ?></p>
                <p class="text-sm text-gray-400 mt-1">Awaiting celestial judgment</p>
            </a>
            <a href="world_monitor.php" class="bg-gray-800/90 border border-emerald-500/30 rounded-xl p-6 hover:border-emerald-500/50 transition-all group">
                <div class="text-4xl mb-2">🌐</div>
                <h2 class="text-xl font-semibold text-emerald-300 group-hover:text-emerald-200">World Monitor</h2>
                <p class="text-3xl font-bold text-white mt-2">—</p>
                <p class="text-sm text-gray-400 mt-1">Observe realm pressure and influence</p>
            </a>
        </div>

        <div class="bg-gray-800/90 border border-cyan-500/20 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-cyan-300 mb-4">Celestial Quick Links</h2>
            <div class="flex flex-wrap gap-4">
                <a href="dao_records.php" class="px-4 py-2 bg-cyan-900/30 border border-cyan-500/40 rounded-lg text-cyan-200 hover:bg-cyan-900/50 transition-all">
                    📘 View Dao Records (Game Logs)
                </a>
                <a href="users.php?banned=1" class="px-4 py-2 bg-red-900/30 border border-red-500/40 rounded-lg text-red-200 hover:bg-red-900/50 transition-all">
                    Banished Cultivators (<?php echo (int)$stats['banned']; ?>)
                </a>
            </div>
        </div>

        <div class="mt-8 p-6 bg-gray-800/50 border border-gray-700 rounded-xl text-gray-400 text-sm">
            <p class="italic">"The Heavenly Dao observes all. Through this observatory, celestial overseers may guide the realm, address anomalies, judge petitions, and maintain order among cultivators."</p>
        </div>
<?php require __DIR__ . '/includes/footer.php'; ?>
