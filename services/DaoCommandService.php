<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/WorldBossService.php';
require_once __DIR__ . '/EventService.php';
require_once __DIR__ . '/ItemService.php';
require_once __DIR__ . '/AdminUserService.php';
require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Heavenly Dao Command System.
 * Executes admin commands with validation, permission checks, and full logging.
 * Admin levels: observer (view only), executor (spawn/event/grant/adjust/warn), overseer (+ ban, global_decree).
 */
class DaoCommandService
{
    public const LEVEL_OBSERVER = 'observer';
    public const LEVEL_EXECUTOR = 'executor';
    public const LEVEL_OVERSEER = 'overseer';

    public const COMMAND_SPAWN_BOSS = 'spawn_boss';
    public const COMMAND_TRIGGER_EVENT = 'trigger_event';
    public const COMMAND_GRANT_ITEM = 'grant_item';
    public const COMMAND_ADJUST_PLAYER = 'adjust_player';
    public const COMMAND_BAN_PLAYER = 'ban_player';
    public const COMMAND_WARN_PLAYER = 'warn_player';
    public const COMMAND_GLOBAL_DECREE = 'global_decree';

    /** Commands that require at least executor level */
    private const EXECUTOR_COMMANDS = [
        self::COMMAND_SPAWN_BOSS,
        self::COMMAND_TRIGGER_EVENT,
        self::COMMAND_GRANT_ITEM,
        self::COMMAND_ADJUST_PLAYER,
        self::COMMAND_WARN_PLAYER,
    ];

    /** Commands that require overseer level */
    /** @var list<string> */
    private const OVERSEER_COMMANDS = [
        self::COMMAND_BAN_PLAYER,
        self::COMMAND_GLOBAL_DECREE,
    ];

    /**
     * Return minimum admin level required for a command. observer = view only (no execute).
     */
    public function getRequiredLevel(string $command): ?string
    {
        if (in_array($command, self::OVERSEER_COMMANDS, true)) {
            return self::LEVEL_OVERSEER;
        }
        if (in_array($command, self::EXECUTOR_COMMANDS, true)) {
            return self::LEVEL_EXECUTOR;
        }
        return null;
    }

    /**
     * Check if admin level is at least as high as required.
     * Hierarchy: observer < executor < overseer.
     */
    public function canExecuteCommand(string $adminLevel, string $command): bool
    {
        $required = $this->getRequiredLevel($command);
        if ($required === null) {
            return false;
        }
        return $this->levelRank($adminLevel) >= $this->levelRank($required);
    }

    private function levelRank(string $level): int
    {
        $rank = ['observer' => 1, 'executor' => 2, 'overseer' => 3];
        return $rank[$level] ?? 0;
    }

    /**
     * Execute a command and log result. Validates permission and inputs.
     *
     * @param int    $adminUserId Admin user executing the command
     * @param string $adminLevel  Admin level (observer/executor/overseer)
     * @param string $command     Command name
     * @param array  $params      Command parameters
     * @return array { success: bool, message: string, ... }
     */
    public function execute(int $adminUserId, string $adminLevel, string $command, array $params = []): array
    {
        if (!$this->canExecuteCommand($adminLevel, $command)) {
            $logId = $this->logCommand($adminUserId, $command, null, $params, false, 'Insufficient permission.');
            return ['success' => false, 'message' => 'The Heavenly Dao denies this authority.', 'log_id' => $logId];
        }

        $params = $this->sanitizeParams($command, $params);
        $validation = $this->validateCommand($command, $params);
        if ($validation !== null) {
            $logId = $this->logCommand($adminUserId, $command, $validation['target_id'] ?? null, $params, false, $validation['message']);
            return ['success' => false, 'message' => $validation['message'], 'log_id' => $logId];
        }

        $result = $this->runCommand($command, $params, $adminUserId);
        $targetId = $this->resolveTargetId($command, $params, $result);
        $this->logCommand($adminUserId, $command, $targetId, $params, $result['success'], $result['message'] ?? '');

        return $result;
    }

    private function sanitizeParams(string $command, array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($v)) {
                $out[$k] = trim($v);
            } elseif (is_int($v) || is_float($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function validateCommand(string $command, array $params): ?array
    {
        switch ($command) {
            case self::COMMAND_SPAWN_BOSS:
                $name = $params['boss_name'] ?? $params['name'] ?? '';
                if (strlen($name) < 1 || strlen($name) > 100) {
                    return ['message' => 'Boss name must be 1–100 characters.', 'target_id' => null];
                }
                return null;

            case self::COMMAND_TRIGGER_EVENT:
                $eventName = $params['event_name'] ?? '';
                $start = $params['start_time'] ?? '';
                $end = $params['end_time'] ?? '';
                if (strlen($eventName) < 1 || strlen($eventName) > 100) {
                    return ['message' => 'Event name must be 1–100 characters.', 'target_id' => null];
                }
                if ($start !== '' && strtotime($start) === false) {
                    return ['message' => 'Invalid start_time.', 'target_id' => null];
                }
                if ($end !== '' && strtotime($end) === false) {
                    return ['message' => 'Invalid end_time.', 'target_id' => null];
                }
                return null;

            case self::COMMAND_GRANT_ITEM:
                $userId = isset($params['user_id']) ? (int)$params['user_id'] : 0;
                $itemTemplateId = isset($params['item_template_id']) ? (int)$params['item_template_id'] : 0;
                $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 1;
                if ($userId < 1) {
                    return ['message' => 'Valid user_id required.', 'target_id' => null];
                }
                if ($itemTemplateId < 1) {
                    return ['message' => 'Valid item_template_id required.', 'target_id' => $userId];
                }
                if ($quantity < 1 || $quantity > 9999) {
                    return ['message' => 'Quantity must be 1–9999.', 'target_id' => $userId];
                }
                return null;

            case self::COMMAND_ADJUST_PLAYER:
                $userId = isset($params['user_id']) ? (int)$params['user_id'] : 0;
                if ($userId < 1) {
                    return ['message' => 'Valid user_id required.', 'target_id' => null];
                }
                $allowed = ['level', 'chi', 'max_chi', 'attack', 'defense', 'gold', 'spirit_stones'];
                foreach (array_keys($params) as $key) {
                    if ($key !== 'user_id' && !in_array($key, $allowed, true)) {
                        return ['message' => 'Invalid adjust field: ' . $key, 'target_id' => $userId];
                    }
                }
                return null;

            case self::COMMAND_BAN_PLAYER:
                $userId = isset($params['user_id']) ? (int)$params['user_id'] : 0;
                $reason = $params['reason'] ?? '';
                if ($userId < 1) {
                    return ['message' => 'Valid user_id required.', 'target_id' => null];
                }
                if (strlen($reason) < 5) {
                    return ['message' => 'Ban reason must be at least 5 characters.', 'target_id' => $userId];
                }
                return null;

            case self::COMMAND_WARN_PLAYER:
                $userId = isset($params['user_id']) ? (int)$params['user_id'] : 0;
                $message = $params['message'] ?? '';
                if ($userId < 1) {
                    return ['message' => 'Valid user_id required.', 'target_id' => null];
                }
                if (strlen($message) < 3) {
                    return ['message' => 'Warning message must be at least 3 characters.', 'target_id' => $userId];
                }
                return null;

            case self::COMMAND_GLOBAL_DECREE:
                $decree = $params['message'] ?? $params['decree'] ?? '';
                $hours = isset($params['hours']) ? (int)$params['hours'] : 24;
                if (strlen($decree) < 1 || strlen($decree) > 2000) {
                    return ['message' => 'Decree message must be 1–2000 characters.', 'target_id' => null];
                }
                if ($hours < 1 || $hours > 720) {
                    return ['message' => 'Hours must be 1–720.', 'target_id' => null];
                }
                return null;

            default:
                return ['message' => 'Unknown command.', 'target_id' => null];
        }
    }

    private function runCommand(string $command, array $params, int $adminUserId): array
    {
        try {
            switch ($command) {
                case self::COMMAND_SPAWN_BOSS:
                    return $this->runSpawnBoss($params);
                case self::COMMAND_TRIGGER_EVENT:
                    return $this->runTriggerEvent($params);
                case self::COMMAND_GRANT_ITEM:
                    return $this->runGrantItem($params);
                case self::COMMAND_ADJUST_PLAYER:
                    return $this->runAdjustPlayer($params);
                case self::COMMAND_BAN_PLAYER:
                    return $this->runBanPlayer($adminUserId, $params);
                case self::COMMAND_WARN_PLAYER:
                    return $this->runWarnPlayer($adminUserId, $params);
                case self::COMMAND_GLOBAL_DECREE:
                    return $this->runGlobalDecree($params);
                default:
                    return ['success' => false, 'message' => 'Unknown command.'];
            }
        } catch (\Throwable $e) {
            error_log('DaoCommandService::runCommand ' . $command . ' ' . $e->getMessage());
            return ['success' => false, 'message' => 'Command failed: ' . $e->getMessage()];
        }
    }

    private function runSpawnBoss(array $params): array
    {
        $name = $params['boss_name'] ?? $params['name'] ?? '';
        $worldBoss = new WorldBossService();
        $legendaryResult = $worldBoss->spawnLegendaryBoss($name);
        if ($legendaryResult['success']) {
            return $legendaryResult;
        }
        $maxHp = isset($params['max_hp']) ? (int)$params['max_hp'] : 100000;
        $duration = isset($params['duration_minutes']) ? (int)$params['duration_minutes'] : 120;
        $maxHp = max(1, min(100_000_000, $maxHp));
        $duration = max(1, min(10080, $duration)); // 1 min to 7 days
        return $worldBoss->spawnBoss($name, $maxHp, $duration);
    }

    private function runTriggerEvent(array $params): array
    {
        $eventName = $params['event_name'] ?? '';
        $start = $params['start_time'] ?? date('Y-m-d H:i:s');
        $end = $params['end_time'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));
        if (strtotime($start) === false) {
            $start = date('Y-m-d H:i:s');
        }
        if (strtotime($end) === false) {
            $end = date('Y-m-d H:i:s', strtotime('+1 day'));
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("INSERT INTO scheduled_events (event_name, start_time, end_time, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$eventName, $start, $end]);
            $eventService = new EventService();
            $eventService->syncScheduledEvents();
            return ['success' => true, 'message' => "Event [{$eventName}] triggered.", 'event_name' => $eventName];
        } catch (PDOException $e) {
            error_log('DaoCommandService::runTriggerEvent ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not trigger event.'];
        }
    }

    private function runGrantItem(array $params): array
    {
        $userId = (int)$params['user_id'];
        $itemTemplateId = (int)$params['item_template_id'];
        $quantity = max(1, min(9999, (int)($params['quantity'] ?? 1)));
        $itemService = new ItemService();
        $result = $itemService->addItemToInventory($userId, $itemTemplateId, $quantity);
        if ($result['success']) {
            return ['success' => true, 'message' => "Granted item template {$itemTemplateId} x{$quantity} to user {$userId}.", 'target_id' => $userId];
        }
        return ['success' => false, 'message' => $result['message'] ?? 'Grant failed.', 'target_id' => $userId];
    }

    private function runAdjustPlayer(array $params): array
    {
        $userId = (int)$params['user_id'];
        $allowed = ['level', 'chi', 'max_chi', 'attack', 'defense', 'gold', 'spirit_stones'];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'User not found.', 'target_id' => $userId];
            }
            $updates = [];
            $bind = [];
            foreach ($allowed as $col) {
                if (!array_key_exists($col, $params)) {
                    continue;
                }
                $val = $params[$col];
                if (is_numeric($val)) {
                    $updates[] = "{$col} = ?";
                    $bind[] = (int)$val;
                }
            }
            if (empty($updates)) {
                return ['success' => false, 'message' => 'No valid adjust fields.', 'target_id' => $userId];
            }
            $bind[] = $userId;
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->prepare($sql)->execute($bind);
            return ['success' => true, 'message' => 'Player adjusted.', 'target_id' => $userId];
        } catch (PDOException $e) {
            error_log('DaoCommandService::runAdjustPlayer ' . $e->getMessage());
            return ['success' => false, 'message' => 'Adjust failed.', 'target_id' => $userId];
        }
    }

    private function runBanPlayer(int $adminUserId, array $params): array
    {
        $adminService = new AdminUserService();
        $result = $adminService->banUser($adminUserId, (int)$params['user_id'], (string)$params['reason']);
        $result['target_id'] = (int)$params['user_id'];
        return $result;
    }

    private function runWarnPlayer(int $adminUserId, array $params): array
    {
        $adminService = new AdminUserService();
        $result = $adminService->issueWarning($adminUserId, (int)$params['user_id'], (string)$params['message']);
        $result['target_id'] = (int)$params['user_id'];
        return $result;
    }

    private function runGlobalDecree(array $params): array
    {
        $decree = $params['message'] ?? $params['decree'] ?? '';
        $hours = max(1, min(720, (int)($params['hours'] ?? 24)));
        $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        try {
            $db = Database::getConnection();
            $db->prepare("INSERT INTO global_announcements (message, created_at, expires_at) VALUES (?, NOW(), ?)")
                ->execute([$decree, $expires]);
            return ['success' => true, 'message' => 'Global decree posted.', 'expires_at' => $expires];
        } catch (PDOException $e) {
            error_log('DaoCommandService::runGlobalDecree ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not post decree.'];
        }
    }

    private function resolveTargetId(string $command, array $params, array $result): ?int
    {
        if (isset($result['target_id']) && (int)$result['target_id'] > 0) {
            return (int)$result['target_id'];
        }
        $uid = $params['user_id'] ?? null;
        return $uid !== null ? (int)$uid : null;
    }

    /**
     * Log command execution to dao_commands_log.
     */
    public function logCommand(
        int $adminUserId,
        string $command,
        ?int $targetId,
        array $params,
        bool $success,
        string $resultMessage
    ): ?int {
        try {
            $db = Database::getConnection();
            $json = json_encode($params, JSON_UNESCAPED_UNICODE);
            $msg = mb_substr($resultMessage, 0, 500);
            $stmt = $db->prepare("
                INSERT INTO dao_commands_log (admin_user_id, command, target_id, params_json, result_success, result_message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$adminUserId, $command, $targetId, $json, $success ? 1 : 0, $msg]);
            return (int)$db->lastInsertId();
        } catch (PDOException $e) {
            error_log('DaoCommandService::logCommand ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get recent command log entries for admin view.
     */
    public function getLog(int $limit = 50, ?string $command = null, ?int $adminUserId = null): array
    {
        try {
            $db = Database::getConnection();
            $sql = "
                SELECT l.id, l.admin_user_id, l.command, l.target_id, l.params_json, l.result_success, l.result_message, l.created_at,
                       u.username AS admin_username
                FROM dao_commands_log l
                LEFT JOIN users u ON u.id = l.admin_user_id
                WHERE 1=1
            ";
            $params = [];
            if ($command !== null && $command !== '') {
                $sql .= " AND l.command = ?";
                $params[] = $command;
            }
            if ($adminUserId !== null && $adminUserId > 0) {
                $sql .= " AND l.admin_user_id = ?";
                $params[] = $adminUserId;
            }
            $sql .= " ORDER BY l.created_at DESC LIMIT " . max(1, min(200, $limit));
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log('DaoCommandService::getLog ' . $e->getMessage());
            return [];
        }
    }

    /**
     * All known commands (for UI).
     */
    public static function getAllCommands(): array
    {
        return [
            self::COMMAND_SPAWN_BOSS,
            self::COMMAND_TRIGGER_EVENT,
            self::COMMAND_GRANT_ITEM,
            self::COMMAND_ADJUST_PLAYER,
            self::COMMAND_WARN_PLAYER,
            self::COMMAND_BAN_PLAYER,
            self::COMMAND_GLOBAL_DECREE,
        ];
    }
}
