<?php

declare(strict_types=1);

namespace Nico\Edge\Support;

/**
 * Password hashing (FR-6).
 *
 * Uses PHP's password_hash with the platform default algorithm, which is
 * currently bcrypt and will follow PHP to argon2id if the default changes. No
 * plaintext password is ever stored, logged, or included in an error message.
 */
final class PasswordHasher
{
    /** Stored as a placeholder in the hash when a rehash is needed after login. */
    public const NEEDS_REHASH = '__needs_rehash__';

    public static function hash(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verifies a password. A malformed stored hash still returns false rather
     * than throwing, so a corrupt row cannot be used to bypass the check.
     */
    public static function verify(string $plain, string $hash): bool
    {
        if ($hash === '' || strlen($hash) > 255) {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /** True when the stored hash uses outdated parameters or an older algorithm. */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
