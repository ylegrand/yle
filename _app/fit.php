<?php
declare(strict_types=1);

require_once __DIR__ . '/Fit/FitValidationException.php';
require_once __DIR__ . '/Fit/FitService.php';

function fit_service(PDO $pdo): FitService {
  return new FitService($pdo);
}
