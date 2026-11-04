#!/usr/bin/env bash
# Craft 6 dev environment for this plugin (a Laravel app with craftcms/cms). Idempotent; safe to re-run.
#   dev/dev.sh          setup (if needed) + start server in background
#   dev/dev.sh setup    database, project, Craft and the plugin installed; no seed
#   dev/dev.sh serve    start server in background (http://localhost:8130/admin, admin / password)
#   dev/dev.sh stop     stop server
#   dev/dev.sh seed     sites, sections, the plugin settings, demo content (dev/seed.php)
#   dev/dev.sh smoke    seed, then dev/smoke.php: saves through Craft on the console (defaults, the remembered choice, drafts, sites)
#   dev/dev.sh e2e      start the server and run dev/e2e.php (the control panel over HTTP: edit pages, saves with the switch, drafts, slideouts, settings)
#   dev/dev.sh ui       start the server and run dev/ui.mts (the control panel in headless Chrome: the switch in the slug field, saving)
#   dev/dev.sh screenshots  documentation screenshots into docs/screenshots (dev/screenshots.mts)
#   dev/dev.sh reset    drop DB and reinstall from scratch
#   dev/dev.sh ci       scaffold + install + smoke + e2e + ui against an already running DB (what GitHub Actions runs)
#   dev/dev.sh coverage ci with line coverage of src/ (pcov), then the report: total, files below the bar, HTML
#   dev/dev.sh typecheck  tsc over the TypeScript dev scripts (dev/*.mts; installs typescript into dev/node_modules once)
# Env: PROJECT_DIR (default ../craft-slug-equals-title-dev6), PORT (8130), DB_DRIVER (pgsql|mysql, default pgsql),
#      DB_NAME (default: project dir name), DB_SERVER (127.0.0.1), DB_PORT, DB_USER, DB_PASSWORD (local defaults: no password).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Override PROJECT_DIR/PORT to run several isolated projects side by side (e.g. one per database).
# own defaults: 3.x keeps its Craft 5 project in ${PLUGIN_DIR}-dev on port 8080
PROJECT_DIR="${PROJECT_DIR:-${PLUGIN_DIR}-dev6}"
PORT="${PORT:-8130}"
DB_DRIVER="${DB_DRIVER:-pgsql}"
DB="${DB_NAME:-$(basename "$PROJECT_DIR" | tr '-' '_')}"
DB_SERVER="${DB_SERVER:-127.0.0.1}"
DB_PORT="${DB_PORT:-$([ "$DB_DRIVER" = mysql ] && echo 3306 || echo 5432)}"
DB_USER="${DB_USER:-$([ "$DB_DRIVER" = mysql ] && echo root || echo "$USER")}"
DB_PASSWORD="${DB_PASSWORD:-}"
PIDFILE="$PROJECT_DIR/storage/serve.pid"
# Craft 6 needs PHP 8.5 (Homebrew's `php`; the default `php` of a Mac may be older)
export PATH="/opt/homebrew/opt/php/bin:/opt/homebrew/opt/postgresql@14/bin:/opt/homebrew/opt/mysql@8.0/bin:$PATH"
MYSQL="mysql -u$DB_USER -h$DB_SERVER -P$DB_PORT --protocol=tcp${DB_PASSWORD:+ -p$DB_PASSWORD}"
# COVERAGE=1: every PHP process the suites run (the console scripts, the dev server) prepends dev/coverage.php (pcov,
# line coverage of src/ into storage/coverage); dev/coverage-report.php merges and prints it (no opcache: the built-in
# server uses it, and its optimizer drops lines such as `continue;` from what pcov sees)
PHP=(php -d memory_limit=1G)
ROUTER="$PROJECT_DIR/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
# this project's servers, with either router (a plain one may be running while a coverage run starts): what stop() kills
ROUTERS="$ROUTER|$PLUGIN_DIR/dev/coverage-router.php"
if [ "${COVERAGE:-0}" = 1 ]; then export COVERAGE_PROJECT_DIR="$PROJECT_DIR"; PHP=(php -d memory_limit=1G -d pcov.enabled=1 -d opcache.enable=0 -d "pcov.directory=$PLUGIN_DIR" -d "auto_prepend_file=$PLUGIN_DIR/dev/coverage.php"); ROUTER="$PLUGIN_DIR/dev/coverage-router.php"; fi

db_up() {
  if [ "$DB_DRIVER" = "mysql" ]; then
    mysqladmin -uroot -h127.0.0.1 --protocol=tcp ping >/dev/null 2>&1 || brew services start mysql@8.0 >/dev/null
    for _ in $(seq 1 30); do mysqladmin -uroot -h127.0.0.1 --protocol=tcp ping >/dev/null 2>&1 && break; sleep 1; done
    $MYSQL -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
  else
    pg_isready -q || brew services start postgresql@14 >/dev/null
    for _ in $(seq 1 20); do pg_isready -q && break; sleep 1; done
    psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$DB'" | grep -q 1 || createdb "$DB"
  fi
}

db_drop() {
  if [ "$DB_DRIVER" = "mysql" ]; then $MYSQL -e "DROP DATABASE IF EXISTS \`$DB\`"; else dropdb --if-exists "$DB"; fi
}

scaffold() {
  [ -d "$PROJECT_DIR" ] && return
  composer create-project "craftcms/craft:^6.0.0-alpha.1" "$PROJECT_DIR" --no-interaction --no-scripts
  cd "$PROJECT_DIR"
  [ -f composer.json.default ] && mv composer.json.default composer.json
  composer config repositories.slug-equals-title "{\"type\":\"path\",\"url\":\"$PLUGIN_DIR\",\"options\":{\"symlink\":true}}"
  composer config minimum-stability dev
  composer config prefer-stable true
  composer require internetztube/craft-slug-equals-title:@dev -W --no-interaction
  composer require --dev phpunit/php-code-coverage --no-interaction
  cp .env.example .env
  local driver; driver=$([ "$DB_DRIVER" = mysql ] && echo mysql || echo pgsql)
  sed -i.bak -e "s|^DB_CONNECTION=.*|DB_CONNECTION=$driver|" -e "s|^#* *DB_HOST=.*|DB_HOST=$DB_SERVER|" -e "s|^#* *DB_PORT=.*|DB_PORT=$DB_PORT|" \
    -e "s|^#* *DB_DATABASE=.*|DB_DATABASE=$DB|" -e "s|^#* *DB_USERNAME=.*|DB_USERNAME=$DB_USER|" -e "s|^#* *DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
    -e "s|^APP_URL=.*|APP_URL=http://localhost:$PORT|" -e "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=database|" .env && rm -f .env.bak
  grep -q '^DB_HOST=' .env || printf 'DB_HOST=%s\nDB_PORT=%s\nDB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB_SERVER" "$DB_PORT" "$DB" "$DB_USER" "$DB_PASSWORD" >> .env
  # the German site's transliteration (ß → ss, ü → ue) is testable with ASCII slugs
  grep -q '^CRAFT_LIMIT_AUTO_SLUGS_TO_ASCII=' .env || echo 'CRAFT_LIMIT_AUTO_SLUGS_TO_ASCII=true' >> .env
  php artisan key:generate --ansi
  composer dump-autoload --quiet
}

install() {
  cd "$PROJECT_DIR"
  composer show phpunit/php-code-coverage >/dev/null 2>&1 || composer require --dev phpunit/php-code-coverage --no-interaction --quiet
  if ! php artisan craft:install:check >/dev/null 2>&1; then
    php artisan craft:install --no-interaction --email=admin@example.com --username=admin --password=password \
      --siteName="Transit" --siteUrl="http://localhost:$PORT" --language=en-US --timezone=UTC
  fi
  php artisan craft:plugin:install slug-equals-title --no-interaction >/dev/null 2>&1 || true
}

install_plugins() {
  cd "$PROJECT_DIR"
  grep -v '^#' "$PLUGIN_DIR/dev/plugins.txt" | grep -q . || return 0
  grep -v '^#' "$PLUGIN_DIR/dev/plugins.txt" | xargs composer require --no-interaction --quiet
}
# seed twice: schema created in the first process is only fully usable in a fresh one (see ensureEntry in seed.php)
seed()  { install_plugins && php artisan craft:migrate:all --no-interaction >/dev/null && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR"; }
# runs the queued jobs until none is left (Laravel's database queue)
queue() { (cd "$PROJECT_DIR" && "${PHP[@]}" artisan queue:work --stop-when-empty --tries=1 --quiet); }
# the tests write plugin settings in console processes; write the project config YAML back afterwards so the control
# panel does not report pending changes
syncpc() { (cd "$PROJECT_DIR" && php artisan craft:project-config:write >/dev/null 2>&1) || true; }
smoke() { seed && "${PHP[@]}" "$PLUGIN_DIR/dev/smoke.php" "$PROJECT_DIR"; local rc=$?; syncpc; return $rc; }
e2e()   { local rc=0; serve && php "$PLUGIN_DIR/dev/e2e.php" "$PROJECT_DIR" "http://localhost:$PORT" || rc=$?; stop; syncpc; return $rc; }
# the control panel in a real browser (headless Chrome), dev/ui.mts: what a person sees and does
ui()    { local rc=0; serve && node "$PLUGIN_DIR/dev/ui.mts" "$PROJECT_DIR" "http://localhost:$PORT" || rc=$?; stop; syncpc; return $rc; }
screenshots() { serve && queue && (cd "$PLUGIN_DIR" && node dev/screenshots.mts "$PROJECT_DIR" "http://localhost:$PORT" docs/screenshots); }

# the server and its workers share one command line (the router names this project): kill them by it, so workers
# the parent already lost (orphans) go too, and wait until the port is free for the next serve()
listening() { (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; }
stop() {
  pkill -f -- "-S localhost:$PORT ($ROUTERS)" 2>/dev/null || true
  rm -f "$PIDFILE"
  for _ in $(seq 1 20); do listening || break; sleep 0.25; done
  listening && pkill -9 -f -- "-S localhost:$PORT ($ROUTERS)" 2>/dev/null || true
}

serve() {
  stop
  cd "$PROJECT_DIR"
  # several workers: the control panel loads its pages' data in parallel requests (one worker hangs on them)
  (cd "$PROJECT_DIR/public" && PHP_CLI_SERVER_WORKERS=6 nohup "${PHP[@]}" -S localhost:$PORT "$ROUTER" > "$PROJECT_DIR/storage/serve.log" 2>&1 & echo $! > "$PIDFILE")
  for _ in $(seq 1 20); do curl -sf -o /dev/null "http://localhost:$PORT/admin/login" && break; sleep 1; done
  # another project on the port would answer instead (several dev projects run side by side)
  if grep -q "Failed to listen" "$PROJECT_DIR/storage/serve.log"; then echo "Port $PORT is in use by another server; set PORT" >&2; return 1; fi
  echo "Craft running at http://localhost:$PORT/admin  (admin / password)  log: $PROJECT_DIR/storage/serve.log"
}

# ci logs to storage/ci.log; dev/tests-md.php turns the "ok" lines into dev/TESTS.md, the list of what is tested
ci() { scaffold; install; { smoke && e2e && ui; } 2>&1 | tee "$PROJECT_DIR/storage/ci.log"; local rc=${PIPESTATUS[0]}; php "$PLUGIN_DIR/dev/tests-md.php" "$PROJECT_DIR/storage/ci.log" > "$PLUGIN_DIR/dev/TESTS.md"; return $rc; }
# the dev scripts are TypeScript, run by Node 24 as they are (type stripping); this checks their types
typecheck() { (cd "$PLUGIN_DIR/dev" && { [ -x node_modules/.bin/tsc ] || npm install --no-package-lock --no-audit --no-fund; } && node_modules/.bin/tsc -p .); }
coverage() {
  [ "${COVERAGE:-0}" = 1 ] || COVERAGE=1 exec "$0" coverage
  rm -rf "${PROJECT_DIR:?}/storage/coverage"
  local rc=0
  ci || rc=$? # set -e: report the coverage of a failing run too
  php "$PLUGIN_DIR/dev/coverage-report.php" "$PROJECT_DIR" && return $rc
}

case "${1:-up}" in
  up)    db_up; scaffold; install; seed; serve ;;
  setup) db_up; scaffold; install ;;
  serve) serve ;;
  stop)  stop ;;
  queue) queue ;;
  seed)  seed ;;
  smoke) smoke ;;
  reset) stop; db_drop; rm -rf "${PROJECT_DIR:?}/config/project"; db_up; install; seed; serve ;; # the YAML too: the seed rebuilds everything
  e2e)   e2e ;;
  ui)    ui ;;
  screenshots) screenshots ;;
  ci)    ci ;;
  coverage) coverage ;;
  typecheck) typecheck ;;
  *)     sed -n "2,17p" "$0"; exit 1 ;;
esac
