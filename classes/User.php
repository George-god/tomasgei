<?php
declare(strict_types=1);

namespace Game\Entity;

/**
 * User entity class representing a cultivation game player
 * Designed for competitive multiplayer with ELO rating system
 */
class User
{
    private ?int $id;
    private string $username;
    private string $email;
    private string $passwordHash;
    private int $realmId;
    private int $level;
    private int $chi;
    private int $maxChi;
    private int $attack;
    private int $defense;
    private int $wins;
    private int $losses;
    private float $rating;
    private string $createdAt;
    private ?string $lastLoginAt;

    public function __construct(
        ?int $id = null,
        string $username = '',
        string $email = '',
        string $passwordHash = '',
        int $realmId = 1,
        int $level = 1,
        int $chi = 100,
        int $maxChi = 100,
        int $attack = 10,
        int $defense = 10,
        int $wins = 0,
        int $losses = 0,
        float $rating = 1000.0,
        string $createdAt = '',
        ?string $lastLoginAt = null
    ) {
        $this->id = $id;
        $this->username = $username;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->realmId = $realmId;
        $this->level = $level;
        $this->chi = $chi;
        $this->maxChi = $maxChi;
        $this->attack = $attack;
        $this->defense = $defense;
        $this->wins = $wins;
        $this->losses = $losses;
        $this->rating = $rating;
        $this->createdAt = $createdAt ?: date('Y-m-d H:i:s');
        $this->lastLoginAt = $lastLoginAt;
    }

    // Getters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRealmId(): int
    {
        return $this->realmId;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getChi(): int
    {
        return $this->chi;
    }

    public function getMaxChi(): int
    {
        return $this->maxChi;
    }

    public function getAttack(): int
    {
        return $this->attack;
    }

    public function getDefense(): int
    {
        return $this->defense;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    public function getRating(): float
    {
        return $this->rating;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    // Setters
    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function setRealmId(int $realmId): void
    {
        $this->realmId = max(1, $realmId);
    }

    public function setLevel(int $level): void
    {
        $this->level = max(1, $level);
    }

    public function setChi(int $chi): void
    {
        $this->chi = max(0, min($chi, $this->maxChi));
    }

    public function setMaxChi(int $maxChi): void
    {
        $this->maxChi = max(1, $maxChi);
    }

    public function setAttack(int $attack): void
    {
        $this->attack = max(1, $attack);
    }

    public function setDefense(int $defense): void
    {
        $this->defense = max(1, $defense);
    }

    public function setWins(int $wins): void
    {
        $this->wins = max(0, $wins);
    }

    public function setLosses(int $losses): void
    {
        $this->losses = max(0, $losses);
    }

    public function setRating(float $rating): void
    {
        $this->rating = max(0.0, $rating);
    }

    public function setLastLoginAt(?string $lastLoginAt): void
    {
        $this->lastLoginAt = $lastLoginAt;
    }

    /**
     * Get win rate as percentage
     * 
     * @return float Win rate percentage (0-100)
     */
    public function getWinRate(): float
    {
        $total = $this->wins + $this->losses;
        if ($total === 0) {
            return 0.0;
        }
        return round(($this->wins / $total) * 100, 2);
    }

    /**
     * Get total battles
     * 
     * @return int Total number of battles
     */
    public function getTotalBattles(): int
    {
        return $this->wins + $this->losses;
    }

    /**
     * Verify password against stored hash
     * 
     * @param string $password Plain text password
     * @return bool True if password matches
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    /**
     * Convert user to array for database operations
     * 
     * @return array User data as array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'password_hash' => $this->passwordHash,
            'realm_id' => $this->realmId,
            'level' => $this->level,
            'chi' => $this->chi,
            'max_chi' => $this->maxChi,
            'attack' => $this->attack,
            'defense' => $this->defense,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'rating' => $this->rating,
            'created_at' => $this->createdAt,
            'last_login_at' => $this->lastLoginAt
        ];
    }

    /**
     * Create User from database array
     * 
     * @param array $data Database row data
     * @return User User instance
     */
    public static function fromArray(array $data): User
    {
        return new User(
            id: $data['id'] ?? null,
            username: $data['username'] ?? '',
            email: $data['email'] ?? '',
            passwordHash: $data['password_hash'] ?? '',
            realmId: (int)($data['realm_id'] ?? 1),
            level: (int)($data['level'] ?? 1),
            chi: (int)($data['chi'] ?? 100),
            maxChi: (int)($data['max_chi'] ?? 100),
            attack: (int)($data['attack'] ?? 10),
            defense: (int)($data['defense'] ?? 10),
            wins: (int)($data['wins'] ?? 0),
            losses: (int)($data['losses'] ?? 0),
            rating: (float)($data['rating'] ?? 1000.0),
            createdAt: $data['created_at'] ?? '',
            lastLoginAt: $data['last_login_at'] ?? null
        );
    }
}
