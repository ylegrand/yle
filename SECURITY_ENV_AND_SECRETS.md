# Politique secrets et configuration (.env)

## Objectif
Éviter toute fuite de secrets et standardiser la configuration sensible.

## Règles
1. Ne pas versionner de mot de passe/API key/secret en clair.
2. Utiliser `.env` local non versionné.
3. Fournir un `.env.example` sans valeur sensible.
4. En cas de secret exposé: rotation immédiate + commit de purge.
5. Le webroot doit refuser les accès directs à `.env`, `/_app` et `/_projects`.
   Les assets des projets passent uniquement par `/p/<slug>/...` après contrôle ACL.
6. L'installeur est désactivé par défaut. Pour une installation neuve, définir
   temporairement `INSTALL_ENABLED=1`, l'utiliser, puis le remettre à `0`.
7. En production, désactiver Xdebug et `display_errors` dans la configuration PHP.
   Le portail masque aussi les exceptions non gérées comme défense complémentaire.

## Variables recommandées (global portail)
- `APP_ENV` (`prod`/`dev`)
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `COOKIE_SECURE` (configurable hors production ; forcé à `1` en `APP_ENV=prod`)
- `COOKIE_SAMESITE` (`Lax` par défaut)
- `SHARE_TOKEN_SECRET` (signature HMAC des liens temporaires publics)
- `INSTALL_ENABLED` (`0` par défaut ; uniquement temporaire lors de l'installation)

## Variables optionnelles par projet
- Préfixe conseillé: `<PROJET>_...` (ex: `WORD_DATA_DIR`)
- Toujours prévoir un fallback sûr si la variable est absente.

## Bonnes pratiques mutualisé
- Stocker `.env` hors webroot si possible.
- Sinon: bloquer l'accès HTTP au fichier (`.htaccess` ou config équivalente).
- Restreindre les permissions fichier au strict nécessaire.
