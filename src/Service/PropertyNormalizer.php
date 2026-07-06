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
