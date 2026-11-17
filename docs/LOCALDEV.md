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
| `./dev.sh serve` | Start the app on :8080 with a 20 MB upload limit |
| `./dev.sh stop` | Stop the dev server |
| `./dev.sh test` | Verify Sprint 1, M0, and M1–M8 workflow checks |
| `./dev.sh test:m0` | M0 platform checks only |
| `./dev.sh test:modules` | M1–M8 workflow checks only |
| `./dev.sh test:interfaces` | Slice A two-app flow only |
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
    Ai/               Ollama client and AI interface
    Auth/             sessions, user repository, ownership
    Http/             front controller, routing, responses
    Knowledge/        sources, chunks, PDF ingestion, vectors, hybrid search
    Generation/       template + Ollama quiz/lesson/report generation
    Projects/         projects and membership
    Tasks/            tasks and assignment
    Activities/       events, hackathons, teams, submissions
    Support/          config, database, logging, migrations, hashing
  storage/
    files/            uploaded sources (outside the webroot in production)
    vectors/          local embedding files (outside the webroot)
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

PDF uploads use `STORAGE_PATH` (default `storage/files`) relative to the `edge/`
directory, which must be writable by the PHP user. On cPanel, verify the real
`upload_max_filesize` / `post_max_size` values; the app enforces the effective
ceiling and exposes it at `/health`. pdf.js is vendored under
`public/assets/vendor/pdfjs/`, so no build step is needed. `pdftotext` is only an
optional server-side re-check; when it is unavailable, the browser-extracted
text is used.

### Ollama (local development)

`edge/.env` can point at a reachable Ollama OpenAI-compatible endpoint:

```bash
OLLAMA_ENABLED=1
OLLAMA_BASE_URL=http://127.0.0.1:11434/v1
OLLAMA_API_KEY=
OLLAMA_EMBED_MODEL=nomic-embed-text:latest
OLLAMA_CHAT_MODEL=qwen141:latest
```

Embeddings are stored under `VECTOR_PATH` (default `storage/vectors`) and referenced from
`knowledge_refs`. Generation uses strict JSON validation and falls back to
`nico-template-v1` if Ollama is unreachable or returns invalid JSON. A private
LAN address works locally but not from cPanel; production needs a reachable
endpoint or the worker path. Tests set `OLLAMA_ENABLED=0` so the suite never
depends on the local model server.

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
