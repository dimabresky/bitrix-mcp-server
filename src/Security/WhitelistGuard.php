<?php

declare(strict_types=1);

namespace BitrixMcp\Security;

use BitrixMcp\Config\Config;
use InvalidArgumentException;
use RuntimeException;

final class WhitelistGuard
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function assertIblockAllowed(int $iblockId): void
    {
        if ($iblockId <= 0) {
            throw new RuntimeException('iblock_id must be a positive integer.');
        }

        $allowed = $this->config->allowedIblocks();
        if ($allowed === []) {
            throw new RuntimeException('allowed_iblocks is empty in config.php.');
        }

        if (!in_array($iblockId, $allowed, true)) {
            throw new RuntimeException(
                sprintf('Iblock ID %d is not in allowed_iblocks whitelist.', $iblockId)
            );
        }
    }

    public function assertHlblockAllowed(int $hlblockId): void
    {
        if ($hlblockId <= 0) {
            throw new RuntimeException('hlblock_id must be a positive integer.');
        }

        $allowed = $this->config->allowedHlblocks();
        if ($allowed === []) {
            throw new RuntimeException('allowed_hlblocks is empty in config.php.');
        }

        if (!in_array($hlblockId, $allowed, true)) {
            throw new RuntimeException(
                sprintf('HL block ID %d is not in allowed_hlblocks whitelist.', $hlblockId)
            );
        }
    }

    /** @return list<int> */
    public function filterIblockIds(?array $requestedIds = null): array
    {
        $allowed = $this->config->allowedIblocks();
        if ($requestedIds === null) {
            return $allowed;
        }

        $filtered = [];
        foreach ($requestedIds as $id) {
            $intId = (int) $id;
            if (in_array($intId, $allowed, true)) {
                $filtered[] = $intId;
            }
        }

        return $filtered;
    }

    /**
     * @param array<mixed> $filter
     * @return array<string, mixed>
     */
    public static function sanitizeFilter(array $filter): array
    {
        $clean = [];
        foreach ($filter as $key => $value) {
            if (
                !is_string($key)
                || strcasecmp($key, 'LOGIC') === 0
                || self::isFilterKey($key) === false
                || self::isFlatValue($value) === false
            ) {
                throw new InvalidArgumentException('Filter must be a flat object of field names.');
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param list<mixed> $select
     * @return list<string>
     */
    public static function sanitizeSelect(array $select): array
    {
        $clean = [];
        foreach ($select as $field) {
            $name = (string) $field;
            if (self::isFieldName($name) === false) {
                throw new InvalidArgumentException('Select field is not allowed: ' . $name);
            }
            $clean[] = $name;
        }

        return $clean;
    }

    private static function isFilterKey(string $key): bool
    {
        return preg_match('/^(>=|<=|!=|!%|!@|><|>|<|=|!|%|@)?[A-Za-z][A-Za-z0-9_]*$/', $key) === 1;
    }

    private static function isFieldName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) === 1;
    }

    private static function isFlatValue(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }
}
