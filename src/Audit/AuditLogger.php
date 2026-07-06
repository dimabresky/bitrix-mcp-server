<?php

declare(strict_types=1);

namespace BitrixMcp\Audit;

use BitrixMcp\Config\Config;

final class AuditLogger
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function log(string $tool, array $params, bool $success, ?string $message = null): void
    {
        $path = $this->config->auditLogPath();
        if ($path === '') {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, "AuditLogger: cannot create directory {$dir}\n");

            return;
        }

        $entry = [
            'timestamp' => date('c'),
            'tool' => $tool,
            'params' => $this->sanitizeParams($params),
            'success' => $success,
            'message' => $message,
        ];

        file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        unset($params['token'], $params['auth_token']);

        return $params;
    }
}
