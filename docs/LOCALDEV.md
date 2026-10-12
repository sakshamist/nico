# Local Development

Setup for the Nico edge application.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `json`, `session`, `openssl`, `mbstring`, `fileinfo`, `hash`
- Docker Desktop
- Python 3.9+ (for the compute preflight)

Check your PHP extensions:

```bash
php -m | grep -E "pdo_mysql|json|session|openssl|mbstring|fileinfo|hash"
```

## Setup

```bash
./dev.sh db:start     # MariaDB 10.11 container on 127.0.0.1:3306
cd edge && cp .env.example .env && cd ..
./dev.sh migrate      # create the schema
./dev.sh serve        # http://127.0.0.1:8080
```

The database is pinned to MariaDB **10.11** to match what cPanel installs by
default, rather than a newer local version — the job queue's atomic claim path
depends on `SELECT ... FOR UPDATE SKIP LOCKED`, and testing against a different
engine would not prove anything.

`.env` holds the local password and is gitignored. `.env.example` is the tracked
template.

## Commands

| Command | Purpose |
|---------|---------|
| `./dev.sh db:start` / `db:stop` | Control the MariaDB container |
| `./dev.sh db:logs` | Tail container logs |
| `./dev.sh migrate` | Apply pending migrations |
| `./dev.sh migrate status` | Show applied vs pending |
| `./dev.sh migrate reset` | Drop the schema (dev only) |
| `./dev.sh serve` | Start the app on :8080 |
| `./dev.sh stop` | Stop the dev server |
| `./dev.sh test` | Verify Sprint 1 acceptance criteria |
| `./dev.sh preflight` | Check the compute machine |
| `./dev.sh preflight --json` | Machine-readable preflight output |

## Layout

```
edge/
  autoload.php        hand-written PSR-4 autoloader (no composer)
  bin/migrate.php     migration CLI
  config/             (reserved for local config)
  migrations/         NN_name.sql, applied in lexical order
  public/             webroot — the only directory that gets uploaded
  src/
    Auth/             sessions, user repository, ownership
    Http/             front controller, routing, responses
    Support/          config, database, logging, migrations, hashing
  storage/
    files/            uploaded sources (outside the webroot in production)
    logs/             structured JSON logs
  tests/              acceptance-criteria verification
worker/
  preflight.py        compute-node environment check
specs/                sprint specs and build status
docs/                 this file
```

## Deploying to cPanel

Upload the **contents of `edge/public/`** into `public_html`, keeping
`src/`, `migrations/`, and `storage/` one level above the webroot so they are not
served.

```
/home/user/nico/            <- src, migrations, storage, .env
/home/user/public_html/     <- index.php, .htaccess, assets/
```

`.htaccess` denies `.env`, `.sql`, and `.log`, and blocks `storage/` as a second
line of defence. The primary control is that those directories sit outside the
webroot entirely.

Run migrations over SSH or the cPanel terminal:

```bash
php /home/user/nico/bin/migrate.php
```

## Troubleshooting

**`Cannot reach the database`** — container not running. `./dev.sh db:start`.

**`Missing required config key: DB_NAME`** — `.env` absent. Copy
`.env.example` to `.env`.

**`There is no active transaction`** — a caller wrapped DDL in a transaction.
MySQL and MariaDB implicitly commit around DDL, so `CREATE TABLE` cannot be
rolled back. Migrations deliberately run statement-by-statement instead.

**`Cannot execute queries while other unbuffered queries are active`** — a
statement's result set was not drained before the next query. Multi-statement
calls like `ANALYZE TABLE` return a result set that must be fetched.

**Index assertions fail after a migration** — `information_schema.STATISTICS` is
cached and lags after DDL. Run `ANALYZE TABLE` first.

**Preflight reports `NONE FITTING`** — not enough available RAM. It sizes against
*available* memory rather than total, and discounts further under swap pressure,
because a model sized against the headline figure gets OOM-killed mid-job.

**Disk below 15 GB** — the processing stack needs it. Check `df -h`. The
preflight gate exists so this is caught before a long install fails halfway.
