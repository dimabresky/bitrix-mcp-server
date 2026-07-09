<?php

declare(strict_types=1);

namespace BitrixMcp\Auth;

use BitrixMcp\Config\Config;
use RuntimeException;

final class TokenAuthenticator
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function assertValid(): void
    {
        $expected = $this->config->authToken();
        if ($expected === '') {
            throw new RuntimeException('auth_token is empty in config.php.');
        }

        $provided = self::extractTokenFromRequest();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Invalid or missing authorization token.');
        }
    }

    public static function extractTokenFromRequest(): string
    {
        $authorization = self::getHeader('Authorization');
        if ($authorization !== '' && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            return $matches[1];
        }

        return self::getHeader('X-MCP-Token');
    }

    public static function isRequestAuthorized(Config $config): bool
    {
        $expected = $config->authToken();
        if ($expected === '') {
            return false;
        }

        $provided = self::extractTokenFromRequest();

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private static function getHeader(string $name): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
            return trim($_SERVER[$serverKey]);
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $name) === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }

        return '';
    }
}
