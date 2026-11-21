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

## Le dossier

- **Dossier** (`Dossier`, entité Doctrine) — un thread AG-UI : la conversation, l'état
  de recherche et les interrupts ouverts, d'un seul utilisateur. Le serveur le garde
  entre deux runs.
- **Phase** (`Phase`) — où en est le dossier : `qualification`, `plan`, `recherche`,
  `selection`, `cadrage`, `redaction`, `livre`. Chaque tool n'est permis que dans
  certaines phases.
- **Partie défendue** (`CaseBrief::side`) — qui l'avocat défend, et sa position.
- **Fiche dossier** (`CaseBrief`) — les faits anonymisés, la question de droit, la
  partie défendue et l'objectif, validés par l'utilisateur. Seule partie du dossier
  envoyée au modèle de tri.
- **Axe de recherche** (`Axis`) — une piste du plan : un intitulé, un objectif, un tour.
  L'agent le traduit en requêtes Judilibre.
- **Plan de recherche** (`SearchPlan`) — les axes validés, tour après tour.
- **Tri** (`Triage`, `JevTriage`) — le classement de chaque décision trouvée par Jev :
  favorable, défavorable, contexte ou hors sujet.
- **Sens** (`Stance`) — ce qu'une décision ou un principe fait pour la partie défendue.
- **Décision retenue** (`RetainedDecision`) — une décision gardée par le tri ; seules
  celles-ci (et les épinglées) peuvent être montrées ou citées.
- **Principe** (`Principle`) — une règle dégagée par la jurisprudence, avec les
  décisions qui la posent ou l'appliquent.
- **Épingler / Retenir / Écarter** — l'utilisateur épingle une décision (`dossier`),
  retient un principe (`pinnedPrinciples`), écarte une décision (`excluded`).
- **Cadrage** — les questions avant la rédaction : destinataire, niveau de détail,
  arguments.
- **Consultation** (`Consultation`) — le livrable : six sections en Markdown, l'annexe
  des décisions citées, l'avertissement. Une décision s'y cite `[[id]]`.

## La recherche

- **Recherche** (`search_axis`) — jusqu'à trois appels à `GET /search` de Judilibre pour
  un axe, toujours contraints par les filtres, puis le tri.
- **Filtres** (`SearchFilters`) — juridictions, chambres, publications, période. Ils
  s'imposent à toutes les recherches. L'agent les change avec `set_filters`, et
  l'utilisateur dans le panneau des filtres.

## L'agent

- **Agent** (`researcher`) — l'agent Symfony AI déclaré dans `config/packages/ai.yaml`
  (Claude Opus).
- **Run** — un tour de l'agent, servi en Server-Sent Events par `POST /agent/stream`
  (protocole AG-UI). Le dossier est enregistré à la fin de chaque run.
- **Interrupt** — la pause d'un run quand l'agent attend une décision humaine : un
  formulaire de questions, le plan, le choix après les principes.
