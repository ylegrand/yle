# Cadrage technique pour CODEX (portail + projets)

## Objectif
Donner à CODEX un cadre de décision stable pour:
- intégrer de nouveaux projets,
- corriger/faire évoluer l'existant,
- sans casser la sécurité ni le routage/serving des assets.

---

## 1) Architecture à préserver

### 1.1 Routeur central
- Le portail principal route les accès:
  - `/` = listing des applications autorisées,
  - `/p/<slug>/...` = exécution/serving du projet cible.
- Le routeur **doit conserver**:
  - authentification obligatoire,
  - vérification ACL projet,
  - garde-fous `realpath` + vérification de préfixe.

### 1.2 Back-office
- Le back-office admin gère:
  - utilisateurs,
  - synchronisation des projets depuis le filesystem,
  - affectation des droits user ↔ projet.
- Les actions en écriture restent en POST + CSRF.

### 1.3 Projets embarqués
- Les projets vivent sous `/_projects/<slug>`.
- Recommandation forte: webroot projet dans `public/`.
- Les contenus applicatifs (données JSON, uploads) doivent rester maîtrisés et non exposer de secrets.

---

## 2) Règles de décision CODEX (ordre de priorité)

1. **Sécurité d'abord**: ne jamais introduire une régression d'auth/ACL/CSRF/session.
2. **Compat mutualisé**: privilégier les solutions simples, sans service système additionnel.
3. **Compat routeur**: respecter le fonctionnement `/p/<slug>/...`.
4. **Robustesse assets**: garantir que CSS/JS/images/audio chargent correctement.
5. **Lisibilité/maintenance**: solutions explicites, peu magiques, bien documentées.

---

## 3) Contrôles obligatoires avant PR

### 3.1 Sécurité
- Vérifier qu'aucune route sensible n'est accessible sans login.
- Vérifier qu'une route projet reste bloquée sans rôle valide.
- Vérifier que chaque POST admin est protégé CSRF.

### 3.2 Assets et routage projet
- Vérifier qu'un projet accessible via `/p/<slug>/` charge:
  - page principale,
  - assets statiques (css/js/images/audio),
  - endpoints API éventuels.
- Vérifier absence d'URL cassées en `/...` quand elles devraient être relatives/BASE.

### 3.3 Secrets
- Interdire l'ajout de nouveaux secrets en clair dans les fichiers versionnés.
- Si config sensible: documenter `.env` et fournir exemple non sensible.

---

## 4) Politique ACL fonctionnelle (besoin métier actuel)

### 4.1 Sémantique métier à appliquer
- Besoin principal: accès binaire.
  - "a accès" => rôle `viewer`
  - "n'a pas accès" => aucune entrée de rôle

### 4.2 Consigne de mise en œuvre
- Pour toute nouvelle logique fonctionnelle, ne pas exiger `editor/admin` sauf demande explicite.
- Conserver le modèle actuel (rétrocompatibilité), mais documenter les usages en mode binaire.

---

## 5) Standard d'intégration d'un nouveau projet

1. Créer `/_projects/<slug>/public/`.
2. Mettre l'entrée dans `public/index.php` (ou html).
3. Utiliser des assets relatifs.
4. Exécuter la synchronisation des projets dans l'admin.
5. Affecter les droits utilisateurs.
6. Tester l'ouverture via `/p/<slug>/` avec un compte autorisé et non autorisé.

---

## 6) Limites et hypothèses (OVH mutualisé)
- Pas d'hypothèse sur systemd/cron avancé/queues.
- Favoriser fichiers + MySQL + PHP standard.
- Prévoir des dégradations gracieuses si extension absente.

