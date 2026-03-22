<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';
require_once dirname(__DIR__) . '/services/AllianceService.php';

use Game\Helper\SessionHelper;
use Game\Service\AllianceService;
use Game\Service\SectService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$allianceService = new AllianceService();
$mySect = $sectService->getSectByUserId($userId);
$message = null;
$error = null;

$canManage = $mySect && in_array((string)($mySect['rank'] ?? $mySect['role'] ?? ''), ['leader', 'elder'], true);
$mySectId = $mySect ? (int)$mySect['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $mySect && $canManage) {
    $action = (string)$_POST['action'];
    if ($action === 'create_alliance') {
        $name = trim((string)($_POST['alliance_name'] ?? ''));
        $result = $allianceService->createAlliance($userId, $name);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'invite_sect') {
        $targetId = (int)($_POST['target_sect_id'] ?? 0);
        $result = $allianceService->inviteSect($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'accept_invite') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        $result = $allianceService->acceptInvite($userId, $inviteId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'decline_invite') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        $result = $allianceService->declineInvite($userId, $inviteId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'cancel_invite') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        $result = $allianceService->cancelInvite($userId, $inviteId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'leave_alliance') {
        $result = $allianceService->leaveAlliance($userId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    }
}

$panel = $mySect ? $allianceService->getAlliancePanelForUser($userId) : null;
$incomingInvites = $mySectId > 0 ? $allianceService->getPendingInvitesForSect($mySectId) : [];
$sectsList = $sectService->listSects();
$occupiedSectIds = array_fill_keys($allianceService->getAllSectIdsInAlliances(), true);
$warBonusRows = AllianceService::warBonusDescription();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alliance - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-sky-400 to-indigo-500 bg-clip-text text-transparent">Alliance</h1>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="territories.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-green-500/30 text-green-300 transition-all">Territories</a>
                <a href="diplomacy.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">Diplomacy</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="bg-gray-800/90 border border-sky-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-sky-300 mb-3">Sect war bonuses</h2>
            <p class="text-gray-400 text-sm mb-4">When your pact includes <strong class="text-white">3 to 5</strong> sects, all members deal increased damage to the War Crystal (attackers) and gain stronger repel scores (defenders). Allied sects <strong class="text-white">cannot declare war</strong> on each other.</p>
            <ul class="space-y-2 text-sm">
                <?php foreach ($warBonusRows as $row): ?>
                <li class="flex justify-between bg-gray-900/50 rounded-lg px-3 py-2 border border-gray-700">
                    <span class="text-gray-300"><?php echo (int)$row['members']; ?> sects in pact</span>
                    <span class="text-amber-300 font-semibold">+<?php echo (int)$row['bonus_pct']; ?>% damage</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if (!$mySect): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-6 text-gray-400">
                Join a sect first to participate in alliances.
            </div>
        <?php else: ?>

            <?php if (!empty($incomingInvites) && $canManage): ?>
            <div class="bg-gray-800/90 border border-indigo-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-lg font-semibold text-indigo-300 mb-3">Invitations to your sect</h2>
                <ul class="space-y-3">
                    <?php foreach ($incomingInvites as $inv): ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 bg-gray-900/50 rounded-lg px-3 py-3 border border-gray-700">
                        <div>
                            <div class="text-white font-medium"><?php echo htmlspecialchars($inv['alliance_name'] ?: 'Alliance', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs text-gray-500">From <?php echo htmlspecialchars($inv['inviter_sect_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($inv['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST">
                                <input type="hidden" name="action" value="accept_invite">
                                <input type="hidden" name="invite_id" value="<?php echo (int)$inv['id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-500 text-white text-sm rounded-lg">Accept</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="action" value="decline_invite">
                                <input type="hidden" name="invite_id" value="<?php echo (int)$inv['id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm rounded-lg">Decline</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($panel): ?>
            <div class="bg-gray-800/90 border border-sky-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-xl font-semibold text-sky-300 mb-1"><?php echo htmlspecialchars($panel['name'] ?: 'Alliance', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-gray-500 text-sm mb-4"><?php echo (int)$panel['member_count']; ?> / <?php echo AllianceService::MAX_SECTS_PER_ALLIANCE; ?> sects ·
                    <?php if (!empty($panel['war_bonus_active'])): ?>
                        <span class="text-amber-300">War bonus active: ×<?php echo htmlspecialchars(number_format((float)$panel['war_damage_multiplier'], 2), ENT_QUOTES, 'UTF-8'); ?> damage</span>
                    <?php else: ?>
                        <span class="text-gray-400">Recruit <?php echo AllianceService::MIN_SECTS_FOR_WAR_BONUS; ?>–<?php echo AllianceService::MAX_SECTS_PER_ALLIANCE; ?> sects to unlock war bonuses</span>
                    <?php endif; ?>
                </p>

                <h3 class="text-sm font-semibold text-gray-300 mb-2">Member sects</h3>
                <ul class="space-y-2 mb-6">
                    <?php foreach ($panel['members'] as $m): ?>
                    <li class="flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2 border border-gray-700">
                        <span class="text-white"><?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?><?php if ($m['id'] === $mySectId): ?> <span class="text-sky-400 text-xs">(yours)</span><?php endif; ?></span>
                        <span class="text-gray-500 text-xs"><?php echo htmlspecialchars($m['role'] === 'founder' ? 'Founder' : 'Member', ENT_QUOTES, 'UTF-8'); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($canManage && !empty($panel['pending_invites_out'])): ?>
                <h3 class="text-sm font-semibold text-gray-300 mb-2">Pending invitations</h3>
                <ul class="space-y-2 mb-6">
                    <?php foreach ($panel['pending_invites_out'] as $p): ?>
                    <li class="flex justify-between items-center bg-gray-900/50 rounded-lg px-3 py-2 border border-gray-700">
                        <span class="text-gray-300"><?php echo htmlspecialchars($p['target_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="cancel_invite">
                            <input type="hidden" name="invite_id" value="<?php echo (int)$p['id']; ?>">
                            <button type="submit" class="text-xs text-red-400 hover:underline">Cancel</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if ($canManage && (int)$panel['member_count'] < AllianceService::MAX_SECTS_PER_ALLIANCE): ?>
                <div class="border-t border-gray-700 pt-4">
                    <h3 class="text-sm font-semibold text-gray-300 mb-2">Invite a sect</h3>
                    <form method="POST" class="flex flex-wrap gap-2 items-center">
                        <input type="hidden" name="action" value="invite_sect">
                        <select name="target_sect_id" required class="flex-1 min-w-[200px] bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                            <option value="">Choose sect…</option>
                            <?php foreach ($sectsList as $s):
                                $sid = (int)$s['id'];
                                if ($sid === $mySectId) {
                                    continue;
                                }
                                if (isset($occupiedSectIds[$sid])) {
                                    continue;
                                }
                            ?>
                            <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> (<?php echo $sid; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white text-sm font-semibold rounded-lg">Send invite</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($canManage): ?>
                <form method="POST" class="mt-6" onsubmit="return confirm('Leave this alliance? Your sect will no longer receive pact bonuses.');">
                    <input type="hidden" name="action" value="leave_alliance">
                    <button type="submit" class="px-3 py-2 bg-red-900/40 hover:bg-red-900/60 border border-red-500/40 text-red-300 text-sm rounded-lg">Leave alliance</button>
                </form>
                <?php endif; ?>
            </div>
            <?php elseif ($canManage): ?>
            <div class="bg-gray-800/90 border border-sky-500/30 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-sky-300 mb-3">Found an alliance</h2>
                <p class="text-gray-400 text-sm mb-4">Create a pact for up to <?php echo AllianceService::MAX_SECTS_PER_ALLIANCE; ?> sects. Invite others; at <?php echo AllianceService::MIN_SECTS_FOR_WAR_BONUS; ?>+ members, sect war damage bonuses apply.</p>
                <form method="POST" class="flex flex-wrap gap-2 items-end">
                    <input type="hidden" name="action" value="create_alliance">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 mb-1">Alliance name (optional)</label>
                        <input type="text" name="alliance_name" maxlength="80" placeholder="Defaults to your sect name" class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white font-semibold rounded-lg">Create alliance</button>
                </form>
            </div>
            <?php else: ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-6 text-gray-400">
                Your sect is not in an alliance. Ask a leader or elder to create one or accept an invitation.
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
