# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Стек

- **WordPress** (PHP 7.4+, требует WP 6.0+)
- **Тема:** Astra + Elementor Pro (визуальный редактор)
- **Плагины:** кастомный `umi-marketplace` (основная бизнес-логика), ACF Pro, Yoast SEO, Classic Editor
- **БД:** MySQL, стандартные таблицы WordPress + 4 кастомные таблицы плагина

## Кастомный плагин: umi-marketplace

Весь код бизнес-логики находится в `wp-content/plugins/umi-marketplace/`.

### Архитектура

Точка входа — `umi-marketplace.php`, создаёт синглтон `Umi_Marketplace`, который подключает все классы и вызывает `::hooks()` на каждом из них через `init` и `plugins_loaded`.

| Класс | Файл | Назначение |
|---|---|---|
| `Umi_Database` | `class-umi-database.php` | Создание и миграция кастомных таблиц |
| `Umi_Roles` | `class-umi-roles.php` | Роли (`umi_buyer`, `umi_seller`) и capabilities |
| `Umi_Settings` | `class-umi-settings.php` | Настройки плагина в `wp_options` (ключ `umi_mp_settings`) |
| `Umi_Balance` | `class-umi-balance.php` | Баланс долей пользователя (usermeta `umi_share_balance`) |
| `Umi_Ledger` | `class-umi-ledger.php` | Журнал операций с долями (`wp_umi_ledger`) |
| `Umi_Cpt` | `class-umi-cpt.php` | Регистрация CPT: `umi_service`, `umi_product`, `umi_deal`, `umi_review` |
| `Umi_Deals` | `class-umi-deals.php` | Логика сделок: статусы, участники, суммы |
| `Umi_Chat` | `class-umi-chat.php` | Чат: треды и сообщения (`wp_umi_chat_threads`, `wp_umi_chat_messages`, `wp_umi_chat_read_state`) |
| `Umi_Email_Verification` | `class-umi-email-verification.php` | Подтверждение email при регистрации |
| `Umi_Shortcodes` | `class-umi-shortcodes.php` | Все frontend-шорткоды (регистрация, кабинет, каталог, чат и т.д.) |
| `Umi_Ajax` | `class-umi-ajax.php` | AJAX-обработчики (чат, загрузка файлов, избранное) |
| `Umi_Admin` | `class-umi-admin.php` | Колонки/поля в wp-admin (пользователи, сделки, журнал) |
| `Umi_Assets` | `class-umi-assets.php` | Подключение JS/CSS |
| `Umi_Favorites` | `class-umi-favorites.php` | Избранное |
| `Umi_Reviews` | `class-umi-reviews.php` | Отзывы |
| `Umi_Limits` | `class-umi-limits.php` | Лимиты объявлений на пользователя |
| `Umi_Capabilities` | `class-umi-capabilities.php` | Дополнительные проверки прав |
| `Umi_Access` | `class-umi-access.php` | Ограничение доступа к разделам |

### Кастомные таблицы БД

- `wp_umi_ledger` — журнал операций с долями
- `wp_umi_chat_threads` — треды чата (listing-тред и dispute-тред)
- `wp_umi_chat_messages` — сообщения чата
- `wp_umi_chat_read_state` — курсор прочтения по пользователям

Миграции запускаются автоматически через `Umi_Database::maybe_install()` при `plugins_loaded`. Версия схемы хранится в `umi_mp_db_version`.

### Ключевые usermeta пользователя

| Ключ | Значение |
|---|---|
| `umi_share_balance` | Баланс долей (целое число) |
| `umi_email_verified` | `0` — не подтверждён, `1` — подтверждён, отсутствует — старый пользователь (пропускается) |
| `umi_email_verify_key` | Хеш одноразового ключа подтверждения |
| `umi_phone` | Телефон (заполняется при регистрации, редактируется в профиле) |
| `umi_profile_city` | Город |
| `umi_profile_profession` | Профессия |
| `umi_limit_services_override` | Индивидуальный лимит услуг (переопределяет дефолт из настроек) |
| `umi_limit_products_override` | Индивидуальный лимит товаров |

### Шорткоды (фронтенд)

Все UI-блоки реализованы как шорткоды в `class-umi-shortcodes.php`:

- `[umi_register]` — регистрация с подтверждением email
- `[umi_login]` — вход + повторная отправка письма подтверждения
- `[umi_user_cabinet]` — личный кабинет (баланс, сделки, диалоги, объявления)
- `[umi_services]` / `[umi_products]` — каталоги с фильтрами
- `[umi_listing_card]` — карточка объявления со встроенным чатом
- `[umi_deals]` — список сделок пользователя
- `[umi_favorites]` — избранное
- `[umi_seller_profile]` — публичный профиль продавца
- `[umi_balance]`, `[umi_unread_badge]`, `[umi_header_toolbar]` — виджеты шапки

### AJAX-эндпоинты

Все обработчики в `Umi_Ajax`, nonce-действие `umi_mp_chat`:

- `umi_mp_chat_poll` — получение новых сообщений
- `umi_mp_chat_send` — отправка сообщения
- `umi_mp_upload` / `umi_mp_dispute_upload` — загрузка изображений
- `umi_mp_favorite` — переключение избранного
- `umi_mp_unread` — счётчик непрочитанных
- `umi_mp_alert_admin` — уведомление администратора (доступно без авторизации)

### Роли и права

- `umi_buyer` — покупатель (создаётся по умолчанию при регистрации)
- `umi_seller` — продавец (назначается через шорткод "Стать продавцом")
- Административная capability: `manage_umi_marketplace` — доступ к разделу UMI в wp-admin

### Статусы сделок

`negotiated` → `waiting_shares` → `paid_shares` / `paid_rub` → `completed` (в любой момент → `dispute`)

### Настройки плагина (wp-admin → UMI → Настройки)

- Курс рублей к долям (пополнение и вывод)
- Лимиты объявлений по умолчанию
- Интервал опроса чата (мс)
- Email для уведомлений о модерации
- ID пользователя-администратора чата поддержки
- ID страницы профиля продавца

## Где искать код

- Бизнес-логика, шорткоды, AJAX, admin-UI — только в `wp-content/plugins/umi-marketplace/`
- Frontend JS — `wp-content/plugins/umi-marketplace/assets/umi-public.js`
- Визуальные шаблоны страниц — Elementor (через wp-admin → Страницы)
- Тема: `wp-content/themes/astra/` (не модифицируется напрямую)