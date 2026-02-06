@php
    $query = [
        'suite' => $suite,
        'no_ai' => $noAi ? '1' : '0',
        'test_image' => $testImage ? '1' : '0',
        'post_on_x' => $postOnX ? '1' : '0',
        'post_on_linkedin' => $postOnLinkedin ? '1' : '0',
        'post_on_bluesky' => $postOnBluesky ? '1' : '0',
    ];
    if ((string) $key !== '') {
        $query['key'] = $key;
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postiz backend test</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 0 auto; padding: 1rem 1.5rem; background: #1a1a1d; color: #e4e4e7; min-height: 100vh; }
        h1 { font-size: 1.35rem; margin-bottom: 0.5rem; color: #fafafa; }
        .nav { margin: 1rem 0; padding: 0.75rem; background: #27272a; border-radius: 6px; border: 1px solid #3f3f46; }
        .nav p { margin: 0 0 0.5rem; font-size: 0.9rem; color: #a1a1aa; }
        .nav a { display: inline-block; margin-right: 0.5rem; margin-bottom: 0.25rem; padding: 0.35rem 0.6rem; background: #3f3f46; border: 1px solid #52525b; border-radius: 4px; text-decoration: none; color: #d4d4d8; font-size: 0.9rem; }
        .nav a:hover { background: #52525b; border-color: #71717a; color: #fff; }
        .nav a.active { background: #4338ca; border-color: #6366f1; color: #fff; }
        .params { margin: 1rem 0; padding: 0.75rem; border: 1px solid #3f3f46; border-radius: 6px; background: #27272a; }
        .params label { display: inline-flex; align-items: center; gap: 0.35rem; margin-right: 1rem; font-size: 0.9rem; color: #d4d4d8; }
        .params input[type=text] { padding: 0.35rem 0.5rem; width: 12rem; background: #3f3f46; border: 1px solid #52525b; border-radius: 4px; color: #e4e4e7; }
        .params input[type=text]::placeholder { color: #71717a; }
        .params input[type=checkbox] { accent-color: #6366f1; }
        .params button { padding: 0.4rem 0.8rem; background: #3b82f6; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; }
        .params button:hover { background: #2563eb; }
        .summary { margin: 0.75rem 0; font-size: 0.9rem; color: #a1a1aa; }
        .summary strong { color: #e4e4e7; }
        .block { margin: 1rem 0; padding: 0.75rem; border: 1px solid #3f3f46; border-radius: 6px; background: #27272a; }
        .block h2 { margin: 0 0 0.5rem; font-size: 1rem; font-weight: 600; color: #fafafa; }
        .meta { color: #a1a1aa; font-size: 0.9rem; margin: 0.25rem 0; }
        .meta strong { color: #d4d4d8; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .skip { color: #a1a1aa; }
        .msg { margin: 0.35rem 0; color: #d4d4d8; white-space: pre-wrap; word-break: break-word; }
        .suggestion { margin-top: 0.5rem; padding: 0.5rem; background: #422c1e; border-left: 3px solid #f59e0b; color: #fcd34d; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>Postiz backend test</h1>
    <p class="summary">Ran at {{ now()->format('Y-m-d H:i:s') }} — Suite: <strong>{{ $suite }}</strong>{{ $noAi ? ' (AI calls skipped)' : '' }}{{ ($suite === 'gemini' && $testImage) ? ', image generation on' : '' }}{{ (($suite === 'full' || $suite === 'social') && ($postOnX || $postOnLinkedin || $postOnBluesky)) ? ', post on platform on' : '' }}</p>

    <nav class="nav" aria-label="Test suite">
        <p>Switch test suite</p>
        <a href="{{ route('test.dashboard', array_merge($query, ['suite' => 'full'])) }}" class="{{ $suite === 'full' ? 'active' : '' }}">Full</a>
        <a href="{{ route('test.dashboard', array_merge($query, ['suite' => 'gemini'])) }}" class="{{ $suite === 'gemini' ? 'active' : '' }}">Gemini only</a>
        <a href="{{ route('test.dashboard', array_merge($query, ['suite' => 'db'])) }}" class="{{ $suite === 'db' ? 'active' : '' }}">DB &amp; config</a>
        <a href="{{ route('test.dashboard', array_merge($query, ['suite' => 'social'])) }}" class="{{ $suite === 'social' ? 'active' : '' }}">Social only</a>
    </nav>

    <div class="params">
        <form method="get" action="{{ route('test.dashboard') }}" style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
            <input type="hidden" name="suite" value="{{ $suite }}">
            <label>
                <input type="checkbox" name="no_ai" value="1" {{ $noAi ? 'checked' : '' }}>
                Skip AI calls
            </label>
            @if($suite === 'gemini')
                <label>
                    <input type="checkbox" name="test_image" value="1" {{ $testImage ? 'checked' : '' }}>
                    Test image generation (Imagen)
                </label>
            @endif
            @if($suite === 'full' || $suite === 'social')
                <span style="font-size: 0.85rem; color: #a1a1aa;">Post on platform (off by default; requires logged-in session):</span>
                <label>
                    <input type="checkbox" name="post_on_x" value="1" {{ $postOnX ? 'checked' : '' }}>
                    Post on X (test tweet)
                </label>
                <label>
                    <input type="checkbox" name="post_on_linkedin" value="1" {{ $postOnLinkedin ? 'checked' : '' }}>
                    Post on LinkedIn
                </label>
                <label>
                    <input type="checkbox" name="post_on_bluesky" value="1" {{ $postOnBluesky ? 'checked' : '' }}>
                    Post on Bluesky
                </label>
            @endif
            @if((string) config('postiz.test_key') !== '')
                <label>
                    Key: <input type="text" name="key" value="{{ $key }}" placeholder="POSTIZ_TEST_KEY">
                </label>
            @endif
            <button type="submit">Refresh with current parameters</button>
        </form>
    </div>

    @if(!empty($showSessionHint) && !empty($frontendUrl))
        <div class="suggestion" style="margin-bottom: 1rem;">
            No session was sent to this page, so “Post on platform” was skipped. To run it with your account: open this link <strong>in the same browser where you’re logged in to Postiz</strong> — it will redirect you back here with a one-time session:<br>
            <a href="{{ $frontendUrl }}/test-session?suite={{ urlencode($suite) }}&post_on_x={{ $postOnX ? '1' : '0' }}&post_on_linkedin={{ $postOnLinkedin ? '1' : '0' }}&post_on_bluesky={{ $postOnBluesky ? '1' : '0' }}&no_ai={{ $noAi ? '1' : '0' }}{{ $testImage ? '&test_image=1' : '' }}" target="_blank" rel="noopener" style="color: #fcd34d;">Get one-time test link (opens Postiz app)</a>
            @if((string) $key !== '')
                <br><span style="font-size: 0.9rem;">If you use a test key, add <code>?key=…</code> to the URL after redirect.</span>
            @endif
        </div>
    @endif

    <p class="summary">Summary: {{ $summary['passed'] }} passed, {{ $summary['failed'] }} failed{{ $summary['skipped'] > 0 ? ', ' . $summary['skipped'] . ' skipped' : '' }}.</p>

    @foreach($results as $r)
        <div class="block">
            <h2>
                @php
                    $cls = ($r['pass'] ?? null) === true ? 'pass' : (($r['pass'] ?? null) === false ? 'fail' : 'skip');
                    $label = ($r['pass'] ?? null) === true ? '[PASS]' : (($r['pass'] ?? null) === false ? '[FAIL]' : '[INFO]');
                @endphp
                <span class="{{ $cls }}">{{ $label }}</span>
                {{ $r['name'] }}
            </h2>
            @if(!empty($r['model']) && $r['model'] !== '—')
                <div class="meta">Model: <strong>{{ $r['model'] }}</strong></div>
            @endif
            @if(!empty($r['prompt']))
                <div class="meta">Prompt: <strong>{{ $r['prompt'] }}</strong></div>
            @endif
            <div class="msg">{{ $r['message'] }}</div>
            @if(!empty($r['error_parsed']['suggestion']))
                <div class="suggestion">{{ $r['error_parsed']['suggestion'] }}</div>
            @endif
        </div>
    @endforeach
</body>
</html>
