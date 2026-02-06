<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Debug: show where .env is loaded from and whether X (Twitter) / LinkedIn env vars are present.
 * Run: php artisan postiz:check-env
 */
class PostizCheckEnv extends Command
{
    protected $signature = 'postiz:check-env';

    protected $description = 'Show env load path and whether X_API_KEY / X_API_SECRET are present (for debugging “not configured”)';

    public function handle(): int
    {
        $basePath = base_path();
        $rootPath = dirname($basePath, 2);
        $envPath = $rootPath.DIRECTORY_SEPARATOR.'.env';
        $envExists = file_exists($envPath);

        $this->line('Env load path (from bootstrap/app.php logic):');
        $this->line('  basePath (backend-laravel): '.$basePath);
        $this->line('  rootPath (project root):    '.$rootPath);
        $this->line('  .env path:                  '.$envPath);
        $this->line('  .env exists:                '.($envExists ? 'yes' : 'NO'));
        $this->newLine();

        $xConfig = Config::get('postiz.integrations.x', []);
        $apiKeyConfig = trim((string) ($xConfig['api_key'] ?? ''));
        $apiSecretConfig = trim((string) ($xConfig['api_secret'] ?? ''));
        $apiKeyEnv = trim((string) ($_ENV['X_API_KEY'] ?? ''));
        $apiSecretEnv = trim((string) ($_ENV['X_API_SECRET'] ?? ''));
        $apiKeyEnvFunc = trim((string) (env('X_API_KEY') ?? ''));
        $apiSecretEnvFunc = trim((string) (env('X_API_SECRET') ?? ''));
        $apiKeyGetenv = trim((string) (getenv('X_API_KEY') ?: ''));
        $apiSecretGetenv = trim((string) (getenv('X_API_SECRET') ?: ''));

        $this->line('X (Twitter) vars:');
        $this->line('  config postiz.integrations.x.api_key:  '.($apiKeyConfig !== '' ? 'set (length '.strlen($apiKeyConfig).')' : 'empty'));
        $this->line('  config postiz.integrations.x.api_secret: '.($apiSecretConfig !== '' ? 'set (length '.strlen($apiSecretConfig).')' : 'empty'));
        $this->line('  $_ENV[X_API_KEY]:                      '.($apiKeyEnv !== '' ? 'set' : 'empty'));
        $this->line('  $_ENV[X_API_SECRET]:                   '.($apiSecretEnv !== '' ? 'set' : 'empty'));
        $this->line('  env(X_API_KEY):                        '.($apiKeyEnvFunc !== '' ? 'set' : 'empty'));
        $this->line('  env(X_API_SECRET):                     '.($apiSecretEnvFunc !== '' ? 'set' : 'empty'));
        $this->line('  getenv(X_API_KEY):                     '.($apiKeyGetenv !== '' ? 'set' : 'empty'));
        $this->line('  getenv(X_API_SECRET):                  '.($apiSecretGetenv !== '' ? 'set' : 'empty'));
        $this->newLine();

        $keyOk = $apiKeyConfig !== '' || $apiKeyEnv !== '' || $apiKeyEnvFunc !== '' || $apiKeyGetenv !== '';
        $secretOk = $apiSecretConfig !== '' || $apiSecretEnv !== '' || $apiSecretEnvFunc !== '' || $apiSecretGetenv !== '';
        if ($keyOk && $secretOk) {
            $this->info('X (Twitter) is configured (key and secret present).');
        } else {
            $this->warn('X (Twitter) is NOT configured: key='.($keyOk ? 'yes' : 'NO').', secret='.($secretOk ? 'yes' : 'NO').'.');
            if (! $envExists) {
                $this->warn('  .env not found at project root. Add X_API_KEY and X_API_SECRET to project root .env or .env.local.');
            } else {
                $this->warn('  .env exists but X vars not seen. Ensure X_API_KEY= and X_API_SECRET= are in project root .env (no leading #).');
            }
        }

        return 0;
    }
}
