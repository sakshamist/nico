<?php

declare(strict_types=1);

namespace Nico\Edge\Interns;

use Nico\Edge\Support\Database;
use PDO;

final class InternInteractionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function messages(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.sender_id, m.body, m.read_at, m.created_at,
                    u.email AS sender_email, u.role AS sender_role
               FROM intern_messages m
               JOIN users u ON u.id = m.sender_id
              WHERE m.intern_id = :intern
              ORDER BY m.id ASC'
        );
        $stmt->execute(['intern' => $internId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['sender_id'] = (int) $row['sender_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    public function postMessage(int $internId, int $senderId, string $body): void
    {
        $this->pdo->prepare(
            'INSERT INTO intern_messages (intern_id, sender_id, body, created_at)
             VALUES (:intern, :sender, :body, :now)'
        )->execute([
            'intern' => $internId,
            'sender' => $senderId,
            'body'   => $body,
            'now'    => gmdate('c'),
        ]);
    }

    public function markRead(int $internId, int $readerId): void
    {
        $this->pdo->prepare(
            'UPDATE intern_messages SET read_at = :now
              WHERE intern_id = :intern AND sender_id <> :reader AND read_at IS NULL'
        )->execute([
            'now'    => gmdate('Y-m-d H:i:s'),
            'intern' => $internId,
            'reader' => $readerId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function notes(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, body, created_at, updated_at
               FROM intern_personal_notes
              WHERE intern_id = :intern
              ORDER BY updated_at DESC'
        );
        $stmt->execute(['intern' => $internId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];

            return $row;
        }, $stmt->fetchAll());
    }

    public function addNote(int $internId, string $title, string $body): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO intern_personal_notes (intern_id, title, body, created_at, updated_at)
             VALUES (:intern, :title, :body, :now, :now_updated)'
        )->execute([
            'intern'     => $internId,
            'title'      => $title,
            'body'       => $body,
            'now'        => $now,
            'now_updated' => $now,
        ]);
    }

    public function deleteNote(int $internId, int $noteId): void
    {
        $this->pdo->prepare(
            'DELETE FROM intern_personal_notes WHERE id = :id AND intern_id = :intern'
        )->execute(['id' => $noteId, 'intern' => $internId]);
    }
}
