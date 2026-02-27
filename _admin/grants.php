<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/auth.php';
require __DIR__ . '/../_app/csrf.php';
require __DIR__ . '/../_app/flash.php';

$pdo = db($cfg);
start_session($cfg);
$u = require_login($pdo);
if (empty($u['is_superadmin'])) { http_response_code(403); exit("Forbidden"); }

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$me = (int)$u['id'];

$users = $pdo->query("SELECT id,email FROM users WHERE is_active=1 ORDER BY email")->fetchAll();
$projects = $pdo->query("SELECT id,slug FROM projects WHERE is_active=1 AND deleted_at IS NULL ORDER BY slug")->fetchAll();

$selectedUserId = (int)($_GET['user_id'] ?? ($users[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($pdo, $me, $_POST['csrf'] ?? '');
  $uid = (int)($_POST['user_id'] ?? 0);

  if (!$uid) {
    flash_set('error', "Utilisateur manquant");
  } else {
    try {
      $pdo->prepare("DELETE FROM user_project_roles WHERE user_id=?")->execute([$uid]);
      foreach ($projects as $p) {
        $role = $_POST['role_' . $p['id']] ?? '';
        if (in_array($role, ['viewer','editor','admin'], true)) {
          $pdo->prepare("INSERT INTO user_project_roles(user_id,project_id,role) VALUES(?,?,?)")
              ->execute([$uid, $p['id'], $role]);
        }
      }
      flash_set('ok', "Droits enregistrés");
    } catch (Throwable $e) {
      flash_set('error', "Erreur: " . $e->getMessage());
    }
  }

  header("Location: /_admin/grants.php?user_id=$uid"); exit;
}

$csrf = csrf_token($pdo, $me);
$flash = flash_get();

$roles = [];
if ($selectedUserId) {
  $st = $pdo->prepare("SELECT project_id, role FROM user_project_roles WHERE user_id=?");
  $st->execute([$selectedUserId]);
  foreach ($st->fetchAll() as $r) $roles[(int)$r['project_id']] = $r['role'];
}
?>
<!doctype html><meta charset="utf-8">
<body style="font-family:system-ui;max-width:1100px;margin:40px auto;">
<p><a href="/_admin/">← Menu</a> | <a href="/">Accueil apps</a></p>
<h2>Droits</h2>

<?php if ($flash): ?>
  <p style="padding:10px;border:1px solid #ddd;background:#fff;"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
<?php endif; ?>

<form method="get" autocomplete="off">
  <label>Utilisateur: </label>
  <select name="user_id">
    <?php foreach($users as $x): ?>
      <option value="<?=$x['id']?>" <?=$x['id']===$selectedUserId?'selected':''?>><?=h($x['email'])?></option>
    <?php endforeach; ?>
  </select>
  <button>Charger</button>
</form>

<?php if ($selectedUserId): ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?=$csrf?>">
  <input type="hidden" name="user_id" value="<?=$selectedUserId?>">

  <table border="1" cellpadding="8" cellspacing="0" style="margin-top:12px">
    <tr><th>Projet</th><th>Rôle</th></tr>
    <?php foreach($projects as $p): $rid=(int)$p['id']; ?>
      <tr>
        <td><?=h($p['slug'])?></td>
        <td>
          <select name="role_<?=$rid?>">
            <option value="">(aucun accès)</option>
            <?php foreach(['viewer','editor','admin'] as $r): ?>
              <option value="<?=$r?>" <?=(($roles[$rid]??'')===$r)?'selected':''?>><?=$r?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <br>
  <button>Enregistrer</button>
</form>
<?php endif; ?>
</body>
