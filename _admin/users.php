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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($pdo, $me, $_POST['csrf'] ?? '');
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $email = trim($_POST['email'] ?? '');
      $pass  = $_POST['password'] ?? '';
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash_set('error', "Email invalide");
      elseif (strlen($pass) < 12) flash_set('error', "Mot de passe trop court (min 12)");
      else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users(email,password_hash,is_superadmin,is_active) VALUES(?,?,0,1)")->execute([$email, $hash]);
        flash_set('ok', "Utilisateur créé: $email");
      }
    } elseif ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) flash_set('error', "ID manquant");
      elseif ($id === $me) flash_set('error', "Impossible de désactiver votre propre compte");
      else {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        flash_set('ok', "Statut mis à jour");
      }
    } elseif ($action === 'reset') {
      $id = (int)($_POST['id'] ?? 0);
      $pass = $_POST['password'] ?? '';
      if (!$id) flash_set('error', "ID manquant");
      elseif (strlen($pass) < 12) flash_set('error', "Mot de passe trop court (min 12)");
      else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $id]);
        flash_set('ok', "Mot de passe mis à jour");
      }
    } else flash_set('error', "Action inconnue");
  } catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) == 1062) flash_set('error', "Email déjà utilisé");
    else flash_set('error', "Erreur DB: " . $e->getMessage());
  } catch (Throwable $e) {
    flash_set('error', "Erreur: " . $e->getMessage());
  }
  header('Location: /_admin/users.php'); exit;
}

$csrf = csrf_token($pdo, $me);
$flash = flash_get();
$users = $pdo->query("SELECT id,email,is_superadmin,is_active,last_login FROM users ORDER BY is_superadmin DESC, email")->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Utilisateurs</title>
  <link rel="stylesheet" href="/assets/portal.css">
</head>
<body>
<main class="container stack">
  <section class="card stack">
    <div class="topbar">
      <h2>Utilisateurs</h2>
      <nav class="nav-links"><a href="/_admin/">← Menu</a><a href="/">Accueil apps</a></nav>
    </div>

    <?php if ($flash): ?>
      <p class="msg <?=h($flash['type'])?>"><b><?=h($flash['type'])?>:</b> <?=h($flash['msg'])?></p>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <tr><th>ID</th><th>Email</th><th>Super</th><th>Actif</th><th>Last login</th><th>Actions</th></tr>
        <?php foreach($users as $x): ?>
        <tr>
          <td><?=h($x['id'])?></td>
          <td><?=h($x['email'])?></td>
          <td><?= $x['is_superadmin'] ? 'oui' : 'non' ?></td>
          <td><?= $x['is_active'] ? 'oui' : 'non' ?></td>
          <td><?=h($x['last_login'] ?? '')?></td>
          <td>
            <?php if (!$x['is_superadmin']): ?>
            <form method="post" class="inline-form" autocomplete="off">
              <input type="hidden" name="csrf" value="<?=$csrf?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?=h($x['id'])?>">
              <button><?= $x['is_active'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </section>

  <section class="card stack">
    <h3>Créer utilisateur</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <input name="email" placeholder="email" autocomplete="off" autocapitalize="none" spellcheck="false">
        <input type="password" name="password" placeholder="mot de passe (min 12)" autocomplete="new-password">
      </div>
      <button>Créer</button>
    </form>
  </section>

  <section class="card stack">
    <h3>Reset mot de passe</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="action" value="reset">
      <div class="row">
        <input name="id" placeholder="user id" autocomplete="off">
        <input type="password" name="password" placeholder="nouveau mot de passe (min 12)" autocomplete="new-password">
      </div>
      <button>Reset</button>
    </form>
  </section>
</main>
</body>
</html>
