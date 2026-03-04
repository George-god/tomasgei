<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Helper/ApiResponse.php';
require_once __DIR__ . '/classes/Helper/SessionHelper.php';
require_once __DIR__ . '/classes/Service/ItemService.php';
require_once __DIR__ . '/classes/Config/Database.php';

use Game\Helper\ApiResponse;
use Game\Helper\SessionHelper;
use Game\Service\ItemService;
use Game\Config\Database;

$userId = SessionHelper::requireUserIdForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed.', 405);
}

$inventoryId = (int)($_POST['inventory_id'] ?? $_GET['inventory_id'] ?? 0);
if ($inventoryId < 1) {
    ApiResponse::error('Invalid inventory item.');
}

$itemService = new ItemService();
$inv = $itemService->getInventoryRow($userId, $inventoryId);
if (!$inv || (int)$inv['quantity'] < 1) {
    ApiResponse::error('Scroll not found or none left.');
}

$template = $itemService->getTemplateById((int)$inv['item_template_id']);
if (!$template || (string)($template['type'] ?? '') !== 'scroll') {
    ApiResponse::error('That item is not a scroll.');
}

$scrollEffect = isset($template['scroll_effect']) && $template['scroll_effect'] !== '' ? (string)$template['scroll_effect'] : null;
if (!$scrollEffect) {
    ApiResponse::error('Scroll has no effect.');
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT active_scroll_type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();
$active = isset($row['active_scroll_type']) && $row['active_scroll_type'] !== '' ? (string)$row['active_scroll_type'] : null;
if ($active !== null) {
    ApiResponse::error('You already have an active scroll. Only one at a time.');
}

$itemService->consumeOne($userId, $inventoryId);
$db->prepare("UPDATE users SET active_scroll_type = ? WHERE id = ?")->execute([$scrollEffect, $userId]);

ApiResponse::success([
    'active_scroll_type' => $scrollEffect,
    'scroll_name' => (string)($template['name'] ?? 'Scroll')
], 'Scroll activated for your next battle.');
