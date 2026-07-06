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
            throw new RuntimeException('auth_token is empty in config.php. Set MCP_AUTH_TOKEN in environment.');
        }

        $fromEnv = getenv('MCP_AUTH_TOKEN') ?: '';
        if ($fromEnv === '' || !hash_equals($expected, $fromEnv)) {
            throw new RuntimeException('Invalid or missing MCP_AUTH_TOKEN.');
        }
    }
}
