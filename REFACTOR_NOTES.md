# Refactor portail / projets

## Objectifs

- rendre le coeur du portail agnostique des projets ;
- replacer toute la logique Fit sous `_projects/fit/` ;
- supporter correctement un déploiement dans un sous-répertoire (`/yle`) ;
- conserver l'ACL sur les assets projet sans exposer `/_projects` ;
- éviter les réponses `Content-Length` fragiles sur les assets complets en HTTP/2.

## Architecture Fit

```text
_projects/fit/
  public/       # interface web via /p/fit/
  src/          # domaine et services Fit
  server/       # handlers externes OAuth/MCP
  migrations/   # schéma Fit
  docs/
  bootstrap.php
  project.php   # déclaration des routes externes
```

Le coeur (`index.php`, `_app/`) ne contient plus de référence runtime à Fit.

## Base path

Les URLs du portail utilisent désormais `_app/http.php` :

- `portal_base_path()`
- `portal_path()`
- `portal_request_path()`
- `portal_absolute_url()`
- `portal_redirect()`

Ainsi `/assets/portal.css` devient automatiquement `/yle/assets/portal.css` en local, tout en restant `/assets/portal.css` à la racine en production.

## Assets projets

Les assets restent servis après ACL via `/p/<slug>/...`.

- pas de `Content-Length` forcé pour les réponses complètes ;
- compression PHP désactivée avant streaming ;
- support Range conservé pour les requêtes partielles ;
- extensions courantes ajoutées ;
- `index.php`, `index.html` et `index.htm` sont réellement supportés.

## Manifest PWA derrière ACL

Un manifest chargé depuis `/p/<slug>/site.webmanifest` doit être déclaré avec :

```html
<link rel="manifest" href="site.webmanifest" crossorigin="use-credentials">
```

Sans cela, certains navigateurs peuvent demander le manifest sans cookie de session et être redirigés vers l'admin.

## Migrations

Les migrations Fit sont déplacées sous `_projects/fit/migrations/`.
`scripts/migrate.php` découvre désormais les migrations du coeur et des projets et reste compatible avec les anciens noms déjà enregistrés dans `schema_migrations`.

## Vérifications effectuées

- `php -l` sur tous les fichiers PHP : OK.
- test des helpers de base path avec `/yle` : OK.
- test de résolution d'un asset `/yle/p/fit/app.css` : OK.

À valider dans l'environnement réel : DB MySQL, sessions Apache/OVH, OAuth/MCP et réponses HTTP/2.
