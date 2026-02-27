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
  else { header('Location: /'); exit; }
}

if ($u && !is_superadmin($u)) {
  header('Location: /');
  exit;
}

$flash = flash_get();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin</title>
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
    <h1>Admin</h1>

    <?php if ($flash): ?>
      <p class="msg <?=h($flash['type'])?>"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
    <?php endif; ?>

    <?php if (!$u): ?>
      <?php if ($error): ?><p class="msg error"><?=h($error)?></p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="text" name="fake_username" autocomplete="username" style="display:none">
        <input type="password" name="fake_password" autocomplete="new-password" style="display:none">
        <div>
          <label for="email">Email</label>
          <input id="email" name="email" placeholder="email" autocomplete="off" autocapitalize="none" spellcheck="false" readonly onfocus="this.removeAttribute('readonly');">
        </div>
        <div>
          <label for="password">Mot de passe</label>
          <input id="password" type="password" name="password" placeholder="mot de passe" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
        </div>
        <button>Se connecter</button>
      </form>
    <?php else: ?>
      <p class="small">Connecté: <b><?=h($u['email'])?></b></p>
      <nav class="nav-links">
        <a href="/_admin/users.php">Utilisateurs</a>
        <a href="/_admin/grants.php">Droits (matrice)</a>
        <a href="/_admin/projects.php">Projets (auto)</a>
        <a href="/">Accueil apps</a>
        <a href="/_admin/logout.php">Logout</a>
      </nav>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
