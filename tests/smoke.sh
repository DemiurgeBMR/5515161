#!/usr/bin/env bash
#
# Обвязка для tests/smoke.php: проверяет синтаксис всех PHP-файлов
# репозитория, поднимает временную БД и встроенный PHP-сервер, гоняет
# смок-сценарии и убирает сервер за собой. Используется и локально, и в
# CI (.github/workflows/ci.yml) — один и тот же скрипт, чтобы поведение
# не расходилось.
#
# Предполагает уже доступный MySQL-сервер (локально запущенный mysqld
# или сервис-контейнер в CI) — сам процесс MySQL не поднимает и не
# останавливает.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_NAME="${DB_NAME:-riveg_rent_ci}"
export DB_USER="${DB_USER:-root}"
export DB_PASS="${DB_PASS:-}"
SMOKE_PORT="${SMOKE_PORT:-8899}"
export SMOKE_BASE_URL="http://127.0.0.1:${SMOKE_PORT}"

echo "==> Проверка синтаксиса (php -l) по всему репозиторию"
LINT_FAILED=0
while IFS= read -r -d '' f; do
    if ! php -l "$f" > /tmp/rr_smoke_lint.txt 2>&1; then
        cat /tmp/rr_smoke_lint.txt
        LINT_FAILED=1
    fi
done < <(find "$ROOT_DIR" -name '*.php' -not -path '*/.git/*' -print0)

if [ "$LINT_FAILED" -ne 0 ]; then
    echo "==> Найдены синтаксические ошибки, дальше не идём"
    exit 1
fi
echo "==> Синтаксис в порядке"

MYSQL_ARGS=(-h "$DB_HOST" -u "$DB_USER")
if [ -n "$DB_PASS" ]; then
    MYSQL_ARGS+=(-p"$DB_PASS")
fi

echo "==> Готовим базу $DB_NAME"
mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

for f in "$ROOT_DIR"/database/migrations/*.sql; do
    echo "==> Миграция: $(basename "$f")"
    mysql "${MYSQL_ARGS[@]}" "$DB_NAME" < "$f"
done

echo "==> Стартуем встроенный PHP-сервер на $SMOKE_BASE_URL"
php -S 127.0.0.1:"$SMOKE_PORT" -t "$ROOT_DIR/public_html" > /tmp/rr_smoke_server.log 2>&1 &
SERVER_PID=$!

cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Ждём готовности сервера"
READY=0
for i in $(seq 1 20); do
    if curl -s -o /dev/null "$SMOKE_BASE_URL/index.php"; then
        READY=1
        break
    fi
    sleep 0.5
done
if [ "$READY" -ne 1 ]; then
    echo "==> Сервер не поднялся за отведённое время"
    cat /tmp/rr_smoke_server.log
    exit 1
fi

echo "==> Запускаем смок-сценарии"
php "$ROOT_DIR/tests/smoke.php"
