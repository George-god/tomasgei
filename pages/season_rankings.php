<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SeasonService.php';

use Game\Helper\SessionHelper;
use Game\Service\SeasonService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$cat = isset($_GET['cat']) ? (string)$_GET['cat'] : 'overall';
$svc = new SeasonService();
$page = $svc->getPageData($userId, $cat);

$season = $page['season'];
$category = $page['category'];
$rows = $page['leaderboard'];
$my = $page['my_row'];

$tabs = [
    SeasonService::CATEGORY_OVERALL => 'Overall',
    SeasonService::CATEGORY_PVP => 'PvP',
    SeasonService::CATEGORY_BOSS => 'World Boss',
    SeasonService::CATEGORY_CULTIVATION => 'Cultivation',
    SeasonService::CATEGORY_SECT => 'Sect',
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Season Rankings - The Upper Realms</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-violet-950/40 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-violet-300 to-fuchsia-400 bg-clip-text text-transparent">Season Rankings</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>

        <?php if ($season === null): ?>
            <div class="bg-gray-800/80 border border-amber-500/30 rounded-xl p-6 text-gray-300">
                <p class="mb-2">No active season yet. Ensure the DB was initialized with <code class="text-amber-400">database_full.sql</code> or <code class="text-amber-400">database_schema.sql</code> and visit again (a default season is created automatically).</p>
            </div>
        <?php else: ?>
            <div class="bg-gray-800/60 border border-violet-500/25 rounded-xl p-4 mb-6 text-sm text-gray-400">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <span class="text-violet-300 font-semibold"><?php echo h((string)$season['name']); ?></span>
                        <span class="text-gray-500"> · ends <?php echo h((string)$season['ends_at']); ?></span>
                    </div>
                    <div class="text-xs text-gray-500">
                        Total score = <?php echo (float)$season['weight_pvp']; ?>×PvP + <?php echo (float)$season['weight_boss']; ?>×Boss DMG + <?php echo (float)$season['weight_cultivation']; ?>×Cultivation + <?php echo (float)$season['weight_sect']; ?>×Sect units
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    PvP wins add 10 PvP points each. Boss damage, cultivation sessions, and sect donation units (per 100 gold) add to their categories. At season end, ranks are finalized, rewards and seasonal titles are sent, and a new season begins — schedule <code class="text-violet-400">controllers/season_process.php?key=…</code> (cron).
                </p>
            </div>

            <div class="flex flex-wrap gap-2 mb-6">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="?cat=<?php echo h($key); ?>"
                       class="px-4 py-2 rounded-lg text-sm font-medium border transition-all <?php echo $category === $key ? 'bg-violet-600/40 border-violet-400 text-violet-100' : 'bg-gray-800/80 border-gray-600 text-gray-400 hover:border-violet-500/50'; ?>">
                        <?php echo h($label); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($my): ?>
                <div class="bg-gray-900/50 border border-fuchsia-500/20 rounded-lg p-4 mb-6 text-sm">
                    <span class="text-fuchsia-300 font-semibold">Your season stats</span>
                    <span class="text-gray-400 ml-2">
                        PvP <?php echo (int)$my['score_pvp']; ?> · Boss <?php echo number_format((int)$my['score_world_boss']); ?> · Cultivation <?php echo (int)$my['score_cultivation']; ?> · Sect <?php echo (int)$my['score_sect']; ?>
                        · <strong class="text-white">Total <?php echo number_format((int)$my['total_score']); ?></strong>
                    </span>
                </div>
            <?php endif; ?>

            <div class="overflow-x-auto rounded-xl border border-gray-700 bg-gray-800/40">
                <table class="min-w-full text-sm text-left">
                    <thead class="text-gray-400 border-b border-gray-700">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Player</th>
                            <th class="px-4 py-3 text-right">Score</th>
                            <?php if ($category === SeasonService::CATEGORY_OVERALL): ?>
                                <th class="px-4 py-3 text-right hidden sm:table-cell">PvP</th>
                                <th class="px-4 py-3 text-right hidden md:table-cell">Boss</th>
                                <th class="px-4 py-3 text-right hidden md:table-cell">Cult.</th>
                                <th class="px-4 py-3 text-right hidden lg:table-cell">Sect</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="text-gray-200">
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="<?php echo $category === SeasonService::CATEGORY_OVERALL ? '7' : '3'; ?>" class="px-4 py-8 text-center text-gray-500">No scores yet this season. Fight in PvP, hit the world boss, cultivate, and donate to your sect.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $isMe = (int)$r['user_id'] === $userId;
                                    switch ($category) {
                                        case SeasonService::CATEGORY_PVP:
                                            $scoreCol = (int)$r['score_pvp'];
                                            break;
                                        case SeasonService::CATEGORY_BOSS:
                                            $scoreCol = (int)$r['score_world_boss'];
                                            break;
                                        case SeasonService::CATEGORY_CULTIVATION:
                                            $scoreCol = (int)$r['score_cultivation'];
                                            break;
                                        case SeasonService::CATEGORY_SECT:
                                            $scoreCol = (int)$r['score_sect'];
                                            break;
                                        default:
                                            $scoreCol = (int)$r['total_score'];
                                    }
                                ?>
                                <tr class="border-b border-gray-700/50 <?php echo $isMe ? 'bg-violet-900/20' : ''; ?>">
                                    <td class="px-4 py-3 text-violet-300 font-mono"><?php echo (int)$r['display_rank']; ?></td>
                                    <td class="px-4 py-3"><?php echo h((string)$r['username']); ?><?php echo $isMe ? ' <span class="text-fuchsia-400 text-xs">(you)</span>' : ''; ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-white"><?php echo number_format($scoreCol); ?></td>
                                    <?php if ($category === SeasonService::CATEGORY_OVERALL): ?>
                                        <td class="px-4 py-3 text-right hidden sm:table-cell text-gray-400"><?php echo number_format((int)$r['score_pvp']); ?></td>
                                        <td class="px-4 py-3 text-right hidden md:table-cell text-gray-400"><?php echo number_format((int)$r['score_world_boss']); ?></td>
                                        <td class="px-4 py-3 text-right hidden md:table-cell text-gray-400"><?php echo number_format((int)$r['score_cultivation']); ?></td>
                                        <td class="px-4 py-3 text-right hidden lg:table-cell text-gray-400"><?php echo number_format((int)$r['score_sect']); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
