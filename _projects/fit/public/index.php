<?php
declare(strict_types=1);

// This project is reached only after the portal router has authenticated the user
// and checked their project role. Keep this guard for direct execution safety.
if (!isset($pdo, $user) || !($pdo instanceof PDO) || !is_array($user) || !isset($user['id'])) {
  http_response_code(403);
  exit('Forbidden');
}

require_once __DIR__ . '/../../../_app/fit.php';
require_once __DIR__ . '/../../../_app/csrf.php';

function fit_web_h(mixed $value): string {
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fit_web_status_label(string $status): string {
  return match ($status) {
    'complete' => 'configuré',
    'incomplete' => 'à définir',
    default => $status,
  };
}


$service = fit_service($pdo);
$context = $service->getSessionContext((int) $user['id']);
$configuration = $service->getConfigurationStatus((int) $user['id']);
$configuredBlocks = count(array_filter(
  $configuration['blocks'],
  static fn(array $block): bool => $block['status'] === 'complete'
));
$totalBlocks = count($configuration['blocks']);
$base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/p/fit/index.php')), '/\\');
if ($base === '') {
  $base = '/p/fit';
}
$page = (string) ($_GET['page'] ?? 'dashboard');
if ($page === 'configure') {
  require_project_role($pdo, $user, 'fit', 'editor');
  $error = null;
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check($pdo, (int) $user['id'], (string) ($_POST['csrf'] ?? ''));
    try {
      if (isset($_POST['revoke_token_id'])) {
        fit_oauth_service($pdo)->revokeToken((int) $user['id'], (int) $_POST['revoke_token_id']);
        header('Location: ' . $base . '/?page=configure&revoked=1'); exit;
      }
      $profile = json_decode((string) ($_POST['profile_data'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
      if (!is_array($profile)) throw new FitValidationException('INVALID_PROFILE', 'Le profil doit être un objet JSON.');
      $service->saveProfile((int) $user['id'], $profile, (string) ($_POST['timezone'] ?? 'Europe/Paris'));
      $code = (string) ($_POST['rule_code'] ?? '');
      $rule = json_decode((string) ($_POST['rule_data'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
      if ($code !== '' && is_array($rule)) $service->saveRuleBlock((int) $user['id'], $code, $rule);
      header('Location: ' . $base . '/?page=configure&saved=1'); exit;
    } catch (Throwable $exception) { $error = $exception instanceof FitValidationException ? $exception->getMessage() : 'Données JSON invalides.'; }
  }
  $csrf = csrf_token($pdo, (int) $user['id']);
  $tokens = fit_oauth_service($pdo)->listActiveTokens((int) $user['id']);
  ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configurer Fit</title><link rel="stylesheet" href="<?= fit_web_h($base) ?>/app.css"></head><body><main class="fit-shell"><header class="fit-header"><div><p class="eyebrow">Fit · configuration</p><h1>Profil et règles</h1></div><a class="back-link" href="<?= fit_web_h($base) ?>/">Tableau de bord</a></header><?php if ($error): ?><section class="notice"><?= fit_web_h($error) ?></section><?php endif; ?><?php if (isset($_GET['saved'])): ?><section class="notice">Configuration enregistrée et versionnée.</section><?php endif; ?><?php if (isset($_GET['revoked'])): ?><section class="notice">Autorisation MCP révoquée.</section><?php endif; ?><section class="panel"><form method="post"><input type="hidden" name="csrf" value="<?= fit_web_h($csrf) ?>"><label>Fuseau horaire <input name="timezone" value="<?= fit_web_h($context['profile']['timezone']) ?>"></label><label>Profil (JSON) <textarea name="profile_data" rows="8"><?= fit_web_h(json_encode($context['profile']['data'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea></label><label>Bloc à versionner <select name="rule_code"><option value="">— ne pas modifier —</option><?php foreach ($configuration['blocks'] as $block): ?><option value="<?= fit_web_h($block['code']) ?>"><?= fit_web_h($block['code']) ?></option><?php endforeach; ?></select></label><label>Données du bloc (JSON) <textarea name="rule_data" rows="8">{}</textarea></label><button class="back-link" type="submit">Enregistrer</button></form></section><section class="panel"><h2>Autorisations MCP actives</h2><?php if (!$tokens): ?><p class="empty">Aucune autorisation active.</p><?php else: ?><?php foreach ($tokens as $token): ?><form method="post" class="rule-list"><input type="hidden" name="csrf" value="<?= fit_web_h($csrf) ?>"><span><?= fit_web_h($token['client_id']) ?> · <?= fit_web_h($token['scopes']) ?> · expire <?= fit_web_h($token['expires_at']) ?></span><button name="revoke_token_id" value="<?= fit_web_h($token['id']) ?>" type="submit">Révoquer</button></form><?php endforeach; ?><?php endif; ?></section></main></body></html><?php exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fit — Portail</title>
  <link rel="stylesheet" href="<?= fit_web_h($base) ?>/app.css">
</head>
<body>
<main class="fit-shell">
  <header class="fit-header">
    <div>
      <p class="eyebrow">Fit · lecture seule</p>
      <h1>Contexte d’entraînement</h1>
      <p class="muted">Les décisions restent préparées par le coach conversationnel ; le portail conserve les faits, versions et historiques.</p>
    </div>
    <div><a class="back-link" href="<?= fit_web_h($base) ?>/?page=configure">Configurer</a> <a class="back-link" href="/_admin/">Retour au portail</a></div>
  </header>

  <section class="summary-grid" aria-label="Résumé Fit">
    <article class="card">
      <span class="metric"><?= $configuredBlocks ?>/<?= $totalBlocks ?></span>
      <span class="muted">blocs de règles configurés</span>
    </article>
    <article class="card">
      <span class="metric"><?= count($context['equipment']) ?></span>
      <span class="muted">équipements actifs</span>
    </article>
    <article class="card">
      <span class="metric"><?= count($context['recent_sessions']) ?></span>
      <span class="muted">séances récentes conservées</span>
    </article>
  </section>

  <?php if ($context['active_draft']): ?>
    <section class="notice" aria-label="Brouillon actif">
      <strong>Une séance est en brouillon.</strong>
      Démarrée le <?= fit_web_h((string) $context['active_draft']['started_at']) ?> ; elle pourra être reprise sans créer de doublon.
    </section>
  <?php endif; ?>

  <div class="columns">
    <section class="panel">
      <h2>Configuration versionnée</h2>
      <ul class="rule-list">
        <?php foreach ($configuration['blocks'] as $block): ?>
          <li>
            <span><?= fit_web_h($block['code']) ?></span>
            <span class="badge badge--<?= fit_web_h($block['status']) ?>"><?= fit_web_h(fit_web_status_label($block['status'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="muted">Aucun programme n’est figé ici : les règles restent des données versionnées par utilisateur.</p>
    </section>

    <section class="panel">
      <h2>Programme actif</h2>
      <?php if ($context['program']): ?>
        <p><strong><?= fit_web_h($context['program']['name']) ?></strong></p>
        <p class="muted">Révision <?= fit_web_h($context['program']['revision_number']) ?> · publiée</p>
        <?php if ($context['program']['notes']): ?><p><?= nl2br(fit_web_h($context['program']['notes'])) ?></p><?php endif; ?>
      <?php else: ?>
        <p class="empty">Aucun programme publié pour ce compte.</p>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Contexte temporaire</h2>
      <?php if (!$context['availability_contexts'] && !$context['active_pain']): ?>
        <p class="empty">Aucun contexte temporaire ni douleur active déclaré.</p>
      <?php else: ?>
        <?php foreach ($context['availability_contexts'] as $item): ?>
          <p><strong><?= fit_web_h($item['location_name'] ?: 'Disponibilité') ?></strong><br><span class="muted">du <?= fit_web_h($item['starts_on']) ?><?= $item['ends_on'] ? ' au ' . fit_web_h($item['ends_on']) : '' ?></span></p>
        <?php endforeach; ?>
        <?php foreach ($context['active_pain'] as $item): ?>
          <p><strong>Signalement : <?= fit_web_h($item['location']) ?></strong><br><span class="muted">déclaré le <?= fit_web_h($item['started_on']) ?></span></p>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="panel panel--wide">
      <h2>Dernières séances</h2>
      <?php if (!$context['recent_sessions']): ?>
        <p class="empty">Aucune séance validée. Les valeurs inconnues restent volontairement absentes.</p>
      <?php else: ?>
        <div class="session-table" role="table">
          <div class="session-row session-row--head" role="row"><span>Début</span><span>Statut</span><span>Durée déclarée</span><span>Énergie</span></div>
          <?php foreach ($context['recent_sessions'] as $session): ?>
            <div class="session-row" role="row">
              <span><?= fit_web_h($session['started_at'] ?: '—') ?></span>
              <span><?= fit_web_h($session['status']) ?></span>
              <span><?= $session['declared_duration_seconds'] === null ? '—' : fit_web_h((int) $session['declared_duration_seconds']) . ' s' ?></span>
              <span><?= $session['energy_before'] === null ? '—' : fit_web_h((int) $session['energy_before']) . ' → ' . fit_web_h((int) $session['energy_after']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
</body>
</html>
