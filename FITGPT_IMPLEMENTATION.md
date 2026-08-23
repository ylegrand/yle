# FitGPT — socle implémenté

## Ce qui est livré

- Projet portail `fit`, accessible sous `/p/fit/` avec l'authentification et l'ACL existantes.
- Tableau de bord web en lecture seule : contexte, équipement, règles versionnées, programme actif, brouillon et historique récent.
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

La migration est strictement additive : `migrations/202608230001_fit_core.sql`. Elle ne modifie ni `users`, ni l'authentification, ni les ACL existantes.

1. Sauvegarder la base depuis phpMyAdmin / OVH.
2. Importer ce fichier SQL dans la base du portail via phpMyAdmin. Il crée 21 tables `fit_*`.
3. Si un accès CLI PHP est disponible, préférer `php scripts/migrate.php`; le runner enregistre les migrations déjà appliquées dans `schema_migrations` et est relançable sans effet de bord.
4. Vérifier : `SHOW TABLES LIKE 'fit_%';` doit retourner 21 tables, puis se connecter au portail et ouvrir `/p/fit/` avec un compte autorisé.

Le runner est réservé au CLI et les dossiers `migrations/` et `scripts/` sont bloqués en HTTP par `.htaccess`.

## Prochaine intégration fonctionnelle

L'interface Web reste volontairement en lecture seule, comme prévu au cadrage. Les entrées structurées doivent venir du coach conversationnel après le POC OAuth/MCP : il appellera le service avec l'identité portail vérifiée et non un secret utilisateur. Avant de l'exposer, il faut finaliser ce POC sur les offres ChatGPT/Claude effectivement utilisées, car leur disponibilité et leurs exigences OAuth sont distinctes.
