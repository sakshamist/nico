<?php

declare(strict_types=1);

namespace Nico\Edge\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Forward-only migration runner (FR-2).
 *
 * Migrations are plain .sql files named NN_description.sql and are applied in
 * lexical order. Applied migrations are recorded, so running the runner twice is
 * a no-op (AC-1).
 */
final class Migrator
{
    private string $directory;

    private PDO $pdo;

    public function __construct(PDO $pdo, string $directory)
    {
        $this->pdo = $pdo;
        $this->directory = rtrim($directory, '/');
    }

    /**
     * @return list<string> names of migrations applied by this call
     */
    public function migrate(): array
    {
        $this->ensureLedger();

        $applied = $this->appliedNames();
        $ran = [];

        foreach ($this->discover() as $name) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($this->directory . '/' . $name);
            if ($sql === false) {
                throw new RuntimeException("Unreadable migration: {$name}");
            }

            $this->apply($name, $sql);
            $ran[] = $name;
        }

        return $ran;
    }

    /** Roll the schema back to an empty state. Test/dev utility only. */
    public function reset(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->discover() as $name) {
            $sql = (string) file_get_contents($this->directory . '/' . $name);

            // Only forward migrations exist, so collect their DROP statements.
            if (preg_match_all('/^DROP TABLE IF EXISTS\s+([a-z_]+);/mi', $sql, $m) > 0) {
                foreach ($m[1] as $table) {
                    $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
                }
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec('DROP TABLE IF EXISTS _migrations');
    }

    /**
     * Applies one migration.
     *
     * Note on atomicity: MySQL and MariaDB perform an *implicit commit* before and
     * after DDL, so a CREATE TABLE cannot be rolled back no matter how it is
     * wrapped. Wrapping DDL in an explicit transaction is therefore both useless
     * and harmful - the transaction is silently committed mid-statement and a
     * later commit() fails with "no active transaction".
     *
     * Migrations are consequently run statement-by-statement and recorded in the
     * ledger only after the last one succeeds. A migration that fails part-way is
     * left unrecorded and must be re-run by hand; this is why migration files are
     * written to be re-runnable (CREATE TABLE IF NOT EXISTS, additive ALTERs).
     *
     * Statements that manage the ledger itself are stripped so a migration cannot
     * accidentally operate on it.
     */
    private function apply(string $name, string $sql): void
    {
        $clean = preg_replace(
            '/^\s*(DROP|CREATE|ALTER|TRUNCATE)\s+TABLE\s+IF\s+EXISTS\s+_migrations.*?;.*$/mi',
            '',
            $sql
        ) ?? $sql;

        try {
            foreach ($this->splitStatements($clean) as $statement) {
                $this->pdo->exec($statement);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO _migrations (name, applied_at) VALUES (:name, :at)'
            );
            $stmt->execute(['name' => $name, 'at' => gmdate('c')]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Migration {$name} failed: " . $e->getMessage()
                . ' (migrations are not atomic - re-run after fixing)',
                0,
                $e
            );
        }
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS _migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL UNIQUE,
                applied_at VARCHAR(32) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        $rows = $this->pdo->query('SELECT name FROM _migrations ORDER BY id')->fetchAll();

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    private function discover(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $p): string => basename($p), $files);
    }

    /**
     * Splits on semicolons that terminate a statement, ignoring those inside
     * quotes or comments.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }

            if ($char === ';' && !$inSingle && !$inDouble) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
