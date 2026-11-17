<?php
/** @var array<string,mixed> $activity */
/** @var list<array<string,mixed>> $teams */
/** @var array<string,mixed>|null $my_team */
/** @var array{going:int,waitlist:int,my_status:?string} $rsvp */
/** @var list<array<string,mixed>> $submissions */
/** @var bool $can_manage */
/** @var bool $can_read_project */
$meta = $activity['meta'] ?? [];
$isHackathon = $activity['kind'] === 'hackathon';
$joinUrl = safe_url($activity['join_url'] ?? null);
$rulesUrl = safe_url($activity['rules_url'] ?? null);
?>
<section class="page-head">
    <div>
        <p class="eyebrow">M6 <?= e($activity['kind']) ?></p>
        <h1><?= e($activity['title']) ?></h1>
        <p class="muted"><?= e($activity['description'] ?: 'No description yet.') ?></p>
        <div class="row">
            <span class="pill"><?= e($activity['status']) ?></span>
            <span class="pill"><?= e($activity['visibility']) ?></span>
            <?php if ($activity['starts_at'] !== null): ?>
                <span class="pill countdown" data-countdown="<?= e($activity['starts_at']) ?>"><?= e($activity['starts_at']) ?> UTC</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <?php if ($can_manage): ?>
            <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/status" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <select name="status">
                    <?php foreach (['draft', 'scheduled', 'live', 'completed', 'cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $activity['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">Update status</button>
            </form>
        <?php endif; ?>
        <?php if (!$isHackathon): ?>
            <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/rsvp" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="button primary" type="submit">
                    <?= $rsvp['my_status'] === 'going' ? 'You are going' : ($rsvp['my_status'] === 'waitlist' ? 'On waitlist' : 'RSVP') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>

<div class="grid two">
    <section class="panel">
        <h2>Details</h2>
        <dl class="details">
            <dt>Starts</dt><dd><?= e($activity['starts_at'] ?? 'TBD') ?> UTC</dd>
            <dt>Ends</dt><dd><?= e($activity['ends_at'] ?? 'TBD') ?> UTC</dd>
            <dt>Location</dt><dd><?= e($activity['location'] ?: 'TBD') ?></dd>
            <dt>Timezone</dt><dd><?= e($activity['timezone']) ?></dd>
            <?php if ($joinUrl !== null): ?>
                <dt>Join</dt><dd><a href="<?= e($joinUrl) ?>" rel="noopener noreferrer"><?= e($joinUrl) ?></a></dd>
            <?php endif; ?>
            <?php if ($rulesUrl !== null): ?>
                <dt>Rules</dt><dd><a href="<?= e($rulesUrl) ?>" rel="noopener noreferrer">Open rules</a></dd>
            <?php endif; ?>
            <?php if ($activity['capacity'] !== null): ?>
                <dt>Capacity</dt><dd><?= (int) $activity['capacity'] ?></dd>
            <?php endif; ?>
            <?php if (!$isHackathon): ?>
                <dt>Going</dt><dd><?= $rsvp['going'] ?> · waitlist <?= $rsvp['waitlist'] ?></dd>
                <?php if (!empty($meta['speaker_name'])): ?>
                    <dt>Speaker</dt><dd><?= e($meta['speaker_name']) ?></dd>
                <?php endif; ?>
                <?php if (!empty($meta['speaker_bio'])): ?>
                    <dt>Bio</dt><dd><?= e($meta['speaker_bio']) ?></dd>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($meta['tracks'])): ?>
                    <dt>Tracks</dt><dd><?= e($meta['tracks']) ?></dd>
                <?php endif; ?>
                <?php if (!empty($meta['prizes'])): ?>
                    <dt>Prizes</dt><dd><?= e($meta['prizes']) ?></dd>
                <?php endif; ?>
                <?php if (!empty($meta['team_size_max'])): ?>
                    <dt>Team size</dt><dd>up to <?= (int) $meta['team_size_max'] ?></dd>
                <?php endif; ?>
            <?php endif; ?>
        </dl>

        <?php if (!empty($meta['agenda'])): ?>
            <h3><?= $isHackathon ? 'Timeline / milestones' : 'Agenda' ?></h3>
            <div class="prose"><?= nl2br(e($meta['agenda'])) ?></div>
        <?php endif; ?>

        <?php if (!empty($meta['resources'])): ?>
            <h3>Resources</h3>
            <div class="prose"><?= nl2br(e($meta['resources'])) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($isHackathon): ?>
        <section class="panel">
            <div class="panel-head">
                <h2>Teams</h2>
                <?php if ($my_team !== null): ?>
                    <span class="pill">Your team: <?= e($my_team['name']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($teams === []): ?>
                <p class="empty">No teams yet. Create the first one.</p>
            <?php else: ?>
                <ul class="list">
                    <?php foreach ($teams as $team): ?>
                        <li>
                            <span><strong><?= e($team['name']) ?></strong>
                                <span class="muted"><?= (int) $team['member_count'] ?> member(s)</span>
                            </span>
                            <?php if ($my_team === null): ?>
                                <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/join" class="inline">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                                    <button class="link" type="submit">Join</button>
                                </form>
                            <?php endif; ?>
                        </li>
                        <li class="muted">
                            <?= e(implode(', ', array_column($team['members'], 'email'))) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <details class="disclosure">
                <summary>Create a team</summary>
                <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/teams" class="stack">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label>Team name <input name="name" required maxlength="191"></label>
                    <button class="primary" type="submit">Create team</button>
                </form>
            </details>
        </section>
    <?php endif; ?>
</div>

<div class="grid two">
    <section class="panel">
        <h2><?= $isHackathon ? 'Submit a project' : 'Add a note or Q&A item' ?></h2>
        <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/submissions" class="stack">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <?php if ($isHackathon && $my_team !== null): ?>
                <input type="hidden" name="team_id" value="<?= (int) $my_team['id'] ?>">
            <?php endif; ?>
            <label>Title <input name="title" required maxlength="255"></label>
            <label><?= $isHackathon ? 'Description' : 'Note / question' ?><textarea name="content" rows="4"></textarea></label>
            <button class="primary" type="submit"><?= $isHackathon ? 'Submit' : 'Add note' ?></button>
        </form>
    </section>

    <section class="panel">
        <h2><?= $isHackathon ? 'Submissions' : 'Notes & Q&A' ?></h2>
        <?php if ($submissions === []): ?>
            <p class="empty">Nothing recorded yet.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($submissions as $submission): ?>
                    <li>
                        <strong><?= e($submission['title']) ?></strong>
                        <span class="muted">
                            <?= e($submission['team_name'] ?? $submission['email']) ?>
                            · <?= e($submission['created_at']) ?>
                        </span>
                        <?php if ($submission['content']): ?>
                            <p class="muted"><?= e($submission['content']) ?></p>
                        <?php endif; ?>
                        <?php if ($can_read_project && !empty($submission['artifact_id'])): ?>
                            <a class="muted" href="/app/artifacts/<?= (int) $submission['artifact_id'] ?>">Captured artifact</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php if ($can_manage): ?>
    <?php
    $toLocal = static fn (?string $utc): string => $utc === null ? '' : str_replace(' ', 'T', substr($utc, 0, 16));
    $meta = is_array($activity['meta'] ?? null) ? $activity['meta'] : [];
    ?>
    <section class="panel">
        <details class="disclosure">
            <summary>Edit activity</summary>
            <form method="post" action="/app/activities/<?= (int) $activity['id'] ?>/edit" class="stack">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label>Title <input name="title" required maxlength="255" value="<?= e($activity['title']) ?>"></label>
                <label>Description <textarea name="description" rows="2"><?= e($activity['description'] ?? '') ?></textarea></label>
                <div class="grid two tight">
                    <label>Starts <input type="datetime-local" name="starts_at" value="<?= e($toLocal($activity['starts_at'])) ?>"></label>
                    <label>Ends <input type="datetime-local" name="ends_at" value="<?= e($toLocal($activity['ends_at'])) ?>"></label>
                </div>
                <div class="grid two tight">
                    <label>Timezone <input name="timezone" value="<?= e($activity['timezone'] ?? 'UTC') ?>" maxlength="64"></label>
                    <label>Status
                        <select name="status">
                            <?php foreach (\Nico\Edge\Activities\ActivityRepository::STATUSES as $status): ?>
                                <option value="<?= e($status) ?>" <?= $activity['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="grid two tight">
                    <label>Location <input name="location" value="<?= e($activity['location'] ?? '') ?>"></label>
                    <label>Capacity <input type="number" min="1" name="capacity" value="<?= e((string) ($activity['capacity'] ?? '')) ?>"></label>
                </div>
                <label>Join URL <input type="url" name="join_url" value="<?= e($activity['join_url'] ?? '') ?>"></label>
                <?php if ($isHackathon): ?>
                    <label>Rules URL <input type="url" name="rules_url" value="<?= e($activity['rules_url'] ?? '') ?>"></label>
                <?php endif; ?>
                <label>Visibility
                    <select name="visibility">
                        <option value="workspace" <?= $activity['visibility'] === 'workspace' ? 'selected' : '' ?>>Project members</option>
                        <option value="public" <?= $activity['visibility'] === 'public' ? 'selected' : '' ?>>Any signed-in user with the link</option>
                    </select>
                </label>
                <?php if ($isHackathon): ?>
                    <div class="grid two tight">
                        <label>Team size max <input type="number" min="1" max="100" name="team_size_max" value="<?= e((string) ($meta['team_size_max'] ?? 4)) ?>"></label>
                        <label>Tracks <input name="tracks" value="<?= e($meta['tracks'] ?? '') ?>"></label>
                    </div>
                    <label>Prizes <input name="prizes" value="<?= e($meta['prizes'] ?? '') ?>"></label>
                <?php else: ?>
                    <label>Speaker name <input name="speaker_name" value="<?= e($meta['speaker_name'] ?? '') ?>"></label>
                    <label>Speaker bio <textarea name="speaker_bio" rows="2"><?= e($meta['speaker_bio'] ?? '') ?></textarea></label>
                <?php endif; ?>
                <label>Agenda / timeline <textarea name="agenda" rows="4"><?= e($meta['agenda'] ?? '') ?></textarea></label>
                <label>Resources / links <textarea name="resources" rows="3"><?= e($meta['resources'] ?? '') ?></textarea></label>
                <button class="primary" type="submit">Save changes</button>
            </form>
        </details>
    </section>
<?php endif; ?>
