<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the array CIBlockElement and HL file fields expect from a file source.
 */
final class BitrixFileArray
{
    /** @var list<string> */
    private array $temporary = [];

    public function __construct(private readonly FileSource $source)
    {
    }

    public static function isDescriptor(mixed $value): bool
    {
        return FileSource::isDescriptor($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function make(mixed $value, string $moduleId = ''): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                'File value must be {"path":"..."}, {"url":"..."}, {"name":"...","content_base64":"..."}, or {"del":true}.'
            );
        }

        $material = $this->source->materialize($value);
        if ($material['delete'] === true) {
            return ['del' => 'Y'];
        }

        if ($material['temporary']) {
            $this->temporary[] = $material['path'];
        }

        $file = \CFile::MakeFileArray($material['path']);
        if (!is_array($file) || (string) ($file['tmp_name'] ?? '') === '') {
            throw new RuntimeException('CFile::MakeFileArray failed.');
        }
        $file['name'] = $material['name'];
        if ($moduleId !== '') {
            $file['MODULE_ID'] = $moduleId;
        }

        return $file;
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function makeProperty(mixed $value, bool $multiple, string $moduleId = ''): array
    {
        if (!$multiple) {
            return $this->make($value, $moduleId);
        }
        if (self::isDescriptor($value)) {
            return [$this->make($value, $moduleId)];
        }
        if (!is_array($value) || !$this->isList($value)) {
            throw new InvalidArgumentException('Multiple file property expects a JSON array of file sources.');
        }

        $files = [];
        foreach ($value as $item) {
            $files[] = $this->make($item, $moduleId);
        }

        return $files;
    }

    public function cleanup(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporary = [];
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
