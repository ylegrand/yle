<?php
function csrf_token(PDO $pdo, int $userId): string {
  $token = bin2hex(random_bytes(32));
  $pdo->prepare("INSERT INTO csrf_tokens(user_id, token, expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 2 HOUR))")
      ->execute([$userId, $token]);
  return $token;
}

function csrf_check(PDO $pdo, int $userId, string $token): void {
  $st = $pdo->prepare("SELECT id FROM csrf_tokens WHERE user_id=? AND token=? AND expires_at > NOW()");
  $st->execute([$userId, $token]);
  $row = $st->fetch();
  if (!$row) {
    http_response_code(403);
    exit("CSRF invalid");
  }
  $pdo->prepare("DELETE FROM csrf_tokens WHERE id=?")->execute([$row['id']]);
}
