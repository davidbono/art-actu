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

// Deliberately public (no Authentik gate), like /mpg.php and /claude.php
// on penloup.eu: it's the map linked from the weekly art newsletter, meant
// to be opened by anyone who got the link. GET renders the map; POST (with
// the right X-Art-Actu-Token header) is how the n8n workflow publishes a
// fresh list of exhibitions each Saturday. Code lives outside
// /srv/penloup/html on purpose since it's updated by n8n, not by hand.
//
// Icon set: hand-drawn 10x10 pixel-art sprites (see PIXEL_ICONS below),
// single accent colour per category, defined once and reused via <use> —
// that shared sprite + shared palette IS the graphic charter. Kept
// separate from the newsletter's colour dots (see the n8n workflow's
// "Construire newsletter + payload" node) since inline SVG is unreliable
// in email clients; the two surfaces share the same hex colours instead.

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

// Hand-drawn 10x10 pixel-art bitmaps for the category icons, one string
// per row ('X' = filled cell). Rendered as a grid of <rect> (see
// render_pixel_icon()) so they read as chunky 8-bit sprites, matching the
// hero banner's pixel-art "ART" animation.
const PIXEL_ICONS = [
    'peinture' => [
        '...XXXX...',
        '.XXXXXXXX.',
        'XXXXXXXXXX',
        'XXX.XXX.XX',
        'XX.XXXXX.X',
        'XXXXXXXXXX',
        'XXXXXXXXXX',
        '.XXXXXXXX.',
        '..XXXXXX..',
        '....XX....',
    ],
    'sculpture' => [
        '...XXXX...',
        '..XXXXXX..',
        '..XXXXXX..',
        '...XXXX...',
        '....XX....',
        '...XXXX...',
        '..XXXXXX..',
        '.XXXXXXXX.',
        'XXXXXXXXXX',
        'XXXXXXXXXX',
    ],
    'photographie' => [
        '...XX.....',
        '.XXXXXXXX.',
        'XXXXXXXXXX',
        'XX.XXXX.XX',
        'X.X....X.X',
        'X.X....X.X',
        'XX.XXXX.XX',
        'XXXXXXXXXX',
        'XXXXXXXXXX',
        '..........',
    ],
    'arts_numeriques' => [
        'XXXXXXXXXX',
        'X........X',
        'X.X.XX.X.X',
        'X.XX..XX.X',
        'X........X',
        'X........X',
        'XXXXXXXXXX',
        '...XXXX...',
        '...XXXX...',
        '..XXXXXX..',
    ],
    'street_art' => [
        '..XXXX....',
        '.X....X...',
        'XXXXXXXX..',
        'X......X..',
        'X.XXXX.X..',
        'X.X..X.X..',
        'X.XXXX.X..',
        'X......X..',
        'XXXXXXXX..',
        '..........',
    ],
    'design' => [
        'XXX.......',
        '.XXX......',
        '..XXX.....',
        '...XXX....',
        '....XXX...',
        '.....XXX..',
        '......XXX.',
        '.......XXX',
        '........X.',
        '.........X',
    ],
    'spectacle_vivant' => [
        '..XXXXXX..',
        '.XXXXXXXX.',
        'XXX.XX.XXX',
        'XXXXXXXXXX',
        'XXXXXXXXXX',
        'X.XXXXXX.X',
        'XX.XXXX.XX',
        'XXXXXXXXXX',
        '.XXXXXXXX.',
        '..XXXXXX..',
    ],
    'exposition' => [
        'XXXXXXXXXX',
        'X........X',
        'X.XX.....X',
        'X........X',
        'X........X',
        'X....X...X',
        'X...XXX..X',
        'X..XXXXX.X',
        'X.XXXXXXXX',
        'XXXXXXXXXX',
    ],
];

function render_pixel_icon(string $id, array $bitmap): string
{
    $rects = '';
    foreach ($bitmap as $row => $line) {
        $cols = str_split($line);
        foreach ($cols as $col => $ch) {
            if ($ch === 'X') {
                $rects .= "<rect x=\"$col\" y=\"$row\" width=\"1\" height=\"1\"/>";
            }
        }
    }
    return "<g id=\"icon-$id\" fill=\"currentColor\">$rects</g>";
}

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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = $_SERVER['HTTP_X_ART_ACTU_TOKEN'] ?? '';
    if (!hash_equals((string) $config['publish_token'], $token)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid token']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['exhibitions']) || !is_array($body['exhibitions'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'expected {"exhibitions": [...]}']);
        exit;
    }

    $geocache = json_file_read($GEOCACHE_PATH);
    if (!is_array($geocache)) {
        $geocache = [];
    }

    $pdo = get_db($DB_PATH);
    $now = date(DATE_ATOM);
    $today = date('Y-m-d');

    $geocodedCount = 0;
    $withCoords = 0;
    $upserted = 0;

    $pdo->beginTransaction();
    foreach ($body['exhibitions'] as $item) {
        if (!is_array($item) || empty($item['title']) || empty($item['venue'])) {
            continue;
        }
        $title = (string) $item['title'];
        $venue = (string) $item['venue'];
        $city = (string) ($item['city'] ?? 'Rennes');
        $geoKey = strtolower(trim($venue) . '|' . trim($city));
        $isNewLookup = !isset($geocache[$geoKey]);

        [$coords, $geocache] = geocode_venue($venue, $city, $geocache, (string) $config['contact_email']);
        if ($isNewLookup) {
            $geocodedCount++;
            // Be polite to Nominatim (max ~1 req/s) when we actually hit
            // the network; cached hits above don't sleep.
            usleep(1100000);
        }
        if ($coords) {
            $withCoords++;
        }

        db_upsert_exhibition($pdo, [
            'ekey'        => exhibition_key($title, $venue),
            'title'       => $title,
            'category'    => normalize_category($item['category'] ?? null),
            'venue'       => $venue,
            'city'        => $city,
            'address'     => (string) ($item['address'] ?? ''),
            'start_date'  => (string) ($item['start_date'] ?? ''),
            'end_date'    => (string) ($item['end_date'] ?? ''),
            'dates_label' => (string) ($item['dates_label'] ?? ''),
            'status'      => (string) ($item['status'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'link'        => (string) ($item['link'] ?? ''),
            'featured'    => (bool) ($item['featured'] ?? false),
            'lat'         => $coords['lat'] ?? null,
            'lon'         => $coords['lon'] ?? null,
        ], $now);
        $upserted++;
    }

    [$expiredCount, $staleCount] = db_prune($pdo, $today, $now);
    $mergedCount = db_dedupe_near_matches($pdo);
    meta_set($pdo, 'updated_at', $now);
    $pdo->commit();

    json_file_update($GEOCACHE_PATH, fn($_old) => $geocache);

    $total = (int) $pdo->query('SELECT COUNT(*) FROM exhibitions')->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'upserted'    => $upserted,
        'total'       => $total,
        'with_coords' => $withCoords,
        'geocoded'    => $geocodedCount,
        'merged'      => $mergedCount,
        'expired_removed' => $expiredCount,
        'stale_removed'   => $staleCount,
    ]);
    exit;
}

// --- GET: render the map page ---

$pdo = get_db($DB_PATH);
$exhibitions = db_all_exhibitions($pdo);
$updatedAt = meta_get($pdo, 'updated_at');

record_visitor($pdo, (string) $config['visitor_salt'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$visitorCount = count_visitors($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$geoJson = json_encode(array_values(array_filter($exhibitions, fn($e) => $e['lat'] !== null && $e['lon'] !== null)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$unlocatedList = array_values(array_filter($exhibitions, fn($e) => $e['lat'] === null || $e['lon'] === null));
$categoriesJson = json_encode(CATEGORIES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Art &amp; Expos — Rennes &amp; Ille-et-Vilaine</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #FAF7F2; color: #2E2B27; }
  .hero { width: 100%; height: 160px; background: #0B0B0E; overflow: hidden; }
  .hero canvas { display: block; width: 100%; height: 100%; }
  .updated-below { margin: 0; padding: 10px 22px; font-size: 0.8rem; color: #B7B2A8; background: #0B0B0E; }
  .visitor-count { color: #8C8577; }
  .map-frame { background: #0B0B0E; padding: 0 10px; }
  #map { height: 42vh; min-height: 240px; max-height: 380px; width: 100%; background: #EDE9E1; }
  /* "Scandinavian" look applied as a CSS filter on the raw OpenStreetMap
     tiles themselves (muted, desaturated, brighter) rather than by using
     a separately-styled tile service — CartoDB's Positron tiles did that
     and started requiring an API key, breaking the map. A filter on the
     standard, always-free OSM tiles can't be taken away like that; it
     only touches the tile pane, so the colourful category markers and
     popups (separate Leaflet panes) stay fully saturated on top. */
  .leaflet-tile-pane { filter: grayscale(45%) brightness(1.12) contrast(0.9) saturate(0.55) sepia(6%); }
  .legend { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; padding: 12px 16px; background: #0B0B0E; border-bottom: 1px solid #262521; font-size: 0.78rem; }
  .legend .item { display: inline-flex; align-items: center; gap: 6px; color: #F4F1EB; background: #1A1916; border: 1.5px solid #34322C; border-radius: 20px; padding: 3px 10px 3px 3px; font: inherit; cursor: pointer; transition: opacity .15s, border-color .15s, background .15s; }
  .legend .item:hover { border-color: #C1694F; }
  .legend .item[aria-pressed="true"] { border-color: currentColor; background: #262521; font-weight: 600; }
  .legend .item[aria-pressed="false"] { opacity: .4; background: #1A1916; filter: grayscale(60%); }
  .legend .item-action { padding: 3px 12px; color: #0B0B0E; background: #E8C468; border-color: #E8C468; font-weight: 600; }
  .legend .item-action:hover { background: #C1694F; border-color: #C1694F; color: #fff; }
  .legend .sep { width: 1px; align-self: stretch; background: #34322C; margin: 0 4px; }
  .legend .chip { width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex: none; }
  .legend .chip svg { width: 11px; height: 11px; stroke: #fff; }
  .panel-bg { background: #0B0B0E; }
  .site-footer { text-align: center; padding: 16px 22px 28px; color: #8C8577; font-size: 0.78rem; border-top: 1px solid #262521; }
  .site-footer a { color: #B7B2A8; }
  .site-footer a:hover { color: #E8C468; }
  .panel { padding: 28px 22px 48px; max-width: 900px; margin: 0 auto; }
  .panel h2 { font-size: 1rem; margin: 26px 0 12px; font-weight: 600; color: #F4F1EB; }
  .panel .empty { color: #B7B2A8; }
  .card { background: #fff; border: 1px solid #E7E1D8; border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; display: flex; gap: 12px; }
  .card .chip { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex: none; }
  .card .chip svg { width: 13px; height: 13px; stroke: #fff; }
  .card .cat { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em; color: #8C8577; margin-bottom: 3px; }
  .card .title { font-weight: 600; margin-bottom: 2px; }
  .card .meta { font-size: 0.85rem; color: #6B6560; margin-bottom: 6px; }
  .card .desc { font-size: 0.9rem; margin-bottom: 6px; }
  .card a { color: #C1694F; font-size: 0.85rem; }
  .popup-entry + .popup-entry { margin-top: 10px; padding-top: 10px; border-top: 1px solid #E7E1D8; }
  /* Leaflet's maxHeight popup option adds scrolling to this element when
     a grouped marker (several exhibitions at the same venue) overflows
     it — just styling the scrollbar here so it doesn't look default/ugly. */
  .leaflet-popup-content-wrapper .leaflet-popup-content { scrollbar-width: thin; scrollbar-color: #C1694F #F4F1EB; }
  .leaflet-popup-content-wrapper .leaflet-popup-content::-webkit-scrollbar { width: 6px; }
  .leaflet-popup-content-wrapper .leaflet-popup-content::-webkit-scrollbar-track { background: #F4F1EB; }
  .leaflet-popup-content-wrapper .leaflet-popup-content::-webkit-scrollbar-thumb { background: #C1694F; border-radius: 3px; }
  .popup-title { font-weight: 600; margin-bottom: 4px; }
  .popup-title .featured-tag { display: inline-block; margin-left: 6px; font-size: 0.7rem; font-weight: 600; color: #B97A1D; }
  .popup-meta { font-size: 0.82rem; color: #6B6560; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
  .popup-meta .chip-sm { width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex: none; }
  .popup-meta .chip-sm svg { width: 9px; height: 9px; stroke: #fff; }
  /* Base marker size is 1.5x the original 26px chip (=39px); "featured"
     events (per the AI's judgement of cultural significance) get a
     further bump plus a gold ring and star badge so they stand out. */
  .marker-chip { border-radius: 50% 50% 50% 4px; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 4px rgba(0,0,0,.35); transform: rotate(45deg); position: relative; }
  .marker-chip svg { transform: rotate(-45deg); }
  .marker-chip.is-featured { box-shadow: 0 0 0 2px #E8C468, 0 2px 6px rgba(0,0,0,.4); }
  .marker-star { position: absolute; top: -5px; right: -5px; transform: rotate(-45deg); background: #E8C468; color: #2B2E33; border-radius: 50%; width: 12px; height: 12px; font-size: 8px; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
  .marker-count { position: absolute; bottom: -4px; right: -4px; transform: rotate(-45deg); background: #2B2E33; color: #fff; border-radius: 50%; min-width: 13px; height: 13px; padding: 0 2px; font-size: 8px; font-weight: 700; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
  .empty { color: #6B6560; font-size: 0.9rem; }
</style>
</head>
<body>

<!-- Icon sprite: the graphic charter — one shared 24x24 stroke grammar,
     reused everywhere via <use>. Colours live per-category in PHP/JS, not
     baked into the icons, so the same shapes work on any chip colour. -->
<svg style="display:none" aria-hidden="true">
  <defs>
<?php foreach (PIXEL_ICONS as $iconId => $bitmap): ?>
    <?= render_pixel_icon($iconId, $bitmap) ?>

<?php endforeach; ?>
  </defs>
</svg>

<div class="hero">
  <canvas id="art-hero" aria-hidden="true"></canvas>
</div>

<div class="map-frame">
<div id="map"></div>
</div>

<p class="updated-below">
  <?= $updatedAt ? 'Mis à jour le ' . htmlspecialchars(date('d/m/Y à H:i', strtotime($updatedAt))) : 'Pas encore de données' ?>
  <span class="visitor-count" title="Nombre de visiteurs distincts">· <?= number_format($visitorCount, 0, ',', ' ') ?> visiteur<?= $visitorCount > 1 ? 's' : '' ?></span>
</p>

<div class="legend" id="legend">
<?php foreach (CATEGORIES as $key => $cat): ?>
  <button type="button" class="item" data-category="<?= $key ?>" aria-pressed="true">
    <span class="chip" style="background:<?= $cat['color'] ?>"><svg viewBox="0 0 10 10" shape-rendering="crispEdges"><use href="#icon-<?= $cat['icon'] ?>"/></svg></span>
    <?= htmlspecialchars($cat['label']) ?>
  </button>
<?php endforeach; ?>
  <span class="sep"></span>
  <button type="button" class="item item-action" id="filter-show-all">Tout afficher</button>
</div>

<div class="panel-bg">
<div class="panel">
<?php if (empty($exhibitions)): ?>
  <p class="empty">Aucune exposition publiée pour le moment — revenez après le prochain envoi de la newsletter.</p>
<?php endif; ?>

<?php if (!empty($unlocatedList)): ?>
  <h2>Non localisées sur la carte</h2>
  <?php foreach ($unlocatedList as $e): $cat = CATEGORIES[$e['category']] ?? CATEGORIES[DEFAULT_CATEGORY]; ?>
    <div class="card" data-category="<?= htmlspecialchars($e['category']) ?>">
      <span class="chip" style="background:<?= $cat['color'] ?>"><svg viewBox="0 0 10 10" shape-rendering="crispEdges"><use href="#icon-<?= $cat['icon'] ?>"/></svg></span>
      <div>
        <div class="cat"><?= htmlspecialchars($cat['label']) ?></div>
        <div class="title"><?= htmlspecialchars($e['title']) ?><?php if (!empty($e['featured'])): ?> <span class="featured-tag">★ Mis en avant</span><?php endif; ?></div>
        <div class="meta"><?= htmlspecialchars($e['venue']) ?>, <?= htmlspecialchars($e['city']) ?><?= $e['dates_label'] ? ' — ' . htmlspecialchars($e['dates_label']) : '' ?></div>
        <?php if ($e['description']): ?><div class="desc"><?= htmlspecialchars($e['description']) ?></div><?php endif; ?>
        <?php if ($e['link']): ?><a href="<?= htmlspecialchars($e['link']) ?>" target="_blank" rel="noopener">En savoir plus →</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<footer class="site-footer">
  © <?= date('Y') ?> David Legoupil —
  <a href="/art-actu-confidentialite.php">Confidentialité</a> ·
  <a href="/art-actu-conditions.php">Conditions d'utilisation</a>
</footer>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
const EXHIBITIONS = <?= $geoJson ?>;
const CATEGORIES = <?= $categoriesJson ?>;

const map = L.map('map').setView([48.117, -1.6778], 10);

// Standard OpenStreetMap tiles: the reference, no-signup, no-API-key,
// free-forever tile server (fair-use policy, fine for this traffic level).
// CartoDB's Positron tiles (used before) now require an API key, which is
// exactly the "please generate an API key" message this replaces. The
// Scandinavian muted look is applied via the .leaflet-tile-pane CSS
// filter above instead, so it's a permanent look on a permanent source.
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
}).addTo(map);

// Base marker size is 1.5x the original 26px chip; a further bump for
// groups containing at least one "featured" event.
function chipIcon(cat, opts) {
  opts = opts || {};
  const size = opts.featured ? 30 : 24;
  const iconSize = Math.round(size * 0.54);
  const svg = '<svg viewBox="0 0 10 10" shape-rendering="crispEdges" style="width:' + iconSize + 'px;height:' + iconSize + 'px"><use href="#icon-' + cat.icon + '"/></svg>';
  const star = opts.featured ? '<span class="marker-star">★</span>' : '';
  const count = opts.count > 1 ? '<span class="marker-count">' + opts.count + '</span>' : '';
  return L.divIcon({
    html: '<div class="marker-chip' + (opts.featured ? ' is-featured' : '') + '" style="width:' + size + 'px;height:' + size + 'px;background:' + cat.color + '">' + svg + star + count + '</div>',
    className: '',
    iconSize: [size, size],
    iconAnchor: [size / 2, size - 2],
    popupAnchor: [0, -(size - 4)],
  });
}

// Exhibitions at (near-)identical coordinates — same venue — are grouped
// into a single marker with a count badge and a multi-entry popup, instead
// of stacking indistinguishable markers on top of each other.
const groups = {};
EXHIBITIONS.forEach(function (e) {
  const gkey = e.lat.toFixed(4) + ',' + e.lon.toFixed(4);
  if (!groups[gkey]) groups[gkey] = { lat: e.lat, lon: e.lon, items: [] };
  groups[gkey].items.push(e);
});

// Every built marker, with the set of categories it represents, so the
// legend filter can show/hide it directly (a grouped marker stays visible
// as long as at least one of its categories is active).
const allMarkers = [];
const bounds = [];

Object.keys(groups).forEach(function (gkey) {
  const g = groups[gkey];
  const items = g.items;
  const anyFeatured = items.some(function (e) { return e.featured; });
  const primaryCat = CATEGORIES[items[0].category] || CATEGORIES['exposition'];
  const marker = L.marker([g.lat, g.lon], { icon: chipIcon(primaryCat, { featured: anyFeatured, count: items.length }) });

  const popupHtml = items.map(function (e) {
    const cat = CATEGORIES[e.category] || CATEGORIES['exposition'];
    const linkHtml = e.link ? '<a href="' + e.link + '" target="_blank" rel="noopener">Voir la source →</a>' : '';
    const descHtml = e.description ? '<div>' + e.description + '</div>' : '';
    const chipSm = '<span class="chip-sm" style="background:' + cat.color + '"><svg viewBox="0 0 10 10" shape-rendering="crispEdges"><use href="#icon-' + cat.icon + '"/></svg></span>';
    const featuredTag = e.featured ? '<span class="featured-tag">★ Mis en avant</span>' : '';
    return '<div class="popup-entry">' +
      '<div class="popup-title">' + e.title + featuredTag + '</div>' +
      '<div class="popup-meta">' + chipSm + cat.label + ' — ' + e.venue + ', ' + e.city + '</div>' +
      '<div class="popup-meta">' + (e.dates_label || '') + '</div>' +
      descHtml + linkHtml +
      '</div>';
  }).join('');
  marker.bindPopup(popupHtml, { maxWidth: 300, maxHeight: 260 });

  marker.addTo(map);
  allMarkers.push({ marker: marker, categories: Array.from(new Set(items.map(function (e) { return e.category; }))) });
  bounds.push([g.lat, g.lon]);
});
if (bounds.length > 0) {
  map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
}

// Category filter: legend buttons toggle marker visibility (a grouped
// marker stays shown while any of its categories is active) and the
// matching "non localisées" cards below.
const legend = document.getElementById('legend');
const catButtons = legend.querySelectorAll('.item[data-category]');

function activeCategories() {
  const active = new Set();
  catButtons.forEach(function (btn) {
    if (btn.getAttribute('aria-pressed') === 'true') active.add(btn.dataset.category);
  });
  return active;
}

function applyFilter() {
  const active = activeCategories();
  allMarkers.forEach(function (m) {
    const visible = m.categories.some(function (c) { return active.has(c); });
    if (visible) {
      if (!map.hasLayer(m.marker)) m.marker.addTo(map);
    } else if (map.hasLayer(m.marker)) {
      map.removeLayer(m.marker);
    }
  });
  document.querySelectorAll('.card[data-category]').forEach(function (card) {
    card.style.display = active.has(card.dataset.category) ? 'flex' : 'none';
  });
}

// Clicking a category isolates it (hides every other category) — click
// the same, now-solo category again to bring all the others back.
catButtons.forEach(function (btn) {
  btn.addEventListener('click', function () {
    const isSoloAlready = Array.from(catButtons).every(function (b) {
      return (b === btn) === (b.getAttribute('aria-pressed') === 'true');
    });
    catButtons.forEach(function (b) {
      b.setAttribute('aria-pressed', isSoloAlready || b === btn ? 'true' : 'false');
    });
    applyFilter();
  });
});
document.getElementById('filter-show-all').addEventListener('click', function () {
  catButtons.forEach(function (btn) { btn.setAttribute('aria-pressed', 'true'); });
  applyFilter();
});

// --- Pixel-art "ART" hero banner ---
// Black canvas, pixels fly in from random positions to spell ART in a
// high-res bold pixel font, hold, then exit (explode / fade wipe /
// confetti fall). 80 styles = 16 font treatments x 5 colour palettes; a
// random style plays each cycle (never repeating the previous one).
(function () {
  const canvasEls = ['art-hero']
    .map(function (id) { return document.getElementById(id); })
    .filter(Boolean);
  if (!canvasEls.length) return;
  const surfaces = canvasEls.map(function (c) { return { canvas: c, ctx: c.getContext('2d') }; });
  const primary = surfaces[0].canvas;

  const WORD = 'ART';
  const GAP = 1;

  // High-resolution bold base font (9 cols x 11 rows per letter, thick
  // 2-cell strokes) — every other font variant below is derived from it.
  const BOLD_COLS = 9, BOLD_ROWS = 11;
  const BOLD_FONT = {
    A: ['...XXX...', '..XXXXX..', '.XX...XX.', 'XX.....XX', 'XX.....XX',
        'XXXXXXXXX', 'XXXXXXXXX', 'XX.....XX', 'XX.....XX', 'XX.....XX', 'XX.....XX'],
    R: ['XXXXXXX..', 'XXXXXXXX.', 'XX....XX.', 'XX....XX.', 'XX....XX.',
        'XXXXXXX..', 'XXXXXX...', 'XX..XX...', 'XX...XX..', 'XX....XX.', 'XX.....XX'],
    T: ['XXXXXXXXX', 'XXXXXXXXX', 'XXXXXXXXX', '...XXX...', '...XXX...',
        '...XXX...', '...XXX...', '...XXX...', '...XXX...', '...XXX...', '...XXX...'],
  };
  // Original thin font, kept as the base for the "classic" thin variant.
  const THIN_COLS = 5, THIN_ROWS = 7;
  const THIN_FONT = {
    A: ['.XXX.', 'X...X', 'X...X', 'XXXXX', 'X...X', 'X...X', 'X...X'],
    R: ['XXXX.', 'X...X', 'X...X', 'XXXX.', 'X.X..', 'X..X.', 'X...X'],
    T: ['XXXXX', '..X..', '..X..', '..X..', '..X..', '..X..', '..X..'],
  };

  function buildCellsFromFont(font, letterCols, letterRows) {
    const cells = [];
    let colOffset = 0;
    for (const letter of WORD) {
      const rows = font[letter];
      for (let r = 0; r < letterRows; r++) {
        for (let c = 0; c < letterCols; c++) {
          if (rows[r][c] === 'X') cells.push({ col: colOffset + c, row: r });
        }
      }
      colOffset += letterCols + GAP;
    }
    const totalCols = WORD.length * letterCols + (WORD.length - 1) * GAP;
    return { cells: cells, totalCols: totalCols, totalRows: letterRows };
  }

  function upscale(base, factor) {
    const out = [];
    base.cells.forEach(function (c) {
      for (let dr = 0; dr < factor; dr++) {
        for (let dc = 0; dc < factor; dc++) out.push({ col: c.col * factor + dc, row: c.row * factor + dr });
      }
    });
    return { cells: out, totalCols: base.totalCols * factor, totalRows: base.totalRows * factor };
  }

  function outline(base) {
    const set = new Set(base.cells.map(function (c) { return c.col + ',' + c.row; }));
    const filled = function (c, r) { return set.has(c + ',' + r); };
    const out = base.cells.filter(function (c) {
      return !filled(c.col + 1, c.row) || !filled(c.col - 1, c.row) || !filled(c.col, c.row + 1) || !filled(c.col, c.row - 1);
    });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function dotted(base) {
    const out = base.cells.filter(function (c) { return (c.col + c.row) % 2 === 0; });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function sparse(base) {
    const out = base.cells.filter(function (c, i) { return (i * 7 + c.col * 3 + c.row * 5) % 9 < 5; });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function outlineThick(base) {
    const once = outline(base);
    const set = new Set(once.cells.map(function (c) { return c.col + ',' + c.row; }));
    const baseSet = new Set(base.cells.map(function (c) { return c.col + ',' + c.row; }));
    const inRing = function (c, r) { return set.has(c + ',' + r); };
    const out = base.cells.filter(function (c) {
      if (inRing(c.col, c.row)) return true;
      return inRing(c.col + 1, c.row) || inRing(c.col - 1, c.row) || inRing(c.col, c.row + 1) || inRing(c.col, c.row - 1);
    }).filter(function (c) { return baseSet.has(c.col + ',' + c.row); });
    return { cells: out, totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function checkerInverse(base) {
    return { cells: base.cells.filter(function (c) { return (c.col + c.row) % 2 === 1; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function stripesVert(base) {
    return { cells: base.cells.filter(function (c) { return c.col % 3 !== 2; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function stripesHoriz(base) {
    return { cells: base.cells.filter(function (c) { return c.row % 3 !== 2; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function diagonalHatch(base) {
    return { cells: base.cells.filter(function (c) { return (c.col + c.row) % 4 < 2; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function noiseLight(base) {
    return { cells: base.cells.filter(function (c, i) { return (c.col * 17 + c.row * 23 + i * 5) % 11 < 8; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function noiseHeavy(base) {
    return { cells: base.cells.filter(function (c, i) { return (c.col * 13 + c.row * 19 + i * 11) % 9 < 3; }), totalCols: base.totalCols, totalRows: base.totalRows };
  }

  function ringDotted(base) {
    return dotted(outline(base));
  }

  function mosaic(base) {
    return {
      cells: base.cells.filter(function (c) { return (Math.floor(c.col / 2) + Math.floor(c.row / 2)) % 2 === 0; }),
      totalCols: base.totalCols, totalRows: base.totalRows,
    };
  }

  // Roughly a 5x pixel-count bump over the original hand-drawn grids, so
  // the different font treatments below (outline, dotted, sparse...) are
  // fine-grained enough to actually read as different from one another.
  const RESOLUTION_FACTOR = 3;
  const THIN_RESOLUTION_FACTOR = 5;
  function getBoldBase() { return upscale(buildCellsFromFont(BOLD_FONT, BOLD_COLS, BOLD_ROWS), RESOLUTION_FACTOR); }
  function getThinBase() { return upscale(buildCellsFromFont(THIN_FONT, THIN_COLS, THIN_ROWS), THIN_RESOLUTION_FACTOR); }

  const FONT_VARIANTS = [
    { name: 'bold', build: function () { return getBoldBase(); } },
    { name: 'outline', build: function () { return outline(getBoldBase()); } },
    { name: 'thin', build: function () { return getThinBase(); } },
    { name: 'dotted', build: function () { return dotted(getBoldBase()); } },
    { name: 'slanted', build: function () { const b = getBoldBase(); b.shear = 1; return b; } },
    { name: 'sparse', build: function () { return sparse(getBoldBase()); } },
    { name: 'outline-thick', build: function () { return outlineThick(getBoldBase()); } },
    { name: 'checker', build: function () { return checkerInverse(getBoldBase()); } },
    { name: 'stripes-v', build: function () { return stripesVert(getBoldBase()); } },
    { name: 'stripes-h', build: function () { return stripesHoriz(getBoldBase()); } },
    { name: 'diagonal', build: function () { return diagonalHatch(getBoldBase()); } },
    { name: 'anti-slant', build: function () { const b = getBoldBase(); b.shear = -1; return b; } },
    { name: 'noise-light', build: function () { return noiseLight(getBoldBase()); } },
    { name: 'noise-heavy', build: function () { return noiseHeavy(getBoldBase()); } },
    { name: 'ring-dotted', build: function () { return ringDotted(getBoldBase()); } },
    { name: 'mosaic', build: function () { return mosaic(getBoldBase()); } },
  ];

  const COLOR_PALETTES = [
    { name: 'neon', colors: ['#48d1ff', '#ff5ca8', '#b586ff'], exit: 'explode', bg: '#0B0B0E' },
    { name: 'sunset', colors: ['#ff8b3d', '#ff5c5c', '#ffd23f'], exit: 'confetti', bg: '#120A08' },
    { name: 'scandinave', colors: ['#C1694F', '#5D80A3', '#5F8B7A', '#D3A048', '#B97A94'], exit: 'fade', bg: '#0E0D0B' },
    { name: 'rainbow', rainbow: true, exit: 'explode', bg: '#050505' },
    { name: 'or', colors: ['#E8C468', '#FFFFFF', '#B97A1D'], exit: 'confetti', bg: '#0A0A0A' },
  ];

  // 80 styles = 16 font treatments x 5 colour palettes.
  const STYLES = [];
  FONT_VARIANTS.forEach(function (font) {
    COLOR_PALETTES.forEach(function (palette) {
      STYLES.push({
        name: font.name + '-' + palette.name, font: font,
        colors: palette.colors, rainbow: palette.rainbow, exit: palette.exit, bg: palette.bg,
      });
    });
  });

  // Picks a random style, never repeating the one that just played.
  function nextStyleIndex() {
    if (STYLES.length <= 1) return 0;
    let idx;
    do { idx = Math.floor(Math.random() * STYLES.length); } while (idx === styleIndex);
    return idx;
  }

  let styleIndex = Math.floor(Math.random() * STYLES.length);
  let phase = 'forming'; // forming | hold | exiting
  let phaseStart = performance.now();
  const DURATIONS = { forming: 1600, hold: 1300, exiting: 1300 };
  let pixels = [];
  let currentTotalCols = 1, currentTotalRows = 1, currentShear = 0;

  function pickColor(style, i) {
    if (style.rainbow) return 'hsl(' + Math.round((i * 41) % 360) + ',85%,60%)';
    return style.colors[i % style.colors.length];
  }

  // Keeps a black margin around the word so it never touches the canvas
  // edges: the grid is laid out inside an inner area shrunk by MARGIN_FRAC
  // on every side rather than filling the full banner.
  const MARGIN_FRAC = 0.1;
  function computeLayout() {
    const innerW = primary.width * (1 - MARGIN_FRAC * 2);
    const innerH = primary.height * (1 - MARGIN_FRAC * 2);
    const cellW = innerW / currentTotalCols;
    const cellH = innerH / currentTotalRows;
    const totalW = currentTotalCols * cellW;
    const totalH = currentTotalRows * cellH;
    return {
      cellW: cellW, cellH: cellH,
      offsetX: (primary.width - totalW) / 2,
      offsetY: (primary.height - totalH) / 2,
      size: Math.min(cellW, cellH) * 0.88,
    };
  }

  function targetFor(layout, cell) {
    let tx = layout.offsetX + cell.col * layout.cellW + layout.cellW / 2;
    if (currentShear) tx += currentShear * (cell.row - currentTotalRows / 2) * layout.cellW * 0.45;
    const ty = layout.offsetY + cell.row * layout.cellH + layout.cellH / 2;
    return { tx: tx, ty: ty };
  }

  function resizeAll() {
    surfaces.forEach(function (s) {
      const rect = s.canvas.parentElement.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      s.canvas.width = Math.max(1, Math.round(rect.width * dpr));
      s.canvas.height = Math.max(1, Math.round(rect.height * dpr));
    });
    if (pixels.length) {
      const layout = computeLayout();
      pixels.forEach(function (p) {
        const t = targetFor(layout, { col: p.col, row: p.row });
        p.tx = t.tx; p.ty = t.ty; p.size = layout.size;
      });
    }
  }
  window.addEventListener('resize', resizeAll);

  function setupForming() {
    const style = STYLES[styleIndex];
    const built = style.font.build();
    currentTotalCols = built.totalCols;
    currentTotalRows = built.totalRows;
    currentShear = built.shear || 0;
    const layout = computeLayout();
    pixels = built.cells.map(function (cell, i) {
      const t = targetFor(layout, cell);
      const startX = Math.random() * primary.width;
      const startY = Math.random() * primary.height;
      return {
        tx: t.tx, ty: t.ty, x: startX, y: startY, startX: startX, startY: startY,
        size: layout.size, color: pickColor(style, i),
        delay: Math.random() * 0.4, alpha: 1, scale: 1, col: cell.col, row: cell.row,
      };
    });
  }

  function setupExit() {
    const style = STYLES[styleIndex];
    const cx = primary.width / 2, cy = primary.height / 2;
    pixels.forEach(function (p) {
      p.startX = p.tx; p.startY = p.ty;
      if (style.exit === 'explode') {
        const angle = Math.atan2(p.ty - cy, p.tx - cx) + (Math.random() - 0.5) * 0.6;
        const dist = 220 + Math.random() * 220;
        p.exitDX = Math.cos(angle) * dist;
        p.exitDY = Math.sin(angle) * dist;
        p.exitRot = (Math.random() - 0.5) * 8;
      } else if (style.exit === 'confetti') {
        p.exitDX = (Math.random() - 0.5) * 260;
        p.exitDY = primary.height * 1.1 + Math.random() * 180;
        p.exitRot = (Math.random() - 0.5) * 12;
      } else {
        p.exitDX = 0; p.exitDY = 0; p.exitRot = 0;
      }
    });
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
  function easeInCubic(t) { return t * t * t; }

  function drawPixelOn(ctx, p, rotation) {
    ctx.save();
    ctx.globalAlpha = Math.max(0, Math.min(1, p.alpha));
    ctx.translate(p.x, p.y);
    if (rotation) ctx.rotate(rotation);
    const s = p.size * (p.scale != null ? p.scale : 1);
    ctx.fillStyle = p.color;
    ctx.fillRect(-s / 2, -s / 2, s, s);
    ctx.restore();
  }

  function drawPixel(p, rotation) {
    surfaces.forEach(function (s) { drawPixelOn(s.ctx, p, rotation); });
  }

  function paintBackground(bg) {
    surfaces.forEach(function (s) {
      s.ctx.fillStyle = bg;
      s.ctx.fillRect(0, 0, s.canvas.width, s.canvas.height);
    });
  }

  function draw(now) {
    const style = STYLES[styleIndex];
    const elapsed = now - phaseStart;
    const dur = DURATIONS[phase];
    const t = Math.min(1, elapsed / dur);

    paintBackground(style.bg);

    if (phase === 'forming') {
      pixels.forEach(function (p) {
        const pt = Math.max(0, Math.min(1, (t - p.delay) / (1 - p.delay)));
        const e = easeOutCubic(pt);
        p.x = p.startX + (p.tx - p.startX) * e;
        p.y = p.startY + (p.ty - p.startY) * e;
        p.alpha = 0.25 + 0.75 * e;
        drawPixel(p, 0);
      });
      if (t >= 1) { phase = 'hold'; phaseStart = now; }
    } else if (phase === 'hold') {
      pixels.forEach(function (p) { p.x = p.tx; p.y = p.ty; p.alpha = 1; p.scale = 1; drawPixel(p, 0); });
      if (t >= 1) { phase = 'exiting'; phaseStart = now; setupExit(); }
    } else {
      if (style.exit === 'fade') {
        pixels.forEach(function (p) {
          const colFrac = p.col / currentTotalCols;
          const local = Math.max(0, Math.min(1, (t - colFrac * 0.5) / 0.5));
          const e = easeInCubic(local);
          p.alpha = 1 - e;
          p.scale = 1 - e * 0.6;
          p.x = p.tx; p.y = p.ty;
          drawPixel(p, 0);
        });
      } else {
        const e = easeInCubic(t);
        pixels.forEach(function (p) {
          p.x = p.tx + p.exitDX * e;
          p.y = p.ty + (style.exit === 'confetti' ? p.exitDY * e * e : p.exitDY * e);
          p.alpha = 1 - e;
          p.scale = 1;
          drawPixel(p, p.exitRot * e);
        });
      }
      if (t >= 1) {
        styleIndex = nextStyleIndex();
        phase = 'forming'; phaseStart = now; setupForming();
      }
    }

    requestAnimationFrame(draw);
  }

  resizeAll();
  setupForming();
  requestAnimationFrame(draw);
})();
</script>

</body>
</html>
