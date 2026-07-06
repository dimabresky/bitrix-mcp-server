<?php

declare(strict_types=1);

namespace BitrixMcp\Tool;

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Service\HighloadService;
use Mcp\Capability\Attribute\McpTool;

final class HighloadTools extends AbstractToolHandler
{
    public function __construct(
        TokenAuthenticator $auth,
        AuditLogger $audit,
        private readonly HighloadService $service,
    ) {
        parent::__construct($auth, $audit);
    }

    #[McpTool(name: 'hlblock_list', description: 'List whitelisted highload blocks.')]
    public function hlblockList(): array
    {
        return $this->run('hlblock_list', [], fn () => [
            'items' => $this->service->listHlblocks(),
        ]);
    }

    #[McpTool(name: 'hlblock_schema', description: 'HL block ORM fields and user fields (UF_*).')]
    public function hlblockSchema(int $hlblock_id): array
    {
        return $this->run('hlblock_schema', ['hlblock_id' => $hlblock_id], fn () => $this->service->getSchema($hlblock_id));
    }

    #[McpTool(name: 'hlblock_records_list', description: 'List HL records. filter_json e.g. {"=UF_ACTIVE":1}')]
    public function hlblockRecordsList(
        int $hlblock_id,
        ?string $filter_json = null,
        ?int $limit = null,
        int $offset = 0,
        ?string $select_json = null,
    ): array {
        $select = null;
        if ($select_json !== null && trim($select_json) !== '') {
            $decoded = json_decode($select_json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('select_json must be a JSON array.');
            }
            $select = array_map('strval', $decoded);
        }

        return $this->run('hlblock_records_list', [
            'hlblock_id' => $hlblock_id,
            'filter_json' => $filter_json,
        ], fn () => [
            'items' => $this->service->listRecords(
                $hlblock_id,
                $this->decodeJsonObject($filter_json),
                $limit,
                $offset,
                $select
            ),
        ]);
    }

    #[McpTool(name: 'hlblock_record_get', description: 'Get one HL record by ID.')]
    public function hlblockRecordGet(int $hlblock_id, int $record_id): array
    {
        return $this->run('hlblock_record_get', [
            'hlblock_id' => $hlblock_id,
            'record_id' => $record_id,
        ], fn () => $this->service->getRecord($hlblock_id, $record_id));
    }

    #[McpTool(name: 'hlblock_record_add', description: 'Create HL record. fields_json with UF_* keys.')]
    public function hlblockRecordAdd(int $hlblock_id, string $fields_json): array
    {
        return $this->run('hlblock_record_add', [
            'hlblock_id' => $hlblock_id,
            'fields_json' => $fields_json,
        ], fn () => $this->service->addRecord($hlblock_id, $this->decodeJsonObject($fields_json)));
    }

    #[McpTool(name: 'hlblock_record_update', description: 'Update HL record (patch fields_json).')]
    public function hlblockRecordUpdate(int $hlblock_id, int $record_id, string $fields_json): array
    {
        return $this->run('hlblock_record_update', [
            'hlblock_id' => $hlblock_id,
            'record_id' => $record_id,
        ], fn () => $this->service->updateRecord(
            $hlblock_id,
            $record_id,
            $this->decodeJsonObject($fields_json)
        ));
    }

    #[McpTool(name: 'hlblock_record_delete', description: 'Delete HL record. confirm must be true.')]
    public function hlblockRecordDelete(int $hlblock_id, int $record_id, bool $confirm = false): array
    {
        if (!$confirm) {
            throw new \InvalidArgumentException('Set confirm=true to delete a record.');
        }

        return $this->run('hlblock_record_delete', [
            'hlblock_id' => $hlblock_id,
            'record_id' => $record_id,
            'confirm' => $confirm,
        ], fn () => $this->service->deleteRecord($hlblock_id, $record_id));
    }
}
