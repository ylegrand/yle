<?php
$cfg = require __DIR__ . '/_app/config.php';
require __DIR__ . '/_app/db.php';

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide";
  if (strlen($pass) < 12) $errors[] = "Mot de passe trop court (min 12)";

  if (!$errors) {
    try {
      $pdo0 = db($cfg, true);
      try {
        $pdo0->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      } catch (Throwable $e) {
        // not fatal on shared hosting
      }

      $pdo = db($cfg, false);

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(190) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          last_login TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
          id INT AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(190) NOT NULL UNIQUE,
          label VARCHAR(190) NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          last_seen_at TIMESTAMP NULL DEFAULT NULL,
          deleted_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_project_roles (
          user_id INT NOT NULL,
          project_id INT NOT NULL,
          role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(user_id, project_id),
          CONSTRAINT fk_upr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_upr_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS csrf_tokens (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          token CHAR(64) NOT NULL,
          expires_at TIMESTAMP NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE(token),
          INDEX(user_id),
          CONSTRAINT fk_csrf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("
        INSERT INTO users(email,password_hash,is_superadmin) VALUES(?,?,1)
        ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), is_superadmin=1, is_active=1
      ");
      $stmt->execute([$email, $hash]);

      $done = true;

    } catch (Throwable $e) {
      $errors[] = "Erreur install: " . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Install</title></head>
<body style="font-family:system-ui;max-width:720px;margin:40px auto;">
<h1>Installation portail</h1>
<p>Crée les tables et le super-admin.</p>

<?php if ($done): ?>
  <p style="padding:10px;background:#e7ffe7;border:1px solid #9be09b;">
    OK. Va sur <a href="/_admin/">/_admin/</a>. Ensuite supprime/renomme <code>install.php</code>.
  </p>
<?php endif; ?>

<?php if ($errors): ?>
  <ul style="padding:10px;background:#ffe7e7;border:1px solid #e09b9b;">
    <?php foreach($errors as $er): ?><li><?=h($er)?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<form method="post" autocomplete="off">
  <label>Email super-admin</label><br>
  <input name="email" style="width:100%" value="<?=h($_POST['email']??'')?>" autocomplete="off"><br><br>
  <label>Mot de passe (min 12)</label><br>
  <input type="password" name="password" style="width:100%" autocomplete="new-password"><br><br>
  <button>Installer</button>
</form>

<p><b>Note OVH :</b> si l’erreur mentionne <code>CREATE DATABASE</code>, crée la base <code><?=h($cfg['db_name'])?></code> dans l’interface OVH puis relance.</p>
</body></html>
