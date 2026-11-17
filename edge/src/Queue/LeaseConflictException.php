<?php

declare(strict_types=1);

namespace Nico\Edge\Queue;

/**
 * The caller no longer holds a valid lease on the job.
 *
 * Covers an unknown lease token, a different worker, an expired lease, and a job
 * that has already reached a terminal state.
 */
final class LeaseConflictException extends \RuntimeException
{
}
