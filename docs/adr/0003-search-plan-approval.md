---
status: accepted
date: 2026-11-21
supersedes: la version du 2026-11-16 (plan de requêtes)
---

# L'utilisateur valide la fiche dossier et les axes de recherche

## Contexte

On veut un humain dans la boucle avant toute recherche, dans des termes qu'un avocat
manipule : des axes de recherche, pas des requêtes Judilibre.

## Décisions

- **`propose_research_plan` est un `ResumableTool`** : il propose le nom du dossier, la
  **fiche dossier** anonymisée (faits, question de droit, partie défendue, objectif) et
  1 à 6 **axes** (intitulé, objectif). Le composant `Agent:ResearchPlan` permet de
  corriger la fiche, d'ajouter, supprimer ou renommer des axes, puis de valider ; ou de
  demander un autre plan avec une consigne.
- **Le plan validé est une liste d'`Axis`**, numérotés `a1`, `a2`… et rattachés à un
  tour. L'agent formule ses requêtes au moment de `search_axis`.
- **Poursuivre la recherche** passe par le même tool : en phase Sélection, il propose
  des axes complémentaires (tour suivant), la fiche du premier tour restant acquise.

## Conséquences

- C'est une barrière de consentement sur la démarche, pas une liste blanche de requêtes.
- L'id de l'interrupt est signé par le SDK : le client ne peut pas valider un plan que
  le modèle n'a pas proposé.
