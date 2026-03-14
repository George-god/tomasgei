<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/DaoRecord.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Cultivation manuals: ownership, passive bonuses, sect library storage, and custom crafting.
 */
class CultivationManualService
{
    private const RUNE_FRAGMENT_TEMPLATE_ID = 56;
    private const LIBRARY_BORROW_DAYS = 3;
    private const LIBRARY_CAPACITY_BASE = 8;
    private const LIBRARY_CAPACITY_PER_LEVEL = 4;

    private const RARITY_PRIORITY = [
        'common' => 1,
        'rare' => 2,
        'epic' => 3,
        'legendary' => 4,
        'mythic' => 5,
    ];

    private const BORROW_LIMITS = [
        'outer_disciple' => 1,
        'inner_disciple' => 2,
        'core_disciple' => 3,
        'elder' => 4,
        'leader' => 4,
    ];

    private const BORROW_RARITY_REQUIREMENTS = [
        'common' => 'outer_disciple',
        'rare' => 'outer_disciple',
        'epic' => 'inner_disciple',
        'legendary' => 'core_disciple',
        'mythic' => 'elder',
    ];

    public function getManualPageData(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $daoProfile = $this->getDaoProfile($userId, $db);
            return [
                'dao_profile' => $daoProfile,
                'owned_manuals' => $this->getOwnedManuals($userId, $db),
                'borrowed_manuals' => $this->getBorrowedManuals($userId, $db),
                'active_effects' => $this->getActiveEffectsForUser($userId, $db),
                'recipes' => $this->getCraftingRecipes($db),
            ];
        } catch (PDOException $e) {
            error_log('CultivationManualService::getManualPageData ' . $e->getMessage());
            return [
                'dao_profile' => null,
                'owned_manuals' => [],
                'borrowed_manuals' => [],
                'active_effects' => $this->emptyEffects(),
                'recipes' => [],
            ];
        }
    }

    public function getSectLibraryPageData(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $membership = $this->getSectMembership($userId, $db);
            if ($membership === null) {
                return [
                    'membership' => null,
                    'library' => null,
                    'owned_manuals' => [],
                    'borrowed_count' => 0,
                ];
            }

            return [
                'membership' => $membership,
                'library' => $this->getSectLibraryData((int)$membership['sect_id'], $db),
                'owned_manuals' => $this->getOwnedManuals($userId, $db),
                'borrowed_count' => $this->getActiveBorrowCount($userId, $db),
            ];
        } catch (PDOException $e) {
            error_log('CultivationManualService::getSectLibraryPageData ' . $e->getMessage());
            return [
                'membership' => null,
                'library' => null,
                'owned_manuals' => [],
                'borrowed_count' => 0,
            ];
        }
    }

    public function getActiveEffectsForUser(int $userId, ?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $daoProfile = $this->getDaoProfile($userId, $db);
            $manuals = $this->getApplicableManuals($userId, $db, $daoProfile);
            $effects = $this->emptyEffects();
            $effects['manuals'] = $manuals;

            foreach ($manuals as $manual) {
                $unlockTier = (string)($manual['unlock_tier'] ?? 'none');
                if ($unlockTier !== 'none' && !in_array($unlockTier, $effects['unlocked_tiers'], true)) {
                    $effects['unlocked_tiers'][] = $unlockTier;
                }
                if (!empty($manual['unlock_technique_key'])) {
                    $effects['unlocked_technique_keys'][] = (string)$manual['unlock_technique_key'];
                }
                $effects['technique_upgrade_pct'] += (float)($manual['technique_upgrade_pct'] ?? 0.0);
                $effects['cooldown_reduction_turns'] += (int)($manual['cooldown_reduction_turns'] ?? 0);
                $effects['passive_attack_pct'] += (float)($manual['passive_attack_pct'] ?? 0.0);
                $effects['passive_defense_pct'] += (float)($manual['passive_defense_pct'] ?? 0.0);
                $effects['passive_max_chi_pct'] += (float)($manual['passive_max_chi_pct'] ?? 0.0);
                $effects['passive_dodge_pct'] += (float)($manual['passive_dodge_pct'] ?? 0.0);
            }

            $effects['unlocked_technique_keys'] = array_values(array_unique($effects['unlocked_technique_keys']));
            usort($effects['unlocked_tiers'], static function (string $a, string $b): int {
                $order = ['basic' => 1, 'advanced' => 2, 'ultimate' => 3];
                return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
            });
            $effects['cooldown_reduction_turns'] = min(3, $effects['cooldown_reduction_turns']);
            $effects['technique_upgrade_pct'] = min(0.60, $effects['technique_upgrade_pct']);

            return $effects;
        } catch (PDOException $e) {
            error_log('CultivationManualService::getActiveEffectsForUser ' . $e->getMessage());
            return $this->emptyEffects();
        }
    }

    public function craftCustomManual(int $userId, int $recipeId): array
    {
        if ($recipeId <= 0) {
            return ['success' => false, 'message' => 'Invalid manual recipe.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $recipe = $this->getCraftingRecipeById($recipeId, $db, true);
            if ($recipe === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Manual recipe not found.'];
            }

            $user = $this->getUserCraftProfile($userId, $db, true);
            if ($user === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'User not found.'];
            }

            if ((int)$user['level'] < (int)$recipe['required_level']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Your level is too low to craft this manual.'];
            }

            if (empty($user['dao_element'])) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Choose a Dao Path before crafting a custom manual.'];
            }

            $materials = $this->getMaterialCountByTier($userId, (int)$recipe['required_material_tier'], $db);
            if ($materials < (int)$recipe['required_materials']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Not enough materials of the required tier.'];
            }

            $fragments = $this->getTemplateCount($userId, self::RUNE_FRAGMENT_TEMPLATE_ID, $db);
            if ($fragments < (int)$recipe['required_rune_fragments']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Not enough Rune Fragments.'];
            }

            if ((int)$user['gold'] < (int)$recipe['required_gold'] || (int)$user['spirit_stones'] < (int)$recipe['required_spirit_stones']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Not enough gold or spirit stones.'];
            }

            $this->consumeMaterialsByTier($db, $userId, (int)$recipe['required_material_tier'], (int)$recipe['required_materials']);
            $this->consumeTemplate($db, $userId, self::RUNE_FRAGMENT_TEMPLATE_ID, (int)$recipe['required_rune_fragments']);
            $db->prepare('UPDATE users SET gold = GREATEST(0, gold - ?), spirit_stones = GREATEST(0, spirit_stones - ?) WHERE id = ?')
                ->execute([(int)$recipe['required_gold'], (int)$recipe['required_spirit_stones'], $userId]);

            $manualId = $this->createCustomManualDefinition($db, $userId, $user, $recipe);
            $this->grantManualOwnership($db, $userId, $manualId, 'crafted');
            $db->commit();

            return ['success' => true, 'message' => 'Custom cultivation manual crafted successfully.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('CultivationManualService::craftCustomManual ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not craft the manual right now.'];
        }
    }

    public function storeManualInSectLibrary(int $userId, int $ownedManualId): array
    {
        if ($ownedManualId <= 0) {
            return ['success' => false, 'message' => 'Invalid manual selection.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $membership = $this->getSectMembership($userId, $db, true);
            if ($membership === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Join a sect before storing manuals.'];
            }

            $library = $this->getSectLibraryData((int)$membership['sect_id'], $db);
            if ($library['stored_count'] >= $library['capacity']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'The sect library is full.'];
            }

            $owned = $this->getOwnedManualById($userId, $ownedManualId, $db, true);
            if ($owned === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Manual not found in your collection.'];
            }

            $db->prepare('INSERT INTO sect_library_manuals (sect_id, manual_id, stored_by_user_id) VALUES (?, ?, ?)')
                ->execute([(int)$membership['sect_id'], (int)$owned['manual_id'], $userId]);
            $db->prepare('DELETE FROM user_cultivation_manuals WHERE id = ? AND user_id = ?')
                ->execute([$ownedManualId, $userId]);

            $db->commit();
            return ['success' => true, 'message' => 'Manual stored in the sect library.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('CultivationManualService::storeManualInSectLibrary ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not store the manual.'];
        }
    }

    public function borrowLibraryManual(int $userId, int $libraryManualId): array
    {
        if ($libraryManualId <= 0) {
            return ['success' => false, 'message' => 'Invalid library manual.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $membership = $this->getSectMembership($userId, $db, true);
            if ($membership === null) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Join a sect before borrowing manuals.'];
            }

            $libraryRow = $this->getSectLibraryManualById($libraryManualId, $db, true);
            if ($libraryRow === null || (int)$libraryRow['sect_id'] !== (int)$membership['sect_id']) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Library manual not found.'];
            }
            if (!empty($libraryRow['borrowed_by_user_id'])) {
                $db->rollBack();
                return ['success' => false, 'message' => 'That manual is already on loan.'];
            }

            $rank = (string)($membership['rank'] ?? 'outer_disciple');
            $maxBorrow = self::BORROW_LIMITS[$rank] ?? 1;
            if ($this->getActiveBorrowCount($userId, $db) >= $maxBorrow) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You have reached your sect library borrowing limit.'];
            }

            $requiredRank = self::BORROW_RARITY_REQUIREMENTS[(string)$libraryRow['rarity']] ?? 'outer_disciple';
            if ($this->compareRanks($rank, $requiredRank) < 0) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Your sect rank is too low to borrow this manual.'];
            }

            $dueAt = date('Y-m-d H:i:s', strtotime('+' . self::LIBRARY_BORROW_DAYS . ' days'));
            $db->prepare('UPDATE sect_library_manuals SET borrowed_by_user_id = ?, borrowed_at = NOW(), due_at = ? WHERE id = ?')
                ->execute([$userId, $dueAt, $libraryManualId]);

            $db->commit();
            return ['success' => true, 'message' => 'Manual borrowed from the sect library.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('CultivationManualService::borrowLibraryManual ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not borrow the manual.'];
        }
    }

    public function returnBorrowedLibraryManual(int $userId, int $libraryManualId): array
    {
        if ($libraryManualId <= 0) {
            return ['success' => false, 'message' => 'Invalid library manual.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('UPDATE sect_library_manuals SET borrowed_by_user_id = NULL, borrowed_at = NULL, due_at = NULL WHERE id = ? AND borrowed_by_user_id = ?');
            $stmt->execute([$libraryManualId, $userId]);
            if ($stmt->rowCount() < 1) {
                return ['success' => false, 'message' => 'That manual is not currently borrowed by you.'];
            }
            return ['success' => true, 'message' => 'Manual returned to the sect library.'];
        } catch (PDOException $e) {
            error_log('CultivationManualService::returnBorrowedLibraryManual ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not return the manual.'];
        }
    }

    public function awardDungeonManual(PDO $db, int $userId, int $difficulty): ?array
    {
        $chance = match (max(1, $difficulty)) {
            1 => 0.25,
            2 => 0.40,
            default => 0.55,
        };
        if ((mt_rand(1, 10000) / 10000) > $chance) {
            return null;
        }

        $rarities = $difficulty >= 3 ? ['epic', 'rare'] : ($difficulty === 2 ? ['rare', 'common'] : ['common', 'rare']);
        $manual = $this->pickManualDefinition($userId, 'dungeon', $rarities, $db);
        if ($manual === null) {
            return null;
        }

        $this->grantManualOwnership($db, $userId, (int)$manual['id'], 'dungeon');
        return $manual;
    }

    public function awardWorldBossManual(PDO $db, int $userId, int $rank): ?array
    {
        $chance = $rank <= 3 ? 1.0 : ($rank <= 10 ? 0.55 : 0.20);
        if ((mt_rand(1, 10000) / 10000) > $chance) {
            return null;
        }

        $rarities = $rank === 1 ? ['mythic', 'legendary'] : ($rank <= 3 ? ['legendary', 'epic'] : ['epic', 'rare']);
        $manual = $this->pickManualDefinition($userId, 'world_boss', $rarities, $db);
        if ($manual === null) {
            return null;
        }

        $this->grantManualOwnership($db, $userId, (int)$manual['id'], 'world_boss');
        return $manual;
    }

    public function awardAncientRuinsManual(int $userId, array $region): ?array
    {
        $regionName = strtolower((string)($region['name'] ?? ''));
        if (strpos($regionName, 'ruin') === false && strpos($regionName, 'fallen sect') === false) {
            return null;
        }

        try {
            $db = Database::getConnection();
            if ((mt_rand(1, 10000) / 10000) > 0.60) {
                return null;
            }

            $manual = $this->pickManualDefinition($userId, 'ancient_ruins', ['epic', 'rare', 'common'], $db);
            if ($manual === null) {
                return null;
            }
            $this->grantManualOwnership($db, $userId, (int)$manual['id'], 'ancient_ruins');
            return $manual;
        } catch (PDOException $e) {
            error_log('CultivationManualService::awardAncientRuinsManual ' . $e->getMessage());
            return null;
        }
    }

    private function getApplicableManuals(int $userId, PDO $db, ?array $daoProfile = null): array
    {
        $daoProfile = $daoProfile ?? $this->getDaoProfile($userId, $db);
        $element = $daoProfile['dao_element'] ?? null;
        $alignment = $daoProfile['dao_alignment'] ?? null;

        $ownedStmt = $db->prepare("
            SELECT um.id AS ownership_id, 'owned' AS holder_type, m.*
            FROM user_cultivation_manuals um
            JOIN cultivation_manuals m ON m.id = um.manual_id
            WHERE um.user_id = ? AND um.is_active = 1
            ORDER BY um.acquired_at DESC, um.id DESC
        ");
        $ownedStmt->execute([$userId]);
        $rows = $ownedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $borrowedStmt = $db->prepare("
            SELECT slm.id AS ownership_id, 'borrowed' AS holder_type, m.*
            FROM sect_library_manuals slm
            JOIN cultivation_manuals m ON m.id = slm.manual_id
            WHERE slm.borrowed_by_user_id = ?
            ORDER BY slm.borrowed_at DESC, slm.id DESC
        ");
        $borrowedStmt->execute([$userId]);
        $rows = array_merge($rows, $borrowedStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return array_values(array_filter($rows, function (array $manual) use ($element, $alignment): bool {
            $manualElement = $manual['dao_element'] ?? null;
            $manualAlignment = (string)($manual['dao_alignment'] ?? 'universal');
            $elementMatch = $manualElement === null || $manualElement === '' || $manualElement === $element;
            $alignmentMatch = $manualAlignment === 'universal' || $alignment === null || $manualAlignment === $alignment;
            return $elementMatch && $alignmentMatch;
        }));
    }

    private function getOwnedManuals(int $userId, ?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->prepare("
                SELECT um.id AS owned_manual_id, um.acquired_from, um.acquired_at, m.*
                FROM user_cultivation_manuals um
                JOIN cultivation_manuals m ON m.id = um.manual_id
                WHERE um.user_id = ?
                ORDER BY um.acquired_at DESC, um.id DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('CultivationManualService::getOwnedManuals ' . $e->getMessage());
            return [];
        }
    }

    private function getBorrowedManuals(int $userId, ?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->prepare("
                SELECT slm.id AS library_manual_id, slm.borrowed_at, slm.due_at, s.name AS sect_name, m.*
                FROM sect_library_manuals slm
                JOIN cultivation_manuals m ON m.id = slm.manual_id
                JOIN sects s ON s.id = slm.sect_id
                WHERE slm.borrowed_by_user_id = ?
                ORDER BY slm.borrowed_at DESC, slm.id DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('CultivationManualService::getBorrowedManuals ' . $e->getMessage());
            return [];
        }
    }

    private function getSectLibraryData(int $sectId, ?PDO $db = null): array
    {
        $db = $db ?? Database::getConnection();
        $level = $this->getLibraryLevel($sectId, $db);
        $capacity = self::LIBRARY_CAPACITY_BASE + ($level * self::LIBRARY_CAPACITY_PER_LEVEL);

        $stmt = $db->prepare("
            SELECT slm.id AS library_manual_id, slm.stored_by_user_id, slm.borrowed_by_user_id, slm.stored_at, slm.borrowed_at, slm.due_at,
                   m.*, sb.building_name, u.username AS borrower_name, su.username AS stored_by_username
            FROM sect_library_manuals slm
            JOIN cultivation_manuals m ON m.id = slm.manual_id
            LEFT JOIN users u ON u.id = slm.borrowed_by_user_id
            LEFT JOIN users su ON su.id = slm.stored_by_user_id
            LEFT JOIN sect_bases b ON b.sect_id = slm.sect_id
            LEFT JOIN sect_buildings sb ON sb.base_id = b.id AND sb.building_key = 'library_pavilion'
            WHERE slm.sect_id = ?
            ORDER BY FIELD(m.rarity, 'mythic', 'legendary', 'epic', 'rare', 'common'), slm.id DESC
        ");
        $stmt->execute([$sectId]);
        $manuals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'level' => $level,
            'capacity' => $capacity,
            'stored_count' => count($manuals),
            'manuals' => $manuals,
        ];
    }

    private function getCraftingRecipes(?PDO $db = null): array
    {
        try {
            $db = $db ?? Database::getConnection();
            $stmt = $db->query('SELECT * FROM cultivation_manual_recipes ORDER BY required_level ASC, id ASC');
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('CultivationManualService::getCraftingRecipes ' . $e->getMessage());
            return [];
        }
    }

    private function pickManualDefinition(int $userId, string $sourceType, array $rarities, PDO $db): ?array
    {
        $dao = $this->getDaoProfile($userId, $db);
        $stmt = $db->prepare('SELECT * FROM cultivation_manuals WHERE source_type = ? AND is_custom = 0');
        $stmt->execute([$sourceType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }

        usort($rows, function (array $a, array $b) use ($rarities): int {
            $aIndex = array_search((string)$a['rarity'], $rarities, true);
            $bIndex = array_search((string)$b['rarity'], $rarities, true);
            $aIndex = $aIndex === false ? 99 : $aIndex;
            $bIndex = $bIndex === false ? 99 : $bIndex;
            if ($aIndex !== $bIndex) {
                return $aIndex <=> $bIndex;
            }
            return (self::RARITY_PRIORITY[(string)$b['rarity']] ?? 0) <=> (self::RARITY_PRIORITY[(string)$a['rarity']] ?? 0);
        });

        $filtered = array_values(array_filter($rows, function (array $manual) use ($dao, $rarities): bool {
            if (!in_array((string)$manual['rarity'], $rarities, true)) {
                return false;
            }
            $elementOk = empty($manual['dao_element']) || empty($dao['dao_element']) || (string)$manual['dao_element'] === (string)$dao['dao_element'];
            $alignment = (string)($manual['dao_alignment'] ?? 'universal');
            $alignmentOk = $alignment === 'universal' || empty($dao['dao_alignment']) || $alignment === (string)$dao['dao_alignment'];
            return $elementOk && $alignmentOk;
        }));
        if ($filtered === []) {
            $filtered = array_values(array_filter($rows, static fn(array $manual): bool => in_array((string)$manual['rarity'], $rarities, true)));
        }
        if ($filtered === []) {
            $filtered = $rows;
        }

        return $filtered[array_rand($filtered)] ?? null;
    }

    private function grantManualOwnership(PDO $db, int $userId, int $manualId, string $source): void
    {
        $db->prepare('INSERT INTO user_cultivation_manuals (user_id, manual_id, acquired_from, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$userId, $manualId, $source]);
        $stmt = $db->prepare('SELECT name, rarity FROM cultivation_manuals WHERE id = ? LIMIT 1');
        $stmt->execute([$manualId]);
        $manual = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Unknown Manual', 'rarity' => 'unknown'];
        DaoRecord::log(
            'manual_acquisition',
            $userId,
            $manualId,
            'You obtained the cultivation manual ' . (string)$manual['name'] . '.',
            [
                'source' => $source,
                'manual_name' => (string)$manual['name'],
                'rarity' => (string)$manual['rarity'],
            ],
            $db
        );
    }

    private function createCustomManualDefinition(PDO $db, int $userId, array $user, array $recipe): int
    {
        $manualKey = 'custom_manual_' . $userId . '_' . time() . '_' . random_int(1000, 9999);
        $name = $this->buildCustomManualName((string)$recipe['name'], (string)$user['dao_element']);
        $description = 'A custom-crafted manual shaped by ' . ($user['dao_path_name'] ?? 'your Dao Path') . ' and refined through high-tier materials.';

        $stmt = $db->prepare("
            INSERT INTO cultivation_manuals (
                manual_key, name, rarity, source_type, dao_element, dao_alignment, unlock_tier, unlock_technique_key,
                technique_upgrade_pct, cooldown_reduction_turns, passive_attack_pct, passive_defense_pct, passive_max_chi_pct, passive_dodge_pct,
                description, is_custom, creator_user_id
            ) VALUES (?, ?, ?, 'crafted', ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $manualKey,
            $name,
            $recipe['rarity'],
            $user['dao_element'],
            $user['dao_alignment'] ?? 'universal',
            $recipe['unlock_tier'],
            $recipe['technique_upgrade_pct'],
            $recipe['cooldown_reduction_turns'],
            $recipe['passive_attack_pct'],
            $recipe['passive_defense_pct'],
            $recipe['passive_max_chi_pct'],
            $recipe['passive_dodge_pct'],
            $description,
            $userId,
        ]);

        return (int)$db->lastInsertId();
    }

    private function buildCustomManualName(string $recipeName, string $daoElement): string
    {
        $prefix = match ($daoElement) {
            'flame' => 'Blazing',
            'water' => 'Tidal',
            'wind' => 'Tempest',
            'earth' => 'Stoneborn',
            default => 'Refined',
        };
        return $prefix . ' ' . $recipeName;
    }

    private function getUserCraftProfile(int $userId, PDO $db, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT u.id, u.level, u.gold, u.spirit_stones, d.element AS dao_element, d.alignment AS dao_alignment, d.name AS dao_path_name
            FROM users u
            LEFT JOIN dao_paths d ON d.id = u.dao_path_id
            WHERE u.id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getDaoProfile(int $userId, PDO $db): ?array
    {
        $stmt = $db->prepare("
            SELECT d.path_key AS dao_path_key, d.name AS dao_path_name, d.element AS dao_element, d.alignment AS dao_alignment
            FROM users u
            LEFT JOIN dao_paths d ON d.id = u.dao_path_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getSectMembership(int $userId, PDO $db, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT s.id AS sect_id, s.name AS sect_name, m.rank
            FROM sect_members m
            JOIN sects s ON s.id = m.sect_id
            WHERE m.user_id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getLibraryLevel(int $sectId, PDO $db): int
    {
        $stmt = $db->prepare("
            SELECT COALESCE(sb.level, 1)
            FROM sect_bases b
            LEFT JOIN sect_buildings sb ON sb.base_id = b.id AND sb.building_key = 'library_pavilion'
            WHERE b.sect_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sectId]);
        return max(1, (int)($stmt->fetchColumn() ?: 1));
    }

    private function getActiveBorrowCount(int $userId, PDO $db): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM sect_library_manuals WHERE borrowed_by_user_id = ?');
        $stmt->execute([$userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function getOwnedManualById(int $userId, int $ownedManualId, PDO $db, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT um.*, m.name, m.rarity
            FROM user_cultivation_manuals um
            JOIN cultivation_manuals m ON m.id = um.manual_id
            WHERE um.id = ? AND um.user_id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$ownedManualId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getSectLibraryManualById(int $libraryManualId, PDO $db, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT slm.*, m.name, m.rarity
            FROM sect_library_manuals slm
            JOIN cultivation_manuals m ON m.id = slm.manual_id
            WHERE slm.id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$libraryManualId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getCraftingRecipeById(int $recipeId, PDO $db, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM cultivation_manual_recipes WHERE id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$recipeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getMaterialCountByTier(int $userId, int $tier, PDO $db): int
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(i.quantity), 0)
            FROM inventory i
            JOIN item_templates t ON t.id = i.item_template_id
            WHERE i.user_id = ? AND t.type = 'material' AND t.material_tier = ?
        ");
        $stmt->execute([$userId, $tier]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function getTemplateCount(int $userId, int $itemTemplateId, PDO $db): int
    {
        $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE user_id = ? AND item_template_id = ?');
        $stmt->execute([$userId, $itemTemplateId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function consumeMaterialsByTier(PDO $db, int $userId, int $tier, int $count): void
    {
        $stmt = $db->prepare("
            SELECT i.id, i.quantity
            FROM inventory i
            JOIN item_templates t ON t.id = i.item_template_id
            WHERE i.user_id = ? AND t.type = 'material' AND t.material_tier = ?
            ORDER BY i.id ASC
        ");
        $stmt->execute([$userId, $tier]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $remaining = $count;
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int)$row['quantity']);
            $remaining -= $take;
            if ($take >= (int)$row['quantity']) {
                $db->prepare('DELETE FROM inventory WHERE id = ? AND user_id = ?')->execute([(int)$row['id'], $userId]);
            } else {
                $db->prepare('UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([$take, (int)$row['id'], $userId]);
            }
        }
    }

    private function consumeTemplate(PDO $db, int $userId, int $itemTemplateId, int $count): void
    {
        $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE user_id = ? AND item_template_id = ? ORDER BY id ASC');
        $stmt->execute([$userId, $itemTemplateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $remaining = $count;
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int)$row['quantity']);
            $remaining -= $take;
            if ($take >= (int)$row['quantity']) {
                $db->prepare('DELETE FROM inventory WHERE id = ? AND user_id = ?')->execute([(int)$row['id'], $userId]);
            } else {
                $db->prepare('UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([$take, (int)$row['id'], $userId]);
            }
        }
    }

    private function compareRanks(string $currentRank, string $requiredRank): int
    {
        $order = [
            'outer_disciple' => 1,
            'inner_disciple' => 2,
            'core_disciple' => 3,
            'elder' => 4,
            'leader' => 5,
        ];
        return ($order[$currentRank] ?? 0) <=> ($order[$requiredRank] ?? 0);
    }

    private function emptyEffects(): array
    {
        return [
            'manuals' => [],
            'unlocked_tiers' => [],
            'unlocked_technique_keys' => [],
            'technique_upgrade_pct' => 0.0,
            'cooldown_reduction_turns' => 0,
            'passive_attack_pct' => 0.0,
            'passive_defense_pct' => 0.0,
            'passive_max_chi_pct' => 0.0,
            'passive_dodge_pct' => 0.0,
        ];
    }
}
