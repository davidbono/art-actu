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

// Shared config/DB/geocoding logic — required by every entry point that
// needs to read or write exhibitions (art-actu.php, art-actu-proposer.php,
// art-actu-valider.php), kept in one place so the category list, the DB
// schema and the geocoding rules can't silently drift apart between them.

$CONFIG_PATH   = __DIR__ . '/config.php';
$GEOCACHE_PATH = __DIR__ . '/geocode_cache.json';
$DB_PATH       = __DIR__ . '/exhibitions.db';

// Exhibitions stay in the database across weekly updates (upserted by a
// stable key, not wiped and replaced) so an event the AI's search doesn't
// happen to re-surface one week doesn't just vanish from the map. Rows
// are only dropped once their own end_date has passed, or — for the rare
// entry with no end_date the AI could determine — once it hasn't been
// seen in a fresh publish for STALE_AFTER_DAYS.
const STALE_AFTER_DAYS = 60;

$config = require $CONFIG_PATH;

const CATEGORIES = [
    'peinture'         => ['label' => 'Peinture',         'icon' => 'peinture',         'color' => '#C1694F'],
    'sculpture'        => ['label' => 'Sculpture',        'icon' => 'sculpture',        'color' => '#8C8577'],
    'photographie'     => ['label' => 'Photographie',     'icon' => 'photographie',     'color' => '#5D80A3'],
    'arts_numeriques'  => ['label' => 'Arts numériques',  'icon' => 'arts_numeriques',  'color' => '#5F8B7A'],
    'street_art'       => ['label' => 'Street art',       'icon' => 'street_art',       'color' => '#D3A048'],
    'design'           => ['label' => 'Design',           'icon' => 'design',           'color' => '#B97A94'],
    'spectacle_vivant' => ['label' => 'Spectacle vivant', 'icon' => 'spectacle_vivant', 'color' => '#7C4A5B'],
    'exposition'       => ['label' => 'Exposition',       'icon' => 'exposition',       'color' => '#A38F76'],
];
const DEFAULT_CATEGORY = 'exposition';

function json_file_read(string $path)
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: 'null', true);
    return $data;
}

// Read-modify-write under an exclusive lock so concurrent geocode lookups
// (or a publish overlapping a page load) can't corrupt the JSON file.
function json_file_update(string $path, callable $mutate)
{
    $fh = fopen($path, 'c+');
    if (!$fh) {
        throw new RuntimeException("cannot open $path");
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw ?: 'null', true);
    if (!is_array($data)) {
        $data = [];
    }
    $result = $mutate($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

// Well-known venues geocoded by hand (Nominatim often fails on official
// names with parenthetical site details) — checked before ever hitting
// the network. Only venues whose coordinates are confidently known belong
// here; anything else falls through to Nominatim rather than risk a
// silently wrong pin.
const KNOWN_VENUES = [
    'musee des beaux-arts de rennes'  => ['lat' => 48.1131, 'lon' => -1.6693],
    'musee des beaux arts de rennes'  => ['lat' => 48.1131, 'lon' => -1.6693],
    'les champs libres'               => ['lat' => 48.1101, 'lon' => -1.6756],
    'la criee centre d\'art contemporain' => ['lat' => 48.1119, 'lon' => -1.6773],
    'la criee'                        => ['lat' => 48.1119, 'lon' => -1.6773],
    'frac bretagne'                   => ['lat' => 48.1308, 'lon' => -1.6960],
];

// Strips parenthetical or dash-separated site detail ("Musee des
// Beaux-Arts de Rennes (Quai Zola)" / "... - Quai Zola" -> "Musee des
// Beaux-Arts de Rennes") and accents, since all of these trip up exact-ish
// matching against KNOWN_VENUES and Nominatim alike.
function strip_venue_detail(string $venue): string
{
    $venue = preg_replace('/\s*\([^)]*\)/', '', $venue) ?? $venue;
    $venue = preg_replace('/\s*[\x{2013}\x{2014}\/,-]\s+.*/u', '', $venue) ?? $venue;
    return trim($venue);
}

function normalize_venue_name(string $venue): string
{
    $venue = strip_venue_detail($venue);
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $venue);
    return strtolower($transliterated !== false ? $transliterated : $venue);
}

// Transliterated/lowercased but NOT stripped of sub-location detail —
// used to find a known venue anywhere in the string, since the AI
// sometimes writes "sub-location - main venue" (e.g. "Salle Anita Conti -
// Les Champs Libres") rather than "main venue - sub-location", and
// strip_venue_detail() alone would keep the wrong (sub-location) half.
function normalize_full_venue(string $venue): string
{
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $venue);
    return strtolower($transliterated !== false ? $transliterated : $venue);
}

function find_known_venue(string $venue): ?array
{
    $normalized = normalize_venue_name($venue);
    if (isset(KNOWN_VENUES[$normalized])) {
        return KNOWN_VENUES[$normalized];
    }
    $full = normalize_full_venue($venue);
    foreach (KNOWN_VENUES as $key => $coords) {
        if (str_contains($full, $key)) {
            return $coords;
        }
    }
    return null;
}

// Loose bounding box around Ille-et-Vilaine / nearby Brittany — used both
// to bias Nominatim's own search and as a hard sanity check afterwards.
// Vague venue text (e.g. "parcours en ville, depart variable") can make
// Nominatim match a same-named place hundreds of km away instead of
// returning no match; without this a wrong-country/wrong-region pin can
// silently end up on the map.
const RENNES_AREA_BOUNDS = ['minLat' => 47.4, 'maxLat' => 48.9, 'minLon' => -2.6, 'maxLon' => -0.7];

function in_rennes_area(float $lat, float $lon): bool
{
    return $lat >= RENNES_AREA_BOUNDS['minLat'] && $lat <= RENNES_AREA_BOUNDS['maxLat']
        && $lon >= RENNES_AREA_BOUNDS['minLon'] && $lon <= RENNES_AREA_BOUNDS['maxLon'];
}

function nominatim_search(string $query, string $contactEmail): ?array
{
    $b = RENNES_AREA_BOUNDS;
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'        => $query,
        'format'   => 'json',
        'limit'    => 1,
        'viewbox'  => $b['minLon'] . ',' . $b['maxLat'] . ',' . $b['maxLon'] . ',' . $b['minLat'],
        'bounded'  => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'art-actu.penloup.eu/1.0 (contact: ' . $contactEmail . ')',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) {
        return null;
    }
    $results = json_decode($response, true);
    if (is_array($results) && count($results) > 0) {
        $lat = (float) $results[0]['lat'];
        $lon = (float) $results[0]['lon'];
        // Belt and braces: 'bounded=1' should already exclude this, but
        // don't trust a third-party service to always honour it.
        if (!in_rennes_area($lat, $lon)) {
            return null;
        }
        return ['lat' => $lat, 'lon' => $lon];
    }
    return null;
}

function geocode_venue(string $venue, string $city, array $cache, string $contactEmail): array
{
    $key = strtolower(trim($venue) . '|' . trim($city));
    if (isset($cache[$key]) && is_array($cache[$key])) {
        return [$cache[$key], $cache];
    }

    $known = find_known_venue($venue);
    if ($known !== null) {
        $cache[$key] = $known;
        return [$known, $cache];
    }

    $cleanVenue = strip_venue_detail($venue);
    // Try the cleaned venue with the city first, then the venue alone
    // (adding the city sometimes confuses Nominatim on well-known names).
    $coords = nominatim_search($cleanVenue . ', ' . trim($city) . ', France', $contactEmail);
    if (!$coords) {
        $coords = nominatim_search($cleanVenue . ', France', $contactEmail);
    }

    // Cache misses too (as null), so a venue we can't geocode doesn't get
    // hammered against Nominatim again every single week.
    $cache[$key] = $coords;
    return [$coords, $cache];
}

function normalize_category(?string $raw): string
{
    $raw = strtolower(trim((string) $raw));
    return array_key_exists($raw, CATEGORIES) ? $raw : DEFAULT_CATEGORY;
}

function get_db(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS exhibitions (
        ekey         TEXT PRIMARY KEY,
        title        TEXT NOT NULL,
        category     TEXT NOT NULL,
        venue        TEXT NOT NULL,
        city         TEXT NOT NULL,
        address      TEXT NOT NULL DEFAULT \'\',
        start_date   TEXT NOT NULL DEFAULT \'\',
        end_date     TEXT NOT NULL DEFAULT \'\',
        dates_label  TEXT NOT NULL DEFAULT \'\',
        status       TEXT NOT NULL DEFAULT \'\',
        description  TEXT NOT NULL DEFAULT \'\',
        link         TEXT NOT NULL DEFAULT \'\',
        featured     INTEGER NOT NULL DEFAULT 0,
        lat          REAL,
        lon          REAL,
        first_seen_at TEXT NOT NULL,
        last_seen_at  TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (mkey TEXT PRIMARY KEY, mvalue TEXT)');
    // Visitor counter: only a salted one-way hash of the IP is kept (never
    // the IP itself), just to de-duplicate repeat visits into a "distinct
    // visitors" count. See art-actu-confidentialite.php.
    $pdo->exec('CREATE TABLE IF NOT EXISTS visitors (ip_hash TEXT PRIMARY KEY, first_seen_at TEXT NOT NULL)');
    // Visitor-submitted events, held here until approved/rejected by mail
    // (see art-actu-proposer.php / art-actu-valider.php) — kept separate
    // from `exhibitions` so an unreviewed submission never appears on the
    // public map, and so it's obvious what's awaiting moderation.
    $pdo->exec('CREATE TABLE IF NOT EXISTS submissions (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        token          TEXT NOT NULL UNIQUE,
        title          TEXT NOT NULL,
        category       TEXT NOT NULL,
        venue          TEXT NOT NULL,
        city           TEXT NOT NULL,
        address        TEXT NOT NULL DEFAULT \'\',
        start_date     TEXT NOT NULL DEFAULT \'\',
        end_date       TEXT NOT NULL DEFAULT \'\',
        dates_label    TEXT NOT NULL DEFAULT \'\',
        status         TEXT NOT NULL DEFAULT \'\',
        description    TEXT NOT NULL DEFAULT \'\',
        link           TEXT NOT NULL DEFAULT \'\',
        submitter_name  TEXT NOT NULL DEFAULT \'\',
        submitter_email TEXT NOT NULL DEFAULT \'\',
        state          TEXT NOT NULL DEFAULT \'pending\',
        created_at     TEXT NOT NULL,
        decided_at     TEXT
    )');
    return $pdo;
}

// True for real public internet addresses only — local/LAN/admin traffic
// (127.0.0.1, 192.168.x.x, 10.x.x.x, 172.16-31.x.x) is never counted as a
// "visitor", it's just testing/admin access to the same server.
function is_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function record_visitor(PDO $pdo, string $salt, string $ip): void
{
    if (!is_public_ip($ip)) {
        return;
    }
    $hash = hash('sha256', $salt . $ip);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO visitors (ip_hash, first_seen_at) VALUES (?, ?)');
    $stmt->execute([$hash, date(DATE_ATOM)]);
}

function count_visitors(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
}

// Uses normalize_venue_name() (parenthetical/dash site detail stripped,
// accents folded) rather than the raw venue string, so the same event
// still matches its existing row week to week even if the AI phrases the
// venue slightly differently ("Musee des Beaux-Arts de Rennes" vs
// "... (Quai Zola)") — otherwise upserts would silently duplicate instead
// of updating in place.
function exhibition_key(string $title, string $venue): string
{
    return strtolower(trim($title)) . '|' . normalize_venue_name($venue);
}

function db_upsert_exhibition(PDO $pdo, array $row, string $now): void
{
    $existing = $pdo->prepare('SELECT first_seen_at FROM exhibitions WHERE ekey = ?');
    $existing->execute([$row['ekey']]);
    $firstSeen = $existing->fetchColumn();
    if ($firstSeen === false) {
        $firstSeen = $now;
    }

    $stmt = $pdo->prepare('INSERT INTO exhibitions
        (ekey, title, category, venue, city, address, start_date, end_date, dates_label, status, description, link, featured, lat, lon, first_seen_at, last_seen_at)
        VALUES (:ekey, :title, :category, :venue, :city, :address, :start_date, :end_date, :dates_label, :status, :description, :link, :featured, :lat, :lon, :first_seen_at, :last_seen_at)
        ON CONFLICT(ekey) DO UPDATE SET
            title = excluded.title, category = excluded.category, venue = excluded.venue, city = excluded.city,
            address = excluded.address, start_date = excluded.start_date, end_date = excluded.end_date,
            dates_label = excluded.dates_label, status = excluded.status, description = excluded.description,
            link = excluded.link, featured = excluded.featured, lat = excluded.lat, lon = excluded.lon,
            last_seen_at = excluded.last_seen_at');
    $stmt->execute([
        ':ekey' => $row['ekey'], ':title' => $row['title'], ':category' => $row['category'],
        ':venue' => $row['venue'], ':city' => $row['city'], ':address' => $row['address'],
        ':start_date' => $row['start_date'], ':end_date' => $row['end_date'], ':dates_label' => $row['dates_label'],
        ':status' => $row['status'], ':description' => $row['description'], ':link' => $row['link'],
        ':featured' => $row['featured'] ? 1 : 0, ':lat' => $row['lat'], ':lon' => $row['lon'],
        ':first_seen_at' => $firstSeen, ':last_seen_at' => $now,
    ]);
}

// Removes exhibitions whose own end_date has passed, plus (for the rare
// entry with no usable end_date) anything not re-confirmed by a publish
// in over STALE_AFTER_DAYS. Returns [expiredCount, staleCount].
function db_prune(PDO $pdo, string $today, string $now): array
{
    $expired = $pdo->prepare("DELETE FROM exhibitions WHERE end_date != '' AND end_date < ?");
    $expired->execute([$today]);
    $expiredCount = $expired->rowCount();

    $staleCutoff = date(DATE_ATOM, strtotime($now) - STALE_AFTER_DAYS * 86400);
    $stale = $pdo->prepare("DELETE FROM exhibitions WHERE end_date = '' AND last_seen_at < ?");
    $stale->execute([$staleCutoff]);
    $staleCount = $stale->rowCount();

    return [$expiredCount, $staleCount];
}

const TITLE_STOPWORDS = ['exposition', 'expositions', 'evenement', 'evenements', 'art', 'arts',
    'du', 'de', 'des', 'la', 'le', 'les', 'et', 'en', 'au', 'aux', 'un', 'une', 'rennes'];

function title_tokens(string $title): array
{
    $t = strtolower($title);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t;
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? $t;
    $words = array_filter(explode(' ', $t), function ($w) {
        return strlen($w) > 2 && !in_array($w, TITLE_STOPWORDS, true);
    });
    return array_values(array_unique($words));
}

function jaccard(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $intersection = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? $intersection / $union : 0.0;
}

// The AI doesn't phrase the same real-world event identically every week
// ("Celina Eceiza - Dormance" one week, "Dormance (Celina Eceiza)" or even
// "Dormancy" the next), so the exact upsert key alone under-deduplicates.
// This groups same-city rows and merges pairs whose titles overlap enough
// to almost certainly be the same event, keeping the more recently-seen
// row's fields but the earliest first_seen_at. Returns merged-away count.
function db_dedupe_near_matches(PDO $pdo): int
{
    $rows = $pdo->query('SELECT * FROM exhibitions')->fetchAll(PDO::FETCH_ASSOC);
    $byCity = [];
    foreach ($rows as $r) {
        $byCity[strtolower(trim($r['city']))][] = $r;
    }

    $toDelete = [];
    $firstSeenOverride = []; // ekey (kept) => earliest first_seen_at across merged rows

    foreach ($byCity as $group) {
        $n = count($group);
        $tokens = array_map(fn($r) => title_tokens($r['title']), $group);
        $removedInGroup = [];
        for ($i = 0; $i < $n; $i++) {
            if (in_array($group[$i]['ekey'], $removedInGroup, true)) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (in_array($group[$j]['ekey'], $removedInGroup, true)) {
                    continue;
                }
                $intersection = count(array_intersect($tokens[$i], $tokens[$j]));
                if ($intersection >= 2 && jaccard($tokens[$i], $tokens[$j]) >= 0.5) {
                    $keepIsI = $group[$i]['last_seen_at'] >= $group[$j]['last_seen_at'];
                    $keep = $keepIsI ? $group[$i] : $group[$j];
                    $drop = $keepIsI ? $group[$j] : $group[$i];
                    $toDelete[] = $drop['ekey'];
                    $removedInGroup[] = $drop['ekey'];
                    $earliest = min($keep['first_seen_at'], $drop['first_seen_at']);
                    $firstSeenOverride[$keep['ekey']] = isset($firstSeenOverride[$keep['ekey']])
                        ? min($firstSeenOverride[$keep['ekey']], $earliest)
                        : $earliest;
                }
            }
        }
    }

    if ($toDelete) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM exhibitions WHERE ekey IN ($placeholders)")->execute($toDelete);
    }
    foreach ($firstSeenOverride as $ekey => $earliest) {
        $pdo->prepare('UPDATE exhibitions SET first_seen_at = ? WHERE ekey = ?')->execute([$earliest, $ekey]);
    }

    return count($toDelete);
}

function db_all_exhibitions(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM exhibitions ORDER BY (start_date = \'\'), start_date, title')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function ($r) {
        return [
            'title'       => $r['title'],
            'category'    => $r['category'],
            'venue'       => $r['venue'],
            'city'        => $r['city'],
            'address'     => $r['address'],
            'start_date'  => $r['start_date'],
            'end_date'    => $r['end_date'],
            'dates_label' => $r['dates_label'],
            'status'      => $r['status'],
            'description' => $r['description'],
            'link'        => $r['link'],
            'featured'    => (bool) $r['featured'],
            'lat'         => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lon'         => $r['lon'] !== null ? (float) $r['lon'] : null,
        ];
    }, $rows);
}

function meta_get(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT mvalue FROM meta WHERE mkey = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function meta_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO meta (mkey, mvalue) VALUES (?, ?) ON CONFLICT(mkey) DO UPDATE SET mvalue = excluded.mvalue');
    $stmt->execute([$key, $value]);
}

// --- Visitor-submitted events (see art-actu-proposer.php / art-actu-valider.php) ---

function db_insert_submission(PDO $pdo, array $row, string $now): void
{
    $stmt = $pdo->prepare('INSERT INTO submissions
        (token, title, category, venue, city, address, start_date, end_date, dates_label, status, description, link, submitter_name, submitter_email, state, created_at)
        VALUES (:token, :title, :category, :venue, :city, :address, :start_date, :end_date, :dates_label, :status, :description, :link, :submitter_name, :submitter_email, \'pending\', :created_at)');
    $stmt->execute([
        ':token' => $row['token'], ':title' => $row['title'], ':category' => $row['category'],
        ':venue' => $row['venue'], ':city' => $row['city'], ':address' => $row['address'],
        ':start_date' => $row['start_date'], ':end_date' => $row['end_date'], ':dates_label' => $row['dates_label'],
        ':status' => $row['status'], ':description' => $row['description'], ':link' => $row['link'],
        ':submitter_name' => $row['submitter_name'], ':submitter_email' => $row['submitter_email'],
        ':created_at' => $now,
    ]);
}

function db_get_submission_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function db_set_submission_state(PDO $pdo, string $token, string $state, string $now): void
{
    $stmt = $pdo->prepare('UPDATE submissions SET state = ?, decided_at = ? WHERE token = ?');
    $stmt->execute([$state, $now, $token]);
}
