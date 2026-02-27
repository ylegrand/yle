<?php
function projects_root_path(string $projectsRoot): string {
  $root = realpath($projectsRoot);
  if (!$root || !is_dir($root)) {
    throw new RuntimeException("Racine projets invalide");
  }
  return $root;
}

function list_project_slugs(string $projectsRoot): array {
  $root = projects_root_path($projectsRoot);
  $items = @scandir($root) ?: [];
  $slugs = [];
  foreach ($items as $it) {
    if ($it === '.' || $it === '..') continue;
    if ($it[0] === '.') continue;
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $it)) continue;
    $full = $root . DIRECTORY_SEPARATOR . $it;
    if (is_dir($full)) $slugs[] = $it;
  }
  sort($slugs);
  return $slugs;
}

function sync_projects(PDO $pdo, string $projectsRoot): array {
  $seen = list_project_slugs($projectsRoot);
  $now = date('Y-m-d H:i:s');

  $ins = $pdo->prepare("
    INSERT INTO projects(slug,is_active,last_seen_at,deleted_at) VALUES(?,1,?,NULL)
    ON DUPLICATE KEY UPDATE is_active=1, last_seen_at=VALUES(last_seen_at), deleted_at=NULL
  ");
  foreach ($seen as $slug) $ins->execute([$slug, $now]);

  $rows = $pdo->query("SELECT slug FROM projects WHERE deleted_at IS NULL")->fetchAll();
  $active = array_map(fn($r)=>$r['slug'], $rows);
  $missing = array_values(array_diff($active, $seen));

  if ($missing) {
    $in = implode(',', array_fill(0, count($missing), '?'));
    $st = $pdo->prepare("UPDATE projects SET is_active=0, deleted_at=NOW() WHERE slug IN ($in)");
    $st->execute($missing);
  }

  $pdo->exec("DELETE upr FROM user_project_roles upr JOIN projects p ON p.id = upr.project_id WHERE p.is_active=0 OR p.deleted_at IS NOT NULL");

  return ['seen'=>$seen, 'missing'=>$missing];
}

function sync_projects_from_filesystem(PDO $pdo, string $projectsRoot): array {
  return sync_projects($pdo, $projectsRoot);
}
