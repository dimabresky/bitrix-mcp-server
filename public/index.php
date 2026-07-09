<?php

declare(strict_types=1);

/**
 * Bitrix MCP server HTTP entry point (Streamable HTTP).
 * Deploy under /local/mcp/public/ on the Bitrix site.
 */

use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Bootstrap\BitrixBootstrap;
use BitrixMcp\Config\Config;
use BitrixMcp\Server\McpServerFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;

$baseDir = dirname(__DIR__);

require $baseDir . '/vendor/autoload.php';

try {
    $config = Config::load($baseDir);

    if (!TokenAuthenticator::isRequestAuthorized($config)) {
        (new SapiEmitter())->emit(new Response(401, [], '{"error":"Unauthorized"}'));
        exit;
    }

    BitrixBootstrap::init($config);

    $psr17Factory = new Psr17Factory();
    $creator = new ServerRequestCreator(
        $psr17Factory,
        $psr17Factory,
        $psr17Factory,
        $psr17Factory,
    );
    $request = $creator->fromGlobals();

    $middleware = [
        new CorsMiddleware(),
        new ProtocolVersionMiddleware(),
    ];

    $server = McpServerFactory::create($config);
    $transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory, null, $middleware);
    $response = $server->run($transport);

    (new SapiEmitter())->emit($response);
} catch (Throwable $e) {
    error_log('[bitrix-mcp-server] ' . $e->getMessage());
    if (!headers_sent()) {
        (new SapiEmitter())->emit(new Response(500, [], '{"error":"Internal Server Error"}'));
    }
}
