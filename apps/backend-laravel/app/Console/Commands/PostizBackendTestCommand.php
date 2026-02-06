<?php

namespace App\Console\Commands;

use App\Services\PostizTestRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Run backend health checks: DB, app key, AI (OpenAI/Gemini), social OAuth URLs.
 * Run: php artisan postiz:test (from apps/backend-laravel).
 *
 * The main way to run tests is the web dashboard: GET /test (see BACKEND-TEST.md).
 * All test logic lives in PostizTestRunner; this command and the dashboard both use it.
 * AI tests run async (in subprocesses) while social tests run; results are merged at the end.
 * Use --only=openai or --only=gemini to run a single AI test (outputs JSON; used internally).
 */
class PostizBackendTestCommand extends Command
{
    protected $signature = 'postiz:test
                            {--json : Output machine-readable JSON report}
                            {--no-ai : Skip OpenAI/Gemini live API calls}
                            {--only= : Run only one AI test: openai or gemini (outputs JSON; used internally for async)}';

    protected $description = 'Run backend connectivity tests (DB, AI, social providers) and print a report';

    public function handle(PostizTestRunner $runner): int
    {
        $noAi = (bool) $this->option('no-ai');
        $json = (bool) $this->option('json');
        $only = $this->option('only');

        if ($only !== null && $only !== '') {
            $only = strtolower($only);
            if ($only === 'openai') {
                $result = $runner->runOpenAi();
                $this->line(json_encode($result));

                return 0;
            }
            if ($only === 'gemini') {
                $result = $runner->runGeminiSimple();
                $this->line(json_encode($result));

                return 0;
            }
            $this->error('--only must be openai or gemini');

            return 1;
        }

        $results = [];
        $stream = ! $json;

        if ($stream) {
            $this->newLine();
            $this->line('========== Postiz Backend Test Report ==========');
            $this->newLine();
        }

        $results[] = $runner->runDatabase();
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }

        $results[] = $runner->runAppKey();
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }

        $results[] = $runner->runCopilotConfig();
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }

        $openaiProc = null;
        $geminiProc = null;
        if (! $noAi) {
            $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $artisan = base_path('artisan');
            $openaiProc = Process::path(base_path())->timeout(120)->start([$php, $artisan, 'postiz:test', '--only=openai']);
            $geminiProc = Process::path(base_path())->timeout(120)->start([$php, $artisan, 'postiz:test', '--only=gemini']);
        } else {
            $results[] = ['name' => 'AI (OpenAI)', 'pass' => null, 'message' => 'Skipped (--no-ai)'];
            $results[] = ['name' => 'AI (Gemini)', 'pass' => null, 'message' => 'Skipped (--no-ai)'];
            if ($stream) {
                $this->printResultLine($results[count($results) - 2]);
                $this->printResultLine($results[count($results) - 1]);
            }
        }

        $results[] = $runner->runSocialProvider('linkedin');
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }
        $results[] = $runner->runSocialProvider('x');
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }
        $results[] = $runner->runSocialProvider('bluesky');
        if ($stream) {
            $this->printResultLine($results[count($results) - 1]);
        }

        if ($openaiProc !== null && $geminiProc !== null) {
            if ($stream) {
                $this->line('  (waiting for AI tests…)');
            }
            $openaiProc->wait();
            $openaiRow = $this->parseSubprocessResult($openaiProc->output(), 'AI (OpenAI)');
            if ($stream) {
                $this->printResultLine($openaiRow);
            }
            $geminiProc->wait();
            $geminiRow = $this->parseSubprocessResult($geminiProc->output(), 'AI (Gemini)');
            if ($stream) {
                $this->printResultLine($geminiRow);
            }
            array_splice($results, 3, 0, [$openaiRow, $geminiRow]);
        }

        if ($json) {
            $this->line(json_encode([
                'ok' => ! in_array(false, array_column($results, 'pass'), true),
                'tests' => $results,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return collect($results)->contains(fn ($r) => $r['pass'] === false) ? 1 : 0;
        }

        $passed = collect($results)->whereStrict('pass', true)->count();
        $failed = collect($results)->whereStrict('pass', false)->count();
        $skipped = collect($results)->filter(fn ($r) => ($r['pass'] ?? null) === null)->count();
        $this->newLine();
        $this->line('Summary: '.$passed.' passed, '.$failed.' failed'.($skipped > 0 ? ', '.$skipped.' skipped' : '').'.');
        $this->line('================================================');
        $this->newLine();

        return collect($results)->contains(fn ($r) => $r['pass'] === false) ? 1 : 0;
    }

    private function printResultLine(array $r): void
    {
        $name = $r['name'];
        $pass = $r['pass'] ?? null;
        $msg = $r['message'] ?? '';
        if ($pass === true) {
            $this->line('  [PASS] '.$name.($msg !== '' ? ' — '.$msg : ''));
        } elseif ($pass === false) {
            $this->error('  [FAIL] '.$name.($msg !== '' ? ' — '.$msg : ''));
        } else {
            $this->line('  [SKIP] '.$name.($msg !== '' ? ' — '.$msg : ''));
        }
    }

    private function parseSubprocessResult(string $output, string $defaultName): array
    {
        $lines = array_filter(explode("\n", $output));
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, '{')) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return [
                        'name' => $decoded['name'] ?? $defaultName,
                        'pass' => $decoded['pass'] ?? false,
                        'message' => $decoded['message'] ?? '',
                    ];
                }
            }
        }

        return ['name' => $defaultName, 'pass' => false, 'message' => 'Subprocess failed or no output'];
    }
}
