<?php

/**
 * Redirect to the unified test dashboard (Gemini suite).
 * Kept for backward compatibility: old links to test-gemini.php go to /test?suite=gemini.
 */

$base = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if ($base !== '/' && $base !== '') {
    $base .= '/';
} else {
    $base = '/';
}
$query = 'suite=gemini';
if (!empty($_GET['key']) && is_string($_GET['key'])) {
    $query .= '&key=' . rawurlencode($_GET['key']);
}
header('Location: ' . $base . 'test?' . $query, true, 302);
exit;
