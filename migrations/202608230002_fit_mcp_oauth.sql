-- OAuth tokens for the Fit MCP protected resource. Additive, no portal account changes.
CREATE TABLE IF NOT EXISTS fit_oauth_authorization_code (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, code_hash CHAR(64) NOT NULL, user_id INT NOT NULL,
  client_id VARCHAR(190) NOT NULL, redirect_uri VARCHAR(1024) NOT NULL, scopes VARCHAR(255) NOT NULL,
  code_challenge VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_fit_oauth_code_hash(code_hash),
  KEY idx_fit_oauth_code_lookup(code_hash,expires_at), CONSTRAINT fk_fit_oauth_code_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fit_oauth_access_token (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, token_hash CHAR(64) NOT NULL, user_id INT NOT NULL,
  client_id VARCHAR(190) NOT NULL, scopes VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME NULL, PRIMARY KEY(id), UNIQUE KEY uq_fit_oauth_token_hash(token_hash),
  KEY idx_fit_oauth_token_lookup(token_hash,expires_at), CONSTRAINT fk_fit_oauth_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
