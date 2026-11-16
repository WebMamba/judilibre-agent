# Glossaire

Les mots du domaine et leur nom dans le code. Le code est en anglais, l'interface et le
prompt en français.

## Le droit

- **Décision** (`Decision`) — une décision de justice publiée dans Judilibre,
  pseudonymisée. Identifiée par son **id** Judilibre (ex. `5fca7c5e8d9…`). C'est le seul
  identifiant que l'agent manipule.
- **Juridiction** (`jurisdiction`) — `cc` (Cour de cassation), `ca` (cours d'appel),
  `tj` (tribunaux judiciaires), `tcom` (tribunaux de commerce).
- **Chambre** (`chamber`) — formation de la Cour de cassation : `civ1`, `civ2`, `civ3`,
  `comm`, `soc`, `cr`, `mi`, `pl`… Les valeurs exactes viennent de la taxonomie.
- **Numéro de pourvoi** (`number`) — ex. `21-12.345`, la référence que citent les juristes.
- **ECLI** — identifiant européen de la décision (ex. `ECLI:FR:CCASS:2023:SO00123`).
- **Publication** (`publication`) — niveau de diffusion : `b` (Bulletin), `r` (Rapport),
  `l` (Lettres de chambre), `c` (Communiqué). Une décision publiée pèse plus qu'une
  décision inédite.
- **Solution** (`solution`) — l'issue : `cassation`, `rejet`, `irrecevabilite`…
- **Zones** (`zones`) — les parties d'une décision, en offsets dans son texte :
  `introduction`, `expose` (les faits), `moyens`, `motivations`, `dispositif`, `annexes`.
- **Taxonomie** (`taxonomy`) — les valeurs possibles d'un filtre, servies par
  `GET /taxonomy`.

## La recherche

- **Recherche** (`search_decisions`) — un appel à `GET /search` de Judilibre : une requête
  plein texte, un opérateur (`or`, `and`, `exact`), éventuellement des champs (zones).
  Toujours contrainte par les filtres.
- **Filtres** (`SearchFilters`) — juridictions, chambres, publications, période. Ils
  s'imposent à toutes les recherches de l'agent. L'agent les change avec `set_filters`,
  et l'utilisateur dans le panneau des filtres.
- **Plan de recherche** (`SearchPlan`) — les recherches que l'agent compte lancer pour une
  question, avec les filtres en vigueur. Il est proposé avec `propose_search_plan` et doit
  être **approuvé** par l'utilisateur avant toute recherche.
- **Dossier** (`Dossier`) — les décisions épinglées (`PinnedDecision`), chacune avec une
  note. L'utilisateur et l'agent l'alimentent.
- **État de recherche** (`ResearchState`) — l'état partagé AG-UI : filtres, plan
  approuvé et dossier. Il vit dans le navigateur (et dans localStorage), et le serveur
  le reçoit à chaque run.

## L'agent

- **Agent** (`researcher`) — l'agent Symfony AI déclaré dans `config/packages/ai.yaml`.
- **Run** — un tour de l'agent, servi en Server-Sent Events par `POST /agent/stream`
  (protocole AG-UI). Le serveur ne garde rien entre deux runs.
- **Interrupt** — la pause d'un run quand l'agent attend une décision humaine (ici,
  l'approbation du plan de recherche).
- **Carte de décision** (`Agent:DecisionList`) — composant Twig affiché dans la réponse.
  L'agent ne fournit que des ids et une phrase de pertinence ; le serveur charge le reste
  depuis Judilibre.
