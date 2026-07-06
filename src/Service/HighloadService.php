<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\UserFieldTable;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use RuntimeException;

final class HighloadService
{
    public function __construct(
        private readonly Config $config,
        private readonly WhitelistGuard $whitelist,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHlblocks(): array
    {
        $allowed = $this->config->allowedHlblocks();
        if ($allowed === []) {
            return [];
        }

        $rows = HighloadBlockTable::getList([
            'filter' => ['@ID' => $allowed],
            'select' => ['ID', 'NAME', 'TABLE_NAME'],
            'order' => ['NAME' => 'ASC'],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = [
                'ID' => (int) $row['ID'],
                'NAME' => (string) $row['NAME'],
                'TABLE_NAME' => (string) $row['TABLE_NAME'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(int $hlblockId): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $hl = $this->getHlblockRow($hlblockId);
        $entity = HighloadBlockTable::compileEntity($hlblockId);
        $fields = [];

        foreach ($entity->getFields() as $name => $field) {
            $fields[] = [
                'NAME' => $name,
                'TYPE' => $field->getDataType(),
                'REQUIRED' => method_exists($field, 'isRequired') ? $field->isRequired() : null,
            ];
        }

        $userFields = [];
        $ufRes = UserFieldTable::getList([
            'filter' => [
                '=ENTITY_ID' => 'HLBLOCK_' . $hlblockId,
            ],
            'select' => ['FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE', 'MANDATORY', 'EDIT_FORM_LABEL'],
        ]);
        while ($uf = $ufRes->fetch()) {
            $label = $uf['EDIT_FORM_LABEL'];
            if (is_array($label)) {
                $label = $label['ru'] ?? $label['en'] ?? reset($label);
            }
            $userFields[] = [
                'FIELD_NAME' => (string) $uf['FIELD_NAME'],
                'USER_TYPE_ID' => (string) $uf['USER_TYPE_ID'],
                'MULTIPLE' => (string) $uf['MULTIPLE'],
                'MANDATORY' => (string) $uf['MANDATORY'],
                'LABEL' => (string) $label,
            ];
        }

        return [
            'hlblock' => $hl,
            'orm_fields' => $fields,
            'user_fields' => $userFields,
        ];
    }

    /**
     * @param array<string, mixed>|null $filter
     * @return list<array<string, mixed>>
     */
    public function listRecords(
        int $hlblockId,
        ?array $filter = null,
        ?int $limit = null,
        int $offset = 0,
        ?array $select = null,
    ): array {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);

        $params = [
            'filter' => $filter ?? [],
            'limit' => $this->config->resolveLimit($limit),
            'offset' => max(0, $offset),
            'order' => ['ID' => 'DESC'],
        ];
        if ($select !== null && $select !== []) {
            $params['select'] = $select;
        }

        $rows = $dataClass::getList($params);
        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecord(int $hlblockId, int $recordId): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);

        $row = $dataClass::getList([
            'filter' => ['=ID' => $recordId],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            throw new RuntimeException(
                sprintf('Record %d not found in HL block %d.', $recordId, $hlblockId)
            );
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addRecord(int $hlblockId, array $fields): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);

        $result = $dataClass::add($fields);
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return $this->getRecord($hlblockId, (int) $result->getId());
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateRecord(int $hlblockId, int $recordId, array $fields): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);

        $result = $dataClass::update($recordId, $fields);
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return $this->getRecord($hlblockId, $recordId);
    }

    public function deleteRecord(int $hlblockId, int $recordId): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);

        $result = $dataClass::delete($recordId);
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return ['deleted' => true, 'ID' => $recordId, 'hlblock_id' => $hlblockId];
    }

    /**
     * @return class-string
     */
    private function dataClass(int $hlblockId): string
    {
        $entity = HighloadBlockTable::compileEntity($hlblockId);

        return $entity->getDataClass();
    }

    /**
     * @return array{ID: int, NAME: string, TABLE_NAME: string}
     */
    private function getHlblockRow(int $hlblockId): array
    {
        $row = HighloadBlockTable::getById($hlblockId)->fetch();
        if (!$row) {
            throw new RuntimeException(sprintf('HL block ID %d not found.', $hlblockId));
        }

        return [
            'ID' => (int) $row['ID'],
            'NAME' => (string) $row['NAME'],
            'TABLE_NAME' => (string) $row['TABLE_NAME'],
        ];
    }

    private function formatErrors(object $result): string
    {
        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[] = method_exists($error, 'getMessage') ? $error->getMessage() : (string) $error;
        }

        return implode('; ', $messages) ?: 'HL operation failed.';
    }
}
