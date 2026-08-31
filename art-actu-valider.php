<?php
declare(strict_types=1);

// Art & Expos — Rennes & Ille-et-Vilaine
// Copyright (C) 2026 David Legoupil
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version. See the LICENSE file at the root
// of this repository, or <https://www.gnu.org/licenses/>.

// Reached from the Approve/Reject links in the "nouvelle proposition"
// email (see art-actu-proposer.php). GET only ever shows a confirmation
// page — it never mutates state — because some mail security gateways
// (and occasionally Gmail's own link scanning) pre-fetch links found in
// email bodies; a GET that directly approved/rejected would risk being
// silently triggered by a scanner rather than a real click. The actual
// state change only happens on the POST that the confirmation page's
// button submits.
//
// The token in the link (64 random hex chars, see art-actu-proposer.php)
// is the only credential required — same "bearer secret" posture as the
// rest of this site (X-Art-Actu-Token, no login system anywhere).

require __DIR__ . '/lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = (string) ($_REQUEST['token'] ?? '');
$action = (string) ($_REQUEST['action'] ?? '');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function render_page(string $title, string $bodyHtml): void
{
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Art &amp; Expos Rennes</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #FAF7F2; color: #2E2B27; line-height: 1.55; }
  header { padding: 18px 22px; background: #0B0B0E; color: #F4F1EB; }
  header h1 { font-size: 1.1rem; margin: 0; font-weight: 600; }
  main { max-width: 560px; margin: 0 auto; padding: 28px 22px 60px; }
  table.details { width: 100%; border-collapse: collapse; margin: 18px 0; }
  table.details th { text-align: left; color: #8C8577; font-size: 0.78rem; text-transform: uppercase; letter-spacing: .03em; padding: 5px 12px 5px 0; vertical-align: top; white-space: nowrap; }
  table.details td { padding: 5px 0; font-size: 0.92rem; }
  .actions { margin-top: 24px; display: flex; gap: 12px; }
  button { font: inherit; font-weight: 600; border: none; border-radius: 8px; padding: 11px 22px; cursor: pointer; }
  .approve { background: #5F8B7A; color: #fff; }
  .approve:hover { background: #4d7365; }
  .reject { background: #8C8577; color: #fff; }
  .reject:hover { background: #6B6560; }
  .banner { padding: 14px 16px; border-radius: 10px; font-size: 0.92rem; }
  .banner.success { background: #E9F1EC; color: #3E5C4C; border: 1px solid #B9D6C4; }
  .banner.error { background: #FBEAE6; color: #7A3325; border: 1px solid #E7C0B5; }
</style>
</head>
<body>
<header><h1><?= htmlspecialchars($title) ?></h1></header>
<main><?= $bodyHtml ?></main>
</body>
</html>
    <?php
}

if ($token === '' || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    render_page('Lien invalide', '<div class="banner error">Ce lien de validation est incomplet ou invalide.</div>');
    exit;
}

$pdo = get_db($DB_PATH);
$submission = db_get_submission_by_token($pdo, $token);

if ($submission === null) {
    http_response_code(404);
    render_page('Proposition introuvable', '<div class="banner error">Cette proposition n\'existe pas (lien invalide).</div>');
    exit;
}

if ($submission['state'] !== 'pending') {
    $already = $submission['state'] === 'approved' ? 'déjà validée et publiée' : 'déjà rejetée';
    render_page('Déjà traité', '<div class="banner success">Cette proposition (« ' . htmlspecialchars($submission['title']) . ' ») a ' . $already . '.</div>');
    exit;
}

if ($method === 'POST') {
    $now = date(DATE_ATOM);

    if ($action === 'approve') {
        $geocache = json_file_read($GEOCACHE_PATH);
        if (!is_array($geocache)) {
            $geocache = [];
        }
        [$coords, $geocache] = geocode_venue($submission['venue'], $submission['city'], $geocache, (string) $config['contact_email']);
        json_file_update($GEOCACHE_PATH, fn($_old) => $geocache);

        db_upsert_exhibition($pdo, [
            'ekey'        => exhibition_key($submission['title'], $submission['venue']),
            'title'       => $submission['title'],
            'category'    => normalize_category($submission['category']),
            'venue'       => $submission['venue'],
            'city'        => $submission['city'],
            'address'     => $submission['address'],
            'start_date'  => $submission['start_date'],
            'end_date'    => $submission['end_date'],
            'dates_label' => $submission['dates_label'],
            'status'      => $submission['status'],
            'description' => $submission['description'],
            'link'        => $submission['link'],
            'featured'    => false,
            'lat'         => $coords['lat'] ?? null,
            'lon'         => $coords['lon'] ?? null,
        ], $now);
        meta_set($pdo, 'updated_at', $now);
        db_set_submission_state($pdo, $token, 'approved', $now);

        render_page('Événement publié', '<div class="banner success">« ' . htmlspecialchars($submission['title']) . ' » est maintenant publié sur la carte.</div>'
            . '<p><a href="/art-actu.php">Voir la carte →</a></p>');
    } else {
        db_set_submission_state($pdo, $token, 'rejected', $now);
        render_page('Proposition rejetée', '<div class="banner success">« ' . htmlspecialchars($submission['title']) . ' » a été rejetée et ne sera pas publiée.</div>');
    }
    exit;
}

// GET: confirmation page, no state change yet.
$rows = [
    ['Catégorie', CATEGORIES[normalize_category($submission['category'])]['label']],
    ['Lieu', $submission['venue']],
    ['Ville', $submission['city']],
    ['Adresse', $submission['address']],
    ['Dates', $submission['dates_label']],
    ['Lien', $submission['link']],
    ['Description', $submission['description']],
    ['Proposé par', trim($submission['submitter_name'] . ($submission['submitter_email'] ? ' <' . $submission['submitter_email'] . '>' : ''))],
];
$detailsHtml = '';
foreach ($rows as [$label, $value]) {
    if ($value === '' || $value === null) {
        continue;
    }
    $detailsHtml .= '<tr><th>' . htmlspecialchars($label) . '</th><td>' . htmlspecialchars($value) . '</td></tr>';
}

$actionLabel = $action === 'approve' ? 'valider et publier' : 'rejeter';
$buttonClass = $action === 'approve' ? 'approve' : 'reject';
$buttonLabel = $action === 'approve' ? 'Confirmer la validation' : 'Confirmer le rejet';

$body = '<p style="font-weight:600;font-size:1.05rem;">' . htmlspecialchars($submission['title']) . '</p>'
    . '<table class="details">' . $detailsHtml . '</table>'
    . '<form method="post">'
    . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
    . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
    . '<div class="actions"><button type="submit" class="' . $buttonClass . '">' . htmlspecialchars($buttonLabel) . '</button></div>'
    . '</form>';

render_page('Confirmer : ' . ucfirst($actionLabel), $body);
