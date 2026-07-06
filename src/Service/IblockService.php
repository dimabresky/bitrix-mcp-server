<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use CIBlockElement;
use RuntimeException;

final class IblockService
{
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
    public function getElement(int $iblockId, int $elementId): array
    {
        $this->whitelist->assertIblockAllowed($iblockId);
        $elementClass = $this->resolver->elementDataClass($iblockId);
        $schema = $this->getSchema($iblockId);

        $propertyCodes = [];
        foreach ($schema['properties'] as $prop) {
            if ($prop['CODE'] !== '') {
                $propertyCodes[] = $prop['CODE'];
            }
        }

        $select = array_merge(
            ['ID', 'NAME', 'CODE', 'ACTIVE', 'IBLOCK_SECTION_ID', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'SORT'],
            $propertyCodes
        );

        $element = $elementClass::query()
            ->setSelect($select)
            ->where('ID', $elementId)
            ->setLimit(1)
            ->fetchObject();

        if (!$element) {
            throw new RuntimeException(sprintf('Element %d not found in iblock %d.', $elementId, $iblockId));
        }

        $data = [
            'ID' => $element->getId(),
            'NAME' => $element->getName(),
            'CODE' => $element->getCode(),
            'ACTIVE' => $element->getActive() ? 'Y' : 'N',
            'IBLOCK_SECTION_ID' => $element->getIblockSectionId(),
            'PREVIEW_TEXT' => method_exists($element, 'getPreviewText') ? $element->getPreviewText() : null,
            'DETAIL_TEXT' => method_exists($element, 'getDetailText') ? $element->getDetailText() : null,
            'SORT' => method_exists($element, 'getSort') ? $element->getSort() : null,
            'PROPERTIES' => [],
        ];

        $propMap = [];
        foreach ($schema['properties'] as $prop) {
            $propMap[$prop['CODE']] = $prop;
        }

        foreach ($propertyCodes as $code) {
            $raw = method_exists($element, 'get') ? $element->get($code) : null;
            $data['PROPERTIES'][$code] = $this->propertyNormalizer->normalizeForOutput(
                $propMap[$code] ?? ['PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'N', 'USER_TYPE' => ''],
                $raw
            );
        }

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

        return $this->getElement($iblockId, (int) $element->getId());
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

        return $this->getElement($iblockId, $elementId);
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
