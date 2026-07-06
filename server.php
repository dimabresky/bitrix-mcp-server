#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bitrix MCP server entry point (STDIO).
 * Requires DOCUMENT_ROOT and MCP_AUTH_TOKEN in environment on the Bitrix server.
 */

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Bootstrap\BitrixBootstrap;
use BitrixMcp\Config\Config;
use BitrixMcp\Security\WhitelistGuard;
use BitrixMcp\Service\HighloadService;
use BitrixMcp\Service\IblockEntityResolver;
use BitrixMcp\Service\IblockService;
use BitrixMcp\Service\PropertyNormalizer;
use BitrixMcp\Tool\HighloadTools;
use BitrixMcp\Tool\IblockTools;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

$baseDir = __DIR__;

require $baseDir . '/vendor/autoload.php';

try {
    $config = Config::load($baseDir);
    BitrixBootstrap::init($config);

    $auth = new TokenAuthenticator($config);
    $whitelist = new WhitelistGuard($config);
    $audit = new AuditLogger($config);
    $resolver = new IblockEntityResolver();
    $propertyNormalizer = new PropertyNormalizer();

    $iblockService = new IblockService($config, $whitelist, $resolver, $propertyNormalizer);
    $hlService = new HighloadService($config, $whitelist);

    $iblockTools = new IblockTools($auth, $audit, $iblockService);
    $hlTools = new HighloadTools($auth, $audit, $hlService);

    $server = Server::builder()
        ->setServerInfo('Bitrix IBlock MCP', '1.0.0')
        ->addTool([$iblockTools, 'iblockList'], 'iblock_list')
        ->addTool([$iblockTools, 'iblockSchema'], 'iblock_schema')
        ->addTool([$iblockTools, 'iblockSectionsList'], 'iblock_sections_list')
        ->addTool([$iblockTools, 'iblockElementsList'], 'iblock_elements_list')
        ->addTool([$iblockTools, 'iblockElementGet'], 'iblock_element_get')
        ->addTool([$iblockTools, 'iblockElementAdd'], 'iblock_element_add')
        ->addTool([$iblockTools, 'iblockElementUpdate'], 'iblock_element_update')
        ->addTool([$iblockTools, 'iblockElementDelete'], 'iblock_element_delete')
        ->addTool([$hlTools, 'hlblockList'], 'hlblock_list')
        ->addTool([$hlTools, 'hlblockSchema'], 'hlblock_schema')
        ->addTool([$hlTools, 'hlblockRecordsList'], 'hlblock_records_list')
        ->addTool([$hlTools, 'hlblockRecordGet'], 'hlblock_record_get')
        ->addTool([$hlTools, 'hlblockRecordAdd'], 'hlblock_record_add')
        ->addTool([$hlTools, 'hlblockRecordUpdate'], 'hlblock_record_update')
        ->addTool([$hlTools, 'hlblockRecordDelete'], 'hlblock_record_delete')
        ->build();

    $transport = new StdioTransport();
    $status = $server->run($transport);
    exit($status);
} catch (Throwable $e) {
    fwrite(STDERR, '[bitrix-mcp-server] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
