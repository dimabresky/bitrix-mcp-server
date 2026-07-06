<?php

declare(strict_types=1);

namespace BitrixMcp\Security;

use BitrixMcp\Config\Config;
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
}
