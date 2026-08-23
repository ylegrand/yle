<?php
declare(strict_types=1);

require_once __DIR__ . '/Fit/FitValidationException.php';
require_once __DIR__ . '/Fit/FitService.php';
require_once __DIR__ . '/Fit/FitMcpController.php';
require_once __DIR__ . '/Fit/FitOAuthService.php';
require_once __DIR__ . '/Fit/FitMcpController.php';

function fit_service(PDO $pdo): FitService {
  return new FitService($pdo);
}

function fit_oauth_service(PDO $pdo): FitOAuthService { return new FitOAuthService($pdo); }
