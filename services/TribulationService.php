<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/StatCalculator.php';
require_once __DIR__ . '/TitleService.php';
require_once __DIR__ . '/DaoRecord.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Multi-phase cultivation tribulations triggered during major realm breakthroughs.
 */
class TribulationService
{
    private const PHASE_COUNT = 3;
    private const FAILURE_CHI_PERCENT = 0.12;
    private const TRIBULATION_TYPES = [
        'lightning' => [
            'label' => 'Lightning Tribulation',
            'difficulty' => 1.00,
            'phases' => ['Gathering Thunderclouds', 'Thunder Pulse Cascade', 'Heaven-Splitting Bolt'],
        ],
        'fire' => [
            'label' => 'Fire Tribulation',
            'difficulty' => 1.03,
            'phases' => ['Scorching Embers', 'Blazing Core Flames', 'Phoenix Ash Inferno'],
        ],
        'demonic_heart' => [
            'label' => 'Demonic Heart Tribulation',
            'difficulty' => 0.98,
            'phases' => ['Whispering Doubts', 'Heartfire Backlash', 'Inner Demon Manifestation'],
        ],
        'void' => [
            'label' => 'Void Tribulation',
            'difficulty' => 1.08,
            'phases' => ['Fractured Space', 'Void Pressure Collapse', 'Abyssal Silence'],
        ],
        'heavenly_judgment' => [
            'label' => 'Heavenly Judgment',
            'difficulty' => 1.12,
            'phases' => ['Heavenly Gaze', 'Law-Binding Chains', 'Judgment Descent'],
        ],
    ];
    private const PHASE_MULTIPLIERS = [0.82, 1.00, 1.22];

    /**
     * @param array<string, mixed> $preparation
     * @return array<string, mixed>
     */
    public function processTribulation(
        int $userId,
        int $realmIdBefore,
        int $realmIdAfter,
        array $preparation = [],
        ?PDO $db = null
    ): array {
        $ownsTransaction = false;

        try {
            $db = $db ?? Database::getConnection();
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownsTransaction = true;
            }

            $user = $this->fetchUser($db, $userId);
            if (!$user) {
                if ($ownsTransaction) {
                    $db->rollBack();
                }
                return ['success' => false, 'error' => 'User not found.'];
            }

            $tribulationType = $this->rollTribulationType($user);
            $typeData = self::TRIBULATION_TYPES[$tribulationType];
            $stats = (new StatCalculator())->calculateFinalStats($userId);
            $finalStats = $stats['final'] ?? [];

            $currentChi = max(0, (int)($user['chi'] ?? 0));
            $maxChi = max(1, (int)($finalStats['max_chi'] ?? $user['max_chi'] ?? 1));
            $defense = max(0, (int)($finalStats['defense'] ?? $user['defense'] ?? 0));
            $attemptsUsed = max(0, (int)($preparation['breakthrough_attempts'] ?? $user['breakthrough_attempts'] ?? 0));
            $pillBonus = max(0.0, (float)($preparation['pill_bonus'] ?? 0.0));
            $sectBonus = max(0.0, (float)($preparation['sect_breakthrough_bonus'] ?? 0.0));
            $runeType = $preparation['rune_type'] ?? $user['active_scroll_type'] ?? null;
            $runeType = $runeType !== null && $runeType !== '' ? (string)$runeType : null;
            $difficultyRating = $this->calculateDifficultyRating($realmIdAfter, $attemptsUsed, $tribulationType, $user);

            $phases = [];
            $chiAfter = min($currentChi, $maxChi);
            $totalDamage = 0;
            $failedPhase = null;

            foreach ($typeData['phases'] as $index => $phaseName) {
                $phaseNumber = $index + 1;
                $phaseResult = $this->processPhase(
                    $phaseNumber,
                    (string)$phaseName,
                    $tribulationType,
                    $chiAfter,
                    $maxChi,
                    $defense,
                    $difficultyRating,
                    $pillBonus,
                    $sectBonus,
                    $runeType,
                    $user
                );
                $phases[] = $phaseResult;
                $chiAfter = (int)$phaseResult['chi_after'];
                $totalDamage += (int)$phaseResult['damage_after_defense'];

                if ($chiAfter <= 0) {
                    $failedPhase = $phaseNumber;
                    break;
                }
            }

            $success = $failedPhase === null;
            $endChi = $success ? $chiAfter : max(1, (int)floor($maxChi * self::FAILURE_CHI_PERCENT));
            $tribulationId = $this->insertTribulation(
                $db,
                $userId,
                $realmIdBefore,
                $success ? $realmIdAfter : null,
                $tribulationType,
                $success,
                $failedPhase,
                $difficultyRating,
                $attemptsUsed,
                $pillBonus,
                $sectBonus,
                $runeType,
                $currentChi,
                $endChi,
                $totalDamage
            );
            $this->insertPhaseLogs($db, $tribulationId, $phases);
            $this->applyOutcome($db, $userId, $realmIdAfter, $success, $endChi, $attemptsUsed);
            $this->notifyOutcome($userId, $tribulationId, $success, $realmIdAfter, $typeData['label']);
            DaoRecord::log(
                'tribulation',
                $userId,
                $tribulationId,
                $success
                    ? 'You survived a ' . $typeData['label'] . ' and advanced your cultivation.'
                    : 'You failed a ' . $typeData['label'] . ' and your breakthrough collapsed.',
                [
                    'tribulation_type' => $tribulationType,
                    'realm_id_before' => $realmIdBefore,
                    'realm_id_after' => $success ? $realmIdAfter : null,
                    'failed_phase' => $failedPhase,
                    'difficulty_rating' => $difficultyRating,
                    'damage_taken' => $totalDamage,
                ],
                $db
            );

            if ($ownsTransaction) {
                $db->commit();
            }

            return [
                'success' => $success,
                'tribulation_id' => $tribulationId,
                'tribulation_type' => $tribulationType,
                'tribulation_label' => $typeData['label'],
                'phase_count' => self::PHASE_COUNT,
                'failed_phase' => $failedPhase,
                'difficulty_rating' => $difficultyRating,
                'chi_before' => $currentChi,
                'chi_after' => $endChi,
                'damage_taken' => $totalDamage,
                'phases' => $phases,
                'realm_ascended' => $success,
                'new_realm_id' => $success ? $realmIdAfter : null,
                'message' => $success
                    ? 'You survived the tribulation and ascended successfully.'
                    : 'The tribulation overwhelmed you and the breakthrough collapsed.',
            ];
        } catch (\Throwable $e) {
            if (isset($db) && $ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('TribulationService::processTribulation ' . $e->getMessage());
            return ['success' => false, 'error' => 'Tribulation processing failed. Please try again.'];
        }
    }

    public function getTribulationById(int $tribulationId, int $userId): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.*, r1.name AS realm_before_name, r2.name AS realm_after_name
                FROM tribulations t
                LEFT JOIN realms r1 ON r1.id = t.realm_id_before
                LEFT JOIN realms r2 ON r2.id = t.realm_id_after
                WHERE t.id = ? AND t.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$tribulationId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $row['tribulation_label'] = $this->getTribulationLabel((string)$row['tribulation_type']);
            $row['phases'] = $this->getTribulationLogs($tribulationId);
            return $row;
        } catch (PDOException $e) {
            error_log('TribulationService::getTribulationById ' . $e->getMessage());
            return null;
        }
    }

    public function getTribulationHistory(int $userId, int $limit = 10): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT t.id, t.tribulation_type, t.success, t.failed_phase, t.difficulty_rating, t.start_chi, t.end_chi, t.created_at,
                       r1.name AS realm_before_name, r2.name AS realm_after_name
                FROM tribulations t
                LEFT JOIN realms r1 ON r1.id = t.realm_id_before
                LEFT JOIN realms r2 ON r2.id = t.realm_id_after
                WHERE t.user_id = ?
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['tribulation_label'] = $this->getTribulationLabel((string)$row['tribulation_type']);
            }
            unset($row);
            return $rows;
        } catch (PDOException $e) {
            error_log('TribulationService::getTribulationHistory ' . $e->getMessage());
            return [];
        }
    }

    public function getTribulationLogs(int $tribulationId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT strike_number, phase_name, chi_before, damage_dealt, damage_after_defense, was_dodged,
                       chi_after, survival_percent, phase_result, message
                FROM tribulation_logs
                WHERE tribulation_id = ?
                ORDER BY strike_number ASC
            ");
            $stmt->execute([$tribulationId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('TribulationService::getTribulationLogs ' . $e->getMessage());
            return [];
        }
    }

    private function processPhase(
        int $phaseNumber,
        string $phaseName,
        string $tribulationType,
        int $currentChi,
        int $maxChi,
        int $defense,
        float $difficultyRating,
        float $pillBonus,
        float $sectBonus,
        ?string $runeType,
        array $user
    ): array {
        $basePercent = 0.17 + (($phaseNumber - 1) * 0.06);
        $phaseMultiplier = self::PHASE_MULTIPLIERS[$phaseNumber - 1] ?? 1.0;
        $rawDamage = (int)max(1, round($maxChi * $basePercent * $difficultyRating * $phaseMultiplier));

        $defenseReduction = min(0.28, $defense / 1800.0);
        $pillReduction = min(0.08, $pillBonus * 0.35);
        $sectReduction = min(0.08, $sectBonus * 2.0);
        $runeReduction = $this->getRuneMitigation($runeType, $tribulationType, $phaseNumber);
        $daoAffinityReduction = $this->getDaoTribulationMitigation($user, $tribulationType);
        $totalReduction = min(0.60, $defenseReduction + $pillReduction + $sectReduction + $runeReduction + $daoAffinityReduction);

        $finalDamage = (int)max(1, round($rawDamage * (1 - $totalReduction)));
        $chiAfter = max(0, $currentChi - $finalDamage);
        $survivalPercent = $maxChi > 0 ? round(($chiAfter / $maxChi) * 100, 2) : 0.0;
        $phaseResult = $chiAfter > 0 ? 'survived' : 'failed';

        return [
            'strike_number' => $phaseNumber,
            'phase_name' => $phaseName,
            'chi_before' => $currentChi,
            'damage_dealt' => $rawDamage,
            'damage_after_defense' => $finalDamage,
            'was_dodged' => 0,
            'chi_after' => $chiAfter,
            'survival_percent' => $survivalPercent,
            'phase_result' => $phaseResult,
            'message' => $this->buildPhaseMessage($tribulationType, $phaseNumber, $finalDamage, $phaseResult),
        ];
    }

    private function calculateDifficultyRating(int $realmIdAfter, int $attemptsUsed, string $tribulationType, array $user): float
    {
        $typeBase = (float)(self::TRIBULATION_TYPES[$tribulationType]['difficulty'] ?? 1.0);
        $realmFactor = max(0.0, ($realmIdAfter - 1) * 0.08);
        $attemptFactor = min(0.36, $attemptsUsed * 0.05);
        $daoModifier = 0.0;
        if (($user['dao_favored_tribulation'] ?? null) === $tribulationType) {
            $daoModifier -= 0.08;
        }
        if (($user['dao_alignment'] ?? null) === 'demonic' && $tribulationType === 'heavenly_judgment') {
            $daoModifier += 0.08;
        }
        if (($user['dao_alignment'] ?? null) === 'demonic' && $tribulationType === 'demonic_heart') {
            $daoModifier -= 0.04;
        }
        return round($typeBase + $realmFactor + $attemptFactor + $daoModifier, 3);
    }

    private function getRuneMitigation(?string $runeType, string $tribulationType, int $phaseNumber): float
    {
        if ($runeType === null || $runeType === '') {
            return 0.0;
        }

        $bonus = 0.0;
        if ($runeType === 'minor_defense') {
            $bonus += 0.06;
        } elseif ($runeType === 'vitality') {
            $bonus += 0.05;
        } elseif ($runeType === 'focus') {
            $bonus += 0.04;
        } elseif ($runeType === 'minor_attack') {
            $bonus += 0.02;
        }

        if ($runeType === 'focus' && $tribulationType === 'demonic_heart') {
            $bonus += 0.06;
        }
        if ($runeType === 'vitality' && $phaseNumber === 3) {
            $bonus += 0.02;
        }

        return min(0.14, $bonus);
    }

    private function buildPhaseMessage(string $tribulationType, int $phaseNumber, int $damageTaken, string $phaseResult): string
    {
        $label = $this->getTribulationLabel($tribulationType);
        if ($phaseResult === 'failed') {
            return "Phase {$phaseNumber} of {$label} broke through your defenses for {$damageTaken} damage.";
        }
        return "You endured phase {$phaseNumber} of {$label} and absorbed {$damageTaken} damage.";
    }

    private function insertTribulation(
        PDO $db,
        int $userId,
        int $realmIdBefore,
        ?int $realmIdAfter,
        string $tribulationType,
        bool $success,
        ?int $failedPhase,
        float $difficultyRating,
        int $attemptsUsed,
        float $pillBonus,
        float $sectBonus,
        ?string $runeType,
        int $startChi,
        int $endChi,
        int $damageTaken
    ): int {
        $stmt = $db->prepare("
            INSERT INTO tribulations (
                user_id, realm_id_before, realm_id_after, tribulation_type, phase_count, failed_phase, success,
                difficulty_rating, breakthrough_attempts_used, pill_bonus_applied, sect_bonus_applied, rune_type,
                start_chi, end_chi, damage_taken, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $realmIdBefore,
            $realmIdAfter,
            $tribulationType,
            self::PHASE_COUNT,
            $failedPhase,
            $success ? 1 : 0,
            $difficultyRating,
            $attemptsUsed,
            $pillBonus,
            $sectBonus,
            $runeType,
            $startChi,
            $endChi,
            $damageTaken,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * @param array<int, array<string, int|float|string>> $phases
     */
    private function insertPhaseLogs(PDO $db, int $tribulationId, array $phases): void
    {
        $stmt = $db->prepare("
            INSERT INTO tribulation_logs (
                tribulation_id, strike_number, phase_name, chi_before, damage_dealt, damage_after_defense,
                was_dodged, chi_after, survival_percent, phase_result, message, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($phases as $phase) {
            $stmt->execute([
                $tribulationId,
                (int)$phase['strike_number'],
                (string)$phase['phase_name'],
                (int)$phase['chi_before'],
                (int)$phase['damage_dealt'],
                (int)$phase['damage_after_defense'],
                (int)$phase['was_dodged'],
                (int)$phase['chi_after'],
                (float)$phase['survival_percent'],
                (string)$phase['phase_result'],
                (string)$phase['message'],
            ]);
        }
    }

    private function applyOutcome(PDO $db, int $userId, int $realmIdAfter, bool $success, int $endChi, int $attemptsUsed): void
    {
        if ($success) {
            $db->prepare("
                UPDATE users
                SET realm_id = ?, chi = ?, breakthrough_attempts = 0, active_scroll_type = NULL
                WHERE id = ?
            ")->execute([$realmIdAfter, $endChi, $userId]);
            return;
        }

        $db->prepare("
            UPDATE users
            SET chi = ?, breakthrough_attempts = ?, active_scroll_type = NULL
            WHERE id = ?
        ")->execute([$endChi, $attemptsUsed + 1, $userId]);
    }

    private function notifyOutcome(int $userId, int $tribulationId, bool $success, int $realmIdAfter, string $tribulationLabel): void
    {
        $notificationService = new NotificationService();
        if ($success) {
            $db = Database::getConnection();
            $realmName = $this->getRealmName($db, $realmIdAfter);
            $notificationService->createNotification(
                $userId,
                'tribulation_success',
                'Tribulation Survived',
                "You overcame {$tribulationLabel} and ascended to {$realmName}.",
                $tribulationId,
                'tribulation'
            );
            return;
        }

        $notificationService->createNotification(
            $userId,
            'tribulation_failure',
            'Tribulation Failed',
            "You were repelled by {$tribulationLabel}. Recover your chi and prepare again.",
            $tribulationId,
            'tribulation'
        );
    }

    private function fetchUser(PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare("
            SELECT u.id, u.realm_id, u.chi, u.max_chi, u.defense, u.breakthrough_attempts, u.active_scroll_type,
                   d.path_key AS dao_path_key, d.alignment AS dao_alignment, d.favored_tribulation
            FROM users u
            LEFT JOIN dao_paths d ON d.id = u.dao_path_id
            WHERE u.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getRealmName(PDO $db, int $realmId): string
    {
        $stmt = $db->prepare('SELECT name FROM realms WHERE id = ? LIMIT 1');
        $stmt->execute([$realmId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string)$name : 'Unknown Realm';
    }

    private function rollTribulationType(array $user): string
    {
        $weights = [
            'lightning' => 20,
            'fire' => 20,
            'demonic_heart' => 20,
            'void' => 20,
            'heavenly_judgment' => 20,
        ];

        $favored = (string)($user['dao_favored_tribulation'] ?? '');
        if ($favored !== '' && isset($weights[$favored])) {
            $weights[$favored] += 25;
        }
        if (($user['dao_alignment'] ?? null) === 'demonic') {
            $weights['demonic_heart'] += 15;
            $weights['heavenly_judgment'] += 8;
        }

        $roll = mt_rand(1, array_sum($weights));
        $running = 0;
        foreach ($weights as $key => $weight) {
            $running += $weight;
            if ($roll <= $running) {
                return $key;
            }
        }

        return 'lightning';
    }

    private function getDaoTribulationMitigation(array $user, string $tribulationType): float
    {
        $mitigation = 0.0;
        if (($user['dao_favored_tribulation'] ?? null) === $tribulationType) {
            $mitigation += 0.06;
        }
        if (($user['dao_alignment'] ?? null) === 'orthodox' && $tribulationType === 'heavenly_judgment') {
            $mitigation += 0.02;
        }
        if (($user['dao_alignment'] ?? null) === 'demonic' && $tribulationType === 'demonic_heart') {
            $mitigation += 0.03;
        }
        return min(0.10, $mitigation);
    }

    private function getTribulationLabel(string $tribulationType): string
    {
        return (string)(self::TRIBULATION_TYPES[$tribulationType]['label'] ?? 'Unknown Tribulation');
    }
}
