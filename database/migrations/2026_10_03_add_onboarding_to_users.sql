-- Интерактивное обучение для новых пользователей (оператор и собственник):
-- пошаговая подсветка интерфейса, которую можно пропустить и пройти заново
-- (см. assets/js/rr-tour.js, api/onboarding.php, rr_onboarding_*() в config.php).
--
-- Прогресс хранится на users, а не в localStorage браузера: «новый
-- пользователь» — это свойство аккаунта, а не устройства, и обучение не должно
-- всплывать заново после выхода из аккаунта или при входе с другого устройства.
--
--   onboarding_status  — pending (ещё не предлагали) → in_progress (идёт) →
--                        completed (прошёл до конца) | skipped (пропустил).
--                        Из любого состояния можно перезапустить → in_progress.
--   onboarding_chapter — на какой странице-«главе» остановился (0, 1, ...):
--                        обучение идёт по нескольким страницам, и при
--                        возврате на сайт оно продолжается с неё, а не с нуля.
--
-- Все уже существующие аккаунты помечаются как 'skipped': им обучение
-- автоматически не показывается (оно для новых пользователей), но запустить
-- его вручную можно в любой момент — меню аккаунта → «Обучение». Для этого
-- колонка сначала создаётся с DEFAULT 'skipped' (так заполняются старые
-- строки), а затем значение по умолчанию меняется на 'pending' — для всех,
-- кто зарегистрируется после этой миграции.
ALTER TABLE `users`
    ADD COLUMN `onboarding_status` enum('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'skipped' AFTER `privacy_consent_at`,
    ADD COLUMN `onboarding_chapter` tinyint unsigned NOT NULL DEFAULT 0 AFTER `onboarding_status`;

ALTER TABLE `users`
    ALTER COLUMN `onboarding_status` SET DEFAULT 'pending';
