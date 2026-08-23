<?php

declare(strict_types=1);

final class FitMcpController {
    private const ROUTES = [
        '/.well-known/oauth-protected-resource/fit-mcp',
        '/.well-known/oauth-authorization-server',
        '/fit-mcp/authorize',
        '/fit-mcp/token',
        '/fit-mcp/revoke',
        '/fit-mcp/mcp',
    ];

    public static function handle(PDO $pdo, array $cfg, string $path): bool {
        if (!in_array($path, self::ROUTES, true)) return false;

        if (empty($cfg['enabled']) || empty($cfg['base_url'])) {
            http_response_code(404);
            echo 'Not found';
            return true;
        }

        switch ($path) {
            case '/.well-known/oauth-protected-resource/fit-mcp':
                self::json([
                    'resource' => $cfg['base_url'] . '/fit-mcp/mcp',
                    'authorization_servers' => [$cfg['base_url']],
                    'scopes_supported' => ['fit.read', 'fit.write'],
                ]);

            case '/.well-known/oauth-authorization-server':
                self::json([
                    'issuer' => $cfg['base_url'],
                    'authorization_endpoint' => $cfg['base_url'] . '/fit-mcp/authorize',
                    'token_endpoint' => $cfg['base_url'] . '/fit-mcp/token',
                    'revocation_endpoint' => $cfg['base_url'] . '/fit-mcp/revoke',
                    'response_types_supported' => ['code'],
                    'grant_types_supported' => ['authorization_code'],
                    'code_challenge_methods_supported' => ['S256'],
                    'scopes_supported' => ['fit.read', 'fit.write'],
                ]);

            case '/fit-mcp/authorize':
                self::authorize($pdo, $cfg);
                return true;

            case '/fit-mcp/token':
                self::token($pdo, $cfg);
                return true;

            case '/fit-mcp/revoke':
                self::revoke($pdo);
                return true;

            case '/fit-mcp/mcp':
                self::mcp($pdo);
                return true;
        }

        return false;
    }

    private static function revoke(PDO $pdo): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }

        $token = (string) ($_POST['token'] ?? '');
        if ($token !== '') {
            $pdo->prepare('UPDATE fit_oauth_access_token SET revoked_at=NOW() WHERE token_hash=?')
                ->execute([hash('sha256', $token)]);
        }

        http_response_code(200);
        header('Cache-Control: no-store');
    }

    private static function authorize(PDO $pdo, array $cfg): void {
        $client = self::client($cfg, (string) ($_REQUEST['client_id'] ?? ''));
        $redirect = (string) ($_REQUEST['redirect_uri'] ?? '');
        $responseType = (string) ($_REQUEST['response_type'] ?? '');
        $challenge = (string) ($_REQUEST['code_challenge'] ?? '');
        $challengeMethod = (string) ($_REQUEST['code_challenge_method'] ?? 'S256');

        if (
            !$client
            || !in_array($redirect, $client['redirect_uris'] ?? [], true)
            || $responseType !== 'code'
            || $challengeMethod !== 'S256'
            || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $challenge)
        ) {
            http_response_code(400);
            echo 'Invalid OAuth authorization request';
            return;
        }

        $user = current_user($pdo);
        if (!$user) {
            http_response_code(401);
            echo 'Connectez-vous au portail, puis relancez l’autorisation MCP.';
            return;
        }

        $scopes = self::scopes((string) ($_REQUEST['scope'] ?? 'fit.read'));
        if ($scopes === '') {
            http_response_code(400);
            echo 'Invalid scope';
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            csrf_check($pdo, (int) $user['id'], (string) ($_POST['csrf'] ?? ''));

            if (($_POST['approve'] ?? '') !== '1') {
                self::redirectError($redirect, (string) ($_REQUEST['state'] ?? ''));
            }

            $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pdo->prepare(
                'INSERT INTO fit_oauth_authorization_code
                 (code_hash,user_id,client_id,redirect_uri,scopes,code_challenge,expires_at)
                 VALUES(?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))'
            )->execute([
                hash('sha256', $code),
                (int) $user['id'],
                $client['client_id'],
                $redirect,
                $scopes,
                $challenge,
            ]);

            $separator = str_contains($redirect, '?') ? '&' : '?';
            header(
                'Location: ' . $redirect . $separator
                . 'code=' . rawurlencode($code)
                . '&state=' . rawurlencode((string) ($_REQUEST['state'] ?? ''))
            );
            exit;
        }

        $csrf = csrf_token($pdo, (int) $user['id']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Autoriser Fit</title><form method="post">';
        echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';

        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope'] as $key) {
            $value = (string) ($_GET[$key] ?? '');
            if ($key === 'code_challenge_method' && $value === '') $value = 'S256';
            echo '<input type="hidden" name="' . $key . '" value="'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
        }

        echo '<p>Autoriser <strong>'
            . htmlspecialchars((string) $client['client_id'], ENT_QUOTES, 'UTF-8')
            . '</strong> avec le périmètre <strong>'
            . htmlspecialchars($scopes, ENT_QUOTES, 'UTF-8')
            . '</strong> ?</p>';
        echo '<button name="approve" value="1">Autoriser</button>';
        echo '<button name="approve" value="0">Refuser</button></form>';
    }

    private static function token(PDO $pdo, array $cfg): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }

        $client = self::client($cfg, (string) ($_POST['client_id'] ?? ''));
        $code = (string) ($_POST['code'] ?? '');
        $redirect = (string) ($_POST['redirect_uri'] ?? '');
        $verifier = (string) ($_POST['code_verifier'] ?? '');

        if (
            !$client
            || !in_array($redirect, $client['redirect_uris'] ?? [], true)
            || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
        ) {
            self::oauthError();
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT * FROM fit_oauth_authorization_code
                 WHERE code_hash=? AND expires_at>NOW() AND consumed_at IS NULL FOR UPDATE'
            );
            $statement->execute([hash('sha256', $code)]);
            $row = $statement->fetch();

            $expectedChallenge = rtrim(
                strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'),
                '='
            );

            if (
                !$row
                || $row['client_id'] !== $client['client_id']
                || $row['redirect_uri'] !== $redirect
                || !hash_equals((string) $row['code_challenge'], $expectedChallenge)
            ) {
                $pdo->rollBack();
                self::oauthError();
            }

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pdo->prepare('UPDATE fit_oauth_authorization_code SET consumed_at=NOW() WHERE id=?')
                ->execute([$row['id']]);
            $pdo->prepare(
                'INSERT INTO fit_oauth_access_token(token_hash,user_id,client_id,scopes,expires_at)
                 VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))'
            )->execute([
                hash('sha256', $token),
                $row['user_id'],
                $row['client_id'],
                $row['scopes'],
            ]);
            $pdo->commit();

            self::json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => $row['scopes'],
            ]);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    private static function mcp(PDO $pdo): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{30,})$/', $auth, $matches)) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer');
            return;
        }

        $statement = $pdo->prepare(
            'SELECT * FROM fit_oauth_access_token
             WHERE token_hash=? AND expires_at>NOW() AND revoked_at IS NULL'
        );
        $statement->execute([hash('sha256', $matches[1])]);
        $token = $statement->fetch();

        if (!$token || !self::hasScope((string) $token['scopes'], 'fit.read')) {
            http_response_code(403);
            return;
        }

        $pdo->prepare('UPDATE fit_oauth_access_token SET last_used_at=NOW() WHERE id=?')
            ->execute([$token['id']]);

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            self::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }

        $method = (string) ($body['method'] ?? '');
        $id = $body['id'] ?? null;

        if ($method === 'tools/list') {
            self::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => self::tools()]]);
        }

        if ($method === 'tools/call') {
            $params = is_array($body['params'] ?? null) ? $body['params'] : [];
            $name = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $data = self::callTool($pdo, $token, $name, $args, $id);

            self::json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]],
                ],
            ]);
        }

        self::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }

    private static function callTool(PDO $pdo, array $token, string $name, array $args, mixed $id): mixed {
        $service = fit_service($pdo);
        $userId = (int) $token['user_id'];

        if ($name === 'fit_get_context') return $service->getSessionContext($userId);
        if ($name === 'fit_get_configuration') return $service->getConfigurationStatus($userId);

        $writeTools = [
            'fit_save_profile',
            'fit_save_rule_block',
            'fit_open_or_resume_draft',
            'fit_checkpoint_draft',
            'fit_close_draft',
        ];

        if (!in_array($name, $writeTools, true)) {
            self::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool']]);
        }

        if (!self::hasScope((string) $token['scopes'], 'fit.write') || ($args['confirmed'] ?? false) !== true) {
            self::json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32003, 'message' => 'fit.write scope and confirmed=true required'],
            ]);
        }

        return match ($name) {
            'fit_save_profile' => $service->saveProfile(
                $userId,
                is_array($args['profile_data'] ?? null) ? $args['profile_data'] : [],
                isset($args['timezone']) ? (string) $args['timezone'] : null
            ),
            'fit_save_rule_block' => $service->saveRuleBlock(
                $userId,
                (string) ($args['code'] ?? ''),
                is_array($args['configuration_data'] ?? null) ? $args['configuration_data'] : []
            ),
            'fit_open_or_resume_draft' => $service->openOrResumeDraft($userId),
            'fit_checkpoint_draft' => $service->checkpointDraft(
                $userId,
                (int) ($args['draft_id'] ?? 0),
                is_array($args['payload'] ?? null) ? $args['payload'] : []
            ),
            'fit_close_draft' => $service->closeDraft(
                $userId,
                (int) ($args['draft_id'] ?? 0),
                is_array($args['payload'] ?? null) ? $args['payload'] : []
            ),
        };
    }

    private static function tools(): array {
        return [
            ['name' => 'fit_get_context', 'description' => 'Contexte Fit autorisé', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            ['name' => 'fit_get_configuration', 'description' => 'Règles versionnées', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            [
                'name' => 'fit_save_profile',
                'description' => 'Enregistre un profil après confirmation explicite',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'profile_data' => ['type' => 'object'],
                        'timezone' => ['type' => 'string'],
                        'confirmed' => ['type' => 'boolean'],
                    ],
                    'required' => ['profile_data', 'confirmed'],
                ],
            ],
            [
                'name' => 'fit_save_rule_block',
                'description' => 'Versionne un bloc de règles après confirmation explicite',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'configuration_data' => ['type' => 'object'],
                        'confirmed' => ['type' => 'boolean'],
                    ],
                    'required' => ['code', 'configuration_data', 'confirmed'],
                ],
            ],
            [
                'name' => 'fit_open_or_resume_draft',
                'description' => 'Ouvre ou reprend une séance après confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['confirmed' => ['type' => 'boolean']],
                    'required' => ['confirmed'],
                ],
            ],
            [
                'name' => 'fit_checkpoint_draft',
                'description' => 'Enregistre les séries d’un brouillon après confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'draft_id' => ['type' => 'integer'],
                        'payload' => ['type' => 'object'],
                        'confirmed' => ['type' => 'boolean'],
                    ],
                    'required' => ['draft_id', 'payload', 'confirmed'],
                ],
            ],
            [
                'name' => 'fit_close_draft',
                'description' => 'Clôture une séance après confirmation',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'draft_id' => ['type' => 'integer'],
                        'payload' => ['type' => 'object'],
                        'confirmed' => ['type' => 'boolean'],
                    ],
                    'required' => ['draft_id', 'payload', 'confirmed'],
                ],
            ],
        ];
    }

    private static function hasScope(string $scopes, string $scope): bool {
        return in_array($scope, array_filter(explode(' ', trim($scopes))), true);
    }

    private static function scopes(string $requested): string {
        $scopes = array_unique(array_filter(explode(' ', trim($requested))));
        sort($scopes);

        if (!$scopes || array_diff($scopes, ['fit.read', 'fit.write']) || !in_array('fit.read', $scopes, true)) {
            return '';
        }
        return implode(' ', $scopes);
    }

    private static function client(array $cfg, string $id): ?array {
        $clients = json_decode((string) ($cfg['oauth_clients'] ?? ''), true);
        if (!is_array($clients)) return null;

        foreach ($clients as $client) {
            if (is_array($client) && ($client['client_id'] ?? '') === $id) return $client;
        }
        return null;
    }

    private static function oauthError(): never {
        self::json(['error' => 'invalid_grant'], 400);
    }

    private static function redirectError(string $url, string $state): never {
        $separator = str_contains($url, '?') ? '&' : '?';
        header('Location: ' . $url . $separator . 'error=access_denied&state=' . rawurlencode($state));
        exit;
    }

    private static function json(array $value, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
