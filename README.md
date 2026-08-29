# Art & Expos — Rennes & Ille-et-Vilaine

Ce site a pour objectif de **promouvoir les lieux culturels de Rennes** et
d'Ille-et-Vilaine, en donnant une visibilité simple et à jour aux
expositions et événements artistiques qui s'y déroulent (musées, centres
d'art, galeries, street art, spectacle vivant...).

Accessible sur [penloup.eu/art-actu.php](https://penloup.eu/art-actu.php).

## Fonctionnement

- Un workflow [n8n](https://n8n.io) s'exécute chaque samedi à 9h : une IA
  recherche sur le web les expositions et événements en cours ou à venir
  à Rennes et en Ille-et-Vilaine, en priorisant une liste de sites de
  lieux culturels locaux.
- Les événements sont stockés dans une base SQLite persistante : chaque
  mise à jour **enrichit** la base (mise à jour des événements déjà connus,
  ajout des nouveaux) plutôt que de tout remplacer, et ne supprime un
  événement que lorsque sa date de fin est dépassée.
- Une newsletter par e-mail est envoyée avec les temps forts de la semaine,
  et pointe vers la carte interactive.
- La carte affiche chaque lieu culturel avec un marqueur coloré par
  catégorie (peinture, sculpture, photographie, arts numériques, street
  art, design, spectacle vivant...), filtrable par catégorie.

## Fichiers

- `art-actu.php` — l'application (rendu de la carte en GET, réception des
  mises à jour du workflow n8n en POST).
- `config.php` *(non versionné)* — jeton de publication et contact utilisé
  pour le géocodage.
- `exhibitions.db`, `geocode_cache.json` *(non versionnés)* — données
  générées automatiquement, reconstruites à chaque exécution du workflow.
