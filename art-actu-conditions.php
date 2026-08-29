<?php
declare(strict_types=1);

// Public legal page, same "no gate" reasoning as art-actu.php itself.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conditions d'utilisation — Art &amp; Expos Rennes</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #FAF7F2; color: #2E2B27; line-height: 1.6; }
  header { padding: 18px 22px; background: #2B2E33; color: #F4F1EB; }
  header h1 { font-size: 1.1rem; margin: 0; font-weight: 600; }
  header a { color: #E8C468; text-decoration: none; font-size: 0.85rem; }
  main { max-width: 720px; margin: 0 auto; padding: 28px 22px 60px; }
  h2 { font-size: 1.05rem; margin: 28px 0 8px; }
  p, li { font-size: 0.95rem; color: #4A4642; }
  a { color: #C1694F; }
  footer { text-align: center; padding: 20px; color: #8C8577; font-size: 0.8rem; }
</style>
</head>
<body>
<header>
  <a href="/art-actu.php">← Retour à la carte</a>
  <h1>Conditions d'utilisation</h1>
</header>
<main>
  <p>L'utilisation du site <a href="/art-actu.php">Art &amp; Expos — Rennes &amp; Ille-et-Vilaine</a>
  implique l'acceptation des présentes conditions.</p>

  <h2>Objet du site</h2>
  <p>Ce site est un projet personnel, gratuit et à but non commercial, destiné à faire connaître
  et à promouvoir les lieux culturels de Rennes et d'Ille-et-Vilaine (musées, centres d'art,
  galeries, lieux de spectacle vivant, street art...) en donnant une visibilité aux expositions
  et événements qui s'y déroulent.</p>

  <h2>Fiabilité des informations</h2>
  <p>Les événements affichés sont recherchés et résumés automatiquement par un système
  automatisé (intelligence artificielle) à partir de sources publiques sur le web. Malgré le
  soin apporté, ces informations (dates, horaires, lieux, descriptions) peuvent être
  incomplètes, erronées ou ne plus être à jour. Elles sont fournies à titre indicatif
  uniquement et ne sauraient engager la responsabilité de l'éditeur du site. Il appartient à
  chaque visiteur de vérifier les informations directement auprès du lieu culturel concerné
  avant de se déplacer.</p>

  <h2>Liens externes</h2>
  <p>Le site contient des liens vers des sites tiers (lieux culturels, presse locale...).
  L'éditeur n'exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur
  contenu, leur disponibilité ou leurs propres conditions d'utilisation.</p>

  <h2>Propriété intellectuelle</h2>
  <p>Le code et la mise en forme propres à ce site sont la propriété de leur auteur. Les noms,
  visuels et contenus relatifs à chaque exposition ou événement restent la propriété de leurs
  lieux culturels et artistes respectifs ; leur mention ici a une vocation purement informative
  et promotionnelle, sans revendication d'aucune sorte sur ces contenus.</p>
  <p>Les fonds de carte sont fournis par
  <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>,
  © les contributeurs d'OpenStreetMap, sous licence
  <a href="https://opendatacommons.org/licenses/odbl/" target="_blank" rel="noopener">ODbL</a>.</p>

  <h2>Disponibilité</h2>
  <p>Le site est fourni "en l'état", sans garantie de disponibilité continue. L'éditeur se
  réserve le droit d'en modifier ou d'en interrompre l'accès à tout moment, sans préavis.</p>

  <h2>Droit applicable</h2>
  <p>Les présentes conditions sont soumises au droit français.</p>

  <h2>Contact</h2>
  <p><a href="mailto:david.legoupil@gmail.com">david.legoupil@gmail.com</a></p>
</main>
<footer>© <?= date('Y') ?> David Legoupil — <a href="/art-actu-confidentialite.php">Politique de confidentialité</a></footer>
</body>
</html>
