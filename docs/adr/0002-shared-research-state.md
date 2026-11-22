---
status: accepted
date: 2026-11-21
supersedes: la version du 2026-11-16 (état dans le localStorage)
---

# Le dossier : un état partagé, persisté côté serveur

## Contexte

AG-UI permet un état partagé entre l'agent et l'interface, un objet par run. Le livrable
est devenu une consultation juridique, longue et coûteuse à produire : la garder dans le
navigateur seul ne suffit plus.

## Décisions

- **Un dossier = un thread AG-UI**, l'entité Doctrine `Dossier` (SQLite) : sa
  conversation, son `ResearchState` et ses interrupts ouverts. Il naît avec son premier
  message, appartient à son utilisateur (`DossierVoter`), sans partage.
- **Le serveur fait foi.** Avant chaque run, `DossierRuns` reconstruit l'entrée depuis le
  dossier : la conversation enregistrée plus les nouveaux messages de l'utilisateur, et
  l'état enregistré où seule la part de l'utilisateur (`filters`, `dossier`,
  `pinnedPrinciples`, `excluded`) vient du navigateur. Un client ne peut forger ni un
  plan validé, ni un résultat de tool, ni une consultation. Après le run, la conversation
  (le `MESSAGES_SNAPSHOT`), l'état et les interrupts ouverts sont enregistrés.
- **Un run à la fois par dossier** : un verrou `symfony/lock` ; un second run reçoit 409.
- **Les filtres restent une contrainte stricte** : `search_axis` les applique toujours.
- **Le navigateur relit l'état enregistré à la fin de chaque run** : un tool qui
  interrompt le run change l'état sans `STATE_DELTA`.

## Conséquences

- Un dossier se reprend d'un appareil à l'autre ; la page rouvre la conversation, ses
  composants, et l'interrupt en attente.
- SQLite suffit sur un seul serveur ; il vit dans un volume (`var/data`). Les migrations
  tournent à chaque déploiement.
