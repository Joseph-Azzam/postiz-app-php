# Postiz Backend Test

This document describes how to run the backend health-check test: what it checks, how to launch it, and which parameters to use.

---

## What the test does

The test runs **synchronous checks** only; it does not modify data. Available suites:

| Suite | What it checks |
|-------|----------------|
| **Full** | Database, application key, Copilot config, AI (OpenAI + Gemini), Social (LinkedIn, X, Bluesky). |
| **Gemini only** | Copilot config, Gemini key, then: config block, **text (chat)** with ai_model, **writer** with writer_model, **image** (config or optional live Imagen when “Test image generation” is on). |
| **DB & config** | Database connection, app key, Copilot config. |
| **Social only** | LinkedIn, X, Bluesky auth URL / form flow. |

- **AI (OpenAI/Gemini):** Sends one short prompt per provider; shows model, prompt, and pass/fail. On 429/quota, a short suggestion is shown (e.g. try another model or enable billing).
- **Exit code (Artisan):** `0` if all run tests passed, `1` if any failed (suitable for scripts/CI).

---

## How to run: web dashboard (recommended)

Open the Laravel test dashboard in your browser:

```
https://your-backend-url/test
```

Example (local): `http://localhost/.../public/test` or your Laravel app URL + `/test`.

- **Menu:** Switch between **Full**, **Gemini only**, **DB & config**, **Social only**.
- **Parameters:** “Skip AI calls” (full suite), “Test image generation (Imagen)” (Gemini suite only, off by default), optional key if `POSTIZ_TEST_KEY` is set.
- **Refresh:** “Refresh with current parameters” re-runs the selected suite with the current options.
- **Results:** Each check is shown with pass/fail, model, prompt (for AI), and any error suggestion.

### Optional: protect the /test URL

Set in `.env`:

```env
POSTIZ_TEST_KEY=your-secret-token
```

Then open: `https://your-backend-url/test?key=your-secret-token`. If the key is set and `?key=` is missing or wrong, the dashboard returns 403.

---

## How to run: Artisan (CLI, optional)

From the Laravel app directory:

```bash
cd apps/backend-laravel
php artisan postiz:test
```

Options:

| Option | Description |
|--------|-------------|
| **(none)** | Run full suite with streaming output. |
| `--no-ai` | Skip OpenAI and Gemini. |
| `--json` | Output a single JSON report: `{ "ok": true|false, "tests": [ { "name", "pass", "message" }, ... ] }`. |

Examples:

```bash
php artisan postiz:test
php artisan postiz:test --no-ai
php artisan postiz:test --json
```

---

## Optional environment variable (web dashboard)

| Variable | Purpose |
|----------|---------|
| `POSTIZ_TEST_KEY` | When set, the **/test** dashboard requires `?key=<value>` to match. If the key is set and the query parameter is missing or wrong, the server returns 403. |

Example in `.env`:

```env
# Optional: protect /test when using the web dashboard
# POSTIZ_TEST_KEY=your-secret-token
```

Then open: `https://your-backend-url/test?key=your-secret-token`

---

## Failure messages

- **AI tests** show the last error and, when possible, a short suggestion (e.g. invalid key, quota, network/SSL). For Gemini 429, a suggestion may recommend another model or billing.
- **Social tests** report “Not configured” when credentials are missing; that is treated as **PASS**. Only unexpected errors are FAIL.

---

## Files involved

| File | Role |
|------|------|
| `GET /test` (Laravel route) | Web test dashboard; uses PostizTestRunner. |
| `apps/backend-laravel/app/Http/Controllers/TestDashboardController.php` | Serves the /test page. |
| `apps/backend-laravel/app/Services/PostizTestRunner.php` | All test logic (DB, config, AI, Gemini detailed, social). |
| `apps/backend-laravel/app/Console/Commands/PostizBackendTestCommand.php` | Artisan command `postiz:test`; uses PostizTestRunner for CLI/CI. |
| `apps/backend-laravel/public/test-gemini.php` | Redirects to `/test?suite=gemini` (backward compatibility). |
