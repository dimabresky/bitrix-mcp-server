<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use InvalidArgumentException;

/**
 * Turns a JSON file source into a local path. Does not call Bitrix.
 *
 * A source has exactly one of: path, url, or name+content_base64.
 * {"del": true} clears the file.
 */
final class FileSource
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly string $documentRoot)
    {
    }

    public static function isDescriptor(mixed $value): bool
    {
        if (!is_array($value) || self::isList($value)) {
            return false;
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys === ['del']) {
            return $value['del'] === true;
        }
        if ($keys === ['path'] || $keys === ['url']) {
            return is_string($value[$keys[0]]) && $value[$keys[0]] !== '';
        }

        return $keys === ['content_base64', 'name']
            && is_string($value['name'])
            && is_string($value['content_base64']);
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array{delete: true}|array{delete: false, path: string, name: string, temporary: bool}
     */
    public function materialize(array $descriptor): array
    {
        if (!self::isDescriptor($descriptor)) {
            throw new InvalidArgumentException(
                'File value must be {"path":"..."}, {"url":"..."}, {"name":"...","content_base64":"..."}, or {"del":true}.'
            );
        }

        if (isset($descriptor['del'])) {
            return ['delete' => true];
        }
        if (isset($descriptor['path'])) {
            return $this->fromPath((string) $descriptor['path']);
        }
        if (isset($descriptor['url'])) {
            return $this->fromUrl((string) $descriptor['url']);
        }

        return $this->fromBase64((string) $descriptor['name'], (string) $descriptor['content_base64']);
    }

    public static function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (self::isCgnat($ip)) {
                return true;
            }

            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return true;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }
        if ($packed === inet_pton('::') || $packed === inet_pton('::1')) {
            return true;
        }

        $bytes = array_values(unpack('C16', $packed) ?: []);
        $first = $bytes[0] ?? 0;
        $second = $bytes[1] ?? 0;
        if (($first & 0xfe) === 0xfc) {
            return true;
        }
        if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return true;
        }

        return $first === 0xff;
    }

    /**
     * @return array{delete: false, path: string, name: string, temporary: bool}
     */
    private function fromPath(string $path): array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('File path must start at the site root.');
        }

        $root = realpath($this->documentRoot);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('DOCUMENT_ROOT is not available.');
        }

        $candidate = $root . $path;
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !$this->isInside($root, $real)) {
            throw new InvalidArgumentException('File path is outside the site root or is not a file.');
        }

        return [
            'delete' => false,
            'path' => $real,
            'name' => basename($real),
            'temporary' => false,
        ];
    }

    /**
     * @return array{delete: false, path: string, name: string, temporary: bool}
     */
    private function fromUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('File URL is invalid.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('File URL must be http or https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('File URL must not contain credentials.');
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || strcasecmp($host, 'localhost') === 0) {
            throw new InvalidArgumentException('File URL host is not allowed.');
        }

        $ips = $this->resolvePublicIps($host);
        $name = $this->safeName(basename((string) ($parts['path'] ?? '')) ?: 'file.bin');
        $target = $this->pinnedUrl($scheme, $ips[0], $parts);
        $hostHeader = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $tmp = $this->tempPath($name);

        try {
            $this->download($target, $hostHeader, $host, $tmp);
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }

        return [
            'delete' => false,
            'path' => $tmp,
            'name' => $name,
            'temporary' => true,
        ];
    }

    /**
     * @return array{delete: false, path: string, name: string, temporary: bool}
     */
    private function fromBase64(string $name, string $encoded): array
    {
        $name = $this->safeName($name);
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException('content_base64 is not valid base64.');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('File is larger than 5 MB.');
        }

        $tmp = $this->tempPath($name);
        if (file_put_contents($tmp, $raw) === false) {
            throw new InvalidArgumentException('Temporary file was not written.');
        }

        return [
            'delete' => false,
            'path' => $tmp,
            'name' => $name,
            'temporary' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isBlockedIp($host)) {
                throw new InvalidArgumentException('File URL host is not allowed.');
            }

            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }
        if ($ips === []) {
            throw new InvalidArgumentException('File URL host did not resolve.');
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new InvalidArgumentException('File URL host is not allowed.');
            }
        }

        return $ips;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function pinnedUrl(string $scheme, string $ip, array $parts): string
    {
        $host = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $url .= $path;
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    private function download(string $url, string $hostHeader, string $peerName, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => "Host: {$hostHeader}\r\nUser-Agent: bitrix-mcp-server\r\n",
            ],
            'ssl' => [
                'peer_name' => $peerName,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $input = @fopen($url, 'rb', false, $context);
        if ($input === false) {
            throw new InvalidArgumentException('File URL could not be downloaded.');
        }

        $status = $this->httpStatus($http_response_header ?? []);
        if ($status >= 300 && $status < 400) {
            fclose($input);
            throw new InvalidArgumentException('File URL redirects are not followed.');
        }
        if ($status !== 200) {
            fclose($input);
            throw new InvalidArgumentException('File URL could not be downloaded.');
        }

        $output = fopen($destination, 'wb');
        if ($output === false) {
            fclose($input);
            throw new InvalidArgumentException('Temporary file was not written.');
        }

        $total = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    break;
                }
                $total += strlen($chunk);
                if ($total > self::MAX_BYTES) {
                    throw new InvalidArgumentException('File is larger than 5 MB.');
                }
                if ($chunk !== '') {
                    fwrite($output, $chunk);
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($total === 0) {
            unlink($destination);
            throw new InvalidArgumentException('File URL returned an empty body.');
        }
    }

    /**
     * @param list<string> $headers
     */
    private function httpStatus(array $headers): int
    {
        if (!isset($headers[0]) || !preg_match('#\s(\d{3})(?:\s|$)#', $headers[0], $match)) {
            return 0;
        }

        return (int) $match[1];
    }

    private function safeName(string $name): string
    {
        $name = trim($name);
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || $name !== basename($name)
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
        ) {
            throw new InvalidArgumentException('File name must be a single path segment.');
        }

        return $name;
    }

    private function tempPath(string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp-file-');
        if ($tmp === false) {
            throw new InvalidArgumentException('Temporary file was not created.');
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext !== '' && preg_match('/^[A-Za-z0-9]{1,8}$/', $ext) === 1) {
            $withExt = $tmp . '.' . strtolower($ext);
            if (!rename($tmp, $withExt)) {
                return $tmp;
            }

            return $withExt;
        }

        return $tmp;
    }

    private function isInside(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function isCgnat(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 4) {
            return false;
        }
        $n = unpack('N', $packed);
        if (!is_array($n)) {
            return false;
        }
        $value = $n[1];

        return $value >= 0x64400000 && $value <= 0x647fffff;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
