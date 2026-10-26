<?php

declare(strict_types=1);

/**
 * Local verification without Bitrix (structure, autoload, config sample).
 * Usage: php scripts/verify-structure.php
 */

$baseDir = dirname(__DIR__);

require $baseDir . '/vendor/autoload.php';

$errors = [];

$requiredFiles = [
    'public/index.php',
    'public/.htaccess',
    'composer.json',
    'config.sample.php',
    'AGENTS.md',
    'README.md',
    'src/Bootstrap/BitrixBootstrap.php',
    'src/Server/McpServerFactory.php',
    'src/Tool/IblockTools.php',
    'src/Tool/HighloadTools.php',
    'src/Tool/UserTools.php',
    'src/Service/UserService.php',
];

foreach ($requiredFiles as $file) {
    if (!is_file($baseDir . '/' . $file)) {
        $errors[] = "Missing file: {$file}";
    }
}

if (is_file($baseDir . '/server.php')) {
    $errors[] = 'Legacy server.php must be removed (HTTP-only deployment).';
}

$classes = [
    \BitrixMcp\Config\Config::class,
    \BitrixMcp\Server\McpServerFactory::class,
    \BitrixMcp\Auth\TokenAuthenticator::class,
    \BitrixMcp\Service\IblockService::class,
    \BitrixMcp\Service\HighloadService::class,
    \BitrixMcp\Service\FileSource::class,
    \BitrixMcp\Service\BitrixFileArray::class,
    \BitrixMcp\Tool\IblockTools::class,
    \BitrixMcp\Tool\HighloadTools::class,
    \BitrixMcp\Service\UserService::class,
    \BitrixMcp\Tool\UserTools::class,
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        $errors[] = "Class not autoloaded: {$class}";
    }
}

// Config load should fail gracefully without config.php
try {
    \BitrixMcp\Config\Config::load($baseDir);
    $errors[] = 'Expected Config::load to fail without config.php';
} catch (Throwable $e) {
    // expected
}

$flagsOff = new \BitrixMcp\Config\Config([]);
if ($flagsOff->usersEnabled() || $flagsOff->usersDeleteEnabled()) {
    $errors[] = 'users_enabled and users_delete_enabled must default to false';
}

$deleteOnly = new \BitrixMcp\Config\Config(['users_delete_enabled' => true]);
if ($deleteOnly->usersEnabled() || $deleteOnly->usersDeleteEnabled()) {
    $errors[] = 'users_delete_enabled must stay off when users_enabled is false';
}

$flagsOn = new \BitrixMcp\Config\Config([
    'users_enabled' => true,
    'users_delete_enabled' => true,
]);
if (!$flagsOn->usersEnabled() || !$flagsOn->usersDeleteEnabled()) {
    $errors[] = 'both user flags must be true when set to true';
}

$usersOnly = new \BitrixMcp\Config\Config(['users_enabled' => true]);
if (!$usersOnly->usersEnabled() || $usersOnly->usersDeleteEnabled()) {
    $errors[] = 'users_enabled must not turn on user deletion';
}

$userService = new \BitrixMcp\Service\UserService($usersOnly);
$prepare = new ReflectionMethod(\BitrixMcp\Service\UserService::class, 'prepareFields');
$added = $prepare->invoke($userService, [
    'LOGIN' => 'a',
    'EMAIL' => 'a@b.c',
    'PASSWORD' => 'secret',
    'GROUP_ID' => [2, '3'],
], true);
if (
    ($added['PASSWORD'] ?? '') !== 'secret'
    || ($added['CONFIRM_PASSWORD'] ?? '') !== 'secret'
    || ($added['GROUP_ID'] ?? []) !== [2, 3]
) {
    $errors[] = 'user add must keep PASSWORD, copy CONFIRM_PASSWORD, and normalize GROUP_ID';
}

try {
    $prepare->invoke($userService, ['GROUP_ID' => [1]], false);
    $errors[] = 'administrators group must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

$updated = $prepare->invoke($userService, ['PASSWORD' => 'x'], false);
if (($updated['CONFIRM_PASSWORD'] ?? '') !== 'x') {
    $errors[] = 'PASSWORD-only update must copy CONFIRM_PASSWORD';
}

try {
    $prepare->invoke($userService, ['ADMIN' => 'Y'], false);
    $errors[] = 'unknown user field must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

$filter = new ReflectionMethod(\BitrixMcp\Service\UserService::class, 'sanitizeFilter');
try {
    $filter->invoke($userService, ['PASSWORD' => 'x']);
    $errors[] = 'PASSWORD filter must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

if ($errors !== []) {
    fwrite(STDERR, "VERIFY FAILED:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "OK: structure and autoload verified.\n";
echo "Next: copy config.sample.php to config.php on the Bitrix server and test HTTP endpoint.\n";
exit(0);
