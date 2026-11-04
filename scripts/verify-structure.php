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

$structureOff = new \BitrixMcp\Config\Config([]);
if ($structureOff->iblockCreateEnabled() || $structureOff->hlblockCreateEnabled()) {
    $errors[] = 'iblock_create_enabled and hlblock_create_enabled must default to false';
}

$iblockCreate = new \BitrixMcp\Config\Config(['iblock_create_enabled' => true, 'site_id' => 's1']);
if (!$iblockCreate->iblockCreateEnabled() || $iblockCreate->hlblockCreateEnabled()) {
    $errors[] = 'iblock_create_enabled must not turn on HL block creation';
}

$hlCreate = new \BitrixMcp\Config\Config(['hlblock_create_enabled' => true]);
if ($hlCreate->iblockCreateEnabled() || !$hlCreate->hlblockCreateEnabled()) {
    $errors[] = 'hlblock_create_enabled must not turn on iblock creation';
}

$iblockService = new \BitrixMcp\Service\IblockService(
    $iblockCreate,
    new \BitrixMcp\Security\WhitelistGuard($iblockCreate),
    new \BitrixMcp\Service\IblockEntityResolver(),
    new \BitrixMcp\Service\PropertyNormalizer(),
);
$prepareType = new ReflectionMethod(\BitrixMcp\Service\IblockService::class, 'prepareTypeFields');
$prepareIblock = new ReflectionMethod(\BitrixMcp\Service\IblockService::class, 'prepareIblockFields');

try {
    $prepareType->invoke($iblockService, ['ID' => 'news', 'RIGHTS' => 'X']);
    $errors[] = 'unknown iblock type field must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareType->invoke($iblockService, ['ID' => 'news', 'NAME' => '']);
    $errors[] = 'empty iblock type NAME must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

$type = $prepareType->invoke($iblockService, ['ID' => 'news', 'NAME' => 'Новости']);
if (($type['SECTIONS'] ?? '') !== 'Y' || ($type['SORT'] ?? 0) !== 500) {
    $errors[] = 'iblock type must default SECTIONS to Y and SORT to 500';
}

try {
    $prepareIblock->invoke($iblockService, [
        'IBLOCK_TYPE_ID' => 'news',
        'NAME' => 'News',
        'CODE' => 'news',
        'VERSION' => 1,
    ]);
    $errors[] = 'VERSION must not be a caller field';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareIblock->invoke($iblockService, [
        'IBLOCK_TYPE_ID' => 'news',
        'NAME' => 'News',
        'CODE' => 'news',
    ]);
    $errors[] = 'iblock without API_CODE must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

$iblock = $prepareIblock->invoke($iblockService, [
    'IBLOCK_TYPE_ID' => 'news',
    'NAME' => 'News',
    'CODE' => 'news',
    'API_CODE' => 'News',
]);
if (
    ($iblock['VERSION'] ?? 0) !== 2
    || ($iblock['SITE_ID'] ?? []) !== ['s1']
    || ($iblock['ACTIVE'] ?? '') !== 'Y'
) {
    $errors[] = 'iblock add must set VERSION 2, SITE_ID from site_id, and ACTIVE Y';
}

$groupPerms = new ReflectionMethod(\BitrixMcp\Service\IblockService::class, 'groupPermissions');
$perms = $groupPerms->invoke($iblockService, [1, 2, 5]);
$everyone = $groupPerms->invoke($iblockService, [2]);
if ($perms !== [1 => 'X', 5 => 'W'] || $everyone !== [2 => 'W']) {
    $errors[] = 'iblock permissions must grant the service groups and skip group 2 when another group exists';
}

$prepareProperty = new ReflectionMethod(\BitrixMcp\Service\IblockService::class, 'preparePropertyFields');

try {
    $prepareProperty->invoke($iblockService, ['NAME' => 'Author', 'CODE' => 'AUTHOR', 'USER_TYPE' => 'HTML']);
    $errors[] = 'unknown property field must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, ['NAME' => '', 'CODE' => 'AUTHOR', 'PROPERTY_TYPE' => 'S']);
    $errors[] = 'empty property NAME must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, ['NAME' => 'Author', 'CODE' => '1AUTHOR', 'PROPERTY_TYPE' => 'S']);
    $errors[] = 'property CODE starting with a digit must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, ['NAME' => 'Author', 'CODE' => 'AUTHOR', 'PROPERTY_TYPE' => 'X']);
    $errors[] = 'unknown PROPERTY_TYPE must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, [
        'NAME' => 'Author',
        'CODE' => 'AUTHOR',
        'PROPERTY_TYPE' => 'S',
        'LINK_IBLOCK_ID' => 1,
    ]);
    $errors[] = 'LINK_IBLOCK_ID must be rejected for PROPERTY_TYPE S';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, ['NAME' => 'Link', 'CODE' => 'LINK', 'PROPERTY_TYPE' => 'E']);
    $errors[] = 'LINK_IBLOCK_ID must be required for PROPERTY_TYPE E';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, ['NAME' => 'Color', 'CODE' => 'COLOR', 'PROPERTY_TYPE' => 'L']);
    $errors[] = 'VALUES must be required for PROPERTY_TYPE L';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareProperty->invoke($iblockService, [
        'NAME' => 'Author',
        'CODE' => 'AUTHOR',
        'PROPERTY_TYPE' => 'S',
        'VALUES' => [['VALUE' => 'Red']],
    ]);
    $errors[] = 'VALUES must be rejected for PROPERTY_TYPE S';
} catch (InvalidArgumentException $e) {
    // expected
}

$stringProperty = $prepareProperty->invoke($iblockService, [
    'NAME' => 'Author',
    'CODE' => 'AUTHOR',
    'PROPERTY_TYPE' => 's',
]);
if (
    ($stringProperty['PROPERTY_TYPE'] ?? '') !== 'S'
    || ($stringProperty['MULTIPLE'] ?? '') !== 'N'
    || ($stringProperty['IS_REQUIRED'] ?? '') !== 'N'
    || ($stringProperty['SORT'] ?? 0) !== 500
    || ($stringProperty['ACTIVE'] ?? '') !== 'Y'
    || isset($stringProperty['LINK_IBLOCK_ID'])
    || isset($stringProperty['VALUES'])
) {
    $errors[] = 'string property must default MULTIPLE N, IS_REQUIRED N, SORT 500, ACTIVE Y';
}

$listProperty = $prepareProperty->invoke($iblockService, [
    'NAME' => 'Color',
    'CODE' => 'COLOR',
    'PROPERTY_TYPE' => 'L',
    'VALUES' => [
        ['VALUE' => 'Red', 'XML_ID' => 'red', 'SORT' => 100, 'DEF' => 'Y'],
    ],
]);
$enum = $listProperty['VALUES'][0] ?? [];
if (
    ($enum['VALUE'] ?? '') !== 'Red'
    || ($enum['XML_ID'] ?? '') !== 'red'
    || ($enum['SORT'] ?? 0) !== 100
    || ($enum['DEF'] ?? '') !== 'Y'
) {
    $errors[] = 'list property must keep enum VALUE, XML_ID, SORT, and DEF';
}

$linkProperty = $prepareProperty->invoke($iblockService, [
    'NAME' => 'Link',
    'CODE' => 'LINK',
    'PROPERTY_TYPE' => 'G',
    'LINK_IBLOCK_ID' => '4',
]);
if (($linkProperty['LINK_IBLOCK_ID'] ?? 0) !== 4) {
    $errors[] = 'section link property must keep LINK_IBLOCK_ID';
}

$hlService = new \BitrixMcp\Service\HighloadService(
    $hlCreate,
    new \BitrixMcp\Security\WhitelistGuard($hlCreate),
);
$prepareHl = new ReflectionMethod(\BitrixMcp\Service\HighloadService::class, 'prepareHlblockFields');

try {
    $prepareHl->invoke($hlService, ['NAME' => 'color', 'TABLE_NAME' => 'hl_color']);
    $errors[] = 'HL NAME must start with an uppercase letter';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareHl->invoke($hlService, ['NAME' => 'Color', 'UF_XML_ID' => 'x']);
    $errors[] = 'unknown HL field must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareHl->invoke($hlService, ['NAME' => 'Color']);
    $errors[] = 'HL TABLE_NAME must be required';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareHl->invoke($hlService, ['NAME' => 'ColorTable', 'TABLE_NAME' => 'hl_color']);
    $errors[] = 'HL NAME ending with Table must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

try {
    $prepareHl->invoke($hlService, ['NAME' => 'Color', 'TABLE_NAME' => 'MyTable']);
    $errors[] = 'HL TABLE_NAME must be lowercase';
} catch (InvalidArgumentException $e) {
    // expected
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
