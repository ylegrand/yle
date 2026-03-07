<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$DATA_DIR = getenv('WORD_DATA_DIR') ?: (realpath(__DIR__ . '/../data') ?: __DIR__ . '/../data');
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

function word_h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
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

function safe_id(string $id): string {
  return preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
}

function set_path(string $setsDir, string $id): string {
  return $setsDir . '/' . $id . '.json';
}

function slugify_title(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  if (function_exists('transliterator_transliterate')) {
    $value = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
  } elseif (function_exists('iconv')) {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($tmp !== false) {
      $value = strtolower($tmp);
    }
  } else {
    $value = strtolower($value);
  }

  $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
  return trim($value, '-');
}

function generate_set_id(string $title, string $setsDir): string {
  $base = safe_id(slugify_title($title));
  if ($base === '') {
    $base = 'set';
  }

  $candidate = $base;
  $n = 2;
  while (is_file(set_path($setsDir, $candidate))) {
    $candidate = $base . '-' . $n;
    $n++;
    if ($n > 9999) {
      $candidate = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
      break;
    }
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
  $_SESSION['word_csrf'] = [
    'token' => $token,
    'exp' => time() + 7200,
  ];

  return $token;
}

function csrf_check_or_403(): void {
  $stored = $_SESSION['word_csrf'] ?? null;
  $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($provided === '') {
    $provided = (string)($_POST['csrf'] ?? '');
  }

  if (!is_array($stored) || !isset($stored['token'], $stored['exp'])) {
    http_response_code(403);
    exit('CSRF invalid');
  }

  if ((int)$stored['exp'] < time()) {
    unset($_SESSION['word_csrf']);
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
    $index = read_json_file($indexPath, []);
    json_response(['ok' => true, 'sets' => $index]);
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
    $bpm = (int)($payload['bpm'] ?? 185);
    $beatsPerGame = (int)($payload['beatsPerGame'] ?? 64);
    $items = $payload['items'] ?? null;

    if ($title === '' || !is_array($items)) {
      json_response(['ok' => false, 'error' => 'Missing fields'], 400);
    }

    if ($id === '') {
      $id = generate_set_id($title, $SETS_DIR);
    }

    $normItems = [];
    foreach ($items as $idx => $it) {
      $itemId = safe_id((string)($it['id'] ?? ''));
      if ($itemId === '') {
        $itemId = 'i' . ($idx + 1);
      }
      $normItems[] = [
        'id' => $itemId,
        'label' => trim((string)($it['label'] ?? '')),
        'img' => (string)($it['img'] ?? ''),
      ];
    }

    $now = gmdate('c');
    $set = [
      'id' => $id,
      'title' => $title,
      'bpm' => $bpm,
      'beatsPerGame' => $beatsPerGame,
      'items' => $normItems,
      'updatedAt' => $now,
    ];

    if (!write_json_atomic(set_path($SETS_DIR, $id), $set)) {
      json_response(['ok' => false, 'error' => 'Write failed'], 500);
    }

    $index = read_json_file($indexPath, []);
    $found = false;
    foreach ($index as &$row) {
      if (($row['id'] ?? '') === $id) {
        $row['title'] = $title;
        $row['bpm'] = $bpm;
        $row['itemCount'] = count($normItems);
        $row['updatedAt'] = $now;
        $found = true;
        break;
      }
    }
    unset($row);

    if (!$found) {
      $index[] = [
        'id' => $id,
        'title' => $title,
        'bpm' => $bpm,
        'itemCount' => count($normItems),
        'updatedAt' => $now,
      ];
    }

    write_json_atomic($indexPath, array_values($index));
    json_response(['ok' => true, 'set' => $set]);
  }

  if ($action === 'delete_set') {
    csrf_check_or_403();

    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') {
      json_response(['ok' => false, 'error' => 'Missing id'], 400);
    }

    $sp = set_path($SETS_DIR, $id);
    if (is_file($sp)) {
      @unlink($sp);
    }

    $index = read_json_file($indexPath, []);
    $index = array_values(array_filter($index, static fn($r) => ($r['id'] ?? '') !== $id));
    write_json_atomic($indexPath, $index);

    remove_dir_tree(__DIR__ . '/uploads/sets/' . $id);
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

    $rel = '/uploads/sets/' . $setId . '/' . $itemId . '.webp';
    $absDir = __DIR__ . '/uploads/sets/' . $setId;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
      json_response(['ok' => false, 'error' => 'Write failed'], 500);
    }
    $abs = __DIR__ . $rel;

    $saved = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
      $bin = @file_get_contents($tmpPath);
      if ($bin !== false) {
        $im = @imagecreatefromstring($bin);
        if ($im !== false) {
          if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($im);
          }
          if (function_exists('imagesavealpha')) {
            @imagesavealpha($im, true);
          }
          $saved = @imagewebp($im, $abs, 85);
          @imagedestroy($im);
        }
      }
    }

    if (!$saved && !move_uploaded_file($tmpPath, $abs)) {
      json_response(['ok' => false, 'error' => 'Move failed'], 500);
    }

    json_response(['ok' => true, 'url' => $rel]);
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

    $tokenData = create_share_token($SHARE_TOKEN_SECRET, 'word', $id, 7200);
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

function page_head(string $title, string $extraHead = '', string $page = '', string $token = ''): void {
  global $BASE;
  $cssHref = append_share_token_to_url($BASE . '/app.css?v=9', $token);
  ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= word_h($title) ?></title>
  <link rel="stylesheet" href="<?= word_h($cssHref) ?>">
  <?= $extraHead ?>
</head>
<body data-page="<?= word_h($page) ?>">
<header class="topbar">
  <div class="topbar__inner">
    <a class="brand" href="<?= word_h($BASE) ?>/?p=home">Say On Beat</a>
    <nav class="nav">
      <?php if (!$token): ?>
      <a href="<?= word_h($BASE) ?>/?p=home">Jouer</a>
      <a href="<?= word_h($BASE) ?>/?p=admin">Admin</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container">
<?php
}

function page_foot(string $token = ''): void {
  global $BASE;
  $jsHref = append_share_token_to_url($BASE . '/app.js?v=9', $token);
  ?>
</main>
<script src="<?= word_h($jsHref) ?>"></script>
</body>
</html>
<?php
}

if ($p === 'play') {
  $setId = safe_id((string)($_GET['set'] ?? ''));
  if ($shareMode && $setId !== $shareSet) {
    http_response_code(403);
    exit('Forbidden');
  }

  $beatBase = $BASE . '/assets/audio/beat';
  $beatWav = append_share_token_to_url($beatBase . '.wav', $shareMode ? $shareToken : '');
  $beatM4a = append_share_token_to_url($beatBase . '.m4a', $shareMode ? $shareToken : '');
  $beatOgg = append_share_token_to_url($beatBase . '.ogg', $shareMode ? $shareToken : '');

  page_head('Jouer', '', 'play', $shareMode ? $shareToken : ''); ?>
  <div id="loadingOverlay" class="loading-overlay" hidden>
    <div class="loading-card">
      <div class="loading-spinner" aria-hidden="true"></div>
      <div class="loading-title" id="loadingMsg">Chargement...</div>
      <div class="loading-detail" id="loadingDetail"></div>
      <div class="row gap center" style="justify-content:center;">
        <button class="btn" id="btnRetryLoad" hidden>Reessayer</button>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="row between center">
      <div>
        <h1 class="h1">Jeu</h1>
        <div class="muted">Set: <span id="setTitle">...</span></div>
      </div>
      <div class="row gap wrap">
        <button class="btn" id="btnStart">Start</button>
        <button class="btn btn--ghost" id="btnStop" disabled>Stop</button>
      </div>
    </div>
    <div class="hud">
      <div>Round <span id="hudRound">1</span>/5</div>
      <div>Step <span id="hudStep">0</span>/8</div>
      <div>Beat <span id="hudBeat">0</span></div>
    </div>
  </div>

  <div class="grid" id="grid"></div>

  <div class="card" id="results" hidden>
    <h2 class="h2">Fin de partie</h2>
    <div class="row gap">
      <a class="btn" href="<?= word_h($BASE) ?>/?p=play&set=<?= word_h($setId) ?>">Rejouer</a>
      <a class="btn btn--ghost" href="<?= word_h($BASE) ?>/?p=home">Changer de set</a>
    </div>
  </div>

  <audio id="beatAudio" preload="none" loop></audio>
  <script>
    window.__BASE__ = "<?= word_h($BASE) ?>";
    window.__PAGE__ = "play";
    window.__SET_ID__ = "<?= word_h($setId) ?>";
    window.__BEAT_CANDIDATES__ = ["<?= word_h($beatM4a) ?>", "<?= word_h($beatOgg) ?>", "<?= word_h($beatWav) ?>"];
    window.__CSRF__ = "";
    window.__SHARE_TOKEN__ = "<?= word_h($shareToken) ?>";
    window.__SHARE_MODE__ = <?= $shareMode ? 'true' : 'false' ?>;
    window.__SHARE_SET__ = "<?= word_h($shareSet) ?>";
    window.__SHARE_EXP__ = <?= (int)$shareExp ?>;
  </script>
<?php page_foot($shareMode ? $shareToken : ''); exit; }

$csrf = csrf_issue_token();

if ($p === 'admin') {
  $extraHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>';
  page_head('Admin', $extraHead, 'admin'); ?>
  <section class="card">
    <div class="row between center">
      <h1 class="h1">Administration</h1>
      <button class="btn" id="btnNewSet">Nouveau set</button>
    </div>
    <p class="muted">CRUD simple: sets, images et rythme.</p>
    <div class="row admin-toolbar">
      <input id="setSearch" class="input-search" placeholder="Rechercher un set (titre)" type="search" autocomplete="off">
      <div id="adminStatus" class="status-pill status-ok">Pret</div>
    </div>

    <div class="two-col">
      <section class="card" id="adminList">
        <h2 class="h2">Sets</h2>
        <div id="setList" class="list">Chargement...</div>
      </section>

      <section class="card" id="adminEditor">
        <h2 class="h2">Editeur</h2>
        <div class="muted">Cree / modifie un set.</div>

        <form id="setForm" class="form">
          <input name="id" type="hidden">

          <div class="row gap">
            <label class="field grow">
              <div class="field__label">Titre</div>
              <input name="title" placeholder="ex: Couleurs" required>
            </label>
          </div>

          <div class="row gap">
            <label class="field">
              <div class="field__label">BPM</div>
              <input name="bpm" type="number" value="185" min="60" max="240" required>
            </label>
            <label class="field">
              <div class="field__label">Beats / partie</div>
              <input name="beatsPerGame" type="number" value="64" min="8" max="512" required>
            </label>
            <div class="field grow"></div>
          </div>

          <div class="items" id="itemsEditor"></div>

          <div class="row gap">
            <button type="submit" class="btn" id="btnSaveSet">Enregistrer</button>
            <button type="button" class="btn btn--danger" id="btnDeleteSet">Supprimer</button>
            <a class="btn btn--ghost" id="btnPreview" href="<?= word_h($BASE) ?>/?p=play&set=">Previsualiser</a>
            <button type="button" class="btn btn--ghost" id="btnShare">Lien 2h + QR</button>
          </div>
        </form>
      </section>
    </div>
  </section>

  <div id="shareModal" class="modal" hidden>
    <div class="modal__backdrop"></div>
    <div class="modal__panel share-panel">
      <h3 class="h2">Lien temporaire</h3>
      <p class="muted" id="shareExpiry"></p>
      <input id="shareUrl" readonly>
      <div id="shareQr"></div>
      <div class="row gap">
        <button class="btn" id="btnCopyShare" type="button">Copier le lien</button>
        <button class="btn btn--ghost" id="btnCloseShare" type="button">Fermer</button>
      </div>
    </div>
  </div>

  <script>
    window.__BASE__ = "<?= word_h($BASE) ?>";
    window.__PAGE__ = "admin";
    window.__CSRF__ = "<?= word_h($csrf) ?>";
    window.__SHARE_TOKEN__ = "";
    window.__SHARE_MODE__ = false;
    window.__SHARE_SET__ = "";
    window.__SHARE_EXP__ = 0;
  </script>
<?php page_foot(); exit; }

page_head('Catalogue', '', 'home'); ?>
<div class="row between center">
  <h1 class="h1">Choisir un set</h1>
  <a class="btn btn--ghost" href="<?= word_h($BASE) ?>/?p=admin">Gerer les sets</a>
</div>
<div class="cards" id="homeSets">Chargement...</div>
<script>
  window.__BASE__ = "<?= word_h($BASE) ?>";
  window.__PAGE__ = "home";
  window.__CSRF__ = "<?= word_h($csrf) ?>";
  window.__SHARE_TOKEN__ = "";
  window.__SHARE_MODE__ = false;
  window.__SHARE_SET__ = "";
  window.__SHARE_EXP__ = 0;
</script>
<?php page_foot();



