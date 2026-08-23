<?php

declare(strict_types=1);

$portalRoot = dirname(__DIR__, 2);
require_once $portalRoot . '/_app/auth.php';
require_once $portalRoot . '/_app/acl.php';
require_once $portalRoot . '/_app/csrf.php';
require_once $portalRoot . '/_app/http.php';

require_once __DIR__ . '/src/FitValidationException.php';
require_once __DIR__ . '/src/FitService.php';
require_once __DIR__ . '/src/FitOAuthService.php';
require_once __DIR__ . '/src/FitMcpController.php';

function fit_project_config(): array {
    return [
        'enabled' => env_bool('FIT_MCP_ENABLED', false),
        'base_url' => rtrim((string) env_value('FIT_MCP_BASE_URL', ''), '/'),
        'oauth_clients' => (string) env_value('FIT_OAUTH_CLIENTS', ''),
    ];
}

function fit_service(PDO $pdo): FitService {
    return new FitService($pdo);
}

function fit_oauth_service(PDO $pdo): FitOAuthService {
    return new FitOAuthService($pdo);
}
