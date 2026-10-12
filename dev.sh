#!/usr/bin/env bash
# Local development helpers.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="nico_db"

db_up() { docker exec "$CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; }

case "${1:-help}" in
  db:start)
    if ! docker ps -q -f "name=^${CONTAINER}$" | grep -q .; then
      docker start "$CONTAINER" >/dev/null
      for _ in $(seq 1 40); do db_up && break; sleep 3; done
    fi
    echo "db ready on 127.0.0.1:3306"
    ;;
  db:stop)  docker stop "$CONTAINER" >/dev/null && echo "db stopped" ;;
  db:logs)  docker logs --tail 50 "$CONTAINER" ;;
  migrate)  php "$ROOT/edge/bin/migrate.php" "${2:-migrate}" ;;
  serve)
    (cd "$ROOT/edge" && php -S 127.0.0.1:8080 -t public >/tmp/nico_server.log 2>&1 &)
    echo "edge on http://127.0.0.1:8080  (health: /health)"
    ;;
  stop)     pkill -f "php -S 127.0.0.1:8080" 2>/dev/null; echo "server stopped" ;;
  test)     php "$ROOT/edge/tests/verify_sprint1.php" ;;
  preflight) python3 "$ROOT/worker/preflight.py" "${@:2}" ;;
  *) echo "usage: ./dev.sh {db:start|db:stop|db:logs|migrate [cmd]|serve|stop|test|preflight [args]}" ;;
esac
