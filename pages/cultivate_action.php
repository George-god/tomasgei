<?php
declare(strict_types=1);

/**
 * Cultivation endpoint - delegates to controller.
 * Exists in pages/ so fetch from pages/game.php can use cultivate_action.php (same directory).
 */
require_once dirname(__DIR__) . '/controllers/cultivate_action.php';
