#!/usr/bin/env bash
#
# Резервная копия сайта на сервере: база данных, загруженные фото и файл .env.
# Запускать из любой папки на сервере:  bash ~/riveg-rent/database/backup.sh
#
# Что получится: папка ~/riveg-backups/ГГГГ-ММ-ДД_ЧЧММ/ и рядом один архив
# с тем же именем (.tar) — его удобно скачать на свой компьютер.
#
# Справочник городов (_cities, _countries, _regions, ~3 ГБ) в копию НЕ входит:
# он загружается заново из database/seed/cities.sql на вашем компьютере.
#
# Внутри копии лежит .env с паролями и ключами. Никому не отправляйте
# архив и не кладите его в Git.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

STAMP="$(date +%Y-%m-%d_%H%M)"
ROOT="$HOME/riveg-backups"
DIR="$ROOT/$STAMP"

mkdir -p "$DIR"
chmod 700 "$ROOT" "$DIR"

# Если что-то пошло не так — не оставляем недоделанную копию, чтобы её
# нельзя было принять за настоящую.
DONE=0
cleanup() {
    if [ "$DONE" != 1 ]; then
        rm -rf "$DIR" "$ROOT/$STAMP.tar"
        echo "ОШИБКА: копия не создана. Не замораживайте сервер, пришлите в чат текст выше." >&2
    fi
}
trap cleanup EXIT

SITE_HOST="$(sed -n 's#^SITE_URL=https\{0,1\}://\([^/]*\).*#\1#p' .env | head -n 1)"
SITE_HOST="${SITE_HOST:-IP-СЕРВЕРА}"

echo "==> База данных"
docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" \
    --single-transaction --routines --triggers --no-tablespaces \
    --ignore-table="$MYSQL_DATABASE._cities" \
    --ignore-table="$MYSQL_DATABASE._countries" \
    --ignore-table="$MYSQL_DATABASE._regions" \
    "$MYSQL_DATABASE"' 2> >(grep -v "Using a password" >&2) | gzip > "$DIR/database.sql.gz"

gzip -t "$DIR/database.sql.gz"
if ! gzip -dc "$DIR/database.sql.gz" | tail -n 3 | grep -q "Dump completed"; then
    echo "Копия базы получилась неполной." >&2
    exit 1
fi

echo "==> Загруженные фото и файлы"
docker compose exec -T app tar -C /var/www/html -czf - public_html/uploads private_uploads > "$DIR/files.tar.gz"
gzip -t "$DIR/files.tar.gz"

echo "==> Настройки (.env)"
cp .env "$DIR/env.backup"
chmod 600 "$DIR/env.backup"

echo "==> Собираю один архив"
tar -C "$ROOT" -cf "$ROOT/$STAMP.tar" "$STAMP"
chmod 600 "$ROOT/$STAMP.tar"

DONE=1

echo
echo "Готово. Размеры:"
du -h "$DIR"/* "$ROOT/$STAMP.tar"
echo
echo "Архив на сервере: $ROOT/$STAMP.tar"
echo "Скачать на свой компьютер (команда для окна Git Bash на вашем компьютере, не на сервере;"
echo "архив ляжет в вашу домашнюю папку, не в папку сайта):"
echo "scp root@$SITE_HOST:riveg-backups/$STAMP.tar ~/"
