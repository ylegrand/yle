<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$DATA_DIR = getenv('WHICH_DATA_DIR') ?: (realpath(__DIR__ . '/../data') ?: __DIR__ . '/../data');
$SETS_DIR = $DATA_DIR . '/sets';
$SHARE_TOKEN_SECRET = (string)(getenv('SHARE_TOKEN_SECRET') ?: '');
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($BASE === '') {
    $BASE = '/';
}

$shareMode = (string)($_SERVER['PORTAL_TEMP_ACCESS'] ?? '') === '1';
$shareSet = (string)($_SERVER['PORTAL_TEMP_SET'] ?? '');
$shareExp = (int)($_SERVER['PORTAL_TEMP_EXP'] ?? 0);
$shareToken = (string)($_GET['st'] ?? '');

function wh_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wh_read_shared_asset(string $name): string {
    $base = realpath(__DIR__ . '/../../_shared');
    if ($base === false) {
        return '';
    }

    $path = realpath($base . '/' . $name);
    if ($path === false) {
        return '';
    }

    $baseNorm = str_replace('\\', '/', $base);
    $pathNorm = str_replace('\\', '/', $path);
    if (!str_starts_with($pathNorm, $baseNorm . '/')) {
        return '';
    }

    $raw = file_get_contents($path);
    return $raw === false ? '' : $raw;
}


function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_id(string $value): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $value) ?? '';
}

function read_json_file(string $path, $default) {
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function write_json_atomic(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (file_put_contents($tmp, $json) === false) {
        return false;
    }

    return rename($tmp, $path);
}

function slugify(string $title): string {
    $title = trim($title);
    if ($title === '') {
        return '';
    }

    if (function_exists('transliterator_transliterate')) {
        $title = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
    } elseif (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if ($tmp !== false) {
            $title = strtolower($tmp);
        }
    } else {
        $title = strtolower($title);
    }

    $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
    return trim($title, '-');
}

function set_path(string $setsDir, string $id): string {
    return $setsDir . '/' . $id . '.json';
}

function generate_set_id(string $title, string $setsDir): string {
    $base = safe_id(slugify($title));
    if ($base === '') {
        $base = 'set';
    }

    $candidate = $base;
    $i = 2;
    while (is_file(set_path($setsDir, $candidate))) {
        $candidate = $base . '-' . $i;
        $i++;
    }

    return $candidate;
}

function remove_dir_tree(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $entry) {
        $real = $entry->getRealPath();
        if ($real === false) {
            continue;
        }
        if ($entry->isDir()) {
            @rmdir($real);
        } else {
            @unlink($real);
        }
    }

    @rmdir($path);
}

function csrf_issue_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['which_csrf'] = [
        'token' => $token,
        'exp' => time() + 7200,
    ];

    return $token;
}

function csrf_check_or_403(): void {
    $stored = $_SESSION['which_csrf'] ?? null;
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($provided === '') {
        $provided = (string)($_POST['csrf'] ?? '');
    }

    if (!is_array($stored) || !isset($stored['token'], $stored['exp'])) {
        http_response_code(403);
        exit('CSRF invalid');
    }

    if ((int)$stored['exp'] < time()) {
        unset($_SESSION['which_csrf']);
        http_response_code(403);
        exit('CSRF expired');
    }

    if (!hash_equals((string)$stored['token'], $provided)) {
        http_response_code(403);
        exit('CSRF invalid');
    }
}

function project_base_url(string $base): string {
    $https = $_SERVER['HTTPS'] ?? '';
    $isHttps = $https === 'on' || $https === '1';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $base;
}

function append_share_token_to_url(string $url, string $token): string {
    if ($token === '') {
        return $url;
    }
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'st=' . rawurlencode($token);
}

function b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function create_share_token(string $secret, string $slug, string $setId, int $ttl): array {
    $payload = [
        'slug' => $slug,
        'set' => $setId,
        'exp' => time() + max(60, $ttl),
    ];

    $payloadB64 = b64url_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sigHex = hash_hmac('sha256', $payloadB64, $secret);

    return [
        'token' => $payloadB64 . '.' . $sigHex,
        'exp' => (int)$payload['exp'],
    ];
}

if (!is_dir($SETS_DIR)) {
    @mkdir($SETS_DIR, 0775, true);
}

$p = (string)($_GET['p'] ?? 'home');
$sharedAdminCss = $p === 'admin' ? wh_read_shared_asset('set-admin-core.css') : '';
$sharedAdminJs = $p === 'admin' ? wh_read_shared_asset('set-admin-core.js') : '';

if ($shareMode && $shareSet === '') {
    http_response_code(403);
    exit('Forbidden');
}

if ($p === 'api') {
    $action = (string)($_GET['action'] ?? '');
    $indexPath = $DATA_DIR . '/index.json';

    $adminOnly = ['list_sets', 'save_set', 'delete_set', 'upload_image', 'create_share_link'];
    if ($shareMode && in_array($action, $adminOnly, true)) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    if ($action === 'list_sets') {
        $sets = read_json_file($indexPath, []);
        json_response(['ok' => true, 'sets' => $sets]);
    }

    if ($action === 'get_set') {
        $id = safe_id((string)($_GET['id'] ?? ''));
        if ($id === '') {
            json_response(['ok' => false, 'error' => 'Missing id'], 400);
        }
        if ($shareMode && $id !== $shareSet) {
            json_response(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $set = read_json_file(set_path($SETS_DIR, $id), []);
        if ($set === []) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }

        json_response(['ok' => true, 'set' => $set]);
    }

    if ($action === 'save_set') {
        csrf_check_or_403();

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: 'null', true);
        if (!is_array($payload)) {
            json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

        $id = safe_id((string)($payload['id'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $items = $payload['items'] ?? null;

        if ($title === '' || !is_array($items)) {
            json_response(['ok' => false, 'error' => 'Missing fields'], 400);
        }

        if ($id === '') {
            $id = generate_set_id($title, $SETS_DIR);
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            $itemId = safe_id((string)($item['id'] ?? ''));
            if ($itemId === '') {
                $itemId = 'i' . ($index + 1);
            }

            $normalized[] = [
                'id' => $itemId,
                'label' => trim((string)($item['label'] ?? '')),
                'img' => (string)($item['img'] ?? ''),
            ];
        }

        $set = [
            'id' => $id,
            'title' => $title,
            'items' => $normalized,
            'updatedAt' => gmdate('c'),
        ];

        if (!write_json_atomic(set_path($SETS_DIR, $id), $set)) {
            json_response(['ok' => false, 'error' => 'Write failed'], 500);
        }

        $indexRows = read_json_file($indexPath, []);
        $found = false;
        foreach ($indexRows as &$row) {
            if (($row['id'] ?? '') === $id) {
                $row['title'] = $title;
                $row['itemCount'] = count($normalized);
                $row['updatedAt'] = $set['updatedAt'];
                $found = true;
                break;
            }
        }
        unset($row);

        if (!$found) {
            $indexRows[] = [
                'id' => $id,
                'title' => $title,
                'itemCount' => count($normalized),
                'updatedAt' => $set['updatedAt'],
            ];
        }

        write_json_atomic($indexPath, array_values($indexRows));
        json_response(['ok' => true, 'set' => $set]);
    }

    if ($action === 'delete_set') {
        csrf_check_or_403();

        $id = safe_id((string)($_GET['id'] ?? ''));
        if ($id === '') {
            json_response(['ok' => false, 'error' => 'Missing id'], 400);
        }

        $setFile = set_path($SETS_DIR, $id);
        if (is_file($setFile)) {
            @unlink($setFile);
        }

        $indexRows = read_json_file($indexPath, []);
        $indexRows = array_values(array_filter($indexRows, static function ($row) use ($id) {
            return ($row['id'] ?? '') !== $id;
        }));
        write_json_atomic($indexPath, $indexRows);

        $uploadDir = __DIR__ . '/uploads/sets/' . $id;
        remove_dir_tree($uploadDir);

        json_response(['ok' => true]);
    }

    if ($action === 'upload_image') {
        csrf_check_or_403();

        $setId = safe_id((string)($_GET['set'] ?? ''));
        $itemId = safe_id((string)($_GET['item'] ?? ''));
        if ($setId === '' || $itemId === '') {
            json_response(['ok' => false, 'error' => 'Missing set/item'], 400);
        }

        if (!isset($_FILES['file'])) {
            json_response(['ok' => false, 'error' => 'No file'], 400);
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'error' => 'Upload error'], 400);
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            json_response(['ok' => false, 'error' => 'Invalid upload'], 400);
        }

        $mime = mime_content_type($tmpPath) ?: '';
        if (strpos($mime, 'image/') !== 0) {
            json_response(['ok' => false, 'error' => 'Not an image'], 400);
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $absDir = __DIR__ . '/uploads/sets/' . $setId;
        if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            json_response(['ok' => false, 'error' => 'Write failed'], 500);
        }

        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $maybeExt) {
            $candidate = $absDir . '/' . $itemId . '.' . $maybeExt;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        $filename = $itemId . '.' . $ext;
        $absolute = $absDir . '/' . $filename;

        $saved = false;
        $raw = @file_get_contents($tmpPath);
        if ($raw !== false && function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($raw);
            if ($source !== false) {
                $srcW = imagesx($source);
                $srcH = imagesy($source);
                $dstW = $srcW > 1024 ? 1024 : $srcW;
                $dstH = $srcW > 0 ? (int)round(($srcH * $dstW) / $srcW) : $srcH;

                $target = $source;
                if ($srcW > 1024 && $dstW > 0 && $dstH > 0) {
                    $target = imagecreatetruecolor($dstW, $dstH);
                    if ($target !== false) {
                        if (in_array($ext, ['png', 'gif', 'webp'], true)) {
                            imagealphablending($target, false);
                            imagesavealpha($target, true);
                            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                            imagefilledrectangle($target, 0, 0, $dstW, $dstH, $transparent);
                        }
                        imagecopyresampled($target, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                    }
                }

                $saved = match ($ext) {
                    'png' => function_exists('imagepng') ? @imagepng($target, $absolute, 6) : false,
                    'gif' => function_exists('imagegif') ? @imagegif($target, $absolute) : false,
                    'webp' => function_exists('imagewebp') ? @imagewebp($target, $absolute, 82) : false,
                    default => function_exists('imagejpeg') ? @imagejpeg($target, $absolute, 85) : false,
                };

                if ($target !== $source) {
                    imagedestroy($target);
                }
                imagedestroy($source);
            }
        }

        if (!$saved && !move_uploaded_file($tmpPath, $absolute)) {
            json_response(['ok' => false, 'error' => 'Move failed'], 500);
        }

        $relative = '/uploads/sets/' . $setId . '/' . $filename;
        json_response(['ok' => true, 'url' => $relative]);
    }

    if ($action === 'create_share_link') {
        csrf_check_or_403();

        $id = safe_id((string)($_GET['id'] ?? ''));
        if ($id === '') {
            json_response(['ok' => false, 'error' => 'Missing id'], 400);
        }

        $set = read_json_file(set_path($SETS_DIR, $id), []);
        if ($set === []) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }

        if ($SHARE_TOKEN_SECRET === '') {
            json_response(['ok' => false, 'error' => 'SHARE_TOKEN_SECRET is not configured'], 500);
        }

        $tokenData = create_share_token($SHARE_TOKEN_SECRET, 'which', $id, 7200);
        $baseUrl = project_base_url($BASE);
        $url = $baseUrl . '/?p=play&set=' . rawurlencode($id) . '&st=' . rawurlencode($tokenData['token']);

        json_response([
            'ok' => true,
            'url' => $url,
            'expiresAt' => gmdate('c', $tokenData['exp']),
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 404);
}

if ($shareMode && $p !== 'play') {
    header('Location: ' . $BASE . '/?p=play&set=' . rawurlencode($shareSet) . '&st=' . rawurlencode($shareToken));
    exit;
}

if ($p === 'play') {
    $setId = safe_id((string)($_GET['set'] ?? ''));
    if ($shareMode && $setId !== $shareSet) {
        http_response_code(403);
        exit('Forbidden');
    }
    $cssHref = append_share_token_to_url($BASE . '/app.css?v=2', $shareMode ? $shareToken : '');
    $jsHref = append_share_token_to_url($BASE . '/app.js?v=3', $shareMode ? $shareToken : '');
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qui est tu ? - Jeu</title>
  <link rel="stylesheet" href="<?= wh_h($cssHref) ?>">
</head>
<body data-page="play">
  <main class="wrap">
    <header class="top">
      <h1 id="playMainTitle">Qui est tu ?</h1>
      <?php if (!$shareMode): ?>
      <nav>
        <a href="<?= wh_h($BASE) ?>/?p=home">Sets</a>
        <a href="<?= wh_h($BASE) ?>/?p=admin">Admin</a>
      </nav>
      <?php endif; ?>
    </header>

    <section class="card play-card">
      <button id="playStage" class="stage" type="button" aria-label="Basculer pause et lecture">
        <img id="playImage" alt="">
        <div id="playHint" class="hint"></div>
        <div id="playCaption" class="caption" hidden></div>
      </button>
    </section>
  </main>

  <script>
    window.__BASE__ = "<?= wh_h($BASE) ?>";
    window.__PAGE__ = "play";
    window.__SET_ID__ = "<?= wh_h($setId) ?>";
    window.__SHARE_TOKEN__ = "<?= wh_h($shareToken) ?>";
    window.__SHARE_MODE__ = <?= $shareMode ? 'true' : 'false' ?>;
    window.__SHARE_SET__ = "<?= wh_h($shareSet) ?>";
    window.__SHARE_EXP__ = <?= (int)$shareExp ?>;
  </script>
  <script src="<?= wh_h($jsHref) ?>"></script>
</body>
</html>
<?php
    exit;
}

$csrf = csrf_issue_token();
$cssHref = append_share_token_to_url($BASE . '/app.css?v=2', $shareMode ? $shareToken : '');
$jsHref = append_share_token_to_url($BASE . '/app.js?v=3', $shareMode ? $shareToken : '');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qui est tu ?</title>
  <link rel="stylesheet" href="<?= wh_h($cssHref) ?>">
  <?php if ($p === 'admin' && $sharedAdminCss !== ''): ?>
  <style><?= $sharedAdminCss ?></style>
  <?php endif; ?>
</head>
<body data-page="<?= wh_h($p) ?>">
  <main class="wrap">
    <header class="top">
      <h1>Qui est tu ?</h1>
      <nav>
        <a href="<?= wh_h($BASE) ?>/?p=home">Sets</a>
        <a href="<?= wh_h($BASE) ?>/?p=admin">Admin</a>
      </nav>
    </header>

    <?php if ($p === 'admin'): ?>
    <section class="card">
      <div class="row between">
        <h2>Administration</h2>
        <button id="btnNewSet" class="btn" type="button">Nouveau set</button>
      </div>
      <p class="muted">Gestion des sets: infos, medias, test et partage.</p>
      <div class="row admin-toolbar">
        <input id="setSearch" class="input-search" placeholder="Rechercher un set (titre)" type="search" autocomplete="off">
        <div id="adminStatus" class="status-pill status-ok">Prêt</div>
      </div>

      <div class="layout">
        <aside>
          <h3>Sets</h3>
          <div id="setList" class="list">Chargement...</div>
        </aside>

        <section>
          <h3>Éditeur</h3>
          <form id="setForm" class="form">
            <input name="id" type="hidden">
            <label>
              <span>Titre</span>
              <input name="title" required>
            </label>

            <div id="setChecklist" class="set-checklist"></div>
            <div id="itemsEditor"></div>

            <div class="row editor-actions">
              <button class="btn" id="btnSaveSet" type="submit">Enregistrer</button>
              <button class="btn danger" id="btnDeleteSet" type="button">Supprimer</button>
              <a class="btn ghost" id="btnPlay" href="#">Prévisualiser</a>
              <button class="btn ghost" id="btnShare" type="button">Lien 2h + QR</button>
            </div>
          </form>
        </section>
      </div>
    </section>

    <div id="shareModal" class="modal" hidden>
      <div class="modal-backdrop"></div>
      <div class="modal-body">
        <h3>Lien temporaire</h3>
        <p class="muted" id="shareExpiry"></p>
        <input id="shareUrl" readonly>
        <div id="shareQr"></div>
        <div class="row">
          <button class="btn" id="btnCopyShare" type="button">Copier le lien</button>
          <button class="btn ghost" id="btnCloseShare" type="button">Fermer</button>
        </div>
      </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
    <?php else: ?>
    <section class="card">
      <div class="row between">
        <h2>Sets</h2>
        <a class="btn ghost" href="<?= wh_h($BASE) ?>/?p=admin">Gérer</a>
      </div>
      <div id="homeSets" class="cards">Chargement...</div>
    </section>
    <?php endif; ?>
  </main>

  <script>
    window.__BASE__ = "<?= wh_h($BASE) ?>";
    window.__PAGE__ = "<?= wh_h($p) ?>";
    window.__CSRF__ = "<?= wh_h($csrf) ?>";
    window.__SHARE_TOKEN__ = "";
    window.__SHARE_MODE__ = false;
    window.__SHARE_SET__ = "";
    window.__SHARE_EXP__ = 0;
  </script>
  <?php if ($p === 'admin' && $sharedAdminJs !== ''): ?>
  <script><?= $sharedAdminJs ?></script>
  <?php endif; ?>
  <script src="<?= wh_h($jsHref) ?>"></script>
</body>
</html>























