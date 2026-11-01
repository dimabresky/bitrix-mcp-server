<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\UserFieldTable;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use InvalidArgumentException;
use RuntimeException;

final class HighloadService
{
    /** @var list<string> */
    private const HLBLOCK_FIELDS = ['NAME', 'TABLE_NAME'];

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
            'filter' => WhitelistGuard::sanitizeFilter($filter ?? []),
            'limit' => $this->config->resolveLimit($limit),
            'offset' => $this->config->resolveOffset($offset),
            'order' => ['ID' => 'DESC'],
        ];
        if ($select !== null && $select !== []) {
            $params['select'] = WhitelistGuard::sanitizeSelect($select);
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
        $files = $this->fileArrays();
        try {
            $result = $dataClass::add($this->prepareFileFields($hlblockId, $fields, $files));
            if (!$result->isSuccess()) {
                throw new RuntimeException($this->formatErrors($result));
            }

            return $this->getRecord($hlblockId, (int) $result->getId());
        } finally {
            $files->cleanup();
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateRecord(int $hlblockId, int $recordId, array $fields): array
    {
        $this->whitelist->assertHlblockAllowed($hlblockId);
        $dataClass = $this->dataClass($hlblockId);
        $files = $this->fileArrays();
        try {
            $result = $dataClass::update($recordId, $this->prepareFileFields($hlblockId, $fields, $files));
            if (!$result->isSuccess()) {
                throw new RuntimeException($this->formatErrors($result));
            }

            return $this->getRecord($hlblockId, $recordId);
        } finally {
            $files->cleanup();
        }
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
     * @param array<string, mixed> $fields
     * @return array{ID: int, NAME: string, TABLE_NAME: string, note: string}
     */
    public function addHlblock(array $fields): array
    {
        if (!$this->config->hlblockCreateEnabled()) {
            throw new RuntimeException('HL block creation is disabled in config.php (hlblock_create_enabled).');
        }

        $prepared = $this->prepareHlblockFields($fields);
        $result = HighloadBlockTable::add($prepared);
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return [
            'ID' => (int) $result->getId(),
            'NAME' => $prepared['NAME'],
            'TABLE_NAME' => $prepared['TABLE_NAME'],
            'note' => 'Add this ID to allowed_hlblocks before record tools can use it.',
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function prepareFileFields(int $hlblockId, array $fields, BitrixFileArray $files): array
    {
        $fileFields = $this->fileUserFields($hlblockId);
        foreach ($fields as $name => $value) {
            $field = strtoupper((string) $name);
            if (!isset($fileFields[$field]) || $value === null) {
                continue;
            }
            $fields[$name] = $files->makeProperty($value, $fileFields[$field], 'highloadblock');
        }

        return $fields;
    }

    /**
     * @return array<string, bool> field name => multiple
     */
    private function fileUserFields(int $hlblockId): array
    {
        $map = [];
        $ufRes = UserFieldTable::getList([
            'filter' => [
                '=ENTITY_ID' => 'HLBLOCK_' . $hlblockId,
                '=USER_TYPE_ID' => 'file',
            ],
            'select' => ['FIELD_NAME', 'MULTIPLE'],
        ]);
        while ($uf = $ufRes->fetch()) {
            $map[strtoupper((string) $uf['FIELD_NAME'])] = (string) $uf['MULTIPLE'] === 'Y';
        }

        return $map;
    }

    private function fileArrays(): BitrixFileArray
    {
        return new BitrixFileArray(new FileSource((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')));
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

    /**
     * @param array<string, mixed> $fields
     * @return array{NAME: string, TABLE_NAME: string}
     */
    private function prepareHlblockFields(array $fields): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('fields_json is empty.');
        }

        $raw = [];
        foreach ($fields as $key => $value) {
            $name = strtoupper((string) $key);
            if (!in_array($name, self::HLBLOCK_FIELDS, true)) {
                throw new InvalidArgumentException('Field is not allowed: ' . $key);
            }
            if (!is_scalar($value) || $value === null) {
                throw new InvalidArgumentException($name . ' must be a string.');
            }
            $raw[$name] = trim((string) $value);
        }

        $entityName = $raw['NAME'] ?? '';
        if (preg_match('/^[A-Z][A-Za-z0-9]{0,99}$/', $entityName) !== 1) {
            throw new InvalidArgumentException('NAME must be 1-100 letters and digits, starting with an uppercase letter.');
        }

        $tableName = $raw['TABLE_NAME'] ?? '';
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $tableName) !== 1) {
            throw new InvalidArgumentException('TABLE_NAME must be 1-64 letters, digits, and underscore, starting with a letter.');
        }

        return [
            'NAME' => $entityName,
            'TABLE_NAME' => $tableName,
        ];
    }
}
