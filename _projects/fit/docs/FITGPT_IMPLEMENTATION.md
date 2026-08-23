# FitGPT — socle implémenté

## Ce qui est livré

- Projet portail `fit`, accessible sous `/p/fit/` avec l'authentification et l'ACL existantes.
- Tableau de bord web en lecture seule : contexte, équipement, règles versionnées, programme actif, brouillon et historique récent.
- Écran `?page=configure` réservé aux éditeurs : profil et blocs de règles enregistrés par versions, avec CSRF.
- Schéma MySQL additif, isolé du modèle du portail par le préfixe `fit_`.
- Service PHP `FitService` pour le profil, les règles versionnées, les brouillons de séance, leurs points de reprise et leur clôture atomique.
- Validation stricte des séries : une seule référence d'exercice, typage dynamique/cardio/isométrique, champs inconnus conservés à `NULL`, motif obligatoire pour une omission.

Le module ne contient ni appel LLM côté serveur, ni authentification locale, ni programme figé. Le futur coach conversationnel prépare les décisions ; le service conserve et contraint les faits.

## Invariants appliqués

- Toutes les requêtes du service sont filtrées par `user_id`; un exercice personnel, un brouillon et une séance ne peuvent être lus ou modifiés que par leur propriétaire.
- `fit_active_session` impose un unique brouillon actif par utilisateur. L'ouverture et la clôture prennent un verrou sur la ligne `users` concernée.
- Une clôture copie le brouillon dans des tables d'historique dans une transaction unique, puis libère le verrou actif.
- Chaque changement de règle ajoute une version dans `fit_rule_block_version`; les données précédentes ne sont pas écrasées.
- Le snapshot de contexte est conservé avec le brouillon puis la séance clôturée, afin que les analyses futures restent explicables.

## Migration production OVH

Les migrations sont strictement additives : `_projects/fit/migrations/202608230001_fit_core.sql` (socle Fit) et `_projects/fit/migrations/202608230002_fit_mcp_oauth.sql` (OAuth MCP). Elles ne modifient ni `users`, ni l'authentification, ni les ACL existantes.

1. Sauvegarder la base depuis phpMyAdmin / OVH.
2. Importer les deux fichiers SQL dans l'ordre, via phpMyAdmin. Ils créent 23 tables `fit_*`.
3. Si un accès CLI PHP est disponible, préférer `php scripts/migrate.php`; le runner enregistre les migrations déjà appliquées dans `schema_migrations` et est relançable sans effet de bord.
4. Vérifier : `SHOW TABLES LIKE 'fit_%';` doit retourner 21 tables, puis se connecter au portail et ouvrir `/p/fit/` avec un compte autorisé.

Le runner est réservé au CLI. Les migrations Fit restent dans le projet et sont découvertes par `scripts/migrate.php`; `/_projects` et `/scripts` restent bloqués en HTTP par `.htaccess`.

## Écritures conversationnelles

Les outils MCP `fit_save_profile`, `fit_save_rule_block`, `fit_open_or_resume_draft`, `fit_checkpoint_draft` et `fit_close_draft` sont disponibles. Ils exigent le scope OAuth `fit.write` et l'argument explicite `confirmed=true`; les lectures restent sous `fit.read`. Les séances utilisent les transactions du service existant.

## Intégration conversationnelle

Le code métier Fit (`src/`), ses routes externes (`server/` + `project.php`), ses migrations et son interface `public/` sont désormais contenus sous `_projects/fit/`. Le portail central ne référence plus Fit directement.

L'interface Web reste volontairement en lecture seule, comme prévu au cadrage. Le guide de mise en œuvre et de recette ChatGPT/Claude est disponible dans `FITGPT_CHATGPT_CLAUDE_HOWTO.md`. Le client conversationnel doit appeler le service avec l'identité portail vérifiée et non un secret utilisateur.

## Import historique

L'import de l'ancien Google Sheet est volontairement différé. Lorsque le mapping métier sera stabilisé, un export `.xlsx` actuel suffira : aucun connecteur Google Sheets ne sera nécessaire.
