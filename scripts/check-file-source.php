<?php

declare(strict_types=1);

/**
 * Checks FileSource without Bitrix.
 * Usage: php scripts/check-file-source.php
 */

$root = dirname(__DIR__);
require $root . '/src/Service/FileSource.php';
require $root . '/src/Service/BitrixFileArray.php';

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
mkdir($site . '/sub', 0777, true);
file_put_contents($site . '/room.jpg', 'jpeg');
file_put_contents($site . '/sub/icon.svg', '<svg/>');
$outside = sys_get_temp_dir() . '/mcp-outside-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($outside, 'secret');

$source = new FileSource($site);

$inside = $source->materialize(['path' => '/room.jpg']);
expect($inside['delete'] === false && is_file($inside['path']) && $inside['temporary'] === false, 'path inside root', $errors);
expect($inside['name'] === 'room.jpg', 'path keeps the file name', $errors);

$nested = $source->materialize(['path' => '/sub/icon.svg']);
expect($nested['name'] === 'icon.svg', 'nested path', $errors);

expectThrow(static fn () => $source->materialize(['path' => 'room.jpg']), 'relative path rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => '/../' . basename($outside)]), 'traversal rejected', $errors);
expectThrow(static fn () => $source->materialize(['path' => '/missing.jpg']), 'missing file rejected', $errors);

if (symlink($outside, $site . '/leak.txt')) {
    expectThrow(static fn () => $source->materialize(['path' => '/leak.txt']), 'symlink outside root rejected', $errors);
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

@unlink($site . '/leak.txt');
@unlink($site . '/room.jpg');
@unlink($site . '/sub/icon.svg');
@rmdir($site . '/sub');
@rmdir($site);
@unlink($outside);

if ($errors !== []) {
    fwrite(STDERR, "CHECK FAILED:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "OK: file source checks passed.\n";
exit(0);
