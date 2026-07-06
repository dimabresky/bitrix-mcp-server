<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Model\Section;
use RuntimeException;

final class IblockEntityResolver
{
    /**
     * @return array{ID: int, API_CODE: string, CODE: string, NAME: string, IBLOCK_TYPE_ID: string}
     */
    public function resolveIblock(int $iblockId): array
    {
        $row = IblockTable::getList([
            'filter' => ['=ID' => $iblockId],
            'select' => ['ID', 'API_CODE', 'CODE', 'NAME', 'IBLOCK_TYPE_ID'],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            throw new RuntimeException(sprintf('Iblock ID %d not found.', $iblockId));
        }

        $apiCode = trim((string) ($row['API_CODE'] ?? ''));
        if ($apiCode === '') {
            throw new RuntimeException(
                sprintf(
                    'Iblock ID %d has no API_CODE. Set «Символьный код API» in admin (required for ORM).',
                    $iblockId
                )
            );
        }

        return [
            'ID' => (int) $row['ID'],
            'API_CODE' => $apiCode,
            'CODE' => (string) ($row['CODE'] ?? ''),
            'NAME' => (string) ($row['NAME'] ?? ''),
            'IBLOCK_TYPE_ID' => (string) ($row['IBLOCK_TYPE_ID'] ?? ''),
        ];
    }

    /**
     * @return class-string
     */
    public function elementDataClass(int $iblockId): string
    {
        $meta = $this->resolveIblock($iblockId);
        $entity = IblockTable::compileEntity($meta['API_CODE']);

        return $entity->getDataClass();
    }

    /**
     * @return class-string
     */
    public function sectionDataClass(int $iblockId): string
    {
        $meta = $this->resolveIblock($iblockId);
        $class = Section::compileEntityByIblock($meta['API_CODE']);
        if (is_string($class)) {
            return $class;
        }

        return $class::class;
    }

    public function apiCode(int $iblockId): string
    {
        return $this->resolveIblock($iblockId)['API_CODE'];
    }
}
