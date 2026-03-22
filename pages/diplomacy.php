<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/SectService.php';
require_once dirname(__DIR__) . '/services/DiplomacyService.php';

use Game\Helper\SessionHelper;
use Game\Service\DiplomacyService;
use Game\Service\SectService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$sectService = new SectService();
$diplomacyService = new DiplomacyService();
$mySect = $sectService->getSectByUserId($userId);
$message = null;
$error = null;

$canManage = $mySect && in_array((string)($mySect['rank'] ?? $mySect['role'] ?? ''), ['leader', 'elder'], true);
$mySectId = $mySect ? (int)$mySect['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $mySect && $canManage) {
    $action = (string)$_POST['action'];
    $targetId = (int)($_POST['target_sect_id'] ?? 0);
    if ($action === 'propose_nap') {
        $result = $diplomacyService->proposeNonAggressionPact($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'accept_nap') {
        $result = $diplomacyService->acceptNonAggressionPact($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'withdraw_nap') {
        $result = $diplomacyService->withdrawNapProposal($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'decline_nap') {
        $result = $diplomacyService->declineNapProposal($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'declare_rivalry') {
        $result = $diplomacyService->declareRivalry($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    } elseif ($action === 'break_agreement') {
        $result = $diplomacyService->breakAgreement($userId, $targetId);
        $message = $result['success'] ? $result['message'] : null;
        $error = $result['success'] ? null : $result['message'];
    }
}

$panel = $mySect ? $diplomacyService->getDiplomacyPanelForUser($userId) : null;
$sectsList = $sectService->listSects();
$rivalMultPct = (int)round((DiplomacyService::RIVAL_WAR_DAMAGE_MULTIPLIER - 1.0) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diplomacy - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-fuchsia-500 bg-clip-text text-transparent">Diplomacy</h1>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="sect.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-amber-500/30 text-amber-300 transition-all">Sect</a>
                <a href="alliance.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-sky-500/30 text-sky-300 transition-all">Alliance</a>
                <a href="territories.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-green-500/30 text-green-300 transition-all">Territories</a>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-violet-300 mb-2">Effects in sect wars</h2>
            <ul class="text-sm text-gray-400 space-y-2 list-disc list-inside">
                <li><strong class="text-white">Non-aggression pact (NAP):</strong> while active, neither sect may declare war on the other&rsquo;s territory.</li>
                <li><strong class="text-white">Rivalry:</strong> both sects deal <strong class="text-amber-300">+<?php echo $rivalMultPct; ?>%</strong> damage when fighting each other in an active sect war.</li>
            </ul>
        </div>

        <div class="bg-gray-800/90 border border-fuchsia-500/25 rounded-xl p-6 mb-8">
            <h2 class="text-lg font-semibold text-fuchsia-300 mb-2">Reputation</h2>
            <p class="text-sm text-gray-400 mb-3">Sect reputation shifts with diplomatic choices: signing a NAP, honoring or breaking treaties, long-standing peace broken, betrayal (declaring rivalry while a NAP is active), declaring or ending feuds, and withdrawing proposals.</p>
            <ul class="text-xs text-gray-500 space-y-1">
                <li>Accepting a NAP: both sects gain standing.</li>
                <li>Breaking an active NAP: the breaker loses standing; the partner gains. Breaking after <?php echo (int)DiplomacyService::LONG_NAP_DAYS; ?>+ days of peace increases the swing.</li>
                <li>Betrayal (rivalry after NAP): severe loss for the betrayer; the betrayed sect gains honor.</li>
                <li>Ending rivalry: small recovery for both sects.</li>
            </ul>
        </div>

        <?php if (!$mySect): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-6 text-gray-400">Join a sect to manage diplomacy.</div>
        <?php elseif ($panel): ?>
            <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 mb-8">
                <h2 class="text-xl font-semibold text-white mb-1"><?php echo htmlspecialchars($panel['sect_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-gray-400 text-sm mb-1">
                    Reputation: <span class="text-amber-300 font-semibold tabular-nums"><?php echo number_format((int)$panel['reputation']); ?></span>
                    <span class="text-violet-300">(<?php echo htmlspecialchars($panel['reputation_band'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                </p>
            </div>

            <?php if ($canManage): ?>
            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-300 mb-4">New diplomacy</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-medium text-violet-300 mb-2">Propose non-aggression pact</h4>
                        <form method="POST" class="flex flex-col gap-2">
                            <input type="hidden" name="action" value="propose_nap">
                            <select name="target_sect_id" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="">Choose sect…</option>
                                <?php foreach ($sectsList as $s):
                                    $sid = (int)$s['id'];
                                    if ($sid === $mySectId || !$diplomacyService->canInitiateNewDiplomacy($mySectId, $sid)) {
                                        continue;
                                    }
                                ?>
                                <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-3 py-2 bg-violet-600 hover:bg-violet-500 text-white text-sm font-semibold rounded-lg">Send proposal</button>
                        </form>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-red-300 mb-2">Declare rivalry</h4>
                        <form method="POST" class="flex flex-col gap-2" onsubmit="return confirm('Declare this sect a rival? This affects sect war damage and reputation.');">
                            <input type="hidden" name="action" value="declare_rivalry">
                            <select name="target_sect_id" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                                <option value="">Choose sect…</option>
                                <?php foreach ($sectsList as $s):
                                    $sid = (int)$s['id'];
                                    if ($sid === $mySectId || !$diplomacyService->canInitiateNewDiplomacy($mySectId, $sid)) {
                                        continue;
                                    }
                                ?>
                                <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($s['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-3 py-2 bg-red-900/60 hover:bg-red-800 border border-red-500/40 text-red-200 text-sm font-semibold rounded-lg">Declare rivalry</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-gray-800/90 border border-gray-600 rounded-xl p-6">
                <h3 class="text-lg font-semibold text-gray-300 mb-4">Relations</h3>
                <?php if (empty($panel['relations'])): ?>
                    <p class="text-gray-500 text-sm">No active diplomatic records. Use the forms above to open negotiations.</p>
                <?php else: ?>
                <ul class="space-y-4">
                    <?php foreach ($panel['relations'] as $rel): ?>
                    <li class="bg-gray-900/50 border border-gray-700 rounded-lg p-4">
                        <div class="flex flex-wrap justify-between gap-2 mb-2">
                            <span class="text-white font-medium"><?php echo htmlspecialchars($rel['other_sect_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="text-gray-500 text-xs">#<?php echo (int)$rel['other_sect_id']; ?></span>
                        </div>
                        <div class="text-sm text-gray-400 space-y-1 mb-3">
                            <?php if (!empty($rel['is_rival'])): ?>
                                <div><span class="text-red-400 font-semibold">Rivalry</span> — +<?php echo $rivalMultPct; ?>% damage vs this sect in sect wars.</div>
                            <?php endif; ?>
                            <?php if ($rel['nap_status'] === 'active'): ?>
                                <div><span class="text-emerald-400 font-semibold">NAP active</span><?php if (!empty($rel['nap_started_at'])): ?> since <?php echo htmlspecialchars((string)$rel['nap_started_at'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?> — no war declarations between you.</div>
                            <?php elseif ($rel['nap_status'] === 'pending'): ?>
                                <div><span class="text-amber-400 font-semibold">NAP pending</span><?php if (!empty($rel['outgoing_nap'])): ?> (you proposed)<?php elseif (!empty($rel['incoming_nap'])): ?> (they proposed)<?php endif; ?></div>
                            <?php else: ?>
                                <?php if (empty($rel['is_rival'])): ?>
                                <div class="text-gray-500">No active treaty (neutral).</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($canManage): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php if (!empty($rel['incoming_nap'])): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="accept_nap">
                                <input type="hidden" name="target_sect_id" value="<?php echo (int)$rel['other_sect_id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded-lg">Accept NAP</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="action" value="decline_nap">
                                <input type="hidden" name="target_sect_id" value="<?php echo (int)$rel['other_sect_id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs rounded-lg">Decline</button>
                            </form>
                            <?php endif; ?>
                            <?php if (!empty($rel['outgoing_nap'])): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="withdraw_nap">
                                <input type="hidden" name="target_sect_id" value="<?php echo (int)$rel['other_sect_id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs rounded-lg">Withdraw proposal</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($rel['nap_status'] === 'active'): ?>
                            <form method="POST" onsubmit="return confirm('Break the non-aggression pact? Your sect will lose diplomatic standing.');">
                                <input type="hidden" name="action" value="break_agreement">
                                <input type="hidden" name="target_sect_id" value="<?php echo (int)$rel['other_sect_id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-orange-900/50 hover:bg-orange-900/70 border border-orange-500/40 text-orange-200 text-xs rounded-lg">Break NAP</button>
                            </form>
                            <?php endif; ?>
                            <?php if (!empty($rel['is_rival'])): ?>
                            <form method="POST" onsubmit="return confirm('End this rivalry?');">
                                <input type="hidden" name="action" value="break_agreement">
                                <input type="hidden" name="target_sect_id" value="<?php echo (int)$rel['other_sect_id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs rounded-lg">End rivalry</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
