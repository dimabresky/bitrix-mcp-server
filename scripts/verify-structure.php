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
    \BitrixMcp\Tool\IblockTools::class,
    \BitrixMcp\Tool\HighloadTools::class,
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

if ($errors !== []) {
    fwrite(STDERR, "VERIFY FAILED:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "OK: structure and autoload verified.\n";
echo "Next: copy config.sample.php to config.php on the Bitrix server and test HTTP endpoint.\n";
exit(0);
