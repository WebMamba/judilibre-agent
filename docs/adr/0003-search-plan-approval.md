---
status: accepted
date: 2026-11-16
---

# L'utilisateur approuve le plan de recherche

## Contexte

On veut un humain dans la boucle avant que l'agent interroge Judilibre, sans lui faire
valider chacune des trois à six recherches d'une question.

## Décisions

- **`propose_search_plan` est un `ResumableTool`.** Il reçoit les recherches prévues
  (requête, opérateur, champs, et le but de chacune) et renvoie un `Interrupt` affiché par
  le composant `Agent:SearchPlan`, avec trois choix :
  - **Lancer** : le plan est approuvé et enregistré dans `ResearchState.plan`.
  - **Modifier** : l'utilisateur écrit une consigne. Le plan est refusé avec cette
    consigne, et l'agent en propose un nouveau.
  - **Non** : le plan est refusé, et l'agent ne cherche pas.
- **`search_decisions` refuse de chercher sans plan approuvé**, et le dit au modèle.
  L'approbation porte sur la démarche : une fois le plan approuvé, l'agent peut affiner
  ses requêtes dans ce cadre sans redemander. Pour une nouvelle question, il propose un
  nouveau plan, qui remplace l'ancien.
- La consigne de « Modifier » est saisie dans le composant. Un petit contrôleur Stimulus
  l'envoie comme payload de reprise, `{approved: false, feedback: "…"}`.

## Conséquences

- C'est une barrière de consentement, pas une liste blanche de requêtes : l'agent peut
  formuler des requêtes qui ne figurent pas mot pour mot dans le plan.
- L'id de l'interrupt est signé par le SDK : le client ne peut ni approuver un plan que
  le modèle n'a pas proposé, ni en changer le contenu.
