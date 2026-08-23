# FitGPT avec ChatGPT et Claude

## But et limites

Le LLM est le coach : il questionne, raisonne et propose. FitGPT est le registre fiable : il lit le contexte autorisé, valide des données structurées et conserve les versions. Il ne lance aucun appel payant OpenAI ou Anthropic depuis OVH.

Ne transmettre au LLM que les données nécessaires à la demande. Les informations de santé ou de douleur doivent être présentées comme déclaratives ; le système ne pose aucun diagnostic médical.

## Prérequis communs

1. Déployer FitGPT uniquement en HTTPS public, avec un nom de domaine stable.
2. Conserver l'identité portail comme source d'identité : pas de compte Fit séparé, pas de clé personnelle dans le client LLM.
3. Utiliser OAuth 2.1 Authorization Code + PKCE entre le client MCP et le portail.
4. Commencer avec les outils de lecture. Toute écriture (règle, brouillon, clôture) devra être demandée explicitement et validée côté portail.
5. Déclarer les identifiants clients et URLs de retour dans l'environnement OVH, jamais dans Git.

## Déroulé utilisateur

1. L'utilisateur ouvre l'URL MCP FitGPT dans ChatGPT ou Claude.
2. Le client ouvre la page d'autorisation du portail ; l'utilisateur s'authentifie avec son compte existant et approuve le périmètre demandé.
3. Le client reçoit un jeton limité à cet utilisateur et aux scopes approuvés.
4. Le coach appelle les outils en lecture, reformule son conseil puis demande confirmation avant toute future écriture.

Un jeton ne donne jamais accès à un autre utilisateur, même si les deux utilisent le même client LLM.

## ChatGPT

Pour un client API OpenAI, la Responses API accepte un serveur MCP distant et un jeton OAuth fourni par l'application cliente ; les outils peuvent être restreints et soumis à approbation. Utiliser l'URL MCP HTTPS de FitGPT, limiter au départ aux outils `fit_get_context`, `fit_get_configuration` et `fit_get_draft`, et exiger l'approbation pour toute écriture future.

L’intégration dans l’interface ChatGPT dépend de l’offre et des réglages de l’espace concerné. Faire un POC avec un seul compte avant de la proposer aux utilisateurs.

Référence : [OpenAI — outils MCP de la Responses API](https://developers.openai.com/api/reference/cli/resources/responses/methods/create).

## Claude

Claude prend en charge les serveurs MCP distants. Le connecteur MCP de l’API Messages exige un serveur HTTPS publiquement accessible ; l'application cliente gère le flux OAuth et transmet le jeton d'accès. Limiter là aussi les outils autorisés au périmètre de lecture pendant le POC.

Références : [Anthropic — MCP](https://docs.anthropic.com/en/docs/mcp) et [connecteur MCP](https://docs.anthropic.com/en/docs/agents-and-tools/mcp-connector).

## POC de recette obligatoire

1. Créer un compte portail de test sans données sensibles.
2. Vérifier qu’un jeton ne peut lire que ce compte.
3. Vérifier expiration, révocation et refus d’un scope absent.
4. Vérifier que les outils de lecture ne modifient aucune table Fit.
5. Ajouter une seule écriture contrôlée, avec confirmation utilisateur, uniquement après validation des quatre points précédents.

## Variables d’environnement prévues

`FIT_OAUTH_CLIENTS` est un JSON injecté par OVH. Chaque entrée contient `client_id` et la liste stricte de ses `redirect_uris`. Ne jamais accepter une URL de retour fournie librement par un client.
