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

- `n8n/newsletter-art-expos.json` — export du workflow n8n "Newsletter Art
  & Expos - Rennes & Ille-et-Vilaine" (déclencheur chaque samedi 9h,
  recherche web + extraction structurée, publication vers `art-actu.php`,
  envoi de la newsletter par e-mail).
- `n8n/validation-soumission.json` — export du workflow n8n "Validation
  soumission Art & Expos" (déclenché par `art-actu-proposer.php` à chaque
  proposition d'événement, envoie l'e-mail de validation avec les liens
  Valider/Rejeter vers `art-actu-valider.php`).
  Les deux exports sont réimportables tels quels ; les identifiants de
  credentials référencés (OpenAI, Gmail, jeton HTTP) devront être remappés
  vers des credentials existants sur l'instance cible, le secret lui-même
  n'étant jamais inclus dans l'export.
- `art-actu.php` — l'application (rendu de la carte en GET, réception des
  mises à jour du workflow n8n en POST).
- `art-actu-proposer.php`, `art-actu-valider.php` — formulaire public de
  proposition d'événement et pages de validation par e-mail (voir
  `lib.php` pour le schéma partagé, y compris la table `submissions`).
- `config.php` *(non versionné)* — jeton de publication et contact utilisé
  pour le géocodage.
- `exhibitions.db`, `geocode_cache.json` *(non versionnés)* — données
  générées automatiquement, reconstruites à chaque exécution du workflow.

## Licence

Ce projet est distribué sous licence [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html)
ou toute version ultérieure — voir le fichier [`LICENSE`](LICENSE).
