<?php
declare(strict_types=1);

require_once __DIR__ . '/Fit/FitValidationException.php';
require_once __DIR__ . '/Fit/FitService.php';
require_once __DIR__ . '/Fit/FitMcpController.php';
require_once __DIR__ . '/Fit/FitOAuthService.php';

function fit_base_path(): string {
  if (isset($_SERVER['PORTAL_BASE_PATH'])) {
    return (string) $_SERVER['PORTAL_BASE_PATH'];
  }
  $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
  $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
  return ($base === '' || $base === '.') ? '' : $base;
}

function fit_request_path(): string {
  $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
  $base = fit_base_path();
  if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base));
  }
  return $path === '' ? '/' : $path;
}

function fit_service(PDO $pdo): FitService {
  return new FitService($pdo);
}

function fit_oauth_service(PDO $pdo): FitOAuthService { return new FitOAuthService($pdo); }
