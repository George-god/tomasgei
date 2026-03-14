<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use PDOException;

/**
 * Item and equipment service - Phase 1.
 * Uses item_templates and inventory (item_template_id). Flat stat bonuses only.
 */
class ItemService
{
    /**
     * Add item(s) to user inventory by template ID.
     */
    public function addItemToInventory(int $userId, int $itemTemplateId, int $quantity = 1): array
    {
        if ($quantity < 1) {
            return ['success' => false, 'message' => 'Invalid quantity.', 'inventory_id' => null];
        }
        try {
            $db = Database::getConnection();
            $template = $this->getTemplateById($itemTemplateId);
            if (!$template) {
                return ['success' => false, 'message' => 'Item not found.', 'inventory_id' => null];
            }
            $stmt = $db->prepare("SELECT id, quantity FROM inventory WHERE user_id = ? AND item_template_id = ? AND is_equipped = 0");
            $stmt->execute([$userId, $itemTemplateId]);
            $row = $stmt->fetch();
            if ($row) {
                $newQty = (int)$row['quantity'] + $quantity;
                $db->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newQty, (int)$row['id']]);
                return ['success' => true, 'message' => 'Stack updated.', 'inventory_id' => (int)$row['id']];
            }
            $db->prepare("INSERT INTO inventory (user_id, item_template_id, quantity, is_equipped) VALUES (?, ?, ?, 0)")->execute([$userId, $itemTemplateId, $quantity]);
            return ['success' => true, 'message' => 'Item added.', 'inventory_id' => (int)$db->lastInsertId()];
        } catch (PDOException $e) {
            error_log("ItemService::addItemToInventory " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.', 'inventory_id' => null];
        }
    }

    /**
     * Equip an item from inventory into the appropriate slot.
     */
    public function equipItem(int $userId, int $inventoryId): array
    {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $inv = $this->getInventoryRow($userId, $inventoryId);
            if (!$inv) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Inventory entry not found.'];
            }
            $template = $this->getTemplateById((int)$inv['item_template_id']);
            if (!$template || !in_array($template['type'], ['weapon', 'armor', 'accessory'], true)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Item cannot be equipped.'];
            }
            $this->ensureEquipmentSlotsRow($db, $userId);
            $slotColumn = $this->getSlotColumnForType($template['type']);
            $stmt = $db->prepare("SELECT {$slotColumn} FROM equipment_slots WHERE user_id = ?");
            $stmt->execute([$userId]);
            $current = $stmt->fetch();
            $oldInvId = $current[$slotColumn] ?? null;
            if ($oldInvId && (int)$oldInvId !== $inventoryId) {
                $db->prepare("UPDATE inventory SET is_equipped = 0 WHERE id = ?")->execute([$oldInvId]);
            }
            $db->prepare("UPDATE equipment_slots SET {$slotColumn} = ? WHERE user_id = ?")->execute([$inventoryId, $userId]);
            $db->prepare("UPDATE inventory SET is_equipped = 1 WHERE id = ?")->execute([$inventoryId]);
            $db->commit();
            return ['success' => true, 'message' => 'Item equipped.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("ItemService::equipItem " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Unequip item from slot (weapon, armor, accessory_1, accessory_2).
     */
    public function unequipItem(int $userId, string $slot): array
    {
        $column = ['weapon' => 'weapon_id', 'armor' => 'armor_id', 'accessory_1' => 'accessory_1_id', 'accessory_2' => 'accessory_2_id'][$slot] ?? null;
        if (!$column) {
            return ['success' => false, 'message' => 'Invalid slot.'];
        }
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT {$column} FROM equipment_slots WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $invId = $row[$column] ?? null;
            if (!$invId) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Slot is empty.'];
            }
            $db->prepare("UPDATE equipment_slots SET {$column} = NULL WHERE user_id = ?")->execute([$userId]);
            $db->prepare("UPDATE inventory SET is_equipped = 0 WHERE id = ?")->execute([$invId]);
            $db->commit();
            return ['success' => true, 'message' => 'Item unequipped.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log("ItemService::unequipItem " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error.'];
        }
    }

    /**
     * Get equipped item stat bonuses (flat) for StatCalculator.
     */
    public function getEquippedItemBonuses(int $userId): array
    {
        $equip = $this->getUserEquipment($userId);
        $attack = $defense = $hp = 0;
        foreach (['weapon', 'armor', 'accessory_1', 'accessory_2'] as $key) {
            $data = $equip[$key] ?? null;
            if (!$data || empty($data['template'])) continue;
            $t = $data['template'];
            $attack += (int)($t['attack_bonus'] ?? 0);
            $defense += (int)($t['defense_bonus'] ?? 0);
            $hp += (int)($t['hp_bonus'] ?? 0);
        }
        return ['attack' => $attack, 'defense' => $defense, 'hp' => $hp, 'crit_bonus' => 0.0, 'lifesteal_bonus' => 0.0];
    }

    /**
     * Get user's current equipment (inventory rows + template data).
     */
    public function getUserEquipment(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT weapon_id, armor_id, accessory_1_id, accessory_2_id FROM equipment_slots WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['weapon_id' => null, 'weapon' => null, 'armor_id' => null, 'armor' => null, 'accessory_1_id' => null, 'accessory_1' => null, 'accessory_2_id' => null, 'accessory_2' => null];
            }
            $out = [
                'weapon_id' => $row['weapon_id'] ? (int)$row['weapon_id'] : null,
                'armor_id' => $row['armor_id'] ? (int)$row['armor_id'] : null,
                'accessory_1_id' => $row['accessory_1_id'] ? (int)$row['accessory_1_id'] : null,
                'accessory_2_id' => $row['accessory_2_id'] ? (int)$row['accessory_2_id'] : null,
                'weapon' => null, 'armor' => null, 'accessory_1' => null, 'accessory_2' => null
            ];
            foreach (['weapon_id' => 'weapon', 'armor_id' => 'armor', 'accessory_1_id' => 'accessory_1', 'accessory_2_id' => 'accessory_2'] as $col => $key) {
                $invId = $out[$col];
                if (!$invId) continue;
                $inv = $this->getInventoryRowById($invId);
                if ($inv && (int)$inv['user_id'] === $userId) {
                    $template = $this->getTemplateById((int)$inv['item_template_id']);
                    $out[$key] = array_merge($inv, ['template' => $template]);
                }
            }
            return $out;
        } catch (PDOException $e) {
            error_log("ItemService::getUserEquipment " . $e->getMessage());
            return ['weapon_id' => null, 'weapon' => null, 'armor_id' => null, 'armor' => null, 'accessory_1_id' => null, 'accessory_1' => null, 'accessory_2_id' => null, 'accessory_2' => null];
        }
    }

    /**
     * Get user inventory with template data.
     */
    public function getUserInventory(int $userId, bool $includeEquipped = true): array
    {
        try {
            $db = Database::getConnection();
            $sql = "SELECT * FROM inventory WHERE user_id = ?";
            if (!$includeEquipped) $sql .= " AND is_equipped = 0";
            $sql .= " ORDER BY is_equipped DESC, item_template_id ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();
            $list = [];
            foreach ($rows as $r) {
                $list[] = array_merge($r, ['template' => $this->getTemplateById((int)$r['item_template_id'])]);
            }
            return $list;
        } catch (PDOException $e) {
            error_log("ItemService::getUserInventory " . $e->getMessage());
            return [];
        }
    }

    public function getTemplateById(int $id): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM item_templates WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Get inventory items that are breakthrough pills (template.breakthrough_bonus > 0). Not equipped.
     *
     * @return array List of [id, quantity, template => [name, breakthrough_bonus, ...]]
     */
    public function getBreakthroughPills(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT i.id, i.quantity, i.item_template_id
                FROM inventory i
                INNER JOIN item_templates t ON i.item_template_id = t.id
                WHERE i.user_id = ? AND i.is_equipped = 0 AND COALESCE(t.breakthrough_bonus, 0) > 0
                ORDER BY t.breakthrough_bonus DESC, i.id ASC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();
            $list = [];
            foreach ($rows as $r) {
                $template = $this->getTemplateById((int)$r['item_template_id']);
                if (!$template || (int)($template['breakthrough_bonus'] ?? 0) <= 0) {
                    continue;
                }
                $list[] = array_merge($r, ['template' => $template]);
            }
            return $list;
        } catch (PDOException $e) {
            error_log("ItemService::getBreakthroughPills " . $e->getMessage());
            return [];
        }
    }

    /**
     * Consume one unit of an inventory stack. Caller must validate (e.g. breakthrough pill use).
     *
     * @return bool True if one was consumed (quantity decremented or row removed)
     */
    public function consumeOne(int $userId, int $inventoryId): bool
    {
        $row = $this->getInventoryRow($userId, $inventoryId);
        if (!$row || (int)$row['quantity'] < 1) {
            return false;
        }
        try {
            $db = Database::getConnection();
            $qty = (int)$row['quantity'];
            if ($qty <= 1) {
                $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?")->execute([$inventoryId, $userId]);
            } else {
                $db->prepare("UPDATE inventory SET quantity = quantity - 1, updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$inventoryId, $userId]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("ItemService::consumeOne " . $e->getMessage());
            return false;
        }
    }

    public function getInventoryRow(int $userId, int $inventoryId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ? AND user_id = ?");
            $stmt->execute([$inventoryId, $userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    private function getInventoryRowById(int $inventoryId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ?");
            $stmt->execute([$inventoryId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** One weapon, one armor, one accessory (Phase 1). */
    private function getSlotColumnForType(string $type): string
    {
        if ($type === 'weapon') return 'weapon_id';
        if ($type === 'armor') return 'armor_id';
        return 'accessory_1_id';
    }

    private function getEquipmentSlots(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT weapon_id, armor_id, accessory_1_id, accessory_2_id FROM equipment_slots WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    private function ensureEquipmentSlotsRow(\PDO $db, int $userId): void
    {
        $db->prepare("INSERT IGNORE INTO equipment_slots (user_id) VALUES (?)")->execute([$userId]);
    }
}
