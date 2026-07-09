<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use CIBlockElement;
use CIBlockSectionPropertyLink;
use RuntimeException;

final class IblockService
{
    /** @var list<string> */
    private const BASE_ELEMENT_FIELDS = [
        'ID', 'NAME', 'CODE', 'ACTIVE', 'IBLOCK_SECTION_ID',
        'PREVIEW_TEXT', 'DETAIL_TEXT', 'SORT',
    ];

    public const PROPERTIES_MODE_ALL = 'all';
    public const PROPERTIES_MODE_SMART_FILTER = 'smart_filter';
    public function __construct(
        private readonly Config $config,
        private readonly WhitelistGuard $whitelist,
        private readonly IblockEntityResolver $resolver,
        private readonly PropertyNormalizer $propertyNormalizer,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIblocks(?string $type = null, ?string $code = null): array
    {
        $allowed = $this->whitelist->filterIblockIds();
        if ($allowed === []) {
            return [];
        }

        $filter = ['@ID' => $allowed];
        if ($type !== null && $type !== '') {
            $filter['=IBLOCK_TYPE_ID'] = $type;
        }
        if ($code !== null && $code !== '') {
            $filter['=CODE'] = $code;
        }

        $rows = IblockTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'CODE', 'NAME', 'API_CODE', 'IBLOCK_TYPE_ID', 'ACTIVE'],
            'order' => ['NAME' => 'ASC'],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = [
                'ID' => (int) $row['ID'],
                'CODE' => (string) $row['CODE'],
                'NAME' => (string) $row['NAME'],
                'API_CODE' => (string) ($row['API_CODE'] ?? ''),
                'IBLOCK_TYPE_ID' => (string) $row['IBLOCK_TYPE_ID'],
                'ACTIVE' => (string) $row['ACTIVE'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(int $iblockId): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);
        $meta = $this->resolver->resolveIblock($iblockId);

        $properties = [];
        $propRes = PropertyTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'select' => [
                'ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED',
                'USER_TYPE', 'LINK_IBLOCK_ID', 'SORT',
            ],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ]);

        while ($prop = $propRes->fetch()) {
            $code = (string) $prop['CODE'];
            $entry = [
                'ID' => (int) $prop['ID'],
                'CODE' => $code,
                'NAME' => (string) $prop['NAME'],
                'PROPERTY_TYPE' => (string) $prop['PROPERTY_TYPE'],
                'MULTIPLE' => (string) $prop['MULTIPLE'],
                'IS_REQUIRED' => (string) $prop['IS_REQUIRED'],
                'USER_TYPE' => (string) ($prop['USER_TYPE'] ?? ''),
                'LINK_IBLOCK_ID' => (int) ($prop['LINK_IBLOCK_ID'] ?? 0),
            ];

            if ($prop['PROPERTY_TYPE'] === 'L') {
                $entry['ENUM'] = $this->loadEnum((int) $prop['ID']);
            }

            if ($code === '') {
                $entry['ORM_NOTE'] = 'Property CODE is empty — ORM set/get will not work until CODE is filled.';
            }

            $properties[] = $entry;
        }

        return [
            'iblock' => $meta,
            'element_fields' => [
                'NAME', 'CODE', 'ACTIVE', 'PREVIEW_TEXT', 'DETAIL_TEXT',
                'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'SORT', 'IBLOCK_SECTION_ID',
            ],
            'properties' => $properties,
            'property_input_hints' => [
                'S' => 'string',
                'N' => 'number as string',
                'L' => 'enum ID (integer)',
                'E' => 'linked element ID',
                'G' => 'linked section ID',
                'directory' => 'UF_XML_ID from HL directory',
                'HTML' => 'HTML string (USER_TYPE HTML)',
                'multiple' => 'JSON array of values; uses addTo on write',
            ],
            'limitations' => [
                'property_definition_crud' => false,
                'property_enum_crud' => false,
                'note' => 'Схема свойств только для чтения. Создание/изменение/удаление свойств инфоблока (CIBlockProperty) не поддерживается. Значения свойств на элементах — через iblock_element_add/update.',
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $filter
     * @return list<array<string, mixed>>
     */
    public function listSections(int $iblockId, ?array $filter = null, ?int $limit = null, int $offset = 0): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);
        $sectionClass = $this->resolver->sectionDataClass($iblockId);

        $query = $sectionClass::query()
            ->setSelect(['ID', 'NAME', 'CODE', 'ACTIVE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'SORT'])
            ->setOrder(['LEFT_MARGIN' => 'ASC'])
            ->setLimit($this->config->resolveLimit($limit))
            ->setOffset(max(0, $offset));

        $this->applyQueryFilter($query, $filter ?? []);

        $collection = $query->fetchCollection();
        $result = [];
        foreach ($collection as $section) {
            $result[] = [
                'ID' => $section->getId(),
                'NAME' => $section->getName(),
                'CODE' => $section->getCode(),
                'ACTIVE' => $section->getActive() ? 'Y' : 'N',
                'IBLOCK_SECTION_ID' => $section->getIblockSectionId(),
                'DEPTH_LEVEL' => method_exists($section, 'getDepthLevel') ? $section->getDepthLevel() : null,
                'SORT' => method_exists($section, 'getSort') ? $section->getSort() : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $filter
     * @param list<string>|null $select
     * @return list<array<string, mixed>>
     */
    public function listElements(
        int $iblockId,
        ?array $filter = null,
        ?int $limit = null,
        int $offset = 0,
        ?array $select = null,
    ): array {
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);

        $selectFields = $select ?? ['ID', 'NAME', 'CODE', 'ACTIVE', 'IBLOCK_SECTION_ID', 'TIMESTAMP_X'];
        $query = $elementClass::query()
            ->setSelect($selectFields)
            ->setOrder(['ID' => 'DESC'])
            ->setLimit($this->config->resolveLimit($limit))
            ->setOffset(max(0, $offset));

        $this->applyQueryFilter($query, $filter ?? []);

        $collection = $query->fetchCollection();
        $result = [];
        foreach ($collection as $element) {
            $row = ['ID' => $element->getId()];
            foreach ($selectFields as $field) {
                if ($field === 'ID') {
                    continue;
                }
                $getter = 'get' . $this->fieldToGetter($field);
                if (method_exists($element, $getter)) {
                    $row[$field] = $element->$getter();
                } elseif (method_exists($element, 'get')) {
                    $row[$field] = $element->get($field);
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSmartFilterSchema(int $iblockId, int $sectionId): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);

        return [
            'iblock_id' => $iblockId,
            'section_id' => $sectionId,
            'items' => $this->getSmartFilterPropertyDefinitions($iblockId, $sectionId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getElement(
        int $iblockId,
        int $elementId,
        string $propertiesMode = self::PROPERTIES_MODE_SMART_FILTER,
        ?int $sectionIdOverride = null,
    ): array {
        $propertiesMode = $this->normalizePropertiesMode($propertiesMode);
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);

        $element = $elementClass::query()
            ->setSelect(self::BASE_ELEMENT_FIELDS)
            ->where('ID', $elementId)
            ->setLimit(1)
            ->fetchObject();

        if (!$element) {
            throw new RuntimeException(sprintf('Element %d not found in iblock %d.', $elementId, $iblockId));
        }

        $sectionId = $sectionIdOverride ?? (int) ($element->getIblockSectionId() ?? 0);
        $propertyDefinitions = $propertiesMode === self::PROPERTIES_MODE_ALL
            ? $this->loadAllPropertyDefinitions($iblockId)
            : $this->getSmartFilterPropertyDefinitions($iblockId, $sectionId);

        $propertyCodes = [];
        foreach ($propertyDefinitions as $prop) {
            if (($prop['CODE'] ?? '') !== '') {
                $propertyCodes[] = (string) $prop['CODE'];
            }
        }

        if ($propertyCodes !== []) {
            try {
                $elementWithProperties = $elementClass::query()
                    ->setSelect(array_merge(self::BASE_ELEMENT_FIELDS, $propertyCodes))
                    ->where('ID', $elementId)
                    ->setLimit(1)
                    ->fetchObject();

                if ($elementWithProperties) {
                    $element = $elementWithProperties;
                }
            } catch (\Throwable $e) {
                error_log('[bitrix-mcp-server] element property select failed: ' . $e->getMessage());
            }
        }

        $data = $this->buildElementData($element);
        $data['properties_mode'] = $propertiesMode;
        $data['smart_filter_section_id'] = $sectionId;
        $data['property_codes'] = $propertyCodes;
        $data['PROPERTIES'] = $this->normalizeElementProperties($element, $propertyDefinitions, $propertyCodes);

        return $data;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function addElement(int $iblockId, array $fields, array $properties = []): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);

        $element = $elementClass::createObject();
        $this->applyElementFields($element, $fields);
        $this->propertyNormalizer->applyToElement($element, $properties);

        $result = $element->save();
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return $this->getElement($iblockId, (int) $element->getId(), self::PROPERTIES_MODE_SMART_FILTER);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function updateElement(
        int $iblockId,
        int $elementId,
        array $fields = [],
        array $properties = [],
    ): array {
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);

        $element = $elementClass::query()
            ->where('ID', $elementId)
            ->setLimit(1)
            ->fetchObject();

        if (!$element) {
            throw new RuntimeException(sprintf('Element %d not found in iblock %d.', $elementId, $iblockId));
        }

        $this->applyElementFields($element, $fields, true);
        $this->propertyNormalizer->applyToElement($element, $properties);

        $result = $element->save();
        if (!$result->isSuccess()) {
            throw new RuntimeException($this->formatErrors($result));
        }

        return $this->getElement($iblockId, $elementId, self::PROPERTIES_MODE_SMART_FILTER);
    }

    public function deleteElement(int $iblockId, int $elementId): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);

        $element = $elementClass::query()
            ->where('ID', $elementId)
            ->setLimit(1)
            ->fetchObject();

        if (!$element) {
            throw new RuntimeException(sprintf('Element %d not found in iblock %d.', $elementId, $iblockId));
        }

        $id = (int) $element->getId();
        $deleteResult = $element->delete();
        if (!$deleteResult->isSuccess()) {
            throw new RuntimeException($this->formatErrors($deleteResult));
        }

        CIBlockElement::UpdateSearch($id, true);

        return ['deleted' => true, 'ID' => $id, 'iblock_id' => $iblockId];
    }

    /**
     * Properties enabled in smart filter for the given section (CIBlockSectionPropertyLink).
     *
     * @return list<array<string, mixed>>
     */
    private function getSmartFilterPropertyDefinitions(int $iblockId, int $sectionId): array
    {
        $links = CIBlockSectionPropertyLink::GetArray($iblockId, $sectionId);
        if (!is_array($links) || $links === []) {
            $links = CIBlockSectionPropertyLink::GetArray($iblockId, 0);
        }

        if (!is_array($links) || $links === []) {
            return [];
        }

        $propertyIds = [];
        $linkMeta = [];
        foreach ($links as $propertyId => $link) {
            if (!is_array($link)) {
                continue;
            }
            if (($link['SMART_FILTER'] ?? '') !== 'Y') {
                continue;
            }
            if (($link['ACTIVE'] ?? 'Y') === 'N') {
                continue;
            }

            $id = (int) $propertyId;
            if ($id <= 0) {
                continue;
            }

            $propertyIds[] = $id;
            $linkMeta[$id] = $link;
        }

        if ($propertyIds === []) {
            return [];
        }

        return $this->loadPropertyDefinitionsByIds($iblockId, $propertyIds, $linkMeta);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAllPropertyDefinitions(int $iblockId): array
    {
        $properties = [];
        $propRes = PropertyTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'select' => [
                'ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED',
                'USER_TYPE', 'LINK_IBLOCK_ID', 'SORT',
            ],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ]);

        while ($prop = $propRes->fetch()) {
            if ((string) ($prop['CODE'] ?? '') === '') {
                continue;
            }
            $properties[] = $this->mapPropertyDefinition($prop, false);
        }

        return $properties;
    }

    /**
     * @param list<int> $propertyIds
     * @param array<int, array<string, mixed>> $linkMeta
     * @return list<array<string, mixed>>
     */
    private function loadPropertyDefinitionsByIds(int $iblockId, array $propertyIds, array $linkMeta = []): array
    {
        $properties = [];
        $propRes = PropertyTable::getList([
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '@ID' => array_values(array_unique($propertyIds)),
            ],
            'select' => [
                'ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED',
                'USER_TYPE', 'LINK_IBLOCK_ID', 'SORT',
            ],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ]);

        while ($prop = $propRes->fetch()) {
            if ((string) ($prop['CODE'] ?? '') === '') {
                continue;
            }
            $id = (int) $prop['ID'];
            $link = $linkMeta[$id] ?? [];
            $entry = $this->mapPropertyDefinition($prop, false);
            if ($link !== []) {
                $entry['SMART_FILTER'] = 'Y';
                $entry['DISPLAY_TYPE'] = (string) ($link['DISPLAY_TYPE'] ?? '');
                $entry['DISPLAY_EXPANDED'] = (string) ($link['DISPLAY_EXPANDED'] ?? '');
            }
            $properties[] = $entry;
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $prop
     * @return array<string, mixed>
     */
    private function mapPropertyDefinition(array $prop, bool $includeEnum = false): array
    {
        $code = (string) $prop['CODE'];
        $entry = [
            'ID' => (int) $prop['ID'],
            'CODE' => $code,
            'NAME' => (string) $prop['NAME'],
            'PROPERTY_TYPE' => (string) $prop['PROPERTY_TYPE'],
            'MULTIPLE' => (string) $prop['MULTIPLE'],
            'IS_REQUIRED' => (string) $prop['IS_REQUIRED'],
            'USER_TYPE' => (string) ($prop['USER_TYPE'] ?? ''),
            'LINK_IBLOCK_ID' => (int) ($prop['LINK_IBLOCK_ID'] ?? 0),
        ];

        if ($includeEnum && $prop['PROPERTY_TYPE'] === 'L') {
            $entry['ENUM'] = $this->loadEnum((int) $prop['ID']);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildElementData(object $element): array
    {
        return [
            'ID' => $element->getId(),
            'NAME' => $element->getName(),
            'CODE' => $element->getCode(),
            'ACTIVE' => $element->getActive() ? 'Y' : 'N',
            'IBLOCK_SECTION_ID' => $element->getIblockSectionId(),
            'PREVIEW_TEXT' => method_exists($element, 'getPreviewText') ? $element->getPreviewText() : null,
            'DETAIL_TEXT' => method_exists($element, 'getDetailText') ? $element->getDetailText() : null,
            'SORT' => method_exists($element, 'getSort') ? $element->getSort() : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $propertyDefinitions
     * @param list<string> $propertyCodes
     * @return array<string, mixed>
     */
    private function normalizeElementProperties(object $element, array $propertyDefinitions, array $propertyCodes): array
    {
        $propMap = [];
        foreach ($propertyDefinitions as $prop) {
            $propMap[$prop['CODE']] = $prop;
        }

        $properties = [];
        foreach ($propertyCodes as $code) {
            try {
                $raw = method_exists($element, 'get') ? $element->get($code) : null;
                $properties[$code] = $this->propertyNormalizer->normalizeForOutput(
                    $propMap[$code] ?? ['PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'N', 'USER_TYPE' => ''],
                    $raw
                );
            } catch (\Throwable $e) {
                $properties[$code] = [
                    '_error' => $e->getMessage(),
                ];
            }
        }

        return $properties;
    }

    private function normalizePropertiesMode(string $mode): string
    {
        if (!in_array($mode, [self::PROPERTIES_MODE_ALL, self::PROPERTIES_MODE_SMART_FILTER], true)) {
            throw new RuntimeException('properties_mode must be "all" or "smart_filter".');
        }

        return $mode;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEnum(int $propertyId): array
    {
        $enums = [];
        $res = PropertyEnumerationTable::getList([
            'filter' => ['=PROPERTY_ID' => $propertyId],
            'select' => ['ID', 'VALUE', 'XML_ID', 'SORT'],
            'order' => ['SORT' => 'ASC'],
        ]);
        while ($row = $res->fetch()) {
            $enums[] = [
                'ID' => (int) $row['ID'],
                'VALUE' => (string) $row['VALUE'],
                'XML_ID' => (string) ($row['XML_ID'] ?? ''),
            ];
        }

        return $enums;
    }

    /**
     * @param object $query Bitrix ORM query
     * @param array<string, mixed> $filter
     */
    private function applyQueryFilter(object $query, array $filter): void
    {
        foreach ($filter as $field => $value) {
            $field = preg_replace('/[^A-Za-z0-9_]/', '', (string) $field) ?? '';
            if ($field === '') {
                continue;
            }
            if (!method_exists($query, 'where')) {
                break;
            }
            $query->where($field, $value);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyElementFields(object $element, array $fields, bool $patch = false): void
    {
        $map = [
            'NAME' => 'setName',
            'CODE' => 'setCode',
            'ACTIVE' => 'setActive',
            'PREVIEW_TEXT' => 'setPreviewText',
            'DETAIL_TEXT' => 'setDetailText',
            'SORT' => 'setSort',
            'IBLOCK_SECTION_ID' => 'setIblockSectionId',
        ];

        foreach ($map as $key => $method) {
            if (!array_key_exists($key, $fields)) {
                if (!$patch && $key === 'NAME') {
                    throw new RuntimeException('Field NAME is required when creating an element.');
                }
                continue;
            }

            if (!method_exists($element, $method)) {
                continue;
            }

            $value = $fields[$key];
            if ($key === 'ACTIVE') {
                $element->$method($value === 'Y' || $value === true || $value === 1 || $value === '1');
                continue;
            }

            $element->$method($value);
        }
    }

    private function fieldToGetter(string $field): string
    {
        $parts = explode('_', strtolower($field));
        $camel = '';
        foreach ($parts as $part) {
            $camel .= ucfirst($part);
        }

        return $camel;
    }

    private function formatErrors(object $result): string
    {
        if (!method_exists($result, 'getErrors')) {
            return 'Bitrix operation failed.';
        }

        $messages = [];
        foreach ($result->getErrors() as $error) {
            $messages[] = method_exists($error, 'getMessage') ? $error->getMessage() : (string) $error;
        }

        return implode('; ', $messages) ?: 'Bitrix operation failed.';
    }
}
