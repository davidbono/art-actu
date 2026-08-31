<?php
declare(strict_types=1);

// Art & Expos — Rennes & Ille-et-Vilaine
// Copyright (C) 2026 David Legoupil — licensed under the GNU GPL v3 or
// later. See the LICENSE file at the root of this repository.

// Public legal page, same "no gate" reasoning as art-actu.php itself:
// linked from its footer, meant to be readable by any visitor. Filename
// prefixed "art-actu-" like art-actu-sites.txt since penloup.eu's docroot
// is a flat namespace shared with several unrelated apps.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confidentialité — Art &amp; Expos Rennes</title>
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
  <h1>Politique de confidentialité</h1>
</header>
<main>
  <p>Ce site (<a href="/art-actu.php">Art &amp; Expos — Rennes &amp; Ille-et-Vilaine</a>) est un
  projet personnel et non commercial visant à faire connaître les lieux culturels de Rennes
  et d'Ille-et-Vilaine. Il ne demande aucune inscription et ne propose aucun compte utilisateur.</p>

  <h2>Données collectées</h2>
  <p>Ce site ne collecte, ne stocke et ne vend aucune donnée personnelle identifiable de ses
  visiteurs. Il ne dépose aucun cookie et ne contient aucun outil d'analyse d'audience (pas de
  Google Analytics, Matomo ou équivalent).</p>
  <p>Le compteur de visiteurs affiché sur la carte ne conserve jamais votre adresse IP : à
  chaque visite, l'adresse IP est transformée de façon irréversible (hachage à sens unique avec
  une clé secrète propre au serveur) avant d'être comparée aux visites déjà connues, uniquement
  pour éviter de compter plusieurs fois la même personne. Il est impossible de retrouver une
  adresse IP à partir des valeurs stockées.</p>
  <p>Le serveur qui héberge le site conserve, comme tout serveur web, des journaux techniques
  standard (adresse IP, horodatage, page demandée) à des fins de sécurité et de bon
  fonctionnement. Ces journaux ne sont ni analysés à des fins commerciales, ni transmis à des
  tiers.</p>

  <h2>Services tiers utilisés</h2>
  <ul>
    <li><strong>OpenStreetMap</strong> — les fonds de carte sont chargés directement par votre
    navigateur depuis les serveurs d'OpenStreetMap, qui peuvent recevoir votre adresse IP selon
    leur propre politique. Voir la
    <a href="https://wiki.osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener">politique de confidentialité d'OpenStreetMap</a>.</li>
    <li><strong>Nominatim (OpenStreetMap)</strong> — utilisé côté serveur pour localiser les
    lieux culturels sur la carte à partir de leur nom/adresse publique ; aucune donnée
    personnelle d'un visiteur n'est transmise à ce service.</li>
    <li><strong>Liens externes</strong> — les fiches d'expositions renvoient vers les sites des
    lieux culturels concernés, qui disposent chacun de leur propre politique de confidentialité.</li>
  </ul>

  <h2>Proposer un événement</h2>
  <p>Le formulaire « + Proposer un événement » demande une adresse email afin de pouvoir vous
  recontacter en cas de besoin (par exemple pour préciser une information avant publication).
  Cette adresse n'est jamais affichée publiquement sur la carte ; elle n'est visible que dans
  l'email de validation envoyé au responsable du site, et n'est utilisée à aucune autre fin.
  Une proposition non validée (rejetée, ou jamais traitée) reste dans la base technique du site
  mais n'apparaît jamais sur la carte publique.</p>

  <h2>Origine des informations affichées</h2>
  <p>Les expositions et événements affichés sont recherchés et résumés automatiquement à partir
  de sources publiques disponibles sur le web. Ces informations peuvent contenir des erreurs ou
  être obsolètes : vérifiez toujours les horaires et modalités directement auprès du lieu
  culturel concerné avant de vous déplacer.</p>

  <h2>Contact</h2>
  <p>Pour toute question relative à cette politique de confidentialité :
  <a href="mailto:contact@penloup.eu">contact@penloup.eu</a>.</p>
</main>
<footer>© <?= date('Y') ?> David Legoupil — <a href="/art-actu-conditions.php">Conditions d'utilisation</a></footer>
</body>
</html>
