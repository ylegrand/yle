# AGENTS.md — Règles de cadrage CODEX (scope: tout le dépôt)

Ce fichier définit les **contraintes fortes** à appliquer systématiquement avant toute modification.
Objectif: intégrer/adapter des projets sans casser la sécurité du portail.

## 1) Contexte produit
- Portail PHP central avec authentification + ACL projet.
- Les projets sont servis via `/p/<slug>/...` et proviennent de `/_projects/<slug>`.
- Hébergement cible: **OVH mutualisé** (pas d'hypothèses SSH/root, dépendances limitées, robustesse d'abord).

## 2) Règles NON négociables (sécurité)
1. **Ne jamais contourner l'auth portail**.
   - Toute page sensible doit rester derrière `require_login(...)` et/ou `require_project_role(...)`.
2. **Ne jamais exposer de secrets en dur** dans le code versionné.
   - Utiliser des variables d'environnement via `.env` (global et éventuellement par projet).
3. **Toujours conserver les protections de chemin** (`realpath`, contrôle de préfixe) pour éviter la traversée de répertoires.
4. **Toujours conserver la protection CSRF** sur actions admin en POST.
5. **Ne jamais dégrader les cookies de session** (`httponly`, `secure` en prod HTTPS, `samesite`).
6. **Éviter toute dépendance infra avancée** non compatible mutualisé (daemon, worker, services système).

## 3) Modèle d'accès attendu (métier simplifié)
- Le besoin métier courant est binaire:
  - `viewer` = "a accès"
  - absence de rôle = "n'a pas accès"
- `editor`/`admin` existent techniquement; ne pas les supprimer sans demande explicite.
- Pour toute nouvelle fonctionnalité, partir du principe de **moindre privilège**.

## 4) Intégration d'un nouveau projet (`/_projects/<slug>`)
- Préférer une structure avec `public/` comme webroot projet.
- Vérifier que les assets passent bien via `/p/<slug>/...`:
  - préférer des chemins relatifs,
  - sinon calculer `BASE` depuis `SCRIPT_NAME`.
- Interdire toute auth locale concurrente du portail (sauf demande explicite).
- Ne pas supposer d'URL absolues à la racine du domaine (`/app.css` etc.).

## 5) Checklist obligatoire AVANT livraison
- [ ] Contrôle d'accès inchangé ou renforcé (jamais affaibli).
- [ ] CSRF présent sur toute action admin en écriture.
- [ ] Assets testés derrière `/p/<slug>/...`.
- [ ] Aucune fuite de secret en clair dans les fichiers suivis.
- [ ] Compatibilité mutualisé maintenue (pas de dépendance système exotique).
- [ ] Documentation mise à jour si structure/flux modifiés.

## 6) Fichiers de référence à lire avant de coder
- `DOC_CADRAGE_CODEX.md`
- `HOW_TO_ADAPT_NEW_PROJECT.md`
- `SECURITY_ENV_AND_SECRETS.md`

