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

    #[McpTool(name: 'iblock_schema', description: 'Iblock schema: fields, property definitions, enum values. Create properties with iblock_property_add. No property update/delete and no enum edits after create. Requires API_CODE.')]
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

    #[McpTool(
        name: 'iblock_element_get',
        description: 'Get one element by ID. properties_mode: smart_filter (default, CIBlockSectionPropertyLink SMART_FILTER=Y for element section) or all (every iblock property). Optional section_id overrides element section for smart_filter.',
    )]
    public function iblockElementGet(
        int $iblock_id,
        int $element_id,
        string $properties_mode = IblockService::PROPERTIES_MODE_SMART_FILTER,
        ?int $section_id = null,
    ): array {
        return $this->run('iblock_element_get', [
            'iblock_id' => $iblock_id,
            'element_id' => $element_id,
            'properties_mode' => $properties_mode,
            'section_id' => $section_id,
        ], fn () => $this->service->getElement($iblock_id, $element_id, $properties_mode, $section_id));
    }

    #[McpTool(
        name: 'iblock_smart_filter_schema',
        description: 'List property definitions with SMART_FILTER=Y for iblock section (same source as catalog.smart.filter).',
    )]
    public function iblockSmartFilterSchema(int $iblock_id, int $section_id): array
    {
        return $this->run('iblock_smart_filter_schema', [
            'iblock_id' => $iblock_id,
            'section_id' => $section_id,
        ], fn () => $this->service->getSmartFilterSchema($iblock_id, $section_id));
    }

    #[McpTool(name: 'iblock_element_add', description: 'Create element. fields_json: NAME, CODE, ACTIVE, and optional PREVIEW_PICTURE/DETAIL_PICTURE file source. properties_json: property values; type F uses the same file source {"path"|"url"|"name"+"content_base64"} or {"del":true}.')]
    public function iblockElementAdd(
        int $iblock_id,
        string $fields_json,
        ?string $properties_json = null,
    ): array {
        return $this->run('iblock_element_add', [
            'iblock_id' => $iblock_id,
            'fields_json' => $fields_json,
            'properties_json' => $properties_json,
        ], fn () => $this->service->addElement(
            $iblock_id,
            $this->decodeJsonObject($fields_json),
            $this->decodeJsonObject($properties_json)
        ));
    }

    #[McpTool(name: 'iblock_element_update', description: 'Patch element fields and property values. PREVIEW_PICTURE, DETAIL_PICTURE and type F accept a file source {"path"|"url"|"name"+"content_base64"} or {"del":true}. Does not change property definitions.')]
    public function iblockElementUpdate(
        int $iblock_id,
        int $element_id,
        ?string $fields_json = null,
        ?string $properties_json = null,
    ): array {
        return $this->run('iblock_element_update', [
            'iblock_id' => $iblock_id,
            'element_id' => $element_id,
            'fields_json' => $fields_json,
            'properties_json' => $properties_json,
        ], fn () => $this->service->updateElement(
            $iblock_id,
            $element_id,
            $this->decodeJsonObject($fields_json),
            $this->decodeJsonObject($properties_json)
        ));
    }

    #[McpTool(
        name: 'iblock_property_add',
        description: 'Create a property on a whitelisted iblock. fields_json: NAME, CODE, PROPERTY_TYPE (S, N, L, F, E, G), optional MULTIPLE, IS_REQUIRED, SORT. E and G require LINK_IBLOCK_ID. L requires VALUES: [{VALUE, XML_ID?, SORT?, DEF?}]. Does not update or delete properties, and does not change enum values later.',
    )]
    public function iblockPropertyAdd(int $iblock_id, string $fields_json): array
    {
        return $this->run('iblock_property_add', [
            'iblock_id' => $iblock_id,
            'fields_json' => $fields_json,
        ], fn () => $this->service->addProperty($iblock_id, $this->decodeJsonObject($fields_json)));
    }

    #[McpTool(
        name: 'iblock_type_add',
        description: 'Create an iblock type. Registered only when iblock_create_enabled is true. fields_json: ID, NAME, optional SECTIONS (Y/N), SORT.',
    )]
    public function iblockTypeAdd(string $fields_json): array
    {
        return $this->run('iblock_type_add', [
            'fields_json' => $fields_json,
        ], fn () => $this->service->addType($this->decodeJsonObject($fields_json)));
    }

    #[McpTool(
        name: 'iblock_add',
        description: 'Create an infoblock. Registered only when iblock_create_enabled is true. fields_json: IBLOCK_TYPE_ID, NAME, CODE, API_CODE, optional ACTIVE, SORT, LID. VERSION is always 2. SITE_ID is the site from LID or site_id. The service user groups get write access, except group 2 unless it is their only group. The new ID is not added to allowed_iblocks.',
    )]
    public function iblockAdd(string $fields_json): array
    {
        return $this->run('iblock_add', [
            'fields_json' => $fields_json,
        ], fn () => $this->service->addIblock($this->decodeJsonObject($fields_json)));
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
