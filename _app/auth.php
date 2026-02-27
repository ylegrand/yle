<?php
require_once __DIR__ . '/db.php';

function start_session(array $cfg): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  $secure = !empty($cfg['cookie_secure']);
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => $cfg['cookie_samesite'] ?? 'Lax',
  ]);

  ini_set('session.use_strict_mode', '1');
  ini_set('session.cookie_httponly', '1');
  if ($secure) ini_set('session.cookie_secure', '1');

  session_start();
}

function current_user(PDO $pdo): ?array {
  if (empty($_SESSION['uid'])) return null;
  $st = $pdo->prepare("SELECT id,email,is_superadmin,is_active FROM users WHERE id=?");
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch();
  if (!$u || !$u['is_active']) return null;
  return $u;
}

function require_login(PDO $pdo, string $redirect = '/_admin/'): array {
  $u = current_user($pdo);
  if (!$u) {
    header("Location: $redirect");
    exit;
  }
  return $u;
}

function login(PDO $pdo, string $email, string $pass): bool {
  $st = $pdo->prepare("SELECT id,password_hash,is_active FROM users WHERE email=?");
  $st->execute([$email]);
  $u = $st->fetch();
  if (!$u || !$u['is_active']) return false;
  if (!password_verify($pass, $u['password_hash'])) return false;

  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
  return true;
}

function logout(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}
