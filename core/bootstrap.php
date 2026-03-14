<?php
declare(strict_types=1);

/**
 * Phase 1 bootstrap: config and database only.
 * Include before any page logic. Does not start session.
 */
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/config/database.php';

use Game\Config\Database;

Database::setConfig([
    'host' => 'localhost',
    'dbname' => 'cultivation_rpg',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
]);
