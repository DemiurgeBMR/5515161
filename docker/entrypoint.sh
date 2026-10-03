#!/bin/sh
# Перед стартом Apache — прогоняем миграции (database/migrate.php сам
# ведёт таблицу _schema_migrations и применяет только новые файлы, так
# что безопасно вызывать его при каждом старте контейнера).
set -e

echo "[entrypoint] Ожидаю базу данных ($DB_HOST)..."
until php -r '
    $h = getenv("DB_HOST"); $n = getenv("DB_NAME");
    $u = getenv("DB_USER"); $p = getenv("DB_PASS") ?: "";
    try {
        new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4", $u, $p);
    } catch (PDOException $e) {
        exit(1);
    }
'; do
    sleep 2
done
echo "[entrypoint] База данных доступна."

php /var/www/html/database/migrate.php

exec "$@"
