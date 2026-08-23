<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/projects.php';

function portal_migration_statements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $statements), static fn(string $statement): bool => $statement !== ''));
}

function portal_migration_files(string $portalRoot): array {
    $files = [];

    $coreDir = $portalRoot . DIRECTORY_SEPARATOR . 'migrations';
    if (is_dir($coreDir)) {
        foreach (glob($coreDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
            $files[] = ['version' => 'core:' . basename($file), 'legacy' => basename($file), 'path' => $file];
        }
    }

    $projectsRoot = $portalRoot . DIRECTORY_SEPARATOR . '_projects';
    if (is_dir($projectsRoot)) {
        foreach (list_project_slugs($projectsRoot) as $slug) {
            $migrationDir = $projectsRoot . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'migrations';
            if (!is_dir($migrationDir)) continue;
            foreach (glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
                $files[] = [
                    'version' => 'project:' . $slug . ':' . basename($file),
                    'legacy' => basename($file),
                    'path' => $file,
                ];
            }
        }
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['version'], $b['version']));
    return $files;
}

$dryRun = in_array('--dry-run', $argv, true);
$portalRoot = realpath(__DIR__ . '/..');
if ($portalRoot === false) {
    fwrite(STDERR, "Portal root not found\n");
    exit(1);
}

try {
    $pdo = db($cfg);
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
      version VARCHAR(190) NOT NULL PRIMARY KEY,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $appliedVersions = array_fill_keys(array_map('strval', $applied), true);

    foreach (portal_migration_files($portalRoot) as $migration) {
        $version = $migration['version'];
        $legacy = $migration['legacy'];
        $file = $migration['path'];

        // Compatibility with migrations applied before project migrations were namespaced.
        if (isset($appliedVersions[$version]) || isset($appliedVersions[$legacy])) {
            echo "SKIP {$version}\n";
            continue;
        }

        echo ($dryRun ? 'PLAN ' : 'APPLY ') . $version . "\n";
        if ($dryRun) continue;

        $sql = file_get_contents($file);
        if ($sql === false) throw new RuntimeException("Cannot read migration {$version}");

        foreach (portal_migration_statements($sql) as $statement) {
            $pdo->exec($statement);
        }

        $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$version]);
        $appliedVersions[$version] = true;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
