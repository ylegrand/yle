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
<!doctype html><meta charset="utf-8">
<body style="font-family:system-ui;max-width:1100px;margin:40px auto;">
<p><a href="/_admin/">← Menu</a> | <a href="/">Accueil apps</a></p>
<h2>Projets</h2>

<?php if ($flash): ?>
  <p style="padding:10px;border:1px solid #ddd;background:#fff;"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
<?php endif; ?>

<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?=$csrf?>">
  <button>Synchroniser (scan _projects/)</button>
</form>

<table border="1" cellpadding="8" cellspacing="0" style="margin-top:12px">
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

<p><b>Racine scan :</b> <?=h(realpath($projectsRoot) ?: $projectsRoot)?></p>
</body>
