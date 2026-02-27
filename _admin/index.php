<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/db.php';
require __DIR__ . '/../_app/auth.php';
require __DIR__ . '/../_app/flash.php';

$pdo = db($cfg);
start_session($cfg);

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$u = current_user($pdo);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$u) {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  if (!login($pdo, $email, $pass)) $error = "Login incorrect ou compte inactif";
  else { header('Location: /_admin/users.php'); exit; }
}

$flash = flash_get();
?>
<!doctype html><meta charset="utf-8">
<body style="font-family:system-ui;max-width:900px;margin:40px auto;">
<h1>Admin</h1>

<?php if ($flash): ?>
  <p style="padding:10px;border:1px solid #ddd;background:#fff;">
    <b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?>
  </p>
<?php endif; ?>

<?php if (!$u): ?>
  <?php if ($error): ?><p style="color:#b00;"><?=h($error)?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <input name="email" placeholder="email" style="width:100%" autocomplete="off"><br><br>
    <input type="password" name="password" placeholder="mot de passe" style="width:100%" autocomplete="current-password"><br><br>
    <button>Se connecter</button>
  </form>
<?php else: ?>
  <p>Connecté: <b><?=h($u['email'])?></b> | <a href="/_admin/logout.php">Logout</a> | <a href="/">Accueil apps</a></p>
  <ul>
    <li><a href="/_admin/users.php">Utilisateurs</a></li>
    <li><a href="/_admin/grants.php">Droits (user ↔ projets)</a></li>
    <li><a href="/_admin/projects.php">Projets (sync)</a></li>
  </ul>
<?php endif; ?>
</body>
