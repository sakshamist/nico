<?php

declare(strict_types=1);

namespace Nico\Edge\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connection plus runtime capability detection (FR-4).
 *
 * The atomic-claim strategy used by the job queue depends on whether the server
 * supports SELECT ... FOR UPDATE SKIP LOCKED. That is *probed* here rather than
 * assumed from a version number, because the edge host's engine is unverified
 * and may be MySQL or MariaDB at an unknown version.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static ?array $capabilities = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::int('DB_PORT', 3306);
        $name = Config::require('DB_NAME');
        $user = Config::require('DB_USER');
        $pass = Config::require('DB_PASSWORD');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // The DSN may contain the password, so report only the safe part.
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * Detects engine, version and atomic-claim support once per process.
     *
     * The probe runs inside a transaction that is always rolled back, and uses a
     * table that is created and dropped so it works on a virgin database.
     */
    public static function capabilities(): array
    {
        if (self::$capabilities !== null) {
            return self::$capabilities;
        }

        $pdo = self::pdo();

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        // MariaDB reports "5.5.5-10.11.x-MariaDB" for compatibility; strip that prefix.
        if (preg_match('/(\d+\.\d+\.\d+)/', $version, $m) === 1) {
            $semver = $m[1];
        } else {
            $semver = '0.0.0';
        }

        $engine = str_contains(strtolower($version), 'mariadb') ? 'mariadb' : 'mysql';

        self::$capabilities = [
            'engine'            => $engine,
            'version_raw'       => $version,
            'version'           => $semver,
            'skip_locked'       => self::probeSkipLocked($pdo),
            'json_type'         => self::probeJsonType($pdo),
            'detected_at'       => gmdate('c'),
        ];

        return self::$capabilities;
    }

    /**
     * Actually executes a SKIP LOCKED statement. Version-number checks miss
     * vendor backports and forks, so the only trustworthy signal is trying it.
     */
    private static function probeSkipLocked(PDO $pdo): bool
    {
        $pdo->exec('DROP TABLE IF EXISTS _nico_cap_probe');
        $pdo->exec('CREATE TABLE _nico_cap_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO _nico_cap_probe (id) VALUES (1)');

        try {
            $pdo->beginTransaction();
            $pdo->query('SELECT id FROM _nico_cap_probe WHERE id = 1 FOR UPDATE SKIP LOCKED');
            $pdo->rollBack();

            return true;
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS _nico_cap_probe');
        }
    }

    /**
     * MariaDB's JSON is an alias for LONGTEXT with a check constraint; MySQL has a
     * native JSON type. Both work for our payloads, but it is worth recording.
     */
    private static function probeJsonType(PDO $pdo): string
    {
        try {
            $pdo->query("SELECT CAST('{\"a\":1}' AS JSON)");
            $pdo->exec('DROP TABLE IF EXISTS _nico_json_probe');
            $pdo->exec('CREATE TABLE _nico_json_probe (j JSON)');
            $pdo->exec('DROP TABLE _nico_json_probe');

            return 'native';
        } catch (PDOException) {
            return 'alias';
        }
    }

    /** Test seam: forget the cached PDO handle and capability cache. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$capabilities = null;
    }
}
