<?php

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|--------------------------------------------------------------------------
| Root environment loading (Postiz centralised env)
|--------------------------------------------------------------------------
|
| Env is loaded from the project ROOT (not apps/backend-laravel) so that
| frontend and Laravel share the same .env, .env.local, .env.production.
|
| Load order (we do NOT stop at APP_ENV; each file is loaded in full):
| 1) Load the ENTIRE root .env → every line (APP_ENV, X_API_KEY, etc.) goes into $_ENV.
| 2) Read APP_ENV from $_ENV only to choose which overlay FILE to load next.
| 3) Load the ENTIRE overlay file (.env.local or .env.production).
|    - Each key in the overlay overwrites that key in $_ENV; other keys are unchanged.
|    - So X_API_KEY from .env stays unless .env.local also defines X_API_KEY= (then it gets overwritten).
*/

$basePath = dirname(__DIR__);
$rootPath = dirname($basePath, 2);

// 1) Load root .env first (all base values).
if (file_exists($rootPath.'/.env')) {
    Dotenv::createMutable($rootPath, '.env')->safeLoad();
}

// 2) Use APP_ENV from .env to choose which overlay file to load.
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'local';

// 3) Overlay env-specific file: only keys in this file override; others stay from .env.
if ($appEnv === 'local' && file_exists($rootPath.'/.env.local')) {
    Dotenv::createMutable($rootPath, '.env.local')->safeLoad();
} elseif ($appEnv === 'production' && file_exists($rootPath.'/.env.production')) {
    Dotenv::createMutable($rootPath, '.env.production')->safeLoad();
}

return Application::configure(basePath: $basePath)
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Auto-discover Artisan command classes (cron-safe, short-lived).
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
