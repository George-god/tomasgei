<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/MarketplaceService.php';
require_once dirname(__DIR__) . '/services/ItemService.php';

use Game\Config\Database;
use Game\Helper\SessionHelper;
use Game\Service\MarketplaceService;
use Game\Service\ItemService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$marketplaceService = new MarketplaceService();
$itemService = new ItemService();

$listings = $marketplaceService->getActiveListings();
$myListings = $marketplaceService->getMyActiveListings($userId);
$inventory = $itemService->getUserInventory($userId, false);
$listableItems = array_filter($inventory, function ($row) {
    return (int)($row['is_equipped'] ?? 1) === 0 && (int)($row['quantity'] ?? 0) >= 1;
});

$db = Database::getConnection();
$stmt = $db->prepare("SELECT gold FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userGold = (int)($stmt->fetch()['gold'] ?? 0);

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-400 to-yellow-500 bg-clip-text text-transparent">
                    🏪 Marketplace
                </h1>
            </div>
            <div class="flex gap-4 items-center">
                <span class="text-amber-300 font-semibold"><?php echo number_format($userGold); ?> Gold</span>
                <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div id="marketplace-message" class="mb-4 hidden"></div>

        <!-- Create listing -->
        <div class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-amber-300 mb-4">Sell an item</h2>
            <form id="create-listing-form" method="POST" action="../controllers/create_listing.php" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Item</label>
                    <select name="inventory_id" required class="bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white min-w-[200px]">
                        <option value="">Choose item...</option>
                        <?php foreach ($listableItems as $inv): $t = $inv['template'] ?? []; ?>
                            <option value="<?php echo (int)$inv['id']; ?>"><?php echo htmlspecialchars($t['name'] ?? 'Item', ENT_QUOTES, 'UTF-8'); ?> (×<?php echo (int)$inv['quantity']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Price (gold)</label>
                    <input type="number" name="price" min="1" required placeholder="1" class="bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white w-24">
                </div>
                <button type="submit" id="create-listing-btn" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg transition-all">List item</button>
            </form>
            <?php if (empty($listableItems)): ?>
                <p class="text-sm text-gray-500 mt-2">You have no listable items (unequipped, quantity ≥ 1). <a href="inventory.php" class="text-amber-400 hover:underline">Inventory</a></p>
            <?php endif; ?>
        </div>

        <!-- My active listings -->
        <?php if (!empty($myListings)): ?>
        <div class="bg-gray-800/90 backdrop-blur border border-purple-500/30 rounded-xl p-6 mb-8">
            <h2 class="text-xl font-semibold text-purple-300 mb-4">My listings</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead><tr class="text-gray-400 text-sm border-b border-gray-600"><th class="pb-2">Item</th><th class="pb-2">Price</th><th class="pb-2">Listed</th><th class="pb-2"></th></tr></thead>
                    <tbody>
                        <?php foreach ($myListings as $m): ?>
                        <tr class="border-b border-gray-700/50" data-listing-id="<?php echo (int)$m['id']; ?>">
                            <td class="py-2 text-white"><?php echo htmlspecialchars($m['item_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-2 text-amber-300"><?php echo number_format((int)$m['price']); ?> gold</td>
                            <td class="py-2 text-gray-500 text-sm"><?php echo htmlspecialchars($m['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-2">
                                <button type="button" class="cancel-listing-btn px-3 py-1 bg-red-900/50 hover:bg-red-800 border border-red-500/50 rounded text-red-300 text-sm" data-listing-id="<?php echo (int)$m['id']; ?>">Cancel</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- All active listings -->
        <div class="bg-gray-800/90 backdrop-blur border border-cyan-500/30 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-cyan-300 mb-4">Active listings</h2>
            <?php if (empty($listings)): ?>
                <p class="text-gray-500">No listings at the moment.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead><tr class="text-gray-400 text-sm border-b border-gray-600"><th class="pb-2">Item</th><th class="pb-2">Type</th><th class="pb-2">Seller</th><th class="pb-2">Price</th><th class="pb-2"></th></tr></thead>
                        <tbody>
                            <?php foreach ($listings as $l): ?>
                            <tr class="border-b border-gray-700/50 listing-row" data-listing-id="<?php echo (int)$l['id']; ?>" data-price="<?php echo (int)$l['price']; ?>">
                                <td class="py-2 text-white"><?php echo htmlspecialchars($l['item_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="py-2 text-gray-400"><?php echo htmlspecialchars($l['item_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="py-2 text-gray-400"><?php echo htmlspecialchars($l['seller_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="py-2 text-amber-300"><?php echo number_format((int)$l['price']); ?> gold</td>
                                <td class="py-2">
                                    <?php if ((int)$l['seller_user_id'] === $userId): ?>
                                        <span class="text-gray-500 text-sm">Your listing</span>
                                    <?php elseif ($userGold >= (int)$l['price']): ?>
                                        <button type="button" class="buy-listing-btn px-3 py-1 bg-green-900/50 hover:bg-green-800 border border-green-500/50 rounded text-green-300 text-sm" data-listing-id="<?php echo (int)$l['id']; ?>">Buy</button>
                                    <?php else: ?>
                                        <span class="text-gray-500 text-sm">Not enough gold</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
(function() {
    var msgEl = document.getElementById('marketplace-message');
    function showMsg(text, isError) {
        msgEl.textContent = text;
        msgEl.className = 'mb-4 p-3 rounded-lg ' + (isError ? 'bg-red-900/30 border border-red-500/50 text-red-300' : 'bg-green-900/30 border border-green-500/50 text-green-300');
        msgEl.classList.remove('hidden');
    }

    var createForm = document.getElementById('create-listing-form');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('create-listing-btn');
            if (btn) btn.disabled = true;
            var fd = new FormData(createForm);
            fetch('../controllers/create_listing.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.href = 'marketplace.php?msg=' + encodeURIComponent(data.message || 'Listing created.');
                    } else {
                        showMsg(data.message || 'Failed to create listing.', true);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(function() {
                    showMsg('Request failed.', true);
                    if (btn) btn.disabled = false;
                });
        });
    }

    document.querySelectorAll('.buy-listing-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-listing-id');
            if (!id) return;
            this.disabled = true;
            var row = this.closest('.listing-row');
            var fd = new FormData();
            fd.append('listing_id', id);
            fetch('../controllers/buy_listing.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (row) row.remove();
                        showMsg(data.message || 'Purchase complete.');
                    } else {
                        showMsg(data.message || 'Purchase failed.', true);
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    showMsg('Request failed.', true);
                    btn.disabled = false;
                });
        });
    });

    document.querySelectorAll('.cancel-listing-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-listing-id');
            if (!id) return;
            this.disabled = true;
            var row = this.closest('tr');
            var fd = new FormData();
            fd.append('listing_id', id);
            fetch('../controllers/cancel_listing.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (row) row.remove();
                        showMsg(data.message || 'Listing cancelled.');
                    } else {
                        showMsg(data.message || 'Cancel failed.', true);
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    showMsg('Request failed.', true);
                    btn.disabled = false;
                });
        });
    });
})();
    </script>
</body>
</html>




