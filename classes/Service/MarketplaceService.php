<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Phase 2 marketplace: list items (gold only), buy, cancel. No auctions or bidding.
 */
class MarketplaceService
{
    /**
     * Create a listing: seller must own item (inventory_id), not equipped. Removes 1 from inventory.
     */
    public function createListing(int $sellerId, int $inventoryId, int $price): array
    {
        if ($price < 1) {
            return ['success' => false, 'message' => 'Price must be at least 1 gold.'];
        }
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $inv = $db->prepare("SELECT id, user_id, item_template_id, quantity, is_equipped FROM inventory WHERE id = ? AND user_id = ?");
            $inv->execute([$inventoryId, $sellerId]);
            $row = $inv->fetch();
            if (!$row) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Item not found in your inventory.'];
            }
            if ((int)$row['is_equipped'] !== 0) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Cannot list equipped items.'];
            }
            $qty = (int)$row['quantity'];
            if ($qty < 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'No quantity to list.'];
            }
            $itemTemplateId = (int)$row['item_template_id'];

            if ($qty <= 1) {
                $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?")->execute([$inventoryId, $sellerId]);
            } else {
                $db->prepare("UPDATE inventory SET quantity = quantity - 1, updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$inventoryId, $sellerId]);
            }

            $db->prepare("INSERT INTO marketplace_listings (seller_user_id, item_template_id, price, status) VALUES (?, ?, ?, 'active')")
                ->execute([$sellerId, $itemTemplateId, $price]);

            $db->commit();
            return ['success' => true, 'message' => 'Listing created.', 'data' => ['listing_id' => (int)$db->lastInsertId()]];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("MarketplaceService::createListing " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Buy a listing: deduct full price from buyer, seller receives 95%, 5% tax removed. Add item to buyer. Mark sold.
     * Prevents double purchase via FOR UPDATE and status check.
     */
    public function buyListing(int $buyerId, int $listingId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id, seller_user_id, item_template_id, price, status FROM marketplace_listings WHERE id = ? FOR UPDATE");
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Listing not found.'];
            }
            if ((string)$listing['status'] !== 'active') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Listing is no longer available.'];
            }

            $sellerId = (int)$listing['seller_user_id'];
            if ($buyerId === $sellerId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You cannot buy your own listing.'];
            }

            $price = (int)$listing['price'];
            $itemTemplateId = (int)$listing['item_template_id'];

            $buyer = $db->prepare("SELECT gold FROM users WHERE id = ? FOR UPDATE");
            $buyer->execute([$buyerId]);
            $buyerRow = $buyer->fetch();
            if (!$buyerRow || (int)$buyerRow['gold'] < $price) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Not enough gold.'];
            }

            $sellerReceives = (int)floor($price * 0.95);

            $db->prepare("UPDATE users SET gold = GREATEST(0, gold - ?) WHERE id = ?")->execute([$price, $buyerId]);
            $db->prepare("UPDATE users SET gold = GREATEST(0, gold + ?) WHERE id = ?")->execute([$sellerReceives, $sellerId]);

            $itemService = new ItemService();
            $add = $itemService->addItemToInventory($buyerId, $itemTemplateId, 1);
            if (!$add['success']) {
                $db->rollBack();
                return ['success' => false, 'message' => $add['message'] ?? 'Could not add item to inventory.'];
            }

            $db->prepare("UPDATE marketplace_listings SET status = 'sold' WHERE id = ?")->execute([$listingId]);

            $db->commit();
            return [
                'success' => true,
                'message' => 'Purchase complete.',
                'data' => [
                    'gold_spent' => $price,
                    'item_template_id' => $itemTemplateId,
                ]
            ];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("MarketplaceService::buyListing " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Cancel own active listing: return item to seller, mark cancelled.
     */
    public function cancelListing(int $userId, int $listingId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id, seller_user_id, item_template_id, status FROM marketplace_listings WHERE id = ? FOR UPDATE");
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Listing not found.'];
            }
            if ((int)$listing['seller_user_id'] !== $userId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You can only cancel your own listings.'];
            }
            if ((string)$listing['status'] !== 'active') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Listing is no longer active.'];
            }

            $itemTemplateId = (int)$listing['item_template_id'];
            $itemService = new ItemService();
            $add = $itemService->addItemToInventory($userId, $itemTemplateId, 1);
            if (!$add['success']) {
                $db->rollBack();
                return ['success' => false, 'message' => $add['message'] ?? 'Could not return item.'];
            }

            $db->prepare("UPDATE marketplace_listings SET status = 'cancelled' WHERE id = ?")->execute([$listingId]);
            $db->commit();
            return ['success' => true, 'message' => 'Listing cancelled. Item returned to inventory.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("MarketplaceService::cancelListing " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Get active listings with item name and seller username.
     * Uses index idx_status_created (status, created_at) when present.
     */
    public function getActiveListings(): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT m.id, m.seller_user_id, m.item_template_id, m.price, m.created_at,
                       t.name AS item_name, t.type AS item_type,
                       u.username AS seller_name
                FROM marketplace_listings m
                JOIN item_templates t ON t.id = m.item_template_id
                JOIN users u ON u.id = m.seller_user_id
                WHERE m.status = 'active'
                ORDER BY m.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("MarketplaceService::getActiveListings " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get current user's active listings (for marketplace "My listings" or cancel buttons).
     */
    public function getMyActiveListings(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT m.id, m.item_template_id, m.price, m.created_at, t.name AS item_name, t.type AS item_type
                FROM marketplace_listings m
                JOIN item_templates t ON t.id = m.item_template_id
                WHERE m.seller_user_id = ? AND m.status = 'active'
                ORDER BY m.created_at DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log("MarketplaceService::getMyActiveListings " . $e->getMessage());
            return [];
        }
    }
}
