<?php
function role_rank(string $r): int {
  return ['viewer'=>1,'editor'=>2,'admin'=>3][$r] ?? 0;
}

function user_role_for_project(PDO $pdo, int $userId, string $slug): ?string {
  $st = $pdo->prepare("
    SELECT upr.role
    FROM user_project_roles upr
    JOIN projects p ON p.id = upr.project_id
    WHERE upr.user_id=? AND p.slug=? AND p.is_active=1 AND p.deleted_at IS NULL
  ");
  $st->execute([$userId, $slug]);
  $row = $st->fetch();
  return $row['role'] ?? null;
}

function require_project_role(PDO $pdo, array $user, string $slug, string $minRole='viewer'): void {
  if (!empty($user['is_superadmin'])) return;
  $r = user_role_for_project($pdo, (int)$user['id'], $slug);
  if (!$r || role_rank($r) < role_rank($minRole)) {
    http_response_code(403);
    exit("Forbidden");
  }
}
