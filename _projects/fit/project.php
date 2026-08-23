<?php

declare(strict_types=1);

return [
    // Routes intentionally outside /p/fit/: token and MCP calls authenticate
    // with OAuth/Bearer rather than the portal session cookie.
    'external_routes' => [
        '/.well-known/oauth-protected-resource/fit-mcp',
        '/.well-known/oauth-authorization-server',
        '/fit-mcp/authorize',
        '/fit-mcp/token',
        '/fit-mcp/revoke',
        '/fit-mcp/mcp',
    ],
    'external_handler' => 'server/mcp.php',
];
