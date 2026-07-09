<?php

declare(strict_types=1);

namespace BitrixMcp\Config;

use RuntimeException;

final class Config
{
    private const HARD_MAX_LIMIT = 100;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $baseDir): self
    {
        $path = $baseDir . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'config.php not found. Copy config.sample.php to config.php and configure whitelist.'
            );
        }

        /** @var array<string, mixed> $data */
        $data = require $path;

        return new self($data);
    }

    public function siteId(): string
    {
        return (string) ($this->data['site_id'] ?? 's1');
    }

    public function serviceUserId(): int
    {
        return (int) ($this->data['service_user_id'] ?? 0);
    }

    public function authToken(): string
    {
        return (string) ($this->data['auth_token'] ?? '');
    }

    /** @return list<int> */
    public function allowedIblocks(): array
    {
        return $this->normalizeIntList($this->data['allowed_iblocks'] ?? []);
    }

    /** @return list<int> */
    public function allowedHlblocks(): array
    {
        return $this->normalizeIntList($this->data['allowed_hlblocks'] ?? []);
    }

    public function maxListLimit(): int
    {
        $limit = (int) ($this->data['max_list_limit'] ?? 50);

        return min(max(1, $limit), self::HARD_MAX_LIMIT);
    }

    public function hardMaxLimit(): int
    {
        return self::HARD_MAX_LIMIT;
    }

    public function auditLogPath(): string
    {
        return (string) ($this->data['audit_log_path'] ?? '');
    }

    public function sessionStorePath(): string
    {
        return (string) ($this->data['session_store_path'] ?? '');
    }

    public function sessionTtl(): int
    {
        return max(60, (int) ($this->data['session_ttl'] ?? 3600));
    }

    public function resolveLimit(?int $requested): int
    {
        $max = $this->maxListLimit();
        if ($requested === null || $requested <= 0) {
            return $max;
        }

        return min($requested, $max);
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function normalizeIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $result[] = $id;
            }
        }

        return array_values(array_unique($result));
    }
}
