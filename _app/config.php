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

load_env_file(__DIR__ . '/../.env');

$appEnv = env_value('APP_ENV', 'prod');
$httpsDetected = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

return [
  'app_env' => $appEnv,
  'db_host' => env_value('DB_HOST', 'localhost'),
  'db_port' => (int) env_value('DB_PORT', '3306'),
  'db_name' => env_value('DB_NAME', ''),
  'db_user' => env_value('DB_USER', ''),
  'db_pass' => env_value('DB_PASS', ''),

  // sécurité cookies session
  'cookie_secure' => env_bool('COOKIE_SECURE', $appEnv === 'prod' ? true : $httpsDetected),
  'cookie_samesite' => env_value('COOKIE_SAMESITE', 'Lax'),
];
