<?php
declare(strict_types=1);

namespace Game\Service;

require_once __DIR__ . '/NotificationService.php';

use Game\Config\Database;
use PDO;
use PDOException;

/**
 * Heavenly Dao anomaly reporting and observatory workflow.
 */
class AnomalyReportService
{
    private const VALID_STATUSES = ['observing', 'investigating', 'resolved'];

    public function submitReport(int $userId, string $title, string $description, string $location): array
    {
        $title = trim($title);
        $description = trim($description);
        $location = trim($location);

        if ($title === '' || mb_strlen($title) < 4) {
            return ['success' => false, 'message' => 'The Heavenly Dao requires a clearer anomaly title.'];
        }
        if ($description === '' || mb_strlen($description) < 12) {
            return ['success' => false, 'message' => 'Describe the anomaly in greater detail so the Heavenly Dao may observe it.'];
        }
        if ($location === '' || mb_strlen($location) < 2) {
            return ['success' => false, 'message' => 'State where the anomaly manifested.'];
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('INSERT INTO bug_reports (user_id, title, description, location, status) VALUES (?, ?, ?, ?, \'observing\')');
            $stmt->execute([$userId, $title, $description, $location]);

            return [
                'success' => true,
                'message' => 'The Heavenly Dao has heard your petition. The anomaly now rests beneath celestial observation.',
            ];
        } catch (PDOException $e) {
            error_log('AnomalyReportService::submitReport ' . $e->getMessage());
            return ['success' => false, 'message' => 'The Heavenly Dao is veiled right now. Try submitting the anomaly again later.'];
        }
    }

    public function getReportsForUser(int $userId): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT br.*, admin.username AS admin_username
                FROM bug_reports br
                LEFT JOIN users admin ON admin.id = br.admin_user_id
                WHERE br.user_id = ?
                ORDER BY br.created_at DESC, br.id DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('AnomalyReportService::getReportsForUser ' . $e->getMessage());
            return [];
        }
    }

    public function getAllReports(?string $statusFilter = null): array
    {
        try {
            $db = Database::getConnection();
            $sql = "
                SELECT br.*, reporter.username AS reporter_username, admin.username AS admin_username
                FROM bug_reports br
                JOIN users reporter ON reporter.id = br.user_id
                LEFT JOIN users admin ON admin.id = br.admin_user_id
            ";
            $params = [];
            if ($statusFilter !== null && in_array($statusFilter, self::VALID_STATUSES, true)) {
                $sql .= ' WHERE br.status = ?';
                $params[] = $statusFilter;
            }
            $sql .= ' ORDER BY FIELD(br.status, \'observing\', \'investigating\', \'resolved\'), br.created_at DESC, br.id DESC';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('AnomalyReportService::getAllReports ' . $e->getMessage());
            return [];
        }
    }

    public function updateReport(int $adminUserId, int $reportId, string $status, string $reply): array
    {
        $status = trim($status);
        $reply = trim($reply);

        if (!in_array($status, self::VALID_STATUSES, true)) {
            return ['success' => false, 'message' => 'Unknown Heavenly Dao status.'];
        }

        try {
            $db = Database::getConnection();
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT id, user_id, title, status FROM bug_reports WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Anomaly report not found.'];
            }

            $hasReply = $reply !== '';
            $stmt = $db->prepare("
                UPDATE bug_reports
                SET status = ?, admin_reply = ?, admin_user_id = ?, replied_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $status,
                $hasReply ? $reply : null,
                $adminUserId,
                $hasReply ? date('Y-m-d H:i:s') : null,
                $reportId,
            ]);

            $notificationService = new NotificationService();
            $decree = $this->buildHeavenlyDecree((string)$report['title'], $status, $reply);
            $notificationService->createNotification(
                (int)$report['user_id'],
                'heavenly_dao_decree',
                'Heavenly Dao Decree',
                $decree,
                $reportId,
                'bug_report'
            );

            $db->commit();
            return ['success' => true, 'message' => 'The Heavenly Dao decree has been issued.'];
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('AnomalyReportService::updateReport ' . $e->getMessage());
            return ['success' => false, 'message' => 'The observatory could not inscribe the decree.'];
        }
    }

    public function getStatusOptions(): array
    {
        return self::VALID_STATUSES;
    }

    private function buildHeavenlyDecree(string $title, string $status, string $reply): string
    {
        $prefix = match ($status) {
            'investigating' => 'The Heavenly Dao turns its gaze upon the anomaly and begins deep investigation.',
            'resolved' => 'The Heavenly Dao has harmonized the disturbance and declares the anomaly resolved.',
            default => 'The Heavenly Dao observes the anomaly and records its tremors.',
        };

        $message = $prefix . ' Report: "' . $title . '".';
        if ($reply !== '') {
            $message .= ' Decree: ' . $reply;
        }
        return $message;
    }
}
