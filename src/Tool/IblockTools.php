<?php

declare(strict_types=1);

namespace BitrixMcp\Tool;

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Service\IblockService;
use Mcp\Capability\Attribute\McpTool;

final class IblockTools extends AbstractToolHandler
{
    public function __construct(
        TokenAuthenticator $auth,
        AuditLogger $audit,
        private readonly IblockService $service,
    ) {
        parent::__construct($auth, $audit);
    }

    #[McpTool(name: 'iblock_list', description: 'List whitelisted infoblocks. Optional filters: type (IBLOCK_TYPE_ID), code.')]
    public function iblockList(?string $type = null, ?string $code = null): array
    {
        return $this->run('iblock_list', ['type' => $type, 'code' => $code], fn () => [
            'items' => $this->service->listIblocks($type, $code),
        ]);
    }

    #[McpTool(name: 'iblock_schema', description: 'Read-only iblock schema: fields, property definitions, enum values. No property add/update/delete (CIBlockProperty). Requires API_CODE.')]
    public function iblockSchema(int $iblock_id): array
    {
        return $this->run('iblock_schema', ['iblock_id' => $iblock_id], fn () => $this->service->getSchema($iblock_id));
    }

    #[McpTool(name: 'iblock_sections_list', description: 'List sections. filter_json: {"CODE":"events"} etc.')]
    public function iblockSectionsList(
        int $iblock_id,
        ?string $filter_json = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        return $this->run('iblock_sections_list', [
            'iblock_id' => $iblock_id,
            'filter_json' => $filter_json,
            'limit' => $limit,
            'offset' => $offset,
        ], fn () => [
            'items' => $this->service->listSections(
                $iblock_id,
                $this->decodeJsonObject($filter_json),
                $limit,
                $offset
            ),
        ]);
    }

    #[McpTool(name: 'iblock_elements_list', description: 'List elements. filter_json, optional select_json array of field codes.')]
    public function iblockElementsList(
        int $iblock_id,
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

        return $this->run('iblock_elements_list', [
            'iblock_id' => $iblock_id,
            'filter_json' => $filter_json,
            'limit' => $limit,
            'offset' => $offset,
        ], fn () => [
            'items' => $this->service->listElements(
                $iblock_id,
                $this->decodeJsonObject($filter_json),
                $limit,
                $offset,
                $select
            ),
        ]);
    }

    #[McpTool(name: 'iblock_element_get', description: 'Get one element with all properties by ID.')]
    public function iblockElementGet(int $iblock_id, int $element_id): array
    {
        return $this->run('iblock_element_get', [
            'iblock_id' => $iblock_id,
            'element_id' => $element_id,
        ], fn () => $this->service->getElement($iblock_id, $element_id));
    }

    #[McpTool(name: 'iblock_element_add', description: 'Create element. fields_json: NAME, CODE, ACTIVE... properties_json: property VALUES on element (not property schema changes), e.g. {"AUTHOR":"text","SOURCE":1}')]
    public function iblockElementAdd(
        int $iblock_id,
        string $fields_json,
        ?string $properties_json = null,
    ): array {
        return $this->run('iblock_element_add', [
            'iblock_id' => $iblock_id,
            'fields_json' => $fields_json,
        ], fn () => $this->service->addElement(
            $iblock_id,
            $this->decodeJsonObject($fields_json),
            $this->decodeJsonObject($properties_json)
        ));
    }

    #[McpTool(name: 'iblock_element_update', description: 'Patch element fields and property VALUES (properties_json). Does not add/update/delete property definitions on iblock.')]
    public function iblockElementUpdate(
        int $iblock_id,
        int $element_id,
        ?string $fields_json = null,
        ?string $properties_json = null,
    ): array {
        return $this->run('iblock_element_update', [
            'iblock_id' => $iblock_id,
            'element_id' => $element_id,
        ], fn () => $this->service->updateElement(
            $iblock_id,
            $element_id,
            $this->decodeJsonObject($fields_json),
            $this->decodeJsonObject($properties_json)
        ));
    }

    #[McpTool(name: 'iblock_element_delete', description: 'Delete element. confirm must be true.')]
    public function iblockElementDelete(int $iblock_id, int $element_id, bool $confirm = false): array
    {
        if (!$confirm) {
            throw new \InvalidArgumentException('Set confirm=true to delete an element.');
        }

        return $this->run('iblock_element_delete', [
            'iblock_id' => $iblock_id,
            'element_id' => $element_id,
            'confirm' => $confirm,
        ], fn () => $this->service->deleteElement($iblock_id, $element_id));
    }
}
