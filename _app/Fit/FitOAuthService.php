<?php
declare(strict_types=1);

final class FitOAuthService {
  public function __construct(private readonly PDO $pdo) {}
  public function listActiveTokens(int $userId): array {
    $st=$this->pdo->prepare('SELECT id,client_id,scopes,expires_at,created_at,last_used_at FROM fit_oauth_access_token WHERE user_id=? AND revoked_at IS NULL AND expires_at>NOW() ORDER BY created_at DESC');
    $st->execute([$userId]); return $st->fetchAll();
  }
  public function revokeToken(int $userId,int $tokenId): bool {
    $st=$this->pdo->prepare('UPDATE fit_oauth_access_token SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL');
    $st->execute([$tokenId,$userId]); return $st->rowCount()===1;
  }
}
