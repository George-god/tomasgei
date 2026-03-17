<?php
declare(strict_types=1);

/**
 * Legacy redirect - Dao Observatory is now Bug Reports in the Heavenly Dao Administration Panel.
 */
require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';

session_start();
SessionHelper::requireAdmin('../pages/game.php', '../pages/login.php');

$status = isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : '';
header('Location: bug_reports.php' . $status);
exit;
