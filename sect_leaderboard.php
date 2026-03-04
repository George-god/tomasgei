<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/SectService.php';

use Game\Helper\SessionHelper;
use Game\Service\SectService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$leaderboard = $sectService->getLeaderboard();
$mySect = $sectService->getSectByUserId($userId);
$mySectId = $mySect ? (int)$mySect['id'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sect Leaderboard - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-500 to-orange-600 bg-clip-text text-transparent">Sect Leaderboard</h1>
            <div class="flex gap-2">
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-600 text-gray-400 text-sm">
                            <th class="px-4 py-3 font-semibold">Rank</th>
                            <th class="px-4 py-3 font-semibold">Sect</th>
                            <th class="px-4 py-3 font-semibold">Tier</th>
                            <th class="px-4 py-3 font-semibold">Sect EXP</th>
                            <th class="px-4 py-3 font-semibold">Leader</th>
                            <th class="px-4 py-3 font-semibold">Members</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 0;
                        foreach ($leaderboard as $row):
                            $rank++;
                            $sectId = (int)$row['id'];
                            $isMine = $mySectId !== null && $sectId === $mySectId;
                            $rowClass = 'border-b border-gray-700/80';
                            if ($rank === 1) {
                                $rowClass .= ' bg-amber-500/10 border-l-4 border-l-amber-400';
                            } elseif ($rank === 2) {
                                $rowClass .= ' bg-gray-400/10 border-l-4 border-l-gray-300';
                            } elseif ($rank === 3) {
                                $rowClass .= ' bg-amber-700/20 border-l-4 border-l-amber-600';
                            }
                            if ($isMine) {
                                $rowClass .= ' ring-1 ring-inset ring-amber-500/50';
                            }
                        ?>
                        <tr class="<?php echo $rowClass; ?> <?php echo $isMine ? 'text-amber-100' : 'text-gray-200'; ?>">
                            <td class="px-4 py-3">
                                <?php if ($rank <= 3): ?>
                                    <span class="font-bold"><?php echo $rank === 1 ? '1st' : ($rank === 2 ? '2nd' : '3rd'); ?></span>
                                <?php else: ?>
                                    <?php echo $rank; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars(ucfirst((string)($row['tier'] ?? 'third')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-3"><?php echo number_format((int)($row['sect_exp'] ?? 0)); ?></td>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($row['leader_username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="px-4 py-3"><?php echo (int)($row['member_count'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($leaderboard)): ?>
                <p class="px-4 py-8 text-gray-500 text-center">No sects yet. Create one from the Sect page.</p>
            <?php endif; ?>
        </div>

        <?php if ($mySectId !== null): ?>
            <p class="mt-4 text-sm text-amber-300/80">Your sect is highlighted.</p>
        <?php endif; ?>
    </div>
</body>
</html>
