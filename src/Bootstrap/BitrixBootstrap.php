<?php

declare(strict_types=1);

namespace BitrixMcp\Bootstrap;

use BitrixMcp\Config\Config;
use RuntimeException;

final class BitrixBootstrap
{
    private static bool $initialized = false;

    public static function init(Config $config): void
    {
        if (self::$initialized) {
            return;
        }

        if (!defined('NO_KEEP_STATISTIC')) {
            define('NO_KEEP_STATISTIC', true);
        }
        if (!defined('BX_CRONTAB')) {
            define('BX_CRONTAB', true);
        }
        if (!defined('BX_NO_ACCELERATOR_RESET')) {
            define('BX_NO_ACCELERATOR_RESET', true);
        }

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot === '' || !is_file($docRoot . '/bitrix/modules/main/include/prolog_before.php')) {
            $docRoot = getenv('DOCUMENT_ROOT') ?: '';
        }
        if ($docRoot === '' || !is_file($docRoot . '/bitrix/modules/main/include/prolog_before.php')) {
            throw new RuntimeException(
                'Set DOCUMENT_ROOT to the Bitrix site root (directory containing /bitrix).'
            );
        }

        $_SERVER['DOCUMENT_ROOT'] = $docRoot;

        require $docRoot . '/bitrix/modules/main/include/prolog_before.php';

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new RuntimeException('Failed to load Bitrix module: iblock');
        }

        if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
            throw new RuntimeException('Failed to load Bitrix module: highloadblock');
        }

        $userId = $config->serviceUserId();
        if ($userId <= 0) {
            throw new RuntimeException('service_user_id must be a positive integer in config.php');
        }

        global $USER;
        if (!is_object($USER)) {
            throw new RuntimeException('Bitrix $USER is not available after prolog_before');
        }

        if (!$USER->Authorize($userId)) {
            throw new RuntimeException('Failed to authorize service user ID ' . $userId);
        }

        self::$initialized = true;
    }
}
