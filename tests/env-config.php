<?php
declare(strict_types=1);
require_once __DIR__.'/../backend/config/env.php';
$key = 'NUTRIFIT_ENV_TEST_'.bin2hex(random_bytes(8));
function checkEnv(bool $ok): void { if (!$ok) throw new RuntimeException('Environment precedence test failed'); }
try {
    checkEnv(env($key, 'default-value') === 'default-value');
    putenv($key.'=test-value');
    checkEnv(env($key, 'default-value') === 'test-value');
    putenv($key.'=');
    checkEnv(env($key, 'default-value') === '');
    putenv($key);
    checkEnv(env($key, 'default-value') === 'default-value');
    echo "PASS: process environment, empty override, default, and removal. No real keys used.\n";
} finally { putenv($key); }
