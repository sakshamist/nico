<?php

declare(strict_types=1);

namespace Nico\Edge\Auth;

use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;
use Nico\Edge\Support\PasswordHasher;
use PDO;

/**
 * User records, credential verification, and ownership checks.
 *
 * Authorization lives here rather than in controllers so that every entry point
 * is forced through the same rules (FR-8). Callers pass a user id; this class
 * decides what they may touch.
 */
final class UserRepository
{
    public const ROLES = ['student', 'teacher', 'admin'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password_hash, role, created_at FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password_hash, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function create(string $email, string $plainPassword, string $role = 'student'): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }

        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email address.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, role, created_at)
             VALUES (:email, :hash, :role, :at)'
        );
        $stmt->execute([
            'email' => $email,
            'hash'  => PasswordHasher::hash($plainPassword),
            'role'  => $role,
            'at'    => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Verifies credentials (FR-6).
     *
     * On failure, a hash comparison is still performed against a dummy value so
     * the response time does not reveal whether an email exists.
     *
     * @return array<string,mixed>|null the user row on success, null otherwise
     */
    public function verifyCredentials(string $email, string $plainPassword): ?array
    {
        $user = $this->findByEmail($email);

        if ($user === null) {
            password_verify($plainPassword, '$2y$10$usesomesillystringfore.HXwmF7oJxRLPfaJPtY3vRJgHFPd');

            return null;
        }

        if (!PasswordHasher::verify($plainPassword, (string) $user['password_hash'])) {
            Log::info('auth.login_failed', ['user_id' => (int) $user['id']]);

            return null;
        }

        // Opportunistic upgrade when hashing parameters have moved on.
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            $update = $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $update->execute([
                'h'  => PasswordHasher::hash($plainPassword),
                'id' => (int) $user['id'],
            ]);
        }

        unset($user['password_hash']);

        return $user;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /**
     * Ownership is the only authorization model in v1: a resource belongs to
     * exactly one user (FR-8). Admins are the deliberate exception, since they
     * need to operate on resources they did not upload.
     */
    public function ownsResource(int $userId, int $resourceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM resources WHERE id = :rid AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['rid' => $resourceId, 'uid' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function canAccessResource(int $userId, string $role, int $resourceId): bool
    {
        if ($role === 'admin') {
            return true;
        }

        return $this->ownsResource($userId, $resourceId);
    }
}
