<?php
declare(strict_types=1);

namespace Game\Config;

use PDO;
use PDOException;

/**
 * Database connection handler using PDO
 * Implements singleton pattern to ensure single database connection
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup(): void
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Set database configuration
     * Should be called before getConnection()
     * 
     * @param array $config Database configuration array
     * @return void
     */
    public static function setConfig(array $config): void
    {
        self::$config = array_merge([
            'host' => 'localhost',
            'dbname' => 'cultivation_rpg',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'options' => []
        ], $config);
    }

    /**
     * Get database connection instance
     * Creates connection if it doesn't exist
     * 
     * @return PDO Database connection instance
     * @throws PDOException If connection fails
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                // Use default config if not set
                if (empty(self::$config)) {
                    self::setConfig([]);
                }

                $host = self::$config['host'];
                $dbname = self::$config['dbname'];
                $username = self::$config['username'];
                $password = self::$config['password'];
                $charset = self::$config['charset'];

                // Build DSN
                $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

                // Default PDO options
                $defaultOptions = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}"
                ];

                // Merge with custom options
                $options = array_merge($defaultOptions, self::$config['options']);

                // Create PDO instance
                self::$instance = new PDO($dsn, $username, $password, $options);

            } catch (PDOException $e) {
                // Log error (in production, use proper logging)
                error_log("Database connection failed: " . $e->getMessage());
                
                // Re-throw with user-friendly message in development
                // In production, you might want to show a generic error page
                throw new PDOException(
                    "Unable to connect to database. Please try again later.",
                    0,
                    $e
                );
            }
        }

        return self::$instance;
    }

    /**
     * Test database connection
     * Useful for health checks or initialization scripts
     * 
     * @return bool True if connection is successful
     */
    public static function testConnection(): bool
    {
        try {
            $pdo = self::getConnection();
            $pdo->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            error_log("Database connection test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Close database connection
     * Useful for cleanup or testing
     * 
     * @return void
     */
    public static function closeConnection(): void
    {
        self::$instance = null;
    }

    /**
     * Execute a prepared statement
     * Helper method for common query execution
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return \PDOStatement Executed statement
     * @throws PDOException If query execution fails
     */
    public static function execute(string $sql, array $params = []): \PDOStatement
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query execution failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw new PDOException(
                "Database query failed. Please try again.",
                0,
                $e
            );
        }
    }

    /**
     * Begin a database transaction
     * 
     * @return bool True on success
     * @throws PDOException If transaction start fails
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit a database transaction
     * 
     * @return bool True on success
     * @throws PDOException If commit fails
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback a database transaction
     * 
     * @return bool True on success
     * @throws PDOException If rollback fails
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Get the last inserted ID
     * 
     * @param string|null $name Sequence name (not used in MySQL)
     * @return string Last inserted ID
     */
    public static function lastInsertId(?string $name = null): string
    {
        return self::getConnection()->lastInsertId($name);
    }
}
