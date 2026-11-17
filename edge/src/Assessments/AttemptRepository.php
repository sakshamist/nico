<?php

declare(strict_types=1);

namespace Nico\Edge\Assessments;

use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Support\Database;
use PDO;

/**
 * M3 click-next attempt state.
 *
 * One row per attempt; answers are a JSON array parallel to the quiz questions
 * (null = unanswered). The client never decides correctness.
 */
final class AttemptRepository
{
    private PDO $pdo;

    public function __construct(
        ?PDO $pdo = null,
        private readonly ArtifactRepository $artifacts = new ArtifactRepository(),
    ) {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return array<string,mixed> */
    public function start(int $artifactId, int $userId): array
    {
        $artifact = $this->quiz($artifactId);

        $existing = $this->pdo->prepare(
            'SELECT * FROM assessment_attempts
              WHERE artifact_id = :artifact AND user_id = :user AND status = \'in_progress\'
              ORDER BY id DESC LIMIT 1'
        );
        $existing->execute(['artifact' => $artifactId, 'user' => $userId]);
        $row = $existing->fetch();

        if ($row !== false) {
            return self::hydrate($row);
        }

        $questions = $artifact['content']['questions'];
        $now = gmdate('c');

        $this->pdo->prepare(
            'INSERT INTO assessment_attempts
                (artifact_id, user_id, project_id, status, current_index, answers,
                 score, total, feedback, created_at, updated_at)
             VALUES
                (:artifact, :user, :project, \'in_progress\', 0, :answers,
                 0, :total, NULL, :now, :now_updated)'
        )->execute([
            'artifact'    => $artifactId,
            'user'        => $userId,
            'project'     => $artifact['project_id'],
            'answers'     => json_encode(array_fill(0, count($questions), null)),
            'total'       => count($questions),
            'now'         => $now,
            'now_updated' => $now,
        ]);

        return $this->find((int) $this->pdo->lastInsertId()) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assessment_attempts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function latestFor(int $artifactId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assessment_attempts
              WHERE artifact_id = :artifact AND user_id = :user
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['artifact' => $artifactId, 'user' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Records an answer and returns server-side feedback. Re-answering the same
     * question is idempotent (the first committed answer wins).
     *
     * @return array<string,mixed>
     */
    public function answer(int $attemptId, int $userId, int $questionIndex, int $optionIndex): array
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM assessment_attempts WHERE id = :id AND user_id = :user FOR UPDATE'
        );
        $stmt->execute(['id' => $attemptId, 'user' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }

            throw new \InvalidArgumentException('Unknown attempt.');
        }

        $attempt = self::hydrate($row);

        if ($attempt['status'] === 'completed') {
            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $this->result($attempt, $questionIndex);
        }

        $artifact = $this->quiz((int) $attempt['artifact_id']);
        $questions = $artifact['content']['questions'];

        if (!isset($questions[$questionIndex])) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }

            throw new \InvalidArgumentException('Question index out of range.');
        }

        $question = $questions[$questionIndex];
        $optionCount = count($question['options']);

        if ($optionIndex < 0 || $optionIndex >= $optionCount) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }

            throw new \InvalidArgumentException('Option index out of range.');
        }

        $answers = $attempt['answers'];
        $already = $answers[$questionIndex] !== null;

        if (!$already) {
            $answers[$questionIndex] = $optionIndex;
        }

        $score = 0;
        $feedback = [];

        foreach ($questions as $index => $q) {
            if (($answers[$index] ?? null) === null) {
                continue;
            }

            $correct = (int) $answers[$index] === (int) $q['answer_index'];

            if ($correct) {
                $score++;
            }

            $feedback[] = [
                'index'            => $index,
                'selected_index'   => (int) $answers[$index],
                'correct_index'    => (int) $q['answer_index'],
                'correct'          => $correct,
                'explanation'      => (string) $q['explanation'],
            ];
        }

        $answered = count(array_filter($answers, static fn ($a): bool => $a !== null));
        $completed = $answered >= count($questions);
        $status = $completed ? 'completed' : 'in_progress';

        $this->pdo->prepare(
            'UPDATE assessment_attempts
                SET answers = :answers, score = :score, total = :total,
                    status = :status, current_index = :current, feedback = :feedback,
                    updated_at = :now
              WHERE id = :id'
        )->execute([
            'answers'  => json_encode(array_values($answers)),
            'score'    => $score,
            'total'    => count($questions),
            'status'   => $status,
            'current'  => $answered,
            'feedback' => json_encode($feedback),
            'now'      => gmdate('c'),
            'id'       => $attemptId,
        ]);

        $attempt = $this->find($attemptId) ?? $attempt;

        if ($ownTransaction) {
            $this->pdo->commit();
        }

        return $this->result($attempt, $questionIndex);
    }

    /** @return array<string,mixed> */
    private function result(array $attempt, int $questionIndex): array
    {
        $artifact = $this->quiz((int) $attempt['artifact_id']);
        $question = $artifact['content']['questions'][$questionIndex] ?? null;

        if ($question === null) {
            return [
                'attempt'  => $attempt,
                'complete' => $attempt['status'] === 'completed',
            ];
        }

        $selected = $attempt['answers'][$questionIndex] ?? null;

        return [
            'attempt'         => $attempt,
            'question_index'  => $questionIndex,
            'selected_index'  => $selected,
            'correct_index'   => (int) $question['answer_index'],
            'correct'         => $selected !== null && (int) $selected === (int) $question['answer_index'],
            'explanation'     => (string) $question['explanation'],
            'complete'        => $attempt['status'] === 'completed',
            'score'           => (int) $attempt['score'],
            'total'           => (int) $attempt['total'],
        ];
    }

    /** @return array<string,mixed> */
    private function quiz(int $artifactId): array
    {
        $artifact = $this->artifacts->find($artifactId);

        if (
            $artifact === null
            || $artifact['module'] !== 'M3'
            || $artifact['type'] !== 'quiz'
            || !is_array($artifact['content']['questions'] ?? null)
        ) {
            throw new \InvalidArgumentException('That artifact is not an answerable quiz.');
        }

        return $artifact;
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['artifact_id'] = (int) $row['artifact_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
        $row['current_index'] = (int) $row['current_index'];
        $row['score'] = $row['score'] === null ? null : (int) $row['score'];
        $row['total'] = (int) $row['total'];
        $row['answers'] = is_string($row['answers'] ?? null)
            ? (json_decode((string) $row['answers'], true) ?: [])
            : [];
        $row['feedback'] = is_string($row['feedback'] ?? null)
            ? (json_decode((string) $row['feedback'], true) ?: [])
            : [];

        return $row;
    }
}
