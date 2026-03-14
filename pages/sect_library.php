<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/CultivationManualService.php';

use Game\Helper\SessionHelper;
use Game\Service\CultivationManualService;

session_start();
$userId = SessionHelper::requireLoggedIn();
$manualService = new CultivationManualService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $result = null;
    if ($action === 'store_manual') {
        $result = $manualService->storeManualInSectLibrary($userId, (int)($_POST['owned_manual_id'] ?? 0));
    } elseif ($action === 'borrow_manual') {
        $result = $manualService->borrowLibraryManual($userId, (int)($_POST['library_manual_id'] ?? 0));
    } elseif ($action === 'return_manual') {
        $result = $manualService->returnBorrowedLibraryManual($userId, (int)($_POST['library_manual_id'] ?? 0));
    }

    if ($result !== null) {
        $query = $result['success']
            ? '?msg=' . urlencode((string)$result['message'])
            : '?err=' . urlencode((string)$result['message']);
        header('Location: sect_library.php' . $query);
        exit;
    }
}

$pageData = $manualService->getSectLibraryPageData($userId);
$membership = $pageData['membership'] ?? null;
$library = $pageData['library'] ?? null;
$ownedManuals = $pageData['owned_manuals'] ?? [];
$borrowedCount = (int)($pageData['borrowed_count'] ?? 0);
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sect Library - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-violet-400 to-emerald-400 bg-clip-text text-transparent">Sect Library</h1>
            <div class="flex gap-2">
                <a href="cultivation_manuals.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-violet-500/30 text-violet-300 transition-all">My Manuals</a>
                <a href="sect_base.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-emerald-500/30 text-emerald-300 transition-all">Sect Base</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!$membership || !$library): ?>
            <div class="bg-gray-800/90 border border-gray-700 rounded-xl p-8 text-center text-gray-300">
                You must belong to a sect to use the sect library.
            </div>
        <?php else: ?>
            <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6 mb-8">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold text-violet-300"><?php echo htmlspecialchars((string)$membership['sect_name'], ENT_QUOTES, 'UTF-8'); ?> Library Pavilion</h2>
                        <p class="text-sm text-gray-400 mt-1">Rank: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$membership['rank'])), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="text-sm text-gray-300">
                        <div>Library level: <span class="text-white font-semibold"><?php echo (int)$library['level']; ?></span></div>
                        <div>Stored manuals: <span class="text-white font-semibold"><?php echo (int)$library['stored_count']; ?>/<?php echo (int)$library['capacity']; ?></span></div>
                        <div>Your current loans: <span class="text-white font-semibold"><?php echo $borrowedCount; ?></span></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-gray-800/90 border border-violet-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-violet-300 mb-4">Store A Manual</h2>
                    <div class="space-y-3">
                        <?php foreach ($ownedManuals as $manual): ?>
                            <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4 flex items-center justify-between gap-4">
                                <div>
                                    <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$manual['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars(ucfirst((string)$manual['rarity']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)$manual['acquired_from'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="action" value="store_manual">
                                    <input type="hidden" name="owned_manual_id" value="<?php echo (int)$manual['owned_manual_id']; ?>">
                                    <button type="submit" class="px-3 py-2 bg-violet-600 hover:bg-violet-500 text-white font-semibold rounded-lg">Store</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$ownedManuals): ?>
                            <p class="text-gray-400">You do not currently own any manuals that can be stored.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-gray-800/90 border border-emerald-500/30 rounded-xl p-6">
                    <h2 class="text-xl font-semibold text-emerald-300 mb-4">Library Collection</h2>
                    <div class="space-y-3">
                        <?php foreach (($library['manuals'] ?? []) as $manual): ?>
                            <div class="bg-gray-900/60 border border-gray-700 rounded-lg p-4">
                                <div class="flex justify-between items-start gap-4">
                                    <div>
                                        <div class="font-semibold text-white"><?php echo htmlspecialchars((string)$manual['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars(ucfirst((string)$manual['rarity']), ENT_QUOTES, 'UTF-8'); ?>
                                            · Stored by <?php echo htmlspecialchars((string)($manual['stored_by_username'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($manual['borrowed_by_user_id'])): ?>
                                        <span class="text-xs px-2 py-1 rounded bg-amber-500/10 border border-amber-500/30 text-amber-300">
                                            Borrowed by <?php echo htmlspecialchars((string)($manual['borrower_name'] ?? 'Member'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs px-2 py-1 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300">Available</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-gray-400 mt-3"><?php echo htmlspecialchars((string)$manual['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="mt-4">
                                    <?php if ((int)($manual['borrowed_by_user_id'] ?? 0) === $userId): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="return_manual">
                                            <input type="hidden" name="library_manual_id" value="<?php echo (int)$manual['library_manual_id']; ?>">
                                            <button type="submit" class="px-3 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-semibold rounded-lg">Return</button>
                                        </form>
                                    <?php elseif (empty($manual['borrowed_by_user_id'])): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="borrow_manual">
                                            <input type="hidden" name="library_manual_id" value="<?php echo (int)$manual['library_manual_id']; ?>">
                                            <button type="submit" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg">Borrow</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($library['manuals'])): ?>
                            <p class="text-gray-400">The library shelves are still empty.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>




