<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/auth.php';
require __DIR__ . '/../_app/projects.php';
require __DIR__ . '/../_app/flash.php';

$pdo = db($cfg);
start_session($cfg);
$u = require_login($pdo);
if (empty($u['is_superadmin'])) { http_response_code(403); exit("Forbidden"); }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$projectsRoot = __DIR__ . '/../_projects';
$res = ['seen' => [], 'missing' => []];

try {
  $res = sync_projects_from_filesystem($pdo, $projectsRoot);
} catch (Throwable $e) {
  flash_set('error', "Erreur sync: " . $e->getMessage());
}

$flash = flash_get();
$projects = $pdo->query("SELECT slug,is_active,last_seen_at,deleted_at FROM projects ORDER BY slug")->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Projets</title>
  <link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="stylesheet" href="/assets/portal.css">
</head>
<body>
<main class="container stack">
  <header class="portal-brand">
    <img src="/assets/brand-logo.png" alt="Logo YLE">
    <div>
      <div class="portal-brand-title">YLE Portail</div>
      <div class="portal-brand-subtitle">Espace central</div>
    </div>
  </header>
  <section class="card stack">
    <div class="topbar">
      <h2>Projets</h2>
      <nav class="nav-links"><a href="/_admin/">← Menu</a><a href="/">Accueil apps</a></nav>
    </div>

    <?php if ($flash): ?>
      <p class="msg <?=h($flash['type'])?>"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
    <?php endif; ?>

    <p class="small">Rafraîchissement automatique depuis <code>_projects/</code> à chaque ouverture de la page.</p>
    <p class="small">Nouveaux dossiers: aucune autorisation par défaut. Dossiers supprimés: droits supprimés automatiquement.</p>
    <p class="small"><b>Dernier scan:</b> vus <?=count($res['seen'])?>, disparus <?=count($res['missing'])?>.</p>

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
