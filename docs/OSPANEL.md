# Установка на компьютер с Open Server Panel 6

Инструкция для нового компьютера с Windows, на котором только что установлен
Open Server Panel (OSP). В конце на компьютере будут работать два сайта:

- **http://riveg-rent/** — основной сайт;
- **http://hotel/** — сайт гостиницы (папка `hotel/`).

Пути ниже даны для OSP в `C:\OSPanel`. Если он стоит в другом месте, например
в `D:\OSPanel`, подставляйте свой путь.

Если на каком-то шаге что-то пошло не так — запустите проверку
`php database\doctor.php` (шаг 6) и пришлите её вывод в чат с Claude.

## 1. Что нужно установить

**Open Server Panel 6** — уже стоит. Проверьте, что в нём есть нужные модули:
откройте папку `C:\OSPanel\modules`. Там должны быть папки:

- `Apache`;
- `PHP-8.5` (подойдёт любая версия от `PHP-8.2`);
- `MySQL-8.0` — именно MySQL, а не MariaDB: схема базы использует кодировку
  `utf8mb4_0900_ai_ci`, которой в MariaDB нет.

Если чего-то не хватает, запустите установщик OSP ещё раз и отметьте
недостающие модули.

**Git для Windows** — https://git-scm.com/download/win. Нужен, чтобы скачать
код и потом получать обновления. При установке всё можно оставить по
умолчанию. Вместе с Git ставится **Git Bash** — окно с командами, в котором
есть `tar`, `gzip`, `ssh` и `scp` (пригодятся для переноса данных с сервера).

## 2. Скачать проект

Откройте Git Bash (Пуск → Git Bash) и выполните:

    cd /c/OSPanel/home
    git clone https://github.com/DemiurgeBMR/5515161.git riveg-rent

Если репозиторий закрытый, откроется окно входа в GitHub — войдите в свой
аккаунт. Код скачается в `C:\OSPanel\home\riveg-rent`.

## 3. Проверить настройки проекта

Настройки сайтов для OSP лежат в `C:\OSPanel\home\riveg-rent\.osp\project.ini`:

    [riveg-rent]
    http_engine = Apache
    php_engine  = PHP-8.5
    web_root    = {base_dir}\public_html
    environment = MySQL-8.0

    [hotel]
    http_engine = Apache
    php_engine  = PHP-8.5
    web_root    = {base_dir}\hotel\public
    environment = MySQL-8.0

Каждый раздел в квадратных скобках — отдельный сайт, его имя — адрес сайта.
Если в `C:\OSPanel\modules` нет `PHP-8.5`, а есть, например, `PHP-8.4`,
поменяйте `php_engine` в обоих разделах. **Если указать модуль, которого нет,
OSP молча не покажет сайт.**

## 4. Проверить расширения PHP

Откройте Блокнотом файл `C:\OSPanel\config\PHP-8.5\default\templates\php.ini`
(вместо `PHP-8.5` — ваша версия) и убедитесь, что перед этими строками нет `;`:

    extension = curl
    extension = fileinfo
    extension = gd
    extension = mbstring
    extension = openssl
    extension = pdo_mysql

В свежем OSP 6 они уже включены, а лимиты загрузки (`upload_max_filesize`,
`post_max_size`) стоят с запасом — менять обычно ничего не нужно. Если
что-то правили — перезапустите модуль PHP.

## 5. Запустить Open Server

Перезапустите Open Server (закройте программу через значок у часов и
откройте снова) — он найдёт проект. Модули включим на следующем шаге
командами в консоли. Если удобнее мышкой — в меню OSP есть пункт включения
модулей проекта (Enable project modules).

## 6. Создать базу данных

Откройте консоль OSP: значок Open Server у часов → меню → консоль
(Command Line Interface). Это обычная командная строка Windows (CMD).
Выполните по очереди:

    osp on MySQL-8.0
    osp on PHP-8.5
    osp on Apache
    osp project riveg-rent
    cd /d C:\OSPanel\home\riveg-rent
    php database\doctor.php

`doctor.php` проверит PHP, расширения, базу и папки и подскажет, что
исправить. На этом этапе он скажет, что базы ещё нет. Создайте её:

    php database\migrate.php

Скрипт сам создаст пустую базу `riveg_rent` и все таблицы. Запустите
`php database\doctor.php` ещё раз — в базе всё должно быть отмечено [ок].

Подключение к MySQL в OSP по умолчанию: сервер `MySQL-8.0`, пользователь
`root`, пароль пустой. Сайт использует те же значения, ничего настраивать
не нужно.

## 7. Открыть сайт и стать администратором

Откройте в браузере **http://riveg-rent/** — именно так, с `http://` и
слешем в конце, иначе браузер начнёт искать слово в поисковике.

Зарегистрируйтесь. Почта на своём компьютере не отправляется: ссылка для
подтверждения появится прямо на экране. Затем в консоли OSP:

    php database\admin_tool.php set-role ВАШ_EMAIL admin

Теперь открывается админка: http://riveg-rent/admin/.

## Справочник городов

Таблицы городов (`_cities`, `_countries`, `_regions`, около 3 ГБ) в Git не
хранятся. Без них при добавлении места не будет выбора города — остальной
сайт работает.

**Где взять файл:**

- со старого компьютера — `database\seed\cities.sql` в папке проекта;
- или выгрузить с сервера. В Git Bash:

      ssh root@IP-СЕРВЕРА
      cd ~/riveg-rent
      docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --no-create-info "$MYSQL_DATABASE" _countries _regions _cities' | gzip > ~/cities.sql.gz
      exit
      scp root@IP-СЕРВЕРА:cities.sql.gz ~/
      gunzip ~/cities.sql.gz

  Файл окажется в `C:\Users\ВАШЕ_ИМЯ\cities.sql`.

**Как загрузить** — в консоли OSP (после `osp project riveg-rent`; база уже
должна быть создана на шаге 6):

    mysql -u root -h MySQL-8.0 riveg_rent < C:\Users\ВАШЕ_ИМЯ\cities.sql

Загрузка занимает от нескольких минут до получаса. Если команда `mysql` не
находится, выполните сначала `osp add MySQL-8.0`.

## Данные с боевого сайта (по желанию)

Для разработки хватает пустой базы. Если нужны настоящие пользователи и
объявления — снимите копию на сервере по [BACKUP.md](BACKUP.md) и скачайте
архив. Дальше в Git Bash (имя архива — ваше):

    cd ~
    tar -xf 2026-10-28_1200.tar
    gunzip -k 2026-10-28_1200/database.sql.gz
    tar -xzf 2026-10-28_1200/files.tar.gz -C /c/OSPanel/home/riveg-rent

И в консоли OSP — строго в этом порядке:

    php database\migrate.php
    mysql -u root -h MySQL-8.0 riveg_rent < C:\Users\ВАШЕ_ИМЯ\2026-10-28_1200\database.sql
    php database\migrate.php

Первый `migrate.php` создаёт все таблицы, включая таблицы городов (их в копии
нет). Потом копия заменяет остальные таблицы. Второй `migrate.php` применяет
изменения базы, появившиеся после того, как копия была снята. Города
загружаются после этого, как описано выше.

Важно:

- `env.backup` из архива на свой компьютер **не переносите**: там боевые
  ключи ЮKassa и почты. Без них локальный сайт не принимает оплату и не
  рассылает письма — настоящие пользователи ничего не получат.
- В копии — личные данные людей. Не отправляйте её никому и удалите, когда
  закончите.

## Сайт гостиницы

После шага 5 он открывается по адресу **http://hotel/**. Базы данных ему не
нужно. Что заполнить перед запуском, показывает

    php hotel\tools\check.php

Подробности — в `hotel/README.md`.

## Обновить код

В Git Bash:

    cd /c/OSPanel/home/riveg-rent
    git pull

После каждого обновления — в консоли OSP `php database\migrate.php`: он
применит новые изменения базы, если они есть.

## Если что-то не работает

| Что видно | Что делать |
|---|---|
| Сайт не открывается, «не удаётся найти сайт» | Проверьте, что модули из `.osp\project.ini` есть в `C:\OSPanel\modules`, и перезапустите OSP. Антивирус может запрещать OSP менять файл HOSTS — добавьте OSP в исключения. VPN и «безопасный DNS» в браузере тоже мешают локальным адресам — отключите их для этих сайтов |
| `could not find driver` | Не включено расширение `pdo_mysql` (шаг 4) |
| `Unknown collation: 'utf8mb4_0900_ai_ci'` | Используется MariaDB или MySQL 5.7 — нужен модуль MySQL-8.0 |
| «Базовая схема создалась не полностью» | `mysql -u root -h MySQL-8.0 -e "DROP DATABASE riveg_rent"`, затем снова `php database\migrate.php` |
| `getaddrinfo` / `No such host` при подключении к базе | Не включён модуль MySQL-8.0: `osp on MySQL-8.0` |
| Не загружаются фотографии | Лимиты загрузки в `php.ini` (шаг 4) |
| `php` или `mysql` — «не является внутренней или внешней командой» | Сначала `osp project riveg-rent` в консоли OSP |
