<?php

/**
 * Bootstrap for the MySQL suite.
 *
 * Runs before anything boots the application, because the connection has to be
 * settled by then -- Laravel reads its configuration once, and `.env` is loaded
 * immutably, so a value already present wins and a value set later is ignored.
 *
 * The connection comes from `.env.mysql-testing` (see `.env.mysql-testing.example`),
 * or from the same variables already exported in the shell. Nothing is invented
 * here: if neither is present the suite reports itself as not ready and each test
 * skips, rather than quietly borrowing the application's own database.
 */
$root = dirname(__DIR__, 2);

$put = function (string $key, string $value): void {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

$source = static function (string $file): array {
    if (! is_file($file)) {
        return [];
    }

    $values = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

        // Quotes are how a password with a space or a `#` in it survives the
        // line, so they are stripped here rather than becoming part of it.
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
};

$file = $root.'/.env.mysql-testing';

$settings = $source($file);

// An exported variable wins over the file, so CI can pass one in and not keep a
// copy of the credentials at all.
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $fromEnvironment = getenv($key);

    if (is_string($fromEnvironment) && $fromEnvironment !== '') {
        $settings[$key] = $fromEnvironment;
    }
}

$put('DB_CONNECTION', 'mysql');
$put('MYSQL_TEST_READY', isset($settings['DB_DATABASE']) ? '1' : '0');

foreach ($settings as $key => $value) {
    $put($key, $value);
}

require $root.'/vendor/autoload.php';
