# CORS preflight: "Redirect is not allowed for a preflight request"

## What you see

- Frontend (e.g. `http://localhost:4200`) calls the API (e.g. `http://localhost/.../api/auth/oauth/GENERIC`).
- Browser sends an **OPTIONS** preflight first (cross-origin + custom headers).
- Error: **"Response to preflight request doesn't pass access control check: Redirect is not allowed for a preflight request."**

## Cause

The server that handles the request is **redirecting** the OPTIONS request (e.g. 302 to login or to another URL). For CORS, the preflight **must** get a **200** (or 204) with CORS headers (`Access-Control-Allow-Origin`, etc.), not a redirect.

Common causes:

1. **Auth / guest middleware** redirects unauthenticated requests to a login page. OPTIONS is unauthenticated, so it gets redirected.
2. **Web server** (Apache/nginx) or **.htaccess** redirects (e.g. HTTP→HTTPS, or add trailing slash) before the app runs.
3. **CORS middleware** not running, or running after a redirect.

## Fix (Laravel backend that serves the API)

### 1. Use this app’s CORS config

- This app’s `config/cors.php` is set up for the Postiz frontend (e.g. `http://localhost:4200`) and `supports_credentials => true`.
- If your API is in another Laravel app (e.g. postiz-php), copy or adapt that `config/cors.php` there and set `allowed_origins` (and optional `CORS_ALLOWED_ORIGINS` in `.env`).

### 2. Do not redirect OPTIONS

- **Laravel:** `HandleCors` must run early and must handle OPTIONS (return 200 + CORS headers). Do **not** redirect OPTIONS in auth/guest middleware.
- **Auth middleware:** Either:
  - Exclude OPTIONS from auth (e.g. in the middleware: `if (request()->isMethod('OPTIONS')) return $next($request);` before any redirect), or
  - Ensure CORS middleware runs **before** auth so OPTIONS is answered before any redirect.
- **Web server / .htaccess:** Do not redirect OPTIONS; let the app respond.

### 3. Verify

- OPTIONS `https://your-api/api/auth/oauth/GENERIC` (or the real URL) should return **200** (or 204) with headers such as:
  - `Access-Control-Allow-Origin: http://localhost:4200`
  - `Access-Control-Allow-Methods: ...`
  - `Access-Control-Allow-Headers: ...`
  - `Access-Control-Allow-Credentials: true` (if the frontend sends credentials)

If the backend you’re calling is **this** Laravel app (backend-laravel), the published `config/cors.php` and Laravel’s default `HandleCors` are enough as long as no other middleware or server rule redirects OPTIONS.
