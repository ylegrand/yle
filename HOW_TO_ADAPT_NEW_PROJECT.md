# HOW_TO_ADAPT_NEW_PROJECT

Ce portail expose des projets placés dans :

www/_projects/<nomprojet>/

et les rend accessibles via :

https://<domaine>/p/<nomprojet>/

Le contrôle d’accès (login + droits par projet) est géré par le portail, pas par les projets.

---

## 1) Structure attendue côté projet

### Cas recommandé (webroot = public)
Le portail détecte automatiquement un dossier public/ et l’utilise comme racine web.

Arbo minimale :

www/_projects/<nomprojet>/
  public/
    index.php (ou index.html / index.htm)
    app.css
    app.js
    assets/

Accès :
/p/<nomprojet>/ → public/index.php
/p/<nomprojet>/app.css → public/app.css
/p/<nomprojet>/assets/... → public/assets/...

---

### Cas alternatif (pas de public)
Si ton projet n’a pas de dossier public/, le portail utilisera la racine du projet comme webroot.

www/_projects/<nomprojet>/
  index.php
  app.css
  app.js
  assets/

---

## 2) Gestion des assets

### Recommandé (chemins relatifs)
<link rel="stylesheet" href="app.css?v=1">
<script src="app.js?v=1"></script>
<img src="assets/logo.png">

---

### Variante PHP avec BASE
<?php
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($BASE === '') $BASE = '/';
?>
<script src="<?= $BASE ?>/app.js"></script>

Le routeur du portail corrige automatiquement SCRIPT_NAME.

---

## 3) Entrypoint

Le portail sert automatiquement :
- index.php
- index.html
- index.htm

Un projet doit contenir au moins un de ces fichiers.

---

## 4) Ajout d’un projet (checklist)

1. Créer le dossier  
www/_projects/<nomprojet>/

2. Ajouter public/ (recommandé)  
public/index.php

3. Ajouter les assets  
public/app.css  
public/app.js  
public/assets/

4. Ajouter un favicon (optionnel)  
public/favicon.ico

5. Synchroniser dans l’admin  
/_admin/projects.php

6. Donner les droits  
/_admin/grants.php

7. Tester  
/ p / <nomprojet> /

---

## 5) À éviter

URLs absolues /app.css  
Auth locale dans le projet  
accès direct _projects/...

---

## 6) Dépannage

Assets 404
- vérifier emplacement dans public/
- vérifier chemins relatifs

Page Not found
- vérifier index.php ou index.html
- relancer la synchro admin
