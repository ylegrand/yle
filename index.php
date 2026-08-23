<?php

$cfg = require __DIR__ . '/_app/config.php';
require __DIR__ . '/_app/db.php';
require __DIR__ . '/_app/auth.php';
require __DIR__ . '/_app/acl.php';
require __DIR__ . '/_app/projects.php';
require __DIR__ . '/_app/fit.php';

$pdo = db($cfg);
start_session($cfg);

// Keep the deployment base (for example /yle in WAMP) when project routing
// later replaces SCRIPT_NAME with its virtual project URL.
$_SERVER['PORTAL_BASE_PATH'] = fit_base_path();

if (FitMcpController::handle($pdo, $cfg)) {
    exit;
}

$projectsRoot = __DIR__ . '/_projects';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function portal_send_project_asset(string $path, string $extension): never {
    $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
    ];

    if (!isset($mimeTypes[$extension]) || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $size = filesize($path);
    if ($size === false) {
        http_response_code(404);
        exit('Not found');
    }

    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

    if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int) $matches[1];
        }

        if ($matches[2] !== '') {
            $end = min($end, (int) $matches[2]);
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $status = 206;
    }

    $length = $size === 0 ? 0 : ($end - $start + 1);
    http_response_code($status);
    header('Content-Type: ' . $mimeTypes[$extension]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        if ($status === 200) {
            readfile($path);
        } else {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                http_response_code(500);
                exit('Internal server error');
            }

            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($handle);
        }
    }
    exit;
}

function app_base_url(): string {
    $https = $_SERVER['HTTPS'] ?? '';
    $isHttps = $https === 'on' || $https === '1';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function b64url_decode(string $value): string|false {
    $pad = strlen($value) % 4;
    if ($pad > 0) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function parse_temp_share_token(array $cfg, string $token, string $expectedSlug): ?array {
    $secret = (string)($cfg['share_token_secret'] ?? '');
    if ($secret === '' || $token === '' || !str_contains($token, '.')) {
        return null;
    }

    [$payloadB64, $sigHex] = explode('.', $token, 2);
    if ($payloadB64 === '' || $sigHex === '') {
        return null;
    }

    $calc = hash_hmac('sha256', $payloadB64, $secret);
    if (!hash_equals($calc, $sigHex)) {
        return null;
    }

    $payloadJson = b64url_decode($payloadB64);
    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $slug = (string)($payload['slug'] ?? '');
    $set = (string)($payload['set'] ?? '');
    $exp = (int)($payload['exp'] ?? 0);

    if ($slug !== $expectedSlug || $set === '' || $exp <= time()) {
        return null;
    }

    return [
        'slug' => $slug,
        'set' => $set,
        'exp' => $exp,
    ];
}

$uri = fit_request_path();
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';



/* ============================================================
   HOME
   ============================================================ */

if ($uri === '/') {

    try {
        sync_projects_from_filesystem($pdo, $projectsRoot);
    } catch (Throwable $e) {
        // On garde la page disponible même si le scan échoue
    }

    $user = current_user($pdo);
    if (!$user) {
        header('Location: ' . fit_base_path() . '/_admin/');
        exit;
    }

    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Applications</title><link rel="icon" type="image/png" href="/assets/favicon.png">
  <link rel="stylesheet" href="/assets/portal.css"></head><body><main class="container stack">
  <header class="portal-brand">
    <img src="/assets/brand-logo.png" alt="Logo YLE">
    <div>
      <div class="portal-brand-title">YLE Portail</div>
      <div class="portal-brand-subtitle">Espace central</div>
    </div>
  </header>';
    echo '<section class="card stack">';
    echo '<div class="topbar"><h1>Applications</h1><span class="badge">Connecté : ' . h($user['email']) . '</span></div>';
    echo '<nav class="nav-links">';
    if (is_superadmin($user)) {
        echo '<a href="/_admin/">Admin</a>';
    }
    echo '<a href="/_admin/logout.php">Logout</a></nav>';

    if (is_superadmin($user)) {
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

    $baseUrl = app_base_url();

    echo '<ul class="project-list">';
    foreach ($rows as $r) {
        $slug = (string)$r['slug'];
        $projectHref = '/p/' . rawurlencode($slug) . '/';
        $projectUrl = $baseUrl . $projectHref;

        $faviconCandidates = [
            '/p/' . rawurlencode($slug) . '/favicon.ico',
            '/p/' . rawurlencode($slug) . '/favicon/favicon.ico',
            '/p/' . rawurlencode($slug) . '/favicon/favicon.svg',
            '/p/' . rawurlencode($slug) . '/favicon/favicon-96x96.png',
            '/p/' . rawurlencode($slug) . '/apple-touch-icon.png',
            '/p/' . rawurlencode($slug) . '/favicon/apple-touch-icon.png',
        ];

        echo '<li class="project-item">';
        echo '<a class="project-link" href="' . h($projectHref) . '">';
        echo '<div class="project-meta">';
        echo '<img class="project-favicon" src="' . h($faviconCandidates[0]) . '" alt="Favicon de ' . h($slug) . '" data-project-href="' . h($projectHref) . '" data-fallbacks="' . h(json_encode($faviconCandidates, JSON_UNESCAPED_SLASHES)) . '">';
        echo '<span class="project-name">' . h($slug) . '</span>';
        echo '</div>';
        echo '</a>';
        echo '<div class="project-qr-wrap"><div class="project-qr" data-url="' . h($projectUrl) . '"></div></div>';
        echo '<p class="small project-url">' . h($projectUrl) . '</p>';
        echo '</li>';
    }
    echo "</ul>";
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>';
    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
  var defaultIcon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%23e2e8f0'/%3E%3Cpath d='M20 18h24a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H20a4 4 0 0 1-4-4V22a4 4 0 0 1 4-4Z' fill='%2394a3b8'/%3E%3Ccircle cx='25' cy='25' r='3' fill='%23f8fafc'/%3E%3Cpath d='M20 39l7-7 5 5 4-4 8 8v1H20v-3Z' fill='%23f8fafc'/%3E%3C/svg%3E";

  function resolveHeadFavicon(projectHref) {
    return fetch(projectHref, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) return null;
        return response.text();
      })
      .then(function (html) {
        if (!html) return null;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var iconNode = doc.querySelector('link[rel~="icon" i], link[rel="shortcut icon" i], link[rel="apple-touch-icon" i]');
        if (!iconNode) return null;
        var href = (iconNode.getAttribute('href') || '').trim();
        if (!href) return null;
        return new URL(href, window.location.origin + projectHref).toString();
      })
      .catch(function () {
        return null;
      });
  }

  function canLoadImage(url) {
    return new Promise(function (resolve) {
      if (!url) return resolve(false);
      var test = new Image();
      var done = false;
      var timer = window.setTimeout(function () {
        if (done) return;
        done = true;
        resolve(false);
      }, 3500);

      test.onload = function () {
        if (done) return;
        done = true;
        window.clearTimeout(timer);
        resolve(true);
      };

      test.onerror = function () {
        if (done) return;
        done = true;
        window.clearTimeout(timer);
        resolve(false);
      };

      test.src = url;
    });
  }

  function firstLoadableIcon(candidates) {
    return candidates.reduce(function (promise, candidate) {
      return promise.then(function (found) {
        if (found) return found;
        return canLoadImage(candidate).then(function (ok) {
          return ok ? candidate : null;
        });
      });
    }, Promise.resolve(null));
  }

  document.querySelectorAll('.project-favicon').forEach(function (img) {
    var fallbacks;
    try {
      fallbacks = JSON.parse(img.dataset.fallbacks || '[]');
    } catch (e) {
      fallbacks = [];
    }

    var projectHref = img.dataset.projectHref || '';
    var iconPromise = projectHref ? resolveHeadFavicon(projectHref) : Promise.resolve(null);

    iconPromise.then(function (headIcon) {
      var candidates = [];
      if (headIcon) candidates.push(headIcon);
      fallbacks.forEach(function (url) {
        if (url && candidates.indexOf(url) === -1) candidates.push(url);
      });
      return firstLoadableIcon(candidates);
    }).then(function (usableIcon) {
      img.src = usableIcon || defaultIcon;
    }).catch(function () {
      img.src = defaultIcon;
    });
  });

  function renderQrs() {
    if (typeof window.QRCode === 'undefined') return;
    document.querySelectorAll('.project-qr').forEach(function (node) {
      var url = node.dataset.url || '';
      if (!url) return;
      new QRCode(node, {
        text: url,
        width: 104,
        height: 104,
        correctLevel: QRCode.CorrectLevel.M
      });
    });
  }

  renderQrs();
  if (typeof window.QRCode === 'undefined') {
    setTimeout(renderQrs, 200);
  }
});
</script>
HTML;
    echo "</section></main></body></html>";

    exit;
}



/* ============================================================
   ROUTE PROJET : /p/<slug>/...
   ============================================================ */

if (preg_match('#^/p/([^/]+)(/.*)?$#', $uri, $m)) {

    $slug = $m[1];
    $path = $m[2] ?? '/';

    // A direct project URL must work even before the home page has refreshed
    // the filesystem catalogue. Discovery only inserts/updates local project
    // metadata; ACL remains enforced immediately afterwards.
    try {
        sync_projects_from_filesystem($pdo, $projectsRoot);
    } catch (Throwable $e) {
        // The subsequent realpath and ACL checks remain authoritative.
    }

    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $slug) || $slug === '.' || $slug === '..') {
        http_response_code(404);
        exit('Not found');
    }

    $shareGrant = null;
    $user = current_user($pdo);

    if ($user) {
        require_project_role($pdo, $user, $slug, 'viewer');
    } else {
        $shareGrant = parse_temp_share_token($cfg, (string)($_GET['st'] ?? ''), $slug);
        if ($shareGrant === null) {
            header('Location: ' . fit_base_path() . '/_admin/');
            exit;
        }

        $_SERVER['PORTAL_TEMP_ACCESS'] = '1';
        $_SERVER['PORTAL_TEMP_SET'] = $shareGrant['set'];
        $_SERVER['PORTAL_TEMP_EXP'] = (string)$shareGrant['exp'];
    }

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

    $webrootPrefix = rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!$target || !str_starts_with($target, $webrootPrefix)) {
        http_response_code(404);
        exit("Not found");
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    /* ============================================================
       Assets : les servir après l'ACL sans exposer /_projects.
       ============================================================ */

    if ($ext !== 'php') {
        portal_send_project_asset($target, $ext);
    }

    /* ============================================================
       Exécution PHP avec contexte corrigé
       ============================================================ */

    $virtual = fit_base_path() . "/p/$slug/" . $rel;

    $_SERVER['SCRIPT_NAME'] = $virtual;
    $_SERVER['PHP_SELF'] = $virtual;
    $_SERVER['REQUEST_URI'] = $virtual;

    chdir(dirname($target));
    require $target;
    exit;
}



http_response_code(404);
echo "Not found";
