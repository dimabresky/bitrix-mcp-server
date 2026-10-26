<?php

declare(strict_types=1);

namespace BitrixMcp\Audit;

use BitrixMcp\Config\Config;

final class AuditLogger
{
    /** @var list<string> */
    private const REDACTED_KEYS = [
        'token',
        'auth_token',
        'password',
        'confirm_password',
        'content_base64',
    ];

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
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
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
        chmod($path, 0640);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeParams($value);
                continue;
            }
            $clean[$key] = is_string($value) ? $this->redactSecrets($value) : $value;
        }

        return $clean;
    }

    private function redactSecrets(string $value): string
    {
        $redacted = preg_replace_callback(
            '/"(content_base64|password|confirm_password)"\s*:\s*"([^"]*)"/i',
            static function (array $match): string {
                if (strtolower($match[1]) === 'content_base64') {
                    $decoded = base64_decode($match[2], true);
                    $bytes = $decoded === false ? 0 : strlen($decoded);

                    return '"' . $match[1] . '":"[redacted, ' . $bytes . ' bytes]"';
                }

                return '"' . $match[1] . '":"[redacted]"';
            },
            $value
        );

        return is_string($redacted) ? $redacted : $value;
    }
}
