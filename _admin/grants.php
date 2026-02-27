<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/auth.php';
require __DIR__ . '/../_app/csrf.php';
require __DIR__ . '/../_app/flash.php';
require __DIR__ . '/../_app/projects.php';

$pdo = db($cfg);
start_session($cfg);
$u = require_login($pdo);
if (empty($u['is_superadmin'])) { http_response_code(403); exit("Forbidden"); }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$me = (int)$u['id'];
$projectsRoot = __DIR__ . '/../_projects';

try {
  sync_projects_from_filesystem($pdo, $projectsRoot);
} catch (Throwable $e) {
  flash_set('error', "Erreur sync: " . $e->getMessage());
}

$users = $pdo->query("SELECT id,email FROM users WHERE is_active=1 ORDER BY email")->fetchAll();
$projects = $pdo->query("SELECT id,slug FROM projects WHERE is_active=1 AND deleted_at IS NULL ORDER BY slug")->fetchAll();

$existingRoles = [];
$stRoles = $pdo->query("SELECT user_id, project_id, role FROM user_project_roles");
foreach ($stRoles->fetchAll() as $row) {
  $existingRoles[(int)$row['user_id']][(int)$row['project_id']] = $row['role'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($pdo, $me, $_POST['csrf'] ?? '');

  try {
    $pdo->beginTransaction();

    if ($users && $projects) {
      $userIds = array_map(fn($x) => (int)$x['id'], $users);
      $projectIds = array_map(fn($x) => (int)$x['id'], $projects);

      $uIn = implode(',', array_fill(0, count($userIds), '?'));
      $pIn = implode(',', array_fill(0, count($projectIds), '?'));
      $pdo->prepare("DELETE FROM user_project_roles WHERE user_id IN ($uIn) AND project_id IN ($pIn)")
          ->execute(array_merge($userIds, $projectIds));

      $ins = $pdo->prepare("INSERT INTO user_project_roles(user_id,project_id,role) VALUES(?,?,?)");
      foreach ($projects as $p) {
        $pid = (int)$p['id'];
        foreach ($users as $usr) {
          $uid = (int)$usr['id'];
          $field = 'allow_' . $pid . '_' . $uid;
          if (!empty($_POST[$field])) {
            $role = $existingRoles[$uid][$pid] ?? 'viewer';
            if (!in_array($role, ['viewer', 'editor', 'admin'], true)) $role = 'viewer';
            $ins->execute([$uid, $pid, $role]);
          }
        }
      }
    }

    $pdo->commit();
    flash_set('ok', "Droits enregistrés");
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', "Erreur: " . $e->getMessage());
  }

  header("Location: /_admin/grants.php"); exit;
}

$csrf = csrf_token($pdo, $me);
$flash = flash_get();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Droits</title>
  <link rel="stylesheet" href="/assets/portal.css">
</head>
<body>
<main class="container stack">
  <section class="card stack">
    <div class="topbar">
      <h2>Droits</h2>
      <nav class="nav-links"><a href="/_admin/">← Menu</a><a href="/">Accueil apps</a></nav>
    </div>

    <?php if ($flash): ?>
      <p class="msg <?=h($flash['type'])?>"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
    <?php endif; ?>

    <p class="small">Vue binaire: case cochée = accès (viewer/editor/admin existant conservé), case vide = pas d'accès.</p>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$csrf?>">

      <div class="table-wrap grants-matrix-wrap">
        <table class="grants-matrix">
          <tr>
            <th>Application \ Utilisateur</th>
            <?php foreach($users as $usr): ?>
              <th><?=h($usr['email'])?></th>
            <?php endforeach; ?>
          </tr>
          <?php foreach($projects as $p): $pid=(int)$p['id']; ?>
            <tr>
              <th><?=h($p['slug'])?></th>
              <?php foreach($users as $usr): $uid=(int)$usr['id']; ?>
                <td class="checkbox-cell">
                  <input
                    type="checkbox"
                    name="allow_<?=$pid?>_<?=$uid?>"
                    value="1"
                    <?= !empty($existingRoles[$uid][$pid]) ? 'checked' : '' ?>
                    aria-label="Accès <?=h($usr['email'])?> sur <?=h($p['slug'])?>"
                  >
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <button>Enregistrer</button>
    </form>
  </section>
</main>
</body>
</html>
