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

// Public submission form ("+ Proposer un événement" on art-actu.php): lets
// any visitor suggest an exhibition/event. Nothing here ever touches the
// public `exhibitions` table directly — a submission is stored as
// `pending` and an email is sent (via the "Validation soumission Art &
// Expos" n8n workflow, since direct SMTP from this host can't reach
// Gmail — see art-actu.php's header comment on newsletter delivery) with
// one-click Approve/Reject links to art-actu-valider.php. Only once the
// site owner clicks Approve does the event get geocoded and published.

require __DIR__ . '/lib.php';

const N8N_WEBHOOK_URL = 'http://localhost:5678/webhook/art-actu-submission';

function build_dates_label(string $start, string $end): string
{
    $fmt = fn(string $d) => date('d/m/Y', strtotime($d));
    if ($start !== '' && $end !== '') {
        return 'Du ' . $fmt($start) . ' au ' . $fmt($end);
    }
    if ($end !== '') {
        return "Jusqu'au " . $fmt($end);
    }
    if ($start !== '') {
        return 'À partir du ' . $fmt($start);
    }
    return '';
}

function derive_status(string $start): string
{
    $today = date('Y-m-d');
    return ($start !== '' && $start > $today) ? 'a_venir' : 'en_cours';
}

function is_valid_iso_date(string $d): bool
{
    return $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$errors = [];
$submitted = false;

$fields = [
    'title' => '', 'category' => DEFAULT_CATEGORY, 'venue' => '', 'city' => 'Rennes',
    'address' => '', 'start_date' => '', 'end_date' => '', 'description' => '',
    'link' => '', 'submitter_name' => '', 'submitter_email' => '',
];

if ($method === 'POST') {
    // Honeypot: a field hidden from real visitors via CSS — only scripts
    // that blindly fill every form field will populate it.
    if (trim((string) ($_POST['site_web'] ?? '')) !== '') {
        // Pretend it worked so the bot doesn't learn to skip the field.
        $submitted = true;
    } else {
        foreach ($fields as $key => $default) {
            $fields[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if ($fields['title'] === '') {
            $errors[] = "Le titre est obligatoire.";
        }
        if ($fields['venue'] === '') {
            $errors[] = "Le lieu est obligatoire.";
        }
        if ($fields['city'] === '') {
            $errors[] = "La ville est obligatoire.";
        }
        if (!array_key_exists($fields['category'], CATEGORIES)) {
            $errors[] = "Catégorie invalide.";
        }
        if ($fields['submitter_email'] === '' || !filter_var($fields['submitter_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Une adresse email valide est nécessaire (pour vous recontacter si besoin — elle ne sera jamais affichée publiquement).";
        }
        if (!is_valid_iso_date($fields['start_date']) || !is_valid_iso_date($fields['end_date'])) {
            $errors[] = "Dates invalides.";
        }
        if ($fields['link'] !== '' && !filter_var($fields['link'], FILTER_VALIDATE_URL)) {
            $errors[] = "Le lien n'est pas une URL valide.";
        }

        if (!$errors) {
            $token = bin2hex(random_bytes(32));
            $now = date(DATE_ATOM);
            $datesLabel = build_dates_label($fields['start_date'], $fields['end_date']);
            $status = derive_status($fields['start_date']);

            $pdo = get_db($DB_PATH);
            db_insert_submission($pdo, [
                'token' => $token,
                'title' => $fields['title'],
                'category' => $fields['category'],
                'venue' => $fields['venue'],
                'city' => $fields['city'],
                'address' => $fields['address'],
                'start_date' => $fields['start_date'],
                'end_date' => $fields['end_date'],
                'dates_label' => $datesLabel,
                'status' => $status,
                'description' => $fields['description'],
                'link' => $fields['link'],
                'submitter_name' => $fields['submitter_name'],
                'submitter_email' => $fields['submitter_email'],
            ], $now);

            $baseUrl = 'https://penloup.eu/art-actu-valider.php?token=' . urlencode($token);
            $payload = [
                'title' => $fields['title'],
                'category_label' => CATEGORIES[$fields['category']]['label'],
                'venue' => $fields['venue'],
                'city' => $fields['city'],
                'address' => $fields['address'],
                'dates_label' => $datesLabel,
                'status_label' => $status === 'a_venir' ? 'À venir' : 'En cours',
                'description' => $fields['description'],
                'link' => $fields['link'],
                'submitter_name' => $fields['submitter_name'],
                'submitter_email' => $fields['submitter_email'],
                'approve_url' => $baseUrl . '&action=approve',
                'reject_url' => $baseUrl . '&action=reject',
                'to_email' => (string) $config['contact_email'],
            ];

            $ch = curl_init(N8N_WEBHOOK_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Art-Actu-Token: ' . $config['publish_token'],
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            curl_exec($ch);
            if (curl_errno($ch)) {
                error_log('art-actu-proposer: notification webhook failed: ' . curl_error($ch));
            }
            curl_close($ch);

            $submitted = true;
            $fields = array_fill_keys(array_keys($fields), '');
            $fields['category'] = DEFAULT_CATEGORY;
            $fields['city'] = 'Rennes';
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Proposer un événement — Art &amp; Expos Rennes</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #FAF7F2; color: #2E2B27; line-height: 1.5; }
  header { padding: 18px 22px; background: #0B0B0E; color: #F4F1EB; }
  header h1 { font-size: 1.1rem; margin: 0; font-weight: 600; }
  header a { color: #E8C468; text-decoration: none; font-size: 0.85rem; }
  main { max-width: 620px; margin: 0 auto; padding: 28px 22px 60px; }
  p.intro { color: #4A4642; font-size: 0.95rem; }
  .banner { padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 0.92rem; }
  .banner.success { background: #E9F1EC; color: #3E5C4C; border: 1px solid #B9D6C4; }
  .banner.error { background: #FBEAE6; color: #7A3325; border: 1px solid #E7C0B5; }
  .banner ul { margin: 4px 0 0; padding-left: 20px; }
  form { display: flex; flex-direction: column; gap: 14px; margin-top: 18px; }
  label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 4px; color: #4A4642; }
  .hint { font-weight: 400; color: #8C8577; font-size: 0.78rem; }
  input[type=text], input[type=email], input[type=url], input[type=date], select, textarea {
    width: 100%; padding: 9px 10px; border: 1px solid #DDD5C7; border-radius: 8px; font: inherit; background: #fff; color: #2E2B27;
  }
  input:focus, select:focus, textarea:focus { outline: 2px solid #C1694F; outline-offset: 1px; }
  textarea { min-height: 90px; resize: vertical; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .hp { position: absolute; left: -9999px; top: -9999px; }
  button[type=submit] { align-self: flex-start; background: #2B2E33; color: #fff; border: none; padding: 11px 22px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
  button[type=submit]:hover { background: #C1694F; }
  footer { text-align: center; padding: 20px; color: #8C8577; font-size: 0.8rem; }
</style>
</head>
<body>
<header>
  <a href="/art-actu.php">← Retour à la carte</a>
  <h1>Proposer un événement</h1>
</header>
<main>
<?php if ($submitted): ?>
  <div class="banner success">
    Merci ! Votre proposition a bien été envoyée. Elle sera publiée sur la carte après
    validation par l'équipe du site — généralement sous quelques jours.
  </div>
  <p><a href="/art-actu.php">← Retour à la carte</a></p>
<?php else: ?>
  <p class="intro">
    Vous connaissez une exposition, un événement artistique ou une action de street art à
    Rennes ou en Ille-et-Vilaine qui n'apparaît pas encore sur la carte ? Proposez-le ici —
    il sera vérifié puis publié.
  </p>

  <?php if ($errors): ?>
    <div class="banner error">
      <strong>Corrigez les points suivants :</strong>
      <ul><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input class="hp" type="text" name="site_web" tabindex="-1" autocomplete="off">

    <div>
      <label for="title">Titre de l'événement *</label>
      <input type="text" id="title" name="title" required value="<?= htmlspecialchars($fields['title']) ?>">
    </div>

    <div>
      <label for="category">Catégorie *</label>
      <select id="category" name="category" required>
        <?php foreach (CATEGORIES as $key => $cat): ?>
          <option value="<?= $key ?>" <?= $fields['category'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($cat['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="row2">
      <div>
        <label for="venue">Lieu *</label>
        <input type="text" id="venue" name="venue" required placeholder="Ex. : Les Champs Libres" value="<?= htmlspecialchars($fields['venue']) ?>">
      </div>
      <div>
        <label for="city">Ville *</label>
        <input type="text" id="city" name="city" required value="<?= htmlspecialchars($fields['city']) ?>">
      </div>
    </div>

    <div>
      <label for="address">Adresse <span class="hint">(optionnel, aide à géolocaliser précisément)</span></label>
      <input type="text" id="address" name="address" value="<?= htmlspecialchars($fields['address']) ?>">
    </div>

    <div class="row2">
      <div>
        <label for="start_date">Date de début <span class="hint">(optionnel)</span></label>
        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($fields['start_date']) ?>">
      </div>
      <div>
        <label for="end_date">Date de fin <span class="hint">(optionnel)</span></label>
        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($fields['end_date']) ?>">
      </div>
    </div>

    <div>
      <label for="description">Description <span class="hint">(optionnel)</span></label>
      <textarea id="description" name="description"><?= htmlspecialchars($fields['description']) ?></textarea>
    </div>

    <div>
      <label for="link">Lien vers plus d'informations <span class="hint">(optionnel, site officiel du lieu ou de l'événement)</span></label>
      <input type="url" id="link" name="link" placeholder="https://..." value="<?= htmlspecialchars($fields['link']) ?>">
    </div>

    <div class="row2">
      <div>
        <label for="submitter_name">Votre nom <span class="hint">(optionnel)</span></label>
        <input type="text" id="submitter_name" name="submitter_name" value="<?= htmlspecialchars($fields['submitter_name']) ?>">
      </div>
      <div>
        <label for="submitter_email">Votre email *  <span class="hint">(jamais affiché publiquement)</span></label>
        <input type="email" id="submitter_email" name="submitter_email" required value="<?= htmlspecialchars($fields['submitter_email']) ?>">
      </div>
    </div>

    <button type="submit">Envoyer la proposition</button>
  </form>
<?php endif; ?>
</main>
<footer>© <?= date('Y') ?> David Legoupil — <a href="/art-actu-confidentialite.php">Confidentialité</a></footer>
</body>
</html>
