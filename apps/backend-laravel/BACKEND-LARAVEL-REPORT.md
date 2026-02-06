# apps/backend-laravel – Code Map & Report

The **Laravel backend** used when `NEXT_PUBLIC_BACKEND_TYPE=laravel`. This is the app you run on WAMP/Krystal and the one to **tweak and expand** for backend activity.

---

## Where the code lives

### Application logic (tweak and expand here)

| Path | Purpose |
|------|--------|
| **`app/Http/Controllers/Api/*.php`** | API controllers – one per domain (auth, posts, integrations, media, etc.). **Main place to add or change API behaviour.** |
| **`app/Console/Commands/*.php`** | Artisan commands run by **cron** (scheduled posts, repeats, digests, autopost, token refresh, missing posts, streaks). **Extend or add cron-driven logic here.** |
| **`app/Models/*.php`** | Eloquent models (ScheduledPost, User, IntegrationToken, etc.). **Add relationships, scopes, and domain logic here.** |
| **`app/Services/*.php`** | Reusable services (e.g. CopilotKit agent, social OAuth). **Business logic that doesn’t belong in controllers.** |
| **`app/Support/PostizLogger.php`** | Structured logging helper (cron, state transitions). **Use for debug/audit logging.** |
| **`app/Providers/AppServiceProvider.php`** | App-level service registration and boot. |
| **`routes/api.php`** | **API route definitions** – maps URLs to controllers. **Add or change routes here.** |
| **`routes/console.php`** | Artisan command registration (minimal). |
| **`config/postiz.php`** | Postiz-specific config (debug logging, cron batch size, retry backoff, etc.). **Tune cron and logging here.** |
| **`database/migrations/*.php`** | Schema migrations. **Change or add tables here.** |
| **`database/seeders/`** | Optional seed data (e.g. DatabaseSeeder). |

### Bootstrap and config (edit only when needed)

| Path | Purpose |
|------|--------|
| **`bootstrap/app.php`** | Loads env from **project root** (`.env` → `.env.local` or `.env.production`). Entry for Laravel. |
| **`config/*.php`** | Laravel config (app, auth, database, queue, logging, etc.). |
| **`app/Console/Kernel.php`** | Registers cron commands; **schedule is intentionally empty** – system cron runs commands. |

### Entry point and static assets

| Path | Purpose |
|------|--------|
| **`public/index.php`** | Web entry point (do not edit under normal use). |
| **`public/.htaccess`** | Apache rewrite rules for Laravel. |
| **`resources/`** | Views, JS/CSS (minimal – API-only app). |

### Docs (reference)

| Path | Purpose |
|------|--------|
| **`docs/`** | Optional docs (e.g. AI copilot, CORS, media upload). |

---

## What each part does

### API routes (`routes/api.php`)

- **User:** `user/self`, organizations, change-org, personal, email-notifications.
- **Auth:** `auth/oauth/{provider}`, register, login, activate, resend-activation.
- **Copilot:** `copilot/chat`, `copilot/agent`, `copilot/list`, `copilot/{id}/list`.
- **Media:** `media`, `media/upload-server`.
- **Settings:** `settings/shortlink` (get/update).
- **Notifications:** `notifications/list`, `notifications`.
- **Integrations:** `integrations`, `integrations/list`, `integrations/social/{id}`, connect, plug list, internal-plugs.
- **Third-party:** `third-party/list`, `third-party`.
- **Signatures / Sets:** `signatures/default`, `sets`.
- **Posts:** `posts/` (index, store, find-slot, tags, group, delete group, update date, retry).
- **Repeats:** `repeats/group/{group}`, store, pause, resume.
- **Digest:** `digest/events` (post/get).
- **Autopost:** `autopost/rules` (get/post), disable, enable.
- **Tokens:** `tokens` (get/post).
- **Missing:** `missing/entries`.
- **Streaks:** `streaks`.

### Console commands (cron jobs)

| Command | Role |
|--------|------|
| **ProcessScheduledPosts** | Publish due scheduled posts (batch). |
| **ProcessRepeatPosts** | Process repeat rules. |
| **ProcessNotificationDigests** | Send notification digests. |
| **ProcessAutopost** | Run autopost rules. |
| **ProcessTokenRefresh** | Refresh integration tokens. |
| **ProcessMissingPosts** | Handle missing post monitoring. |
| **ProcessStreakReminders** | Send streak reminders. |
| **PostizCheckEnv** | Optional env check. |

Invoke via system cron, e.g. `* * * * * cd /path && php artisan postiz:process-scheduled-posts` (and similar for other commands). See `docs/CRON-AND-POSTING.md` for setup and logging.

### Controllers (summary)

- **AuthController** – OAuth link, register, login, activate, resend activation.
- **UserController** – Self, organizations, change org, personal, email notifications.
- **ScheduledPostController** – Posts CRUD, find-slot, groups, retry, date.
- **PostRepeatRuleController** – Repeats by group, store, pause, resume.
- **NotificationDigestController** – Digest events.
- **NotificationController** – Notifications list/index.
- **AutopostRuleController** – Rules index, store, disable, enable.
- **IntegrationTokenController** – Tokens index, store.
- **IntegrationsListController** – Channels, list, plug list, internal plugs.
- **IntegrationsSocialController** – Social integration URL, connect.
- **PostMonitorController** – Missing entries.
- **UserStreakController** – Streaks.
- **CopilotController** – Chat, agent, list, thread list.
- **MediaController** – Media index, upload.
- **SettingsController** – Shortlink get/update.
- **SignatureController** – Default signature.
- **SetsController** – Sets index.
- **TagsController** – Tags CRUD.
- **ThirdPartyListController** – Third-party list.
- **Controller.php** – Base controller (minimal).

---

## Folders to ignore (don’t focus here)

| Folder / file | Reason |
|---------------|--------|
| **`vendor/`** | Composer dependencies; never edit. Reinstall with `composer install`. |
| **`node_modules/`** | NPM/pnpm deps (if present); for Vite/front-end assets only. |
| **`storage/`** | Logs, cache, sessions, framework files. Generated at runtime. |
| **`bootstrap/cache/`** | Cached bootstrap files (config, routes). Cleared with `php artisan optimize:clear`. |
| **`public/build/`** | Vite build output (if used). |
| **`public/hot`** | Vite dev server marker file. |
| **`.env`, `.env.local`, `.env.production`** | Env is in **project root**; not in this app folder. |
| **`tests/`** | Default Laravel tests; extend when adding tests, not core logic. |
| **`database/factories/`** | Model factories for testing. |
| **`resources/views/welcome.blade.php`** | Default welcome view (not used by API). |
| **`phpunit.xml`** | PHPUnit config. |
| **`vite.config.js`** | Front-end build (optional). |
| **`*.log`** | Log files. |

---

## Folders to focus on (tweak and expand)

| Priority | Path | Use for |
|----------|------|---------|
| **1** | **`app/Http/Controllers/Api/`** | New or changed API behaviour. |
| **2** | **`app/Console/Commands/`** | New or changed cron behaviour (scheduled posts, tokens, etc.). |
| **3** | **`app/Models/`** | New or changed models, relationships, and domain logic. |
| **4** | **`routes/api.php`** | New or changed API routes. |
| **5** | **`app/Services/`** | Shared business logic used by controllers or commands. |
| **6** | **`database/migrations/`** | New or changed schema. |
| **7** | **`config/postiz.php`** | Cron batch size, logging, retry behaviour. |
| **8** | **`app/Support/PostizLogger.php`** | Consistent logging format. |

---

## Quick reference

- **Add or change an API endpoint:** `routes/api.php` + `app/Http/Controllers/Api/<Name>Controller.php`.
- **Add or change a cron job:** `app/Console/Commands/<Name>.php` + register in `app/Console/Kernel.php`; run via system cron.
- **Add or change a table:** `database/migrations/` + `app/Models/<Name>.php`.
- **Env and URLs:** Configure in **project root** `.env` / `.env.local` / `.env.production`; Laravel reads from there via `bootstrap/app.php`.
