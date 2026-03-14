<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectMissionService.php';

use Game\Helper\SessionHelper;
use Game\Service\SectMissionService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$missionService = new SectMissionService();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'assign_mission') {
        $npcId = (int)($_POST['npc_id'] ?? 0);
        $missionType = (string)($_POST['mission_type'] ?? '');
        $result = $missionService->assignMission($userId, $npcId, $missionType);
        if (!empty($result['success'])) {
            $message = $result['message'];
        } else {
            $error = $result['message'] ?? 'Could not assign mission.';
        }
    } elseif ($action === 'collect_mission') {
        $missionId = (int)($_POST['mission_id'] ?? 0);
        $result = $missionService->collectMission($userId, $missionId);
        if (!empty($result['success'])) {
            $message = $result['message'];
        } else {
            $error = $result['message'] ?? 'Could not collect mission.';
        }
    }
}

$pageData = $missionService->getMissionPageData($userId);
$sect = $pageData['sect'] ?? null;
$availableNpcs = $pageData['available_npcs'] ?? [];
$activeMissions = $pageData['active_missions'] ?? [];
$readyMissions = $pageData['ready_missions'] ?? [];
$definitions = $pageData['mission_definitions'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sect Missions - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-teal-400 to-emerald-400 bg-clip-text text-transparent">Sect Missions</h1>
            <div class="flex gap-2">
                <a href="sect_base.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-emerald-500/30 text-emerald-300 transition-all">Base</a>
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!$sect): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-8 text-center">
                <p class="text-gray-300 text-lg">You must join a sect to manage NPC missions.</p>
            </div>
        <?php else: ?>
            <div class="bg-gray-800/90 border border-teal-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-2xl font-semibold text-teal-300 mb-2"><?php echo htmlspecialchars($sect['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> Mission Board</h2>
                <p class="text-gray-400 text-sm">NPC disciples can run gathering, scouting, and treasure missions. Higher NPC ranks improve success chance and reward reliability.</p>
            </div>

            <div class="bg-gray-800/90 border border-teal-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-xl font-semibold text-teal-300 mb-4">Mission Types</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($definitions as $key => $mission): ?>
                        <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                            <h3 class="font-semibold text-white mb-2"><?php echo htmlspecialchars((string)$mission['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="text-sm text-gray-400">Duration: <?php echo (int)$mission['duration_minutes']; ?> minutes</p>
                            <p class="text-sm text-cyan-300">Base success: <?php echo number_format((float)$mission['base_success_chance'], 1); ?>%</p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-gray-800/90 border border-emerald-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-xl font-semibold text-emerald-300 mb-4">Assign NPC Missions</h2>
                <?php if (empty($availableNpcs)): ?>
                    <p class="text-gray-500">No active NPC disciples are currently available. Recruit more real members or wait for a mission to finish.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($availableNpcs as $npc): ?>
                            <form method="POST" class="flex flex-wrap items-center gap-3 bg-gray-900/50 rounded-lg p-4 border border-gray-700">
                                <input type="hidden" name="action" value="assign_mission">
                                <input type="hidden" name="npc_id" value="<?php echo (int)$npc['id']; ?>">
                                <div class="min-w-[180px]">
                                    <div class="text-white font-medium"><?php echo htmlspecialchars($npc['npc_name'] ?? 'Unknown NPC', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($npc['npc_rank'] ?? 'outer_disciple'))), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($npc['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <select name="mission_type" class="bg-gray-800 border border-gray-600 rounded px-3 py-2 text-white text-sm">
                                    <?php foreach ($definitions as $missionKey => $mission): ?>
                                        <option value="<?php echo htmlspecialchars((string)$missionKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$mission['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg">Assign Mission</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-gray-800/90 border border-cyan-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-cyan-300 mb-4">Active Missions</h2>
                    <?php if (empty($activeMissions)): ?>
                        <p class="text-gray-500">No NPC missions are active right now.</p>
                    <?php else: ?>
                        <ul class="space-y-3">
                            <?php foreach ($activeMissions as $mission): ?>
                                <li class="bg-gray-900/50 rounded-lg p-4 border border-gray-700">
                                    <div class="flex justify-between gap-4 items-start">
                                        <div>
                                            <div class="text-white font-medium"><?php echo htmlspecialchars((string)($mission['npc_name'] ?? 'Unknown NPC'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($mission['npc_rank'] ?? 'outer_disciple'))), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <div class="text-right text-sm text-gray-400">
                                            <div><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($mission['mission_type'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div>Ends: <?php echo htmlspecialchars((string)($mission['end_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-sm text-cyan-300">Success chance: <?php echo number_format((float)($mission['success_chance'] ?? 0), 1); ?>%</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="bg-gray-800/90 border border-amber-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-amber-300 mb-4">Ready To Collect</h2>
                    <?php if (empty($readyMissions)): ?>
                        <p class="text-gray-500">No mission reports are waiting.</p>
                    <?php else: ?>
                        <ul class="space-y-3">
                            <?php foreach ($readyMissions as $mission): ?>
                                <li class="bg-gray-900/50 rounded-lg p-4 border border-gray-700">
                                    <div class="flex justify-between items-start gap-4">
                                        <div>
                                            <div class="text-white font-medium"><?php echo htmlspecialchars((string)($mission['npc_name'] ?? 'Unknown NPC'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($mission['mission_type'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <span class="px-2 py-1 rounded text-xs <?php echo (string)($mission['status'] ?? '') === 'completed' ? 'bg-green-500/10 border border-green-500/30 text-green-300' : 'bg-red-500/10 border border-red-500/30 text-red-300'; ?>">
                                            <?php echo htmlspecialchars(ucfirst((string)($mission['status'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-400 mt-3"><?php echo htmlspecialchars((string)($mission['result_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php if ((string)($mission['status'] ?? '') === 'completed'): ?>
                                        <div class="mt-2 text-sm text-amber-300">
                                            <?php if ((int)($mission['reward_gold'] ?? 0) > 0): ?>+<?php echo (int)$mission['reward_gold']; ?> gold <?php endif; ?>
                                            <?php if ((int)($mission['reward_spirit_stones'] ?? 0) > 0): ?>+<?php echo (int)$mission['reward_spirit_stones']; ?> spirit stones <?php endif; ?>
                                            <?php if (!empty($mission['reward_item_name']) && (int)($mission['reward_quantity'] ?? 0) > 0): ?>+<?php echo (int)$mission['reward_quantity']; ?> <?php echo htmlspecialchars((string)$mission['reward_item_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="action" value="collect_mission">
                                        <input type="hidden" name="mission_id" value="<?php echo (int)$mission['id']; ?>">
                                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-lg">
                                            <?php echo (string)($mission['status'] ?? '') === 'completed' ? 'Collect Rewards' : 'Acknowledge Report'; ?>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>




