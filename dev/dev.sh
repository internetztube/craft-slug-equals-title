#!/usr/bin/env bash
# Craft 5 dev environment for this plugin. Idempotent; safe to re-run.
#   dev/dev.sh          setup (if needed) + start server in background
#   dev/dev.sh serve    start server in background (http://localhost:8080/admin, admin / password)
#   dev/dev.sh stop     stop server
#   dev/dev.sh seed     sites, sections, a category group, a product type (Commerce), the plugin settings, demo content and the demo site's templates (dev/seed.php, dev/templates)
#   dev/dev.sh smoke    seed, then dev/smoke.php: saves through Craft on the console (defaults, the remembered choice, drafts, sites, the migration)
#   dev/dev.sh e2e      start the server and run dev/e2e.php (the control panel over HTTP: edit pages, saves with the switch, drafts, slideouts, settings)
#   dev/dev.sh ui       start the server and run dev/ui.mts (the control panel in headless Chrome: the switch under the slug field, the preview, saving)
#   dev/dev.sh screenshots  documentation screenshots into docs/screenshots (dev/screenshots.mts)
#   dev/dev.sh reset    drop DB and reinstall from scratch
#   dev/dev.sh ci       scaffold + install + smoke + e2e + ui against an already running DB (what GitHub Actions runs)
#   dev/dev.sh coverage ci with line coverage of src/ (pcov), then the report: total, files below the bar, HTML
#   dev/dev.sh typecheck  tsc over the TypeScript dev scripts (dev/*.mts; installs typescript into dev/node_modules once)
# Env: PROJECT_DIR (default ../craft-slug-equals-title-dev), PORT (8080), DB_DRIVER (pgsql|mysql, default pgsql),
#      DB_NAME (default: project dir name), DB_SERVER (127.0.0.1), DB_PORT, DB_USER, DB_PASSWORD (local defaults: no password).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Override PROJECT_DIR/PORT to run several isolated projects side by side (e.g. one per database).
PROJECT_DIR="${PROJECT_DIR:-${PLUGIN_DIR}-dev}"
PORT="${PORT:-8080}"
DB_DRIVER="${DB_DRIVER:-pgsql}"
DB="${DB_NAME:-$(basename "$PROJECT_DIR" | tr '-' '_')}"
DB_SERVER="${DB_SERVER:-127.0.0.1}"
DB_PORT="${DB_PORT:-$([ "$DB_DRIVER" = mysql ] && echo 3306 || echo 5432)}"
DB_USER="${DB_USER:-$([ "$DB_DRIVER" = mysql ] && echo root || echo "$USER")}"
DB_PASSWORD="${DB_PASSWORD:-}"
PIDFILE="$PROJECT_DIR/storage/serve.pid"
export PATH="/opt/homebrew/opt/postgresql@14/bin:/opt/homebrew/opt/mysql@8.0/bin:$PATH"
MYSQL="mysql -u$DB_USER -h$DB_SERVER -P$DB_PORT --protocol=tcp${DB_PASSWORD:+ -p$DB_PASSWORD}"
# COVERAGE=1: every PHP process the suites run (the console scripts, the dev server) prepends dev/coverage.php (pcov,
# line coverage of src/ into storage/coverage); dev/coverage-report.php merges and prints it (no opcache: the built-in
# server uses it, and its optimizer drops lines such as `continue;` from what pcov sees)
PHP=(php)
ROUTER="$PROJECT_DIR/vendor/craftcms/cms/bootstrap/router.php"
if [ "${COVERAGE:-0}" = 1 ]; then export COVERAGE_PROJECT_DIR="$PROJECT_DIR"; PHP=(php -d pcov.enabled=1 -d opcache.enable=0 -d "pcov.directory=$PLUGIN_DIR/src" -d "auto_prepend_file=$PLUGIN_DIR/dev/coverage.php"); ROUTER="$PLUGIN_DIR/dev/coverage-router.php"; fi

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
  composer create-project craftcms/craft "$PROJECT_DIR" --no-interaction --no-scripts
  cd "$PROJECT_DIR"
  composer config repositories.slug-equals-title "{\"type\":\"path\",\"url\":\"$PLUGIN_DIR\",\"options\":{\"symlink\":true}}"
  composer config minimum-stability dev
  composer config prefer-stable true
  composer require internetztube/craft-slug-equals-title:@dev --no-interaction
  composer require --dev phpunit/php-code-coverage --no-interaction
  cat > .env <<ENV
CRAFT_APP_ID=
CRAFT_ENVIRONMENT=dev
CRAFT_SECURITY_KEY=
CRAFT_DEV_MODE=true
CRAFT_ALLOW_ADMIN_CHANGES=true
CRAFT_DISALLOW_ROBOTS=true
CRAFT_LIMIT_AUTO_SLUGS_TO_ASCII=true
CRAFT_DB_DRIVER=$DB_DRIVER
CRAFT_DB_SERVER=$DB_SERVER
CRAFT_DB_PORT=$DB_PORT
CRAFT_DB_DATABASE=$DB
CRAFT_DB_USER=$DB_USER
CRAFT_DB_PASSWORD=$DB_PASSWORD
CRAFT_DB_SCHEMA=public
CRAFT_DB_TABLE_PREFIX=
PRIMARY_SITE_URL=http://localhost:$PORT
ENV
  php craft setup/security-key
  php craft setup/app-id
}

install() {
  cd "$PROJECT_DIR"
  composer show phpunit/php-code-coverage >/dev/null 2>&1 || composer require --dev phpunit/php-code-coverage --no-interaction --quiet
  php craft install/check >/dev/null 2>&1 && return
  php craft install --interactive=0 --email=admin@example.com --username=admin --password=password \
    --siteName="Slug Equals Title Dev" --siteUrl='$PRIMARY_SITE_URL' --language=en-US
}

install_plugins() {
  cd "$PROJECT_DIR"
  # Craft Commerce, for the product type support (dev/plugins.txt); no-op when already required
  xargs composer require --no-interaction --quiet < "$PLUGIN_DIR/dev/plugins.txt"
  for handle in $(php craft plugin/list | awk 'NF>5 && $(NF-1)=="No"{print $(NF-4)}'); do php craft plugin/install "$handle"; done
}
# seed twice: schema created in the first process is only fully usable in a fresh one (see ensureEntry in seed.php)
seed()  { install_plugins && php craft migrate/all --no-backup --interactive=0 && cp "$PLUGIN_DIR"/dev/templates/*.twig "$PROJECT_DIR/templates/" && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR" && php "$PLUGIN_DIR/dev/seed.php" "$PROJECT_DIR"; }
# the tests write plugin settings in console processes; write the project config YAML back afterwards so the control
# panel does not report pending changes
syncpc() { (cd "$PROJECT_DIR" && php craft project-config/write >/dev/null 2>&1) || true; }
smoke() { seed && "${PHP[@]}" "$PLUGIN_DIR/dev/smoke.php" "$PROJECT_DIR"; local rc=$?; syncpc; return $rc; }
e2e()   { serve && php "$PLUGIN_DIR/dev/e2e.php" "$PROJECT_DIR" "http://localhost:$PORT"; local rc=$?; stop; syncpc; return $rc; }
# the control panel in a real browser (headless Chrome), dev/ui.mts: what a person sees and does
ui()    { serve && node "$PLUGIN_DIR/dev/ui.mts" "$PROJECT_DIR" "http://localhost:$PORT"; local rc=$?; stop; syncpc; return $rc; }
# queue first: a pending job (Commerce's catalog pricing) shows an indicator in the control panel
screenshots() { serve && (cd "$PROJECT_DIR" && php craft queue/run >/dev/null) && (cd "$PLUGIN_DIR" && node dev/screenshots.mts "$PROJECT_DIR" "http://localhost:$PORT" docs/screenshots); }

stop() { [ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; }

serve() {
  stop
  cd "$PROJECT_DIR"
  nohup "${PHP[@]}" -S localhost:$PORT -t "$PROJECT_DIR/web" "$ROUTER" > storage/serve.log 2>&1 &
  echo $! > "$PIDFILE"
  for _ in $(seq 1 10); do curl -sf -o /dev/null "http://localhost:$PORT/admin/login" && break; sleep 1; done
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
  serve) serve ;;
  stop)  stop ;;
  seed)  seed ;;
  smoke) smoke ;;
  reset) stop; db_drop; rm -rf "${PROJECT_DIR:?}/config/project"; db_up; install; seed; serve ;; # the YAML too: the seed rebuilds everything
  e2e)   e2e ;;
  ui)    ui ;;
  screenshots) screenshots ;;
  ci)    ci ;;
  coverage) coverage ;;
  typecheck) typecheck ;;
  *)     sed -n "2,16p" "$0"; exit 1 ;;
esac
