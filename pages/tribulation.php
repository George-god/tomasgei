<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/TribulationService.php';

use Game\Helper\SessionHelper;
use Game\Service\TribulationService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$tribulationService = new TribulationService();
$history = $tribulationService->getTribulationHistory($userId, 12);
$requestedId = (int)($_GET['id'] ?? 0);
$selectedTribulation = null;

if ($requestedId > 0) {
    $selectedTribulation = $tribulationService->getTribulationById($requestedId, $userId);
}
if ($selectedTribulation === null && $history !== []) {
    $selectedTribulation = $tribulationService->getTribulationById((int)$history[0]['id'], $userId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tribulations - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">Cultivation Tribulations</h1>
            </div>
            <div class="flex gap-2">
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">Dashboard</a>
            </div>
        </div>

        <?php if ($selectedTribulation === null): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-8 text-center text-gray-300">
                No tribulations recorded yet. Reach a major breakthrough and challenge the heavens.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 space-y-6">
                    <div class="bg-gray-800/90 border <?php echo !empty($selectedTribulation['success']) ? 'border-green-500/30' : 'border-red-500/30'; ?> rounded-xl p-6">
                        <div class="flex flex-wrap justify-between gap-4 items-start">
                            <div>
                                <div class="text-sm uppercase tracking-[0.2em] text-gray-500 mb-2"><?php echo htmlspecialchars((string)($selectedTribulation['tribulation_label'] ?? 'Tribulation'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <h2 class="text-3xl font-bold <?php echo !empty($selectedTribulation['success']) ? 'text-green-300' : 'text-red-300'; ?>">
                                    <?php echo !empty($selectedTribulation['success']) ? 'Survived' : 'Failed'; ?>
                                </h2>
                                <p class="text-sm text-gray-400 mt-2">
                                    <?php echo htmlspecialchars((string)($selectedTribulation['realm_before_name'] ?? 'Unknown Realm'), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($selectedTribulation['success']) && !empty($selectedTribulation['realm_after_name'])): ?>
                                        -> <?php echo htmlspecialchars((string)$selectedTribulation['realm_after_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="text-right text-sm text-gray-400">
                                <div>Difficulty: <span class="text-purple-300 font-semibold"><?php echo number_format((float)($selectedTribulation['difficulty_rating'] ?? 1.0), 3); ?>x</span></div>
                                <div>Created: <?php echo htmlspecialchars((string)($selectedTribulation['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($selectedTribulation['failed_phase'])): ?>
                                    <div>Broken at phase <?php echo (int)$selectedTribulation['failed_phase']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                            <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-4">
                                <div class="text-xs text-gray-500 mb-1">Start Chi</div>
                                <div class="text-xl font-semibold text-cyan-300"><?php echo number_format((int)($selectedTribulation['start_chi'] ?? 0)); ?></div>
                            </div>
                            <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-4">
                                <div class="text-xs text-gray-500 mb-1">End Chi</div>
                                <div class="text-xl font-semibold text-cyan-300"><?php echo number_format((int)($selectedTribulation['end_chi'] ?? 0)); ?></div>
                            </div>
                            <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-4">
                                <div class="text-xs text-gray-500 mb-1">Total Damage</div>
                                <div class="text-xl font-semibold text-red-300"><?php echo number_format((int)($selectedTribulation['damage_taken'] ?? 0)); ?></div>
                            </div>
                            <div class="bg-gray-900/50 border border-gray-700 rounded-lg p-4">
                                <div class="text-xs text-gray-500 mb-1">Attempts Pressure</div>
                                <div class="text-xl font-semibold text-amber-300"><?php echo number_format((int)($selectedTribulation['breakthrough_attempts_used'] ?? 0)); ?></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 text-sm">
                            <div class="bg-gray-900/40 border border-gray-700 rounded-lg p-3 text-gray-300">
                                Pill preparation: <span class="text-emerald-300 font-semibold">+<?php echo number_format((float)($selectedTribulation['pill_bonus_applied'] ?? 0) * 100, 1); ?>%</span>
                            </div>
                            <div class="bg-gray-900/40 border border-gray-700 rounded-lg p-3 text-gray-300">
                                Sect bonus: <span class="text-emerald-300 font-semibold">+<?php echo number_format((float)($selectedTribulation['sect_bonus_applied'] ?? 0) * 100, 1); ?>%</span>
                            </div>
                            <div class="bg-gray-900/40 border border-gray-700 rounded-lg p-3 text-gray-300">
                                Rune support: <span class="text-cyan-300 font-semibold"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($selectedTribulation['rune_type'] ?? 'none'))), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-800/90 border border-purple-500/30 rounded-xl p-6">
                        <h3 class="text-xl font-semibold text-purple-300 mb-4">Three-Phase Survival Progress</h3>
                        <div class="space-y-4">
                            <?php foreach (($selectedTribulation['phases'] ?? []) as $phase): ?>
                                <div class="bg-gray-900/50 border <?php echo (string)($phase['phase_result'] ?? 'survived') === 'failed' ? 'border-red-500/40' : 'border-gray-700'; ?> rounded-lg p-4">
                                    <div class="flex flex-wrap justify-between gap-4 items-start mb-3">
                                        <div>
                                            <div class="text-xs text-gray-500 mb-1">Phase <?php echo (int)($phase['strike_number'] ?? 0); ?></div>
                                            <div class="text-lg font-semibold text-white"><?php echo htmlspecialchars((string)($phase['phase_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm <?php echo (string)($phase['phase_result'] ?? 'survived') === 'failed' ? 'text-red-300' : 'text-green-300'; ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)($phase['phase_result'] ?? 'survived')), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">Damage taken: <?php echo number_format((int)($phase['damage_after_defense'] ?? 0)); ?></div>
                                        </div>
                                    </div>

                                    <div class="mb-2 flex justify-between text-xs text-gray-400">
                                        <span>Chi after phase</span>
                                        <span><?php echo number_format((int)($phase['chi_after'] ?? 0)); ?> (<?php echo number_format((float)($phase['survival_percent'] ?? 0), 1); ?>%)</span>
                                    </div>
                                    <div class="w-full bg-gray-950 rounded-full h-3 overflow-hidden border border-gray-700">
                                        <div class="h-full <?php echo (string)($phase['phase_result'] ?? 'survived') === 'failed' ? 'bg-gradient-to-r from-red-600 to-red-400' : 'bg-gradient-to-r from-cyan-500 to-purple-500'; ?>" style="width: <?php echo max(0, min(100, (float)($phase['survival_percent'] ?? 0))); ?>%"></div>
                                    </div>

                                    <p class="text-sm text-gray-400 mt-3"><?php echo htmlspecialchars((string)($phase['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-6">
                        <h3 class="text-xl font-semibold text-gray-200 mb-4">Recent Tribulations</h3>
                        <?php if ($history === []): ?>
                            <p class="text-gray-500 text-sm">No previous tribulations.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($history as $entry): ?>
                                    <a href="tribulation.php?id=<?php echo (int)$entry['id']; ?>" class="block bg-gray-900/50 border <?php echo (int)$entry['id'] === (int)($selectedTribulation['id'] ?? 0) ? 'border-purple-500/50' : 'border-gray-700'; ?> rounded-lg p-4 hover:border-purple-500/40 transition-all">
                                        <div class="flex justify-between gap-3 items-start">
                                            <div>
                                                <div class="text-sm font-semibold text-white"><?php echo htmlspecialchars((string)($entry['tribulation_label'] ?? 'Tribulation'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars((string)($entry['realm_before_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($entry['realm_after_name'])): ?> -> <?php echo htmlspecialchars((string)$entry['realm_after_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                                            </div>
                                            <span class="text-xs <?php echo !empty($entry['success']) ? 'text-green-300' : 'text-red-300'; ?>">
                                                <?php echo !empty($entry['success']) ? 'Success' : 'Failed'; ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-2">
                                            Difficulty <?php echo number_format((float)($entry['difficulty_rating'] ?? 1.0), 3); ?>x
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>




