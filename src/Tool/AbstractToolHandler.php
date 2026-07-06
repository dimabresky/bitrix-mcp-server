<?php

declare(strict_types=1);

namespace BitrixMcp\Tool;

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use Throwable;

abstract class AbstractToolHandler
{
    public function __construct(
        protected readonly TokenAuthenticator $auth,
        protected readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    protected function run(string $toolName, array $params, callable $callback): mixed
    {
        try {
            $this->auth->assertValid();
            $result = $callback();
            $this->audit->log($toolName, $params, true);

            return $result;
        } catch (Throwable $e) {
            $this->audit->log($toolName, $params, false, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonObject(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('JSON must decode to an object.');
        }

        return $decoded;
    }
}
