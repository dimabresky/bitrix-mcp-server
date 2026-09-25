<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

/**
 * Normalizes iblock property values for MCP responses and documents input formats for agents.
 */
final class PropertyNormalizer
{
    /**
     * @param array<string, mixed> $schemaProperty row from PropertyTable + ENUMS
     * @param mixed $rawValue ORM property value object or scalar
     */
    public function normalizeForOutput(array $schemaProperty, mixed $rawValue): mixed
    {
        if ($rawValue === null) {
            return null;
        }

        $type = (string) ($schemaProperty['PROPERTY_TYPE'] ?? 'S');
        $multiple = ($schemaProperty['MULTIPLE'] ?? 'N') === 'Y';
        $userType = (string) ($schemaProperty['USER_TYPE'] ?? '');

        if ($multiple && is_iterable($rawValue)) {
            $items = [];
            foreach ($rawValue as $item) {
                $items[] = $this->normalizeSingle($type, $userType, $item);
            }

            return $items;
        }

        return $this->normalizeSingle($type, $userType, $rawValue);
    }

    private function normalizeSingle(string $type, string $userType, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'getItem')) {
            $item = $value->getItem();
            if ($item === null) {
                return null;
            }
            if ($type === 'L' && method_exists($item, 'getId')) {
                return [
                    'ID' => $item->getId(),
                    'VALUE' => method_exists($item, 'getValue') ? $item->getValue() : null,
                    'XML_ID' => method_exists($item, 'getXmlId') ? $item->getXmlId() : null,
                ];
            }
            if (method_exists($item, 'getValue')) {
                return $item->getValue();
            }
        }

        if (is_object($value) && method_exists($value, 'getValue')) {
            return $value->getValue();
        }

        if ($type === 'E' || $type === 'G') {
            return is_numeric($value) ? (int) $value : $value;
        }

        if ($userType === 'directory') {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Scalar / JSON value from CIBlockElement::GetProperties() row.
     *
     * @param array<string, mixed> $schemaProperty
     * @param array<string, mixed> $classicProperty
     */
    public function extractClassicValue(array $schemaProperty, array $classicProperty): mixed
    {
        $type = (string) ($schemaProperty['PROPERTY_TYPE'] ?? 'S');
        $multiple = ($schemaProperty['MULTIPLE'] ?? 'N') === 'Y';
        $userType = (string) ($schemaProperty['USER_TYPE'] ?? '');
        $raw = $classicProperty['~VALUE'] ?? $classicProperty['VALUE'] ?? null;

        if ($userType === 'Polygon') {
            return $this->polygonToJsonString($raw);
        }

        if ($multiple) {
            if ($raw === null || $raw === false || $raw === '') {
                return [];
            }
            $items = is_array($raw) ? $raw : [$raw];
            $out = [];
            foreach ($items as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                $out[] = $this->classicScalar($type, $item);
            }

            return $out;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw) && ($type === 'S' || $userType === 'HTML')) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->classicScalar($type, $raw);
    }

    /**
     * Values for CIBlockElement::SetPropertyValuesEx.
     *
     * @param array<string, mixed> $schemaProperty
     */
    public function prepareForClassicWrite(array $schemaProperty, mixed $value): mixed
    {
        $type = (string) ($schemaProperty['PROPERTY_TYPE'] ?? 'S');
        $multiple = ($schemaProperty['MULTIPLE'] ?? 'N') === 'Y';
        $userType = (string) ($schemaProperty['USER_TYPE'] ?? '');

        if ($userType === 'Polygon') {
            return $this->polygonToJsonString($value) ?? '';
        }

        if ($multiple) {
            if ($value === null || $value === '') {
                return [];
            }
            $items = is_array($value) && $this->isListArray($value) ? $value : [$value];
            $out = [];
            foreach ($items as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                $out[] = $this->classicScalar($type, $item);
            }

            return $out;
        }

        if ($value === null) {
            return false;
        }

        if (is_array($value) && $this->isListArray($value) && ($type === 'S' || $userType === 'HTML')) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->classicScalar($type, $value);
    }

    private function polygonToJsonString(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_object($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $string = trim((string) $raw);

        return $string === '' ? null : $string;
    }

    private function classicScalar(string $type, mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('VALUE', $value)) {
            $value = $value['VALUE'];
        }
        if ($type === 'E' || $type === 'G' || $type === 'L') {
            return is_numeric($value) ? (int) $value : $value;
        }
        if ($type === 'N') {
            return is_numeric($value) ? (string) $value : (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }
        if (is_scalar($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Apply incoming properties to element object (patch). Keys = property CODE (uppercase).
     *
     * @param object $element ORM element object
     * @param array<string, mixed> $properties
     */
    public function applyToElement(object $element, array $properties): void
    {
        foreach ($properties as $code => $value) {
            $code = strtoupper((string) $code);
            if ($value === null) {
                continue;
            }

            if (is_array($value) && $this->isListArray($value)) {
                $nestedGeometry = isset($value[0]) && is_array($value[0]);
                if ($nestedGeometry && method_exists($element, 'set')) {
                    $element->set(
                        $code,
                        json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                    continue;
                }
                if (method_exists($element, 'removeAll')) {
                    $element->removeAll($code);
                }
                foreach ($value as $item) {
                    if (method_exists($element, 'addTo')) {
                        $element->addTo($code, $item);
                    }
                }
                continue;
            }

            if (method_exists($element, 'set')) {
                $element->set($code, $value);
            }
        }
    }

    /**
     * @param array<int, mixed> $arr
     */
    private function isListArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
