<?php

function load_env_file(string $path): void {
  if (!is_file($path) || !is_readable($path)) {
    return;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return;
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }

    $pos = strpos($line, '=');
    if ($pos === false) {
      continue;
    }

    $key = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));

    if ($key === '' || getenv($key) !== false) {
      continue;
    }

    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
      $value = substr($value, 1, -1);
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
  }
}

function env_value(string $key, ?string $default = null): ?string {
  $value = getenv($key);
  return $value === false ? $default : $value;
}

function env_bool(string $key, bool $default): bool {
  $raw = env_value($key);
  if ($raw === null) {
    return $default;
  }

  $raw = strtolower(trim($raw));
  return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function portal_base_path(): string {
  if (isset($_SERVER['PORTAL_BASE_PATH'])) {
    return (string) $_SERVER['PORTAL_BASE_PATH'];
  }
  $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
  $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
  if (str_ends_with($base, '/_admin')) {
    $base = rtrim(dirname($base), '/');
  }
  return ($base === '' || $base === '.') ? '' : $base;
}

function portal_configure_production_error_handling(array $cfg): void {
  if (PHP_SAPI === 'cli' || ($cfg['app_env'] ?? 'prod') !== 'prod') {
    return;
  }

  // Les détails vont dans les logs PHP, jamais dans une réponse HTTP de prod.
  ini_set('display_errors', '0');
  ini_set('display_startup_errors', '0');
  ini_set('html_errors', '0');
  ini_set('xdebug.display_exception', '0');
  ini_set('xdebug.force_display_errors', '0');

  set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
      'Portal internal error: %s in %s:%d',
      get_class($exception),
      basename($exception->getFile()),
      $exception->getLine()
    ));

    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      header('X-Content-Type-Options: nosniff');
    }

    echo 'Internal server error';
  });
}

load_env_file(__DIR__ . '/../.env');

$appEnv = strtolower((string) env_value('APP_ENV', 'prod'));
if (!in_array($appEnv, ['dev', 'prod'], true)) {
  $appEnv = 'prod';
}

$httpsDetected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
$cookieSecure = $appEnv === 'prod'
  ? true
  : env_bool('COOKIE_SECURE', $httpsDetected);
$cookieSameSite = env_value('COOKIE_SAMESITE', 'Lax');
if (!in_array($cookieSameSite, ['Lax', 'Strict', 'None'], true)) {
  $cookieSameSite = 'Lax';
}
if ($cookieSameSite === 'None' && !$cookieSecure) {
  $cookieSameSite = 'Lax';
}

$config = [
  'app_env' => $appEnv,
  'db_host' => env_value('DB_HOST', 'localhost'),
  'db_port' => (int) env_value('DB_PORT', '3306'),
  'db_name' => env_value('DB_NAME', ''),
  'db_user' => env_value('DB_USER', ''),
  'db_pass' => env_value('DB_PASS', ''),
  'share_token_secret' => env_value('SHARE_TOKEN_SECRET', ''),
  'install_enabled' => env_bool('INSTALL_ENABLED', false),
  'fit_mcp_enabled' => env_bool('FIT_MCP_ENABLED', false),
  'fit_mcp_base_url' => rtrim((string) env_value('FIT_MCP_BASE_URL', ''), '/'),
  'fit_oauth_clients' => (string) env_value('FIT_OAUTH_CLIENTS', ''),

  // sécurité cookies session
  'cookie_secure' => $cookieSecure,
  'cookie_samesite' => $cookieSameSite,
];

portal_configure_production_error_handling($config);

return $config;
