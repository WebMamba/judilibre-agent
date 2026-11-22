---
status: accepted
date: 2026-11-21
---

# Un déroulé en phases, et le tri des décisions par Jev

## Contexte

Le but est une consultation juridique en vue d'une plaidoirie. Une recherche Judilibre
renvoie des centaines de décisions : les faire toutes lire par Opus coûte cher et noie
son contexte.

## Décisions

- **Le dossier a une phase** (`Phase`) : qualification, plan, recherche, sélection,
  cadrage, rédaction, livré. Chaque tool n'est permis que dans certaines phases
  (`PhaseGuard`) : le serveur impose l'ordre, pas seulement le prompt. L'interface
  l'affiche en frise.
- **Qualification et cadrage** passent par `ask_questions`, un interrupt qui affiche un
  formulaire (choix unique, multiple, réponse courte). Trois tours de qualification au
  plus ; la partie défendue est obligatoire.
- **`search_axis` cherche et trie** : 1 à 3 requêtes de 50 résultats, dédoublonnées, sans
  les décisions déjà triées ni celles écartées par l'utilisateur. **Jev** (TypeSafe AI,
  `jev-1.14.0` épinglé), via `symfony/ai-type-safe-platform`, reçoit une `Evaluation` :
  l'état est la fiche anonymisée et l'axe, chaque décision est une `ChoiceQuestion`
  (favorable, défavorable, contexte, hors sujet). Une décision est retenue si la
  probabilité « hors sujet » est sous `TRIAGE_THRESHOLD`. Les lots sont découpés sous la
  limite de contexte et envoyés en parallèle. Jev lit le sommaire, les thèmes et les
  extraits ; les motivations pour une décision sans sommaire.
- **Opus ne reçoit que les décisions retenues**, présente les principes avec
  `present_findings` (par axe, chacun avec ses décisions retenues), et l'utilisateur
  épingle, écarte, puis choisit : poursuivre ou rédiger. Seul l'utilisateur épingle.

## Conséquences

- Seule la fiche anonymisée part chez TypeSafe, jamais les noms des parties.
- Les arrêts écartés par le tri ne sont pas montrés.
- Le tri n'explique pas son choix : Jev ne renvoie que des probabilités.
