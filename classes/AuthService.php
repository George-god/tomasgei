<?php
declare(strict_types=1);

namespace Game\Service;

use Game\Config\Database;
use Game\Entity\User;
use PDOException;

/**
 * Authentication service for user registration and login
 */
class AuthService
{
    /**
     * Register a new user
     * 
     * @param string $username Username
     * @param string $email Email address
     * @param string $password Plain text password
     * @param string $confirmPassword Password confirmation
     * @return array ['success' => bool, 'user' => User|null, 'errors' => array]
     */
    public function register(
        string $username,
        string $email,
        string $password,
        string $confirmPassword
    ): array {
        $errors = [];

        // Validate inputs
        $usernameError = $this->validateUsername($username);
        if ($usernameError) {
            $errors['username'] = $usernameError;
        }

        $emailError = $this->validateEmail($email);
        if ($emailError) {
            $errors['email'] = $emailError;
        }

        $passwordError = $this->validatePassword($password, $confirmPassword);
        if ($passwordError) {
            $errors['password'] = $passwordError;
        }

        // Check if username already exists
        if (empty($errors['username'])) {
            if ($this->usernameExists($username)) {
                $errors['username'] = 'Username is already taken.';
            }
        }

        // Check if email already exists
        if (empty($errors['email'])) {
            if ($this->emailExists($email)) {
                $errors['email'] = 'Email is already registered.';
            }
        }

        // If validation errors, return early
        if (!empty($errors)) {
            return [
                'success' => false,
                'user' => null,
                'errors' => $errors
            ];
        }

        // Hash password securely
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            return [
                'success' => false,
                'user' => null,
                'errors' => ['password' => 'Failed to hash password. Please try again.']
            ];
        }

        // Create new user with default cultivation stats
        // Default realm_id = 1 (Qi Refining)
        $user = new User(
            username: $username,
            email: $email,
            passwordHash: $passwordHash,
            realmId: 1,
            level: 1,
            chi: 100,
            maxChi: 100,
            attack: 10,
            defense: 10,
            wins: 0,
            losses: 0,
            rating: 1000.0
        );

        // Save to database
        try {
            $db = Database::getConnection();
            
            $sql = "INSERT INTO users (
                username, email, password_hash, realm_id, level, 
                chi, max_chi, attack, defense, wins, losses, rating, created_at
            ) VALUES (
                :username, :email, :password_hash, :realm_id, :level,
                :chi, :max_chi, :attack, :defense, :wins, :losses, :rating, :created_at
            )";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':username' => $user->getUsername(),
                ':email' => $user->getEmail(),
                ':password_hash' => $user->getPasswordHash(),
                ':realm_id' => $user->getRealmId(),
                ':level' => $user->getLevel(),
                ':chi' => $user->getChi(),
                ':max_chi' => $user->getMaxChi(),
                ':attack' => $user->getAttack(),
                ':defense' => $user->getDefense(),
                ':wins' => $user->getWins(),
                ':losses' => $user->getLosses(),
                ':rating' => $user->getRating(),
                ':created_at' => $user->getCreatedAt()
            ]);

            $user = new User(
                id: (int)$db->lastInsertId(),
                username: $user->getUsername(),
                email: $user->getEmail(),
                passwordHash: $user->getPasswordHash(),
                realmId: $user->getRealmId(),
                level: $user->getLevel(),
                chi: $user->getChi(),
                maxChi: $user->getMaxChi(),
                attack: $user->getAttack(),
                defense: $user->getDefense(),
                wins: $user->getWins(),
                losses: $user->getLosses(),
                rating: $user->getRating(),
                createdAt: $user->getCreatedAt()
            );

            return [
                'success' => true,
                'user' => $user,
                'errors' => []
            ];

        } catch (PDOException $e) {
            error_log("Registration failed: " . $e->getMessage());
            return [
                'success' => false,
                'user' => null,
                'errors' => ['general' => 'Registration failed. Please try again later.']
            ];
        }
    }

    /**
     * Login user
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @return array ['success' => bool, 'user' => User|null, 'error' => string|null]
     */
    public function login(string $username, string $password): array
    {
        try {
            $db = Database::getConnection();
            
            // Find user by username or email
            $sql = "SELECT * FROM users WHERE username = :identifier OR email = :identifier LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':identifier' => $username]);
            $userData = $stmt->fetch();

            if (!$userData) {
                return [
                    'success' => false,
                    'user' => null,
                    'error' => 'Invalid username or password.'
                ];
            }

            $user = User::fromArray($userData);

            // Verify password
            if (!$user->verifyPassword($password)) {
                return [
                    'success' => false,
                    'user' => null,
                    'error' => 'Invalid username or password.'
                ];
            }

            // Update last login
            $updateSql = "UPDATE users SET last_login_at = NOW() WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([':id' => $user->getId()]);

            return [
                'success' => true,
                'user' => $user,
                'error' => null
            ];

        } catch (PDOException $e) {
            error_log("Login failed: " . $e->getMessage());
            return [
                'success' => false,
                'user' => null,
                'error' => 'Login failed. Please try again later.'
            ];
        }
    }

    /**
     * Validate username
     * 
     * @param string $username Username to validate
     * @return string|null Error message or null if valid
     */
    private function validateUsername(string $username): ?string
    {
        $username = trim($username);

        if (empty($username)) {
            return 'Username is required.';
        }

        if (strlen($username) < 3) {
            return 'Username must be at least 3 characters long.';
        }

        if (strlen($username) > 30) {
            return 'Username must be less than 30 characters.';
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return 'Username can only contain letters, numbers, and underscores.';
        }

        return null;
    }

    /**
     * Validate email
     * 
     * @param string $email Email to validate
     * @return string|null Error message or null if valid
     */
    private function validateEmail(string $email): ?string
    {
        $email = trim($email);

        if (empty($email)) {
            return 'Email is required.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }

        if (strlen($email) > 255) {
            return 'Email is too long.';
        }

        return null;
    }

    /**
     * Validate password
     * 
     * @param string $password Password to validate
     * @param string $confirmPassword Password confirmation
     * @return string|null Error message or null if valid
     */
    private function validatePassword(string $password, string $confirmPassword): ?string
    {
        if (empty($password)) {
            return 'Password is required.';
        }

        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }

        if (strlen($password) > 72) {
            return 'Password is too long (maximum 72 characters).';
        }

        if ($password !== $confirmPassword) {
            return 'Passwords do not match.';
        }

        return null;
    }

    /**
     * Check if username exists
     * 
     * @param string $username Username to check
     * @return bool True if exists
     */
    private function usernameExists(string $username): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log("Username check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if email exists
     * 
     * @param string $email Email to check
     * @return bool True if exists
     */
    private function emailExists(string $email): bool
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log("Email check failed: " . $e->getMessage());
            return false;
        }
    }
}
