<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

/**
 * Normalizes iblock property values for MCP responses and documents input formats for agents.
 */
final class PropertyNormalizer
{
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
