---
status: accepted
date: 2026-11-21
---

# La consultation : des sections, des citations vérifiées

## Décisions

- **Six sections fixes**, écrites une à une avec `write_section` en Markdown : faits,
  problématique, réponse synthétique, discussion, risques, arguments de plaidoirie.
  L'annexe des décisions citées et l'avertissement sont produits par le serveur, pas par
  le modèle.
- **Les citations sont vérifiées** : une décision se cite `[[id]]` ; une section qui cite
  un id hors du dossier (retenu par le tri ou épinglé) ou écarté est refusée. La
  consultation n'est livrée que si chaque décision épinglée est discutée. Le rendu
  remplace chaque `[[id]]` par la citation, liée à la décision.
- **Retouches** : `write_section` réécrit une section en gardant la version précédente,
  que l'utilisateur peut restaurer. **Reprendre la recherche** après livraison ramène en
  Sélection et marque la consultation « à mettre à jour ».
- **Vue Document** à côté du chat (Live Component `ConsultationPanel`), **export Word**
  avec PhpWord, à partir du même HTML.

## Conséquences

- Le modèle ne peut pas citer une décision de mémoire dans le document.
