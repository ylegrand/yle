<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/auth.php';
require __DIR__ . '/../_app/csrf.php';
require __DIR__ . '/../_app/projects.php';
require __DIR__ . '/../_app/flash.php';

$pdo = db($cfg);
start_session($cfg);
$u = require_login($pdo);
if (empty($u['is_superadmin'])) { http_response_code(403); exit("Forbidden"); }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$me = (int)$u['id'];
$projectsRoot = __DIR__ . '/../_projects';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($pdo, $me, $_POST['csrf'] ?? '');
  try {
    $res = sync_projects($pdo, realpath($projectsRoot) ?: $projectsRoot);
    flash_set('ok', "Sync OK — vus: " . count($res['seen']) . ", disparus: " . count($res['missing']));
  } catch (Throwable $e) {
    flash_set('error', "Erreur sync: " . $e->getMessage());
  }
  header('Location: /_admin/projects.php'); exit;
}

$csrf = csrf_token($pdo, $me);
$flash = flash_get();
$projects = $pdo->query("SELECT slug,is_active,last_seen_at,deleted_at FROM projects ORDER BY slug")->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Projets</title>
  <link rel="stylesheet" href="/assets/portal.css">
</head>
<body>
<main class="container stack">
  <section class="card stack">
    <div class="topbar">
      <h2>Projets</h2>
      <nav class="nav-links"><a href="/_admin/">← Menu</a><a href="/">Accueil apps</a></nav>
    </div>

    <?php if ($flash): ?>
      <p class="msg <?=h($flash['type'])?>"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <button>Synchroniser (scan _projects/)</button>
    </form>

    <div class="table-wrap">
      <table>
        <tr><th>Slug</th><th>Actif</th><th>Last seen</th><th>Deleted</th><th>Lien</th></tr>
        <?php foreach($projects as $p): ?>
        <tr>
          <td><?=h($p['slug'])?></td>
          <td><?= $p['is_active'] ? 'oui' : 'non' ?></td>
          <td><?=h($p['last_seen_at'] ?? '')?></td>
          <td><?=h($p['deleted_at'] ?? '')?></td>
          <td><?php if ($p['is_active']): ?><a href="/p/<?=rawurlencode($p['slug'])?>/">ouvrir</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <p class="small"><b>Racine scan :</b> <?=h(realpath($projectsRoot) ?: $projectsRoot)?></p>
  </section>
</main>
</body>
</html>
