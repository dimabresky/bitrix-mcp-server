<?php

declare(strict_types=1);

/**
 * Copy to config.php and adjust for your Bitrix site.
 * Do not commit config.php (secrets and site-specific IDs).
 */
return [
    // Site identifier (LID), single-site setup
    'site_id' => 's1',

    // Bitrix user ID for $USER->Authorize() — must have rights on whitelisted iblocks/HL
    'service_user_id' => 1,

    // Bearer token for MCP clients (Authorization: Bearer <token>)
    'auth_token' => 'change-me-long-random-string',

    // Allowed infoblock IDs (numeric). Each must have API_CODE set for ORM operations.
    'allowed_iblocks' => [],

    // Allowed highload block IDs
    'allowed_hlblocks' => [],

    // Default and maximum page size for list tools
    'max_list_limit' => 50,

    'audit_log_path' => __DIR__ . '/logs/audit.log',

    // MCP HTTP session storage (must be writable by PHP)
    'session_store_path' => __DIR__ . '/sessions',
    'session_ttl' => 3600,

    // Hostnames permitted by DNS rebinding protection (your site domain(s))
    'allowed_hosts' => ['staging.example.com', 'www.example.com'],
];
