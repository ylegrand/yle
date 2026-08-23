<?php

declare(strict_types=1);

function projects_root_path(string $projectsRoot): string {
    $root = realpath($projectsRoot);
    if (!$root || !is_dir($root)) {
        throw new RuntimeException('Racine projets invalide');
    }
    return $root;
}

function project_slug_is_valid(string $slug): bool {
    return $slug !== '.'
        && $slug !== '..'
        && preg_match('/^[a-zA-Z0-9._-]+$/', $slug) === 1;
}

function list_project_slugs(string $projectsRoot): array {
    $root = projects_root_path($projectsRoot);
    $items = @scandir($root) ?: [];
    $slugs = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '') continue;
        if ($item[0] === '.' || $item[0] === '_') continue;
        if (!project_slug_is_valid($item)) continue;
        if (is_dir($root . DIRECTORY_SEPARATOR . $item)) $slugs[] = $item;
    }

    sort($slugs);
    return $slugs;
}

function sync_projects(PDO $pdo, string $projectsRoot): array {
    $seen = list_project_slugs($projectsRoot);
    $now = date('Y-m-d H:i:s');

    $insert = $pdo->prepare(
        'INSERT INTO projects(slug,is_active,last_seen_at,deleted_at) VALUES(?,1,?,NULL)
         ON DUPLICATE KEY UPDATE is_active=1, last_seen_at=VALUES(last_seen_at), deleted_at=NULL'
    );
    foreach ($seen as $slug) {
        $insert->execute([$slug, $now]);
    }

    $rows = $pdo->query('SELECT slug FROM projects WHERE deleted_at IS NULL')->fetchAll();
    $active = array_map(static fn(array $row): string => (string) $row['slug'], $rows);
    $missing = array_values(array_diff($active, $seen));

    if ($missing) {
        $in = implode(',', array_fill(0, count($missing), '?'));
        $statement = $pdo->prepare("UPDATE projects SET is_active=0, deleted_at=NOW() WHERE slug IN ($in)");
        $statement->execute($missing);
    }

    $pdo->exec(
        'DELETE upr FROM user_project_roles upr
         JOIN projects p ON p.id = upr.project_id
         WHERE p.is_active=0 OR p.deleted_at IS NOT NULL'
    );

    return ['seen' => $seen, 'missing' => $missing];
}

function sync_projects_from_filesystem(PDO $pdo, string $projectsRoot): array {
    return sync_projects($pdo, $projectsRoot);
}

/**
 * Register only the requested project when a direct URL is hit before an admin/home scan.
 * This avoids a full filesystem + DB synchronization on every CSS/JS/image request.
 */
function ensure_project_registered(PDO $pdo, string $projectsRoot, string $slug): bool {
    if (!project_slug_is_valid($slug)) return false;

    $root = projects_root_path($projectsRoot);
    $projectPath = $root . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($projectPath)) return false;

    $statement = $pdo->prepare('SELECT id FROM projects WHERE slug=? AND is_active=1 AND deleted_at IS NULL');
    $statement->execute([$slug]);
    if ($statement->fetchColumn()) return true;

    $upsert = $pdo->prepare(
        'INSERT INTO projects(slug,is_active,last_seen_at,deleted_at) VALUES(?,1,NOW(),NULL)
         ON DUPLICATE KEY UPDATE is_active=1, last_seen_at=NOW(), deleted_at=NULL'
    );
    $upsert->execute([$slug]);
    return true;
}

function project_manifest(string $projectRoot): array {
    $manifestPath = $projectRoot . DIRECTORY_SEPARATOR . 'project.php';
    if (!is_file($manifestPath) || !is_readable($manifestPath)) return [];

    $manifest = require $manifestPath;
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid project manifest: ' . $manifestPath);
    }
    return $manifest;
}

/**
 * Optional project-owned routes that cannot use the normal session-protected /p/<slug>/ path
 * (OAuth token endpoints, MCP bearer endpoints, webhooks, etc.).
 * The core only dispatches a validated route to the handler declared by the project itself.
 */
function dispatch_project_external_route(
    PDO $pdo,
    array $portalConfig,
    string $projectsRoot,
    string $requestPath
): bool {
    $root = projects_root_path($projectsRoot);

    foreach (list_project_slugs($root) as $slug) {
        $projectRoot = $root . DIRECTORY_SEPARATOR . $slug;
        $manifest = project_manifest($projectRoot);
        $routes = $manifest['external_routes'] ?? [];
        $handlerRelative = $manifest['external_handler'] ?? null;

        if (!is_array($routes) || !is_string($handlerRelative) || $handlerRelative === '') continue;
        if (!in_array($requestPath, $routes, true)) continue;

        $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($handlerRelative, '/\\'));
        $projectPrefix = rtrim(realpath($projectRoot) ?: $projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!$candidate || !str_starts_with($candidate, $projectPrefix) || !is_file($candidate)) {
            throw new RuntimeException("Invalid external handler for project {$slug}");
        }

        $handler = require $candidate;
        if (!is_callable($handler)) {
            throw new RuntimeException("External handler for project {$slug} must return a callable");
        }

        $handler($pdo, $portalConfig, $requestPath);
        return true;
    }

    return false;
}

function project_webroot(string $projectsRoot, string $slug): ?string {
    if (!project_slug_is_valid($slug)) return null;

    $base = realpath(projects_root_path($projectsRoot) . DIRECTORY_SEPARATOR . $slug);
    if (!$base || !is_dir($base)) return null;

    if (is_dir($base . DIRECTORY_SEPARATOR . 'public')) {
        $public = realpath($base . DIRECTORY_SEPARATOR . 'public');
        return $public && is_dir($public) ? $public : null;
    }

    return $base;
}

function project_entrypoint(string $webroot): ?string {
    foreach (['index.php', 'index.html', 'index.htm'] as $name) {
        $candidate = realpath($webroot . DIRECTORY_SEPARATOR . $name);
        if ($candidate && is_file($candidate)) return $candidate;
    }
    return null;
}

function project_resolve_target(string $webroot, string $requestPath): ?string {
    $relative = ltrim($requestPath, '/');
    if ($relative === '') return project_entrypoint($webroot);

    $candidate = realpath($webroot . DIRECTORY_SEPARATOR . $relative);
    if (!$candidate) return null;

    $webrootReal = realpath($webroot);
    if (!$webrootReal) return null;
    $prefix = rtrim($webrootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if ($candidate !== $webrootReal && !str_starts_with($candidate, $prefix)) return null;

    if (is_dir($candidate)) return project_entrypoint($candidate);
    return is_file($candidate) ? $candidate : null;
}

function project_asset_mime_types(): array {
    return [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
    ];
}

function portal_disable_output_compression(): void {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function portal_send_project_asset(string $path, string $extension): never {
    $mimeTypes = project_asset_mime_types();
    $extension = strtolower($extension);

    if (!isset($mimeTypes[$extension]) || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    $size = filesize($path);
    if ($size === false) {
        http_response_code(404);
        exit('Not found');
    }

    portal_disable_output_compression();

    header('Content-Type: ' . $mimeTypes[$extension]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');

    $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;

    if ($size > 0 && $range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
        if ($matches[1] === '' && $matches[2] === '') {
            $range = '';
        } elseif ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int) $matches[1];
            if ($matches[2] !== '') $end = min($end, (int) $matches[2]);
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $partial = true;
    }

    if ($partial) {
        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        if ($method === 'HEAD') exit;

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit('Internal server error');
        }

        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    // Do not force Content-Length for full responses. Some shared-hosting HTTP/2
    // compression layers alter the body after PHP, which can otherwise trigger
    // ERR_HTTP2_PROTOCOL_ERROR when the announced length no longer matches.
    http_response_code(200);
    if ($method === 'GET') readfile($path);
    exit;
}
