<?php
// Single-file app: pages + API (no auth).
// Structure:
//  - /public (this file + app.js + app.css + assets + uploads)
//  - /data (index.json + sets/*.json) OUTSIDE webroot recommended
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$DATA_DIR = getenv('DATA_DIR') ?: realpath(__DIR__ . '/../data');
if ($DATA_DIR === false) { $DATA_DIR = __DIR__ . '/../data'; }
$SETS_DIR = $DATA_DIR . '/sets';
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($BASE === '') $BASE = '/';


function json_response($payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_file(string $path, $default) {
  if (!file_exists($path)) return $default;
  $raw = file_get_contents($path);
  if ($raw === false) return $default;
  $data = json_decode($raw, true);
  return is_null($data) ? $default : $data;
}

function write_json_atomic(string $path, $data): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  if (file_put_contents($tmp, $json) === false) return false;
  return rename($tmp, $path);
}

function safe_id(string $id): string {
  return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
}

function set_path(string $SETS_DIR, string $id): string {
  return $SETS_DIR . '/' . $id . '.json';
}

function slugify_title(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  // Best-effort transliteration to ASCII
  if (function_exists('transliterator_transliterate')) {
    $s = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
  } elseif (function_exists('iconv')) {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($tmp !== false) $s = $tmp;
    $s = strtolower($s);
  } else {
    $s = strtolower($s);
  }
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  return $s;
}

function generate_set_id(string $title, string $SETS_DIR): string {
  $base = slugify_title($title);
  if ($base === '') $base = 'set';
  $base = safe_id($base);
  if ($base === '') $base = 'set';

  $candidate = $base;
  $n = 2;
  while (file_exists(set_path($SETS_DIR, $candidate))) {
    $candidate = $base . '-' . $n;
    $n++;
    if ($n > 9999) {
      // Extremely unlikely fallback
      $candidate = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
      break;
    }
  }
  return $candidate;
}


$p = $_GET['p'] ?? 'home';

// ---------- API ----------
if ($p === 'api') {
  $action = $_GET['action'] ?? '';
  if (!is_dir($SETS_DIR)) @mkdir($SETS_DIR, 0775, true);

  if ($action === 'list_sets') {
    $index = read_json_file($DATA_DIR . '/index.json', []);
    json_response(['ok' => true, 'sets' => $index]);
  }

  if ($action === 'get_set') {
    $id = safe_id($_GET['id'] ?? '');
    if ($id === '') json_response(['ok'=>false,'error'=>'Missing id'], 400);
    $set = read_json_file(set_path($SETS_DIR, $id), null);
    if ($set === null) json_response(['ok'=>false,'error'=>'Not found'], 404);
    json_response(['ok'=>true,'set'=>$set]);
  }

  if ($action === 'save_set') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: 'null', true);
    if (!is_array($payload)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);

    $id = safe_id((string)($payload['id'] ?? ''));
    $title = (string)($payload['title'] ?? '');
    $bpm = (int)($payload['bpm'] ?? 185);
    $beatsPerGame = (int)($payload['beatsPerGame'] ?? 64);
    $items = $payload['items'] ?? null;

    if ($title === '' || !is_array($items)) {
      json_response(['ok'=>false,'error'=>'Missing fields'], 400);
    }

    if ($id === '') {
      $id = generate_set_id($title, $SETS_DIR);
    }
    if (count($items) < 1) json_response(['ok'=>false,'error'=>'items must be >= 1'], 400);

    $normItems = [];
    foreach ($items as $it) {
      $iid = safe_id((string)($it['id'] ?? ''));
      if ($iid === '') $iid = 'i' . (count($normItems)+1);
      $normItems[] = [
        'id' => $iid,
        'label' => (string)($it['label'] ?? ''),
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
      json_response(['ok'=>false,'error'=>'Write failed'], 500);
    }

    $indexPath = $DATA_DIR . '/index.json';
    $index = read_json_file($indexPath, []);
    if (!is_array($index)) $index = [];
    $found = false;
    foreach ($index as &$row) {
      if (($row['id'] ?? '') === $id) {
        $row['title'] = $title;
        $row['bpm'] = $bpm;
        $row['updatedAt'] = $now;
        $found = true;
        break;
      }
    }
    if (!$found) {
      $index[] = ['id'=>$id,'title'=>$title,'bpm'=>$bpm,'updatedAt'=>$now];
    }
    write_json_atomic($indexPath, $index);

    json_response(['ok'=>true,'set'=>$set]);
  }

  if ($action === 'delete_set') {
    $id = safe_id($_GET['id'] ?? '');
    if ($id === '') json_response(['ok'=>false,'error'=>'Missing id'], 400);

    $sp = set_path($SETS_DIR, $id);
    if (file_exists($sp)) @unlink($sp);

    $indexPath = $DATA_DIR . '/index.json';
    $index = read_json_file($indexPath, []);
    $index = array_values(array_filter($index, fn($r) => ($r['id'] ?? '') !== $id));
    write_json_atomic($indexPath, $index);

    $upDir = __DIR__ . '/uploads/sets/' . $id;
    if (is_dir($upDir)) {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($upDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
      }
      @rmdir($upDir);
    }
    json_response(['ok'=>true]);
  }

  if ($action === 'upload_image') {
    $setId = safe_id($_GET['set'] ?? '');
    $itemId = safe_id($_GET['item'] ?? '');
    if ($setId === '' || $itemId === '') json_response(['ok'=>false,'error'=>'Missing set/item'], 400);
    if (!isset($_FILES['file'])) json_response(['ok'=>false,'error'=>'No file'], 400);

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'Upload error'], 400);

    $tmpPath = $file['tmp_name'];
    $mime = mime_content_type($tmpPath) ?: '';
    if (strpos($mime, 'image/') !== 0) json_response(['ok'=>false,'error'=>'Not an image'], 400);

    $rel = '/uploads/sets/' . $setId . '/' . $itemId . '.webp';
    $absDir = __DIR__ . '/uploads/sets/' . $setId;
    if (!is_dir($absDir)) @mkdir($absDir, 0775, true);
    $abs = __DIR__ . $rel;

    // Convert to WebP on the fly (GD). If GD WebP isn't available, fallback to moving the file (client-side cropper sends WebP).
    $saved = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
      $bin = @file_get_contents($tmpPath);
      if ($bin !== false) {
        $im = @imagecreatefromstring($bin);
        if ($im !== false) {
          if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($im);
          if (function_exists('imagesavealpha')) @imagesavealpha($im, true);
          $saved = @imagewebp($im, $abs, 85);
          @imagedestroy($im);
        }
      }
    }

    if (!$saved) {
      if (!move_uploaded_file($tmpPath, $abs)) json_response(['ok'=>false,'error'=>'Move failed'], 500);
    } else {
      @unlink($tmpPath);
    }

    json_response(['ok'=>true,'url'=>$rel]);
  }
  json_response(['ok'=>false,'error'=>'Unknown action'], 404);
}

// ---------- HTML Pages ----------
function page_head(string $title, string $extraHead = '', string $page = ''): void { global $BASE; ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="<?= $BASE ?>/app.css?v=6">
  <?= $extraHead ?>
</head>
<body data-page="<?= htmlspecialchars($page) ?>">
<header class="topbar">
  <div class="topbar__inner">
    <a class="brand" href="<?= $BASE ?>/?p=home">Say On Beat</a>
    <nav class="nav">
      <a href="<?= $BASE ?>/?p=home">Jouer</a>
      <a href="<?= $BASE ?>/?p=admin">Admin</a>
    </nav>
  </div>
</header>
<main class="container">
<?php }

function page_foot(): void { global $BASE; ?>
</main>
<script src="<?= $BASE ?>/app.js?v=7"></script>
</body>
</html>
<?php }

if ($p === 'play') {
  $set = htmlspecialchars($_GET['set'] ?? '');
  $beatUrl = $BASE . '/assets/audio/beat.wav';
  $extraHead = <<<HTML
  <link rel="preload" as="audio" href="{$beatUrl}" crossorigin>
  <script>
  // Warm up cache ASAP (best-effort).
  (function(){
    var u = "{$beatUrl}";
    try{ fetch(u, {cache:'force-cache', mode:'same-origin'}).catch(function(){}); }catch(e){}
    if('caches' in window){
      caches.open('say-on-beat-v1').then(function(c){ return c.add(u); }).catch(function(){});
    }
  })();
  </script>
HTML;
  page_head('Jouer', $extraHead, 'play'); ?>
  <div id="loadingOverlay" class="loading-overlay" hidden>
    <div class="loading-card">
      <div class="loading-spinner" aria-hidden="true"></div>
      <div class="loading-title" id="loadingMsg">Chargement…</div>
      <div class="loading-detail" id="loadingDetail"></div>
      <div class="row gap center" style="justify-content:center;">
        <button class="btn" id="btnRetryLoad" hidden>Réessayer</button>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="row between center">
      <div>
        <h1 class="h1">Jeu</h1>
        <div class="muted">Set: <span id="setTitle">…</span></div>
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
      <a class="btn" href="<?= $BASE ?>/?p=play&set=<?= $set ?>">Rejouer</a>
      <a class="btn btn--ghost" href="<?= $BASE ?>/?p=home">Changer de set</a>
    </div>
  </div>

  <audio id="beatAudio" src="<?= $BASE ?>/assets/audio/beat.wav" preload="auto" loop></audio>
  <script>
    window.__BASE__ = "<?= $BASE ?>";
    window.__PAGE__ = "play";
    window.__BASE__ = "<?= $BASE ?>";
    window.__SET_ID__ = "<?= $set ?>";
  </script>
<?php page_foot(); exit; }

if ($p === 'admin') {
  page_head('Admin', '', 'admin'); ?>
  <div class="row between center">
    <h1 class="h1">Admin — Sets</h1>
    <button class="btn" id="btnNewSet">Nouveau set</button>
  </div>

  <div class="two-col">
    <section class="card" id="adminList">
      <h2 class="h2">Liste</h2>
      <div id="setList" class="list">Chargement…</div>
    </section>

    <section class="card" id="adminEditor">
      <h2 class="h2">Éditeur</h2>
      <div class="muted">Crée / modifie un set.</div>

      <form id="setForm" class="form">
        <div class="row gap">
          <label class="field">
            <div class="field__label">ID (auto)</div>
            <input name="id" placeholder="auto" readonly>
          </label>
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
          <button type="submit" class="btn">Enregistrer</button>
          <button type="button" class="btn btn--danger" id="btnDeleteSet">Supprimer</button>
          <a class="btn btn--ghost" id="btnPreview" href="<?= $BASE ?>/?p=play&set=">Prévisualiser</a>
        </div>
      </form>
    </section>
  </div>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css" referrerpolicy="no-referrer" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" referrerpolicy="no-referrer"></script>

  <div class="modal" id="cropModal" hidden>
    <div class="modal__backdrop"></div>
    <div class="modal__panel">
      <div class="row between center">
        <div class="h2">Recadrage</div>
        <button class="btn btn--ghost" id="btnCropClose">Fermer</button>
      </div>
      <div class="cropWrap">
        <img id="cropImage" alt="crop">
      </div>
      <div class="row gap">
        <button class="btn" id="btnCropSave">Enregistrer l'image</button>
        <div class="muted">Sortie: 512×512 WebP</div>
      </div>
    </div>
  </div>

  <script>window.__BASE__ = "<?= $BASE ?>";
    window.__PAGE__ = "admin";
    window.__BASE__ = "<?= $BASE ?>";</script>
<?php page_foot(); exit; }

page_head('Catalogue', '', 'home'); ?>
<div class="row between center">
  <h1 class="h1">Choisir un set</h1>
  <a class="btn btn--ghost" href="<?= $BASE ?>/?p=admin">Gérer les sets</a>
</div>
<div class="cards" id="homeSets">Chargement…</div>
<script>window.__BASE__ = "<?= $BASE ?>";
  window.__PAGE__ = "home";
  window.__BASE__ = "<?= $BASE ?>";</script>
<?php page_foot();
