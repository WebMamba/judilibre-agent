---
status: accepted
date: 2026-11-16
---

# L'état de recherche partagé : filtres stricts et dossier

## Contexte

AG-UI permet un état partagé entre l'agent et l'interface. Le SDK n'en prend qu'un
objet par run.

## Décisions

- **Un seul objet, `ResearchState`**, avec trois parties : `filters`, `plan` (le plan
  approuvé, voir ADR 0003) et `dossier`. Deux Live Components l'affichent : le panneau
  des filtres et le panneau du dossier. Il implémente `DescribesItself` pour dire au
  modèle, en clair, les filtres en vigueur et le contenu du dossier.
- **Les filtres sont une contrainte stricte.** `search_decisions` n'a pas de paramètre de
  filtre : il applique ceux de l'état. L'agent ne peut les changer qu'avec `set_filters`,
  et le changement se voit dans le panneau. L'utilisateur peut les modifier dans le
  panneau, et ses changements partent avec le run suivant.
- **Le dossier est alimenté des deux côtés** : l'utilisateur épingle depuis une carte de
  décision, l'agent avec `pin_decision` / `unpin_decision` quand on le lui demande.
- **L'état survit au rechargement grâce à localStorage** : un contrôleur Stimulus le
  sauvegarde à chaque `ag-ui:state-changed` et le restaure au chargement de la page. Il
  n'y a toujours pas de stockage côté serveur.

## Conséquences

- Une recherche ne peut pas sortir des filtres à l'insu de l'utilisateur.
- Le dossier appartient au navigateur : il ne se partage pas entre appareils. Une
  persistance serveur (Doctrine) pourra le remplacer plus tard.
- L'état envoyé par le client est validé : un état qui ne correspond pas à la classe
  donne une 422.
