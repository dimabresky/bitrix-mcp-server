<?php

declare(strict_types=1);

namespace BitrixMcp\Server;

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use BitrixMcp\Service\HighloadService;
use BitrixMcp\Service\IblockEntityResolver;
use BitrixMcp\Service\IblockService;
use BitrixMcp\Service\PropertyNormalizer;
use BitrixMcp\Tool\HighloadTools;
use BitrixMcp\Tool\IblockTools;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use RuntimeException;

final class McpServerFactory
{
    public static function create(Config $config): Server
    {
        $sessionPath = $config->sessionStorePath();
        if ($sessionPath === '') {
            throw new RuntimeException('session_store_path must be set in config.php.');
        }

        $auth = new TokenAuthenticator($config);
        $whitelist = new WhitelistGuard($config);
        $audit = new AuditLogger($config);
        $resolver = new IblockEntityResolver();
        $propertyNormalizer = new PropertyNormalizer();

        $iblockService = new IblockService($config, $whitelist, $resolver, $propertyNormalizer);
        $hlService = new HighloadService($config, $whitelist);

        $iblockTools = new IblockTools($auth, $audit, $iblockService);
        $hlTools = new HighloadTools($auth, $audit, $hlService);

        // mcp/sdk HandlerResolver accepts Closure or [ClassName::class, 'method'], not [$instance, 'method'].
        return Server::builder()
            ->setServerInfo('Bitrix IBlock MCP', '2.0.0')
            ->setSession(new FileSessionStore($sessionPath, $config->sessionTtl()))
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockList']), 'iblock_list')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockSchema']), 'iblock_schema')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockSectionsList']), 'iblock_sections_list')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockElementsList']), 'iblock_elements_list')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockElementGet']), 'iblock_element_get')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockElementAdd']), 'iblock_element_add')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockElementUpdate']), 'iblock_element_update')
            ->addTool(\Closure::fromCallable([$iblockTools, 'iblockElementDelete']), 'iblock_element_delete')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockList']), 'hlblock_list')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockSchema']), 'hlblock_schema')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockRecordsList']), 'hlblock_records_list')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockRecordGet']), 'hlblock_record_get')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockRecordAdd']), 'hlblock_record_add')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockRecordUpdate']), 'hlblock_record_update')
            ->addTool(\Closure::fromCallable([$hlTools, 'hlblockRecordDelete']), 'hlblock_record_delete')
            ->build();
    }
}
