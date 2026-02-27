# HOW_TO_ADAPT_NEW_PROJECT

Ce guide décrit **la façon conforme** d'ajouter un projet sans casser:
- la sécurité du portail,
- le routage `/p/<slug>/...`,
- le chargement des assets.

---

## 0) Règles non négociables

- Ne jamais contourner l'authentification du portail.
- Ne jamais implémenter une auth locale concurrente dans un projet (sauf demande explicite).
- Ne jamais supposer des URLs absolues racine (`/app.css`) pour les assets.
- Ne jamais introduire de secrets en clair dans le dépôt.

---

## 1) Structure attendue côté projet

### Cas recommandé (webroot = `public/`)
Le portail détecte automatiquement `public/` et l'utilise comme racine web.

Arbo minimale:

```text
www/_projects/<nomprojet>/
  public/
    index.php (ou index.html / index.htm)
    app.css
    app.js
    assets/
```

Accès:
- `/p/<nomprojet>/` → `public/index.php`
- `/p/<nomprojet>/app.css` → `public/app.css`
- `/p/<nomprojet>/assets/...` → `public/assets/...`

### Cas alternatif (sans `public/`)
Si le projet n'a pas `public/`, la racine du projet devient webroot.

```text
www/_projects/<nomprojet>/
  index.php
  app.css
  app.js
  assets/
```

---

## 2) Gestion des assets

### Recommandé: chemins relatifs

```html
<link rel="stylesheet" href="app.css?v=1">
<script src="app.js?v=1"></script>
<img src="assets/logo.png" alt="logo">
```

### Variante PHP avec `BASE`

```php
<?php
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($BASE === '') $BASE = '/';
?>
<script src="<?= $BASE ?>/app.js"></script>
```

Le routeur du portail corrige `SCRIPT_NAME` pour ce cas.

---

## 3) Entrypoint

Le portail sert automatiquement:
- `index.php`
- `index.html`
- `index.htm`

Un projet doit contenir au moins un de ces fichiers.

---

## 4) Checklist d'ajout (obligatoire)

1. Créer `www/_projects/<nomprojet>/`.
2. Ajouter `public/` (recommandé) et l'entrée du projet.
3. Ajouter les assets dans `public/`.
4. Synchroniser les projets via `/_admin/projects.php`.
5. Donner les droits via `/_admin/grants.php`.
6. Tester avec un utilisateur **autorisé** puis **non autorisé**.
7. Vérifier que tous les assets passent via `/p/<nomprojet>/...`.

---

## 5) ACL (besoin métier actuel)

Le besoin est binaire:
- "a accès" ⇒ rôle `viewer`
- "n'a pas accès" ⇒ aucun rôle

Conserver `editor/admin` pour compatibilité technique, sans les imposer côté métier.

---

## 6) À éviter

- URLs absolues racine (`/app.css`, `/app.js`, ...).
- Auth locale parallèle.
- Dépendances serveur incompatibles mutualisé.
- Accès direct non contrôlé aux secrets/fichiers sensibles.

---

## 7) Dépannage rapide

### Assets 404
- Vérifier emplacement dans `public/`.
- Vérifier chemins relatifs ou calcul `BASE`.
- Vérifier l'URL finale via `/p/<slug>/...`.

### Page `Not found`
- Vérifier présence de `index.php|html|htm`.
- Vérifier que le projet est synchronisé en admin.
- Vérifier les droits utilisateur sur le projet.

