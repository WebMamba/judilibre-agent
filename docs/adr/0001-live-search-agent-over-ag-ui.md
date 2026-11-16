---
status: accepted
date: 2026-11-16
---

# Un agent qui interroge Judilibre en direct, servi en AG-UI

## Contexte

On veut un assistant qui retrouve des décisions de justice. Deux voies : aspirer tout
Judilibre pour construire un RAG, ou laisser un agent interroger l'API de recherche de
Judilibre. Judilibre compte des millions de décisions, plafonne une requête à 10 000
résultats et grossit chaque jour ; sa recherche plein texte et ses filtres juridiques
(juridiction, chambre, publication, solution, période) sont déjà bons.

## Décisions

- **Phase 1 sans index propre.** L'agent (Symfony AI, `claude-opus-5-5`) a des tools qui
  appellent Judilibre : `search_decisions`, `read_decision`, `list_taxonomy`. Un index
  vectoriel sur un corpus ciblé reste possible en phase 2, comme un tool de plus.
- **AG-UI, sur le modèle de [demo-ag-ui](https://github.com/WebMamba/demo-ag-ui)**, avec
  `webmamba/ag-ui-bundle`. Le run est streamé dans la réponse au `POST /agent/stream`
  (Server-Sent Events) : pas de Mercure, pas de Messenger, pas de base de données. Le
  navigateur garde la conversation et la renvoie à chaque run.
- **Accès PISTE par clé API** dans l'en-tête `KeyId` (le schéma déclaré par la spec
  OpenAPI de Judilibre), via un client HTTP scopé. L'URL de base (bac à sable ou
  production) est une variable d'environnement.
- **Les cartes de décisions se chargent côté serveur, par id.** `Agent:DecisionList` reçoit
  des ids et une phrase de pertinence ; juridiction, date, numéro, solution et lien
  viennent de Judilibre, avec un cache. L'agent ne peut pas inventer une référence
  affichée.
- **Toutes les juridictions** (`cc`, `ca`, `tj`, `tcom`), avec des filtres qui démarrent sur
  la Cour de cassation.

## Conséquences

- Les réponses sont toujours à jour, et il n'y a rien à stocker ni à resynchroniser.
- La qualité dépend de la recherche par mots de Judilibre : l'agent doit reformuler en
  vocabulaire juridique et multiplier les recherches. C'est ce que la phase 2 améliorera
  si besoin.
- Les tests n'appellent ni Judilibre ni Anthropic : Judilibre est simulé par
  `MockHttpClient` avec des fixtures, et le modèle par une plateforme scriptée.
