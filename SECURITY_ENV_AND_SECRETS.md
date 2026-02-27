# Politique secrets et configuration (.env)

## Objectif
Éviter toute fuite de secrets et standardiser la configuration sensible.

## Règles
1. Ne pas versionner de mot de passe/API key/secret en clair.
2. Utiliser `.env` local non versionné.
3. Fournir un `.env.example` sans valeur sensible.
4. En cas de secret exposé: rotation immédiate + commit de purge.

## Variables recommandées (global portail)
- `APP_ENV` (`prod`/`dev`)
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `COOKIE_SECURE` (`1` en prod HTTPS)
- `COOKIE_SAMESITE` (`Lax` par défaut)

## Variables optionnelles par projet
- Préfixe conseillé: `<PROJET>_...` (ex: `WORD_DATA_DIR`)
- Toujours prévoir un fallback sûr si la variable est absente.

## Bonnes pratiques mutualisé
- Stocker `.env` hors webroot si possible.
- Sinon: bloquer l'accès HTTP au fichier (`.htaccess` ou config équivalente).
- Restreindre les permissions fichier au strict nécessaire.

