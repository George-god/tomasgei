<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Heavenly Dao petitions for world-change suggestions.
 */
class DaoPetitionService
{
    private const VALID_STATUSES = ['observing', 'contemplating', 'accepted', 'denied'];

    public function submitPetition(int $userId, string $title, string $description, string $category): array
    {
        $title = trim($title);
        $description = trim($description);
        $category = trim($category);

        if ($title === '' || mb_strlen($title) < 4) {
            return ['success' => false, 'message' => 'The Heavenly Dao requires a clearer petition title.'];
        }
        if ($description === '' || mb_strlen($description) < 12) {
            return ['success' => false, 'message' => 'Your petition must contain a fuller explanation of the proposed change.'];
        }
        if ($category === '' || mb_strlen($category) < 2) {
            return ['success' => false, 'message' => 'State what branch of the world your petition concerns.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('INSERT INTO dao_petitions (user_id, title, description, category, status) VALUES (?, ?, ?, ?, \'observing\')');
            $stmt->execute([$userId, $title, $description, $category]);

            return [
                'success' => true,
                'message' => 'Your petition has entered the currents of Heaven. The Heavenly Dao now observes its weight.',
            ];
        } catch (PDOException $e) {
            error_log('DaoPetitionService::submitPetition ' . $e->getMessage());
            return ['success' => false, 'message' => 'The Heavenly Dao cannot receive this petition right now.'];
        }
    }

    public function getPetitionsForUser(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT dp.*, admin.username AS admin_username
                FROM dao_petitions dp
                LEFT JOIN users admin ON admin.id = dp.admin_user_id
                WHERE dp.user_id = ?
                ORDER BY dp.created_at DESC, dp.id DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('DaoPetitionService::getPetitionsForUser ' . $e->getMessage());
            return [];
        }
    }

    public function getAllPetitions(?string $statusFilter = null): array
    {
        try {
            $db = Database::getConnection();
            $sql = "
                SELECT dp.*, reporter.username AS reporter_username, admin.username AS admin_username
                FROM dao_petitions dp
                JOIN users reporter ON reporter.id = dp.user_id
                LEFT JOIN users admin ON admin.id = dp.admin_user_id
            ";
            $params = [];
            if ($statusFilter !== null && in_array($statusFilter, self::VALID_STATUSES, true)) {
                $sql .= ' WHERE dp.status = ?';
                $params[] = $statusFilter;
            }
            $sql .= ' ORDER BY FIELD(dp.status, \'observing\', \'contemplating\', \'accepted\', \'denied\'), dp.created_at DESC, dp.id DESC';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('DaoPetitionService::getAllPetitions ' . $e->getMessage());
            return [];
        }
    }

    public function updatePetition(int $adminUserId, int $petitionId, string $status, string $response): array
    {
        $status = trim($status);
        $response = trim($response);

        if (!in_array($status, self::VALID_STATUSES, true)) {
            return ['success' => false, 'message' => 'Unknown Heavenly Dao petition status.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT id, user_id, title FROM dao_petitions WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$petitionId]);
            $petition = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$petition) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Petition not found.'];
            }

            $hasResponse = $response !== '';
            $stmt = $db->prepare("
                UPDATE dao_petitions
                SET status = ?, heavenly_response = ?, admin_user_id = ?, responded_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $status,
                $hasResponse ? $response : null,
                $adminUserId,
                $hasResponse ? date('Y-m-d H:i:s') : null,
                $petitionId,
            ]);

            $message = $this->buildHeavenlyMessage((string)$petition['title'], $status, $response);
            $notificationService = new NotificationService();
            $notificationService->createNotification(
                (int)$petition['user_id'],
                'heavenly_dao_message',
                'Heavenly Dao Message',
                $message,
                $petitionId,
                'dao_petition'
            );

            $db->commit();
            return ['success' => true, 'message' => 'The Heavenly Dao has rendered judgment upon the petition.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DaoPetitionService::updatePetition ' . $e->getMessage());
            return ['success' => false, 'message' => 'The Heavenly Dao could not finalize this petition response.'];
        }
    }

    public function getStatusOptions(): array
    {
        return self::VALID_STATUSES;
    }

    private function buildHeavenlyMessage(string $title, string $status, string $response): string
    {
        $prefix = match ($status) {
            'contemplating' => 'The Heavenly Dao contemplates your suggestion and weighs its effect upon the mortal world.',
            'accepted' => 'The Heavenly Dao accepts the essence of your petition and marks it as worthy of future change.',
            'denied' => 'The Heavenly Dao denies this petition, for its current order remains unchanged.',
            default => 'The Heavenly Dao observes your petition and records its resonance.',
        };

        $message = $prefix . ' Petition: "' . $title . '".';
        if ($response !== '') {
            $message .= ' Message: ' . $response;
        }
        return $message;
    }
}
