# StreamLive V34

Платформа каналов / видео (StreamLive).

## База
Исходник из `474737473473` + правки пачек.

## Пачка 1 — просмотры
Единый счётчик `includes/content_views.php` (`content_view_register`) на:
- channel.php, channel-pc.php, embed*, smotrim, streamtube
- video.php, resource, forum_thread, articles

## Пачка 2
- **Премьера восстановлена** в `video.php` (countdown как в v27)
- Плашка **«Сделано в Grok»** (закрывается, localStorage)
- **WTP / Musicle** — `account_external_ids.php` (шифр AES-256, один раз)
- **Зеркало сайта** — `account_mirror.php` + `cron/mirror_sync.php` (1 аккаунт = 1 зеркало)
- SQL: `sql/migrations/060_wtp_musicle_mirror.sql`

## Важно
Премьеры, schedule (`platforma/studio/schedule.php`), живое расписание — **не удалялись**.

## Cron зеркал
```bash
php cron/mirror_sync.php
```
