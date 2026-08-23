<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

return static function (PDO $pdo, array $portalConfig, string $requestPath): void {
    FitMcpController::handle($pdo, fit_project_config(), $requestPath);
};
