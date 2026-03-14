<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';
require_once dirname(__DIR__) . '/services/SectBaseService.php';

use Game\Helper\SessionHelper;
use Game\Service\SectBaseService;
use Game\Service\SectService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$baseService = new SectBaseService();
$mySect = $sectService->getSectByUserId($userId);
$baseData = $mySect ? $baseService->getBaseForSect((int)$mySect['id']) : null;

$members = $baseData['members'] ?? [];
$npcs = $baseData['npcs'] ?? [];
$buildings = $baseData['buildings'] ?? [];
$npcBonuses = $baseData['npc_bonuses'] ?? ['cultivation_speed' => 0.0, 'gold_gain' => 0.0, 'breakthrough' => 0.0];
$npcDiscipleCapacity = (int)($baseData['npc_disciple_capacity'] ?? 0);
$activeDiscipleNpcCount = (int)($baseData['active_disciple_npc_count'] ?? 0);
$realReplacements = (int)($baseData['real_joiners_replacing_npcs'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sect Base - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-emerald-400 to-cyan-400 bg-clip-text text-transparent">Sect Base</h1>
            <div class="flex gap-2">
                <a href="sect_library.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Library</a>
                <a href="sect_missions.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-teal-500/30 text-teal-300 transition-all">Missions</a>
                <a href="territories.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-green-500/30 text-green-300 transition-all">Territories</a>
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if (!$mySect || !$baseData): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-8 text-center">
                <p class="text-gray-300 text-lg">You are not part of a sect yet.</p>
                <p class="text-gray-500 text-sm mt-2">Create or join a sect first to unlock a living sect base.</p>
            </div>
        <?php else: ?>
            <div class="bg-gray-800/90 border border-emerald-500/30 rounded-xl p-6 mb-8">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold text-emerald-300"><?php echo htmlspecialchars($baseData['base']['base_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="text-gray-400 text-sm mt-1">Home of <?php echo htmlspecialchars($mySect['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>.</p>
                    </div>
                    <div class="text-sm text-right text-gray-400">
                        <div>Real members: <span class="text-white font-semibold"><?php echo count($members); ?></span></div>
                        <div>NPC residents: <span class="text-white font-semibold"><?php echo count($npcs); ?></span></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <div class="bg-gray-900/50 border border-cyan-500/20 rounded-lg p-4">
                        <div class="text-sm text-gray-400 mb-1">NPC Passive Bonuses</div>
                        <div class="text-cyan-300 text-sm">+<?php echo number_format((float)$npcBonuses['cultivation_speed'] * 100, 1); ?>% cultivation</div>
                        <div class="text-amber-300 text-sm">+<?php echo number_format((float)$npcBonuses['gold_gain'] * 100, 1); ?>% gold</div>
                        <div class="text-purple-300 text-sm">+<?php echo number_format((float)$npcBonuses['breakthrough'] * 100, 1); ?>% breakthrough</div>
                    </div>
                    <div class="bg-gray-900/50 border border-green-500/20 rounded-lg p-4">
                        <div class="text-sm text-gray-400 mb-1">Disciple Residency</div>
                        <div class="text-green-300 text-lg font-semibold"><?php echo $activeDiscipleNpcCount; ?> / <?php echo $npcDiscipleCapacity; ?> NPC disciples active</div>
                        <div class="text-xs text-gray-500 mt-2">NPC disciples step back as real players join the sect.</div>
                    </div>
                    <div class="bg-gray-900/50 border border-amber-500/20 rounded-lg p-4">
                        <div class="text-sm text-gray-400 mb-1">Replacement Progress</div>
                        <div class="text-amber-300 text-lg font-semibold"><?php echo $realReplacements; ?> real joiners replaced NPC disciples</div>
                        <div class="w-full bg-gray-800 rounded-full h-2 mt-3 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-amber-500 to-orange-400" style="width: <?php echo $npcDiscipleCapacity > 0 ? min(100, (int)round(($realReplacements / $npcDiscipleCapacity) * 100)) : 0; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gray-800/90 border border-emerald-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-xl font-semibold text-emerald-300 mb-4">Buildings</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($buildings as $building): ?>
                        <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <h3 class="font-semibold text-white"><?php echo htmlspecialchars($building['building_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h3>
                                <span class="px-2 py-1 rounded bg-cyan-500/10 border border-cyan-500/30 text-cyan-300 text-xs">Level <?php echo (int)($building['level'] ?? 1); ?></span>
                            </div>
                            <p class="text-sm text-gray-400 mb-3"><?php echo htmlspecialchars($building['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="text-xs text-emerald-300"><?php echo htmlspecialchars($building['bonus_summary'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-amber-300 mb-4">Real Sect Members</h2>
                    <ul class="space-y-2">
                        <?php foreach ($members as $member): ?>
                            <li class="flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2">
                                <div>
                                    <div class="text-white font-medium"><?php echo htmlspecialchars($member['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($member['rank'] ?? $member['role'] ?? 'outer_disciple'))), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="text-sm text-amber-300"><?php echo number_format((int)($member['contribution'] ?? 0)); ?> contrib.</div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="bg-gray-800/90 border border-purple-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-purple-300 mb-4">Resident NPCs</h2>
                    <ul class="space-y-2">
                        <?php foreach ($npcs as $npc): ?>
                            <li class="flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2">
                                <div>
                                    <div class="text-white font-medium"><?php echo htmlspecialchars($npc['npc_name'] ?? 'Unknown NPC', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($npc['npc_rank'] ?? 'outer_disciple'))) . ' · ' . (string)($npc['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="text-sm text-purple-300">
                                    <?php if (!empty($npc['bonus_type']) && (float)($npc['bonus_value'] ?? 0) > 0): ?>
                                        +<?php echo number_format((float)$npc['bonus_value'] * 100, 1); ?>% <?php echo htmlspecialchars(str_replace('_', ' ', (string)$npc['bonus_type']), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        Passive support
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>




