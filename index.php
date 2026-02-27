<?php

$cfg = require __DIR__ . '/_app/config.php';
require __DIR__ . '/_app/db.php';
require __DIR__ . '/_app/auth.php';
require __DIR__ . '/_app/acl.php';

$pdo = db($cfg);
start_session($cfg);

$projectsRoot = __DIR__ . '/_projects';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';



/* ============================================================
   HOME
   ============================================================ */

if ($uri === '/') {

    $user = current_user($pdo);
    if (!$user) {
        header('Location: /_admin/');
        exit;
    }

    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Applications</title><link rel="stylesheet" href="/assets/portal.css"></head><body><main class="container stack">';
    echo '<section class="card stack">';
    echo '<div class="topbar"><h1>Applications</h1><span class="badge">Connecté : ' . h($user['email']) . '</span></div>';
    echo '<nav class="nav-links"><a href="/_admin/">Admin</a><a href="/_admin/logout.php">Logout</a></nav>';

    if (!empty($user['is_superadmin'])) {
        $rows = $pdo->query("SELECT slug FROM projects WHERE is_active=1 AND deleted_at IS NULL ORDER BY slug")->fetchAll();
    } else {
        $st = $pdo->prepare("
            SELECT p.slug
            FROM projects p
            JOIN user_project_roles upr ON upr.project_id = p.id
            WHERE upr.user_id = ?
              AND p.is_active=1
              AND p.deleted_at IS NULL
            ORDER BY p.slug
        ");
        $st->execute([(int)$user['id']]);
        $rows = $st->fetchAll();
    }

    echo '<ul class="project-list">';
    foreach ($rows as $r) {
        echo "<li><a href='/p/" . h($r['slug']) . "/'>" . h($r['slug']) . "</a></li>";
    }
    echo "</ul>";
    echo "</section></main></body></html>";

    exit;
}



/* ============================================================
   ROUTE PROJET : /p/<slug>/...
   ============================================================ */

if (preg_match('#^/p/([^/]+)(/.*)?$#', $uri, $m)) {

    $slug = $m[1];
    $path = $m[2] ?? '/';

    $user = require_login($pdo, '/_admin/');
    require_project_role($pdo, $user, $slug, 'viewer');

    $base = realpath($projectsRoot . '/' . $slug);
    if (!$base || !is_dir($base)) {
        http_response_code(404);
        exit("Project not found");
    }

    // Si dossier public existe → webroot = public
    $webroot = is_dir($base . '/public')
        ? realpath($base . '/public')
        : $base;

    if (!$webroot) {
        http_response_code(500);
        exit("Invalid project");
    }

    $rel = ltrim($path, '/');

    if ($rel === '') {
        $rel = 'index.php';
    }

    $target = realpath($webroot . '/' . $rel);

    if (!$target || !str_starts_with($target, $webroot)) {
        http_response_code(404);
        exit("Not found");
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    /* ============================================================
       Si ce n'est PAS du PHP → laisser Apache servir le fichier
       ============================================================ */

    if ($ext !== 'php') {

        $publicPrefix = is_dir($base . '/public') ? 'public/' : '';
        $redirect = "/_projects/$slug/" . $publicPrefix . $rel;

        header("Location: $redirect", true, 302);
        exit;
    }

    /* ============================================================
       Exécution PHP avec contexte corrigé
       ============================================================ */

    $virtual = "/p/$slug/" . $rel;

    $_SERVER['SCRIPT_NAME'] = $virtual;
    $_SERVER['PHP_SELF'] = $virtual;
    $_SERVER['REQUEST_URI'] = $virtual;

    chdir(dirname($target));
    require $target;
    exit;
}



http_response_code(404);
echo "Not found";