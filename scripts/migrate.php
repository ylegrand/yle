<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit('Not found');
}

$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';

function portal_migration_statements(string $sql): array {
  $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
  $statements = preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql) ?: [];
  return array_values(array_filter(array_map('trim', $statements), static fn (string $statement): bool => $statement !== ''));
}

$dryRun = in_array('--dry-run', $argv, true);
$migrationDir = realpath(__DIR__ . '/../migrations');
if ($migrationDir === false) {
  fwrite(STDERR, "Migration directory not found\n");
  exit(1);
}

try {
  $pdo = db($cfg);
  $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
  $appliedVersions = array_fill_keys($applied, true);
  $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
  sort($files, SORT_STRING);

  foreach ($files as $file) {
    $version = basename($file);
    if (isset($appliedVersions[$version])) {
      echo "SKIP {$version}\n";
      continue;
    }

    echo ($dryRun ? 'PLAN ' : 'APPLY ') . $version . "\n";
    if ($dryRun) {
      continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
      throw new RuntimeException("Cannot read migration {$version}");
    }

    foreach (portal_migration_statements($sql) as $statement) {
      $pdo->exec($statement);
    }

    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$version]);
  }
} catch (Throwable $exception) {
  fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
  exit(1);
}
