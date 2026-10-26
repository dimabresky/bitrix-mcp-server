<?php

declare(strict_types=1);

/**
 * Checks FileSource without Bitrix.
 * Usage: php scripts/check-file-source.php
 */

$root = dirname(__DIR__);
require $root . '/src/Service/FileSource.php';
require $root . '/src/Service/BitrixFileArray.php';
require $root . '/vendor/autoload.php';

use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use BitrixMcp\Service\BitrixFileArray;
use BitrixMcp\Service\FileSource;

$errors = [];

function expect(bool $ok, string $message, array &$errors): void
{
    if (!$ok) {
        $errors[] = $message;
    }
}

function expectThrow(callable $fn, string $message, array &$errors): void
{
    try {
        $fn();
        $errors[] = $message;
    } catch (InvalidArgumentException) {
        // expected
    }
}

$site = sys_get_temp_dir() . '/mcp-file-source-' . bin2hex(random_bytes(4));
mkdir($site . '/upload/sub', 0777, true);
file_put_contents($site . '/room.jpg', 'jpeg');
file_put_contents($site . '/upload/room.jpg', 'jpeg');
file_put_contents($site . '/upload/sub/icon.svg', '<svg/>');
file_put_contents($site . '/upload/shell.php', '<?php');
$outside = sys_get_temp_dir() . '/mcp-outside-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($outside, 'secret');

$source = new FileSource($site);

$inside = $source->materialize(['path' => '/upload/room.jpg']);
expect($inside['delete'] === false && is_file($inside['path']) && $inside['temporary'] === false, 'path inside upload', $errors);
expect($inside['name'] === 'room.jpg', 'path keeps the file name', $errors);

$nested = $source->materialize(['path' => '/upload/sub/icon.svg']);
expect($nested['name'] === 'icon.svg', 'nested path', $errors);

expectThrow(static fn () => $source->materialize(['path' => '/room.jpg']), 'path outside upload rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => 'room.jpg']), 'relative path rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => '/../' . basename($outside)]), 'traversal rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => '/upload/missing.jpg']), 'missing file rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => '/upload/shell.php']), 'php path rejected', $errors);

if (symlink($outside, $site . '/upload/leak.txt')) {
    expectThrow(static fn () => $source->materialize(['path' => '/upload/leak.txt']), 'symlink outside root rejected', $errors);
}

expect(FileSource::isBlockedIp('127.0.0.1'), 'loopback blocked', $errors);
expect(FileSource::isBlockedIp('10.1.2.3'), 'private blocked', $errors);
expect(FileSource::isBlockedIp('192.168.0.8'), 'rfc1918 blocked', $errors);
expect(FileSource::isBlockedIp('169.254.1.1'), 'link-local blocked', $errors);
expect(FileSource::isBlockedIp('100.64.0.1'), 'cgnat blocked', $errors);
expect(FileSource::isBlockedIp('::1'), 'ipv6 loopback blocked', $errors);
expect(FileSource::isBlockedIp('::ffff:127.0.0.1'), 'mapped loopback blocked', $errors);
expect(FileSource::isBlockedIp('::ffff:169.254.169.254'), 'mapped link-local blocked', $errors);
expect(FileSource::isBlockedIp('::ffff:10.0.0.1'), 'mapped private blocked', $errors);
expect(!FileSource::isBlockedIp('::ffff:8.8.8.8'), 'mapped public allowed', $errors);
expect(!FileSource::isBlockedIp('8.8.8.8'), 'public ipv4 allowed', $errors);
expect(!FileSource::isBlockedIp('2001:4860:4860::8888'), 'public ipv6 allowed', $errors);
expect(FileSource::isBlockedIp('2002:7f00:1::'), '6to4 loopback blocked', $errors);
expect(FileSource::isBlockedIp('2001:0::80ff:fffe'), 'teredo loopback blocked', $errors);
expect(FileSource::isBlockedIp('64:ff9b::7f00:1'), 'nat64 loopback blocked', $errors);
expect(!FileSource::isBlockedIp('2002:808:808::'), 'public 6to4 allowed', $errors);

expectThrow(static fn () => $source->materialize(['url' => 'http://8.8.8.8:8080/a.jpg']), 'nonstandard port rejected', $errors);
expectThrow(static fn () => $source->materialize(['url' => 'http://127.0.0.1/room.jpg']), 'loopback url rejected', $errors);
expectThrow(static fn () => $source->materialize(['url' => 'http://10.0.0.5/a']), 'private url rejected', $errors);
expectThrow(static fn () => $source->materialize(['url' => 'file:///etc/passwd']), 'file scheme rejected', $errors);
expectThrow(static fn () => $source->materialize(['url' => 'http://localhost/a']), 'localhost rejected', $errors);
expectThrow(static fn () => $source->materialize(['url' => 'http://[::ffff:127.0.0.1]/a']), 'mapped loopback url rejected', $errors);

$encoded = $source->materialize([
    'name' => 'photo.jpg',
    'content_base64' => base64_encode('picture'),
]);
expect($encoded['temporary'] === true && is_file($encoded['path']) && file_get_contents($encoded['path']) === 'picture', 'base64 written', $errors);
if (is_file($encoded['path'])) {
    unlink($encoded['path']);
}

expectThrow(
    static fn () => $source->materialize(['name' => '../photo.jpg', 'content_base64' => base64_encode('x')]),
    'name with slash rejected',
    $errors
);
expectThrow(
    static fn () => $source->materialize(['name' => 'a.php', 'content_base64' => base64_encode('x')]),
    'php name rejected',
    $errors
);
expectThrow(
    static fn () => $source->materialize(['name' => '.env', 'content_base64' => base64_encode('x')]),
    'dot name rejected',
    $errors
);
expectThrow(
    static fn () => $source->materialize([
        'name' => 'big.bin',
        'content_base64' => base64_encode(str_repeat('a', FileSource::MAX_BYTES + 1)),
    ]),
    'oversize base64 rejected',
    $errors
);

$deleted = $source->materialize(['del' => true]);
expect($deleted === ['delete' => true], 'del clears the file', $errors);

$files = new BitrixFileArray($source);
expect($files->makeProperty(['del' => true], true) === ['del' => 'Y'], 'multiple delete is not wrapped', $errors);
expect($files->makeProperty(['del' => true], false) === ['del' => 'Y'], 'single delete', $errors);
expect(!FileSource::isDescriptor(['path' => '/a', 'url' => 'http://example.com']), 'two sources rejected', $errors);

$filter = WhitelistGuard::sanitizeFilter(['!ACTIVE' => 'Y', '%NAME' => 'a', '@ID' => [1, 2]]);
expect($filter === ['!ACTIVE' => 'Y', '%NAME' => 'a', '@ID' => [1, 2]], 'flat filter kept', $errors);
expectThrow(
    static fn () => WhitelistGuard::sanitizeFilter(['CREATED_BY_USER.PASSWORD' => 'x']),
    'dotted filter rejected',
    $errors
);
expectThrow(
    static fn () => WhitelistGuard::sanitizeFilter(['LOGIC' => 'OR']),
    'logic filter rejected',
    $errors
);
expectThrow(
    static fn () => WhitelistGuard::sanitizeSelect(['CREATED_BY_USER.PASSWORD']),
    'dotted select rejected',
    $errors
);
$cfg = new Config([]);
expect($cfg->resolveOffset(-5) === 0 && $cfg->resolveOffset(10001) === 10000, 'offset bounded', $errors);

@unlink($site . '/upload/leak.txt');
@unlink($site . '/upload/shell.php');
@unlink($site . '/upload/room.jpg');
@unlink($site . '/upload/sub/icon.svg');
@unlink($site . '/room.jpg');
@rmdir($site . '/upload/sub');
@rmdir($site . '/upload');
@rmdir($site);
@unlink($outside);

if ($errors !== []) {
    fwrite(STDERR, "CHECK FAILED:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "OK: file source checks passed.\n";
exit(0);
