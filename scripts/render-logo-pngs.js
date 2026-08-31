// Rasterises the category logo SVGs (../logos/*.svg, one per category,
// exported from Apple's SF Symbols / SVG export with a plain black fill)
// into the PNGs served at /art-actu-logos/ (../logos-png/*.png): the icon
// recoloured white, centred on a solid circle in that category's own
// accent colour (must match the CATEGORIES colours in art-actu.php).
//
// Needed because the logo files aren't reliably named/matched by hand
// (typos happen — "Photogrpahie.svg", "Spectaple Vivant.svg" — hence the
// fuzzy match below) and because the newsletter email needs a plain
// <img src> (inline SVG isn't reliably supported by mail clients), so a
// PNG render is required there regardless.
//
// Usage: npm install (once, installs sharp) && node render-logo-pngs.js
// Re-run any time a file under ../logos/ changes.

const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

const LOGOS_DIR = path.join(__dirname, '..', 'logos');
const OUT_DIR = path.join(__dirname, '..', 'logos-png');

// category key -> accent colour (must match CATEGORIES in art-actu.php)
const CATEGORIES = {
  peinture: { color: '#C1694F' },
  sculpture: { color: '#8C8577' },
  photographie: { color: '#5D80A3' },
  arts_numeriques: { color: '#5F8B7A' },
  street_art: { color: '#D3A048' },
  design: { color: '#B97A94' },
  spectacle_vivant: { color: '#7C4A5B' },
  exposition: { color: '#A38F76' },
};

function normalizeKey(s) {
  return s
    .toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]/g, '');
}

function levenshtein(a, b) {
  const m = a.length, n = b.length;
  const d = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
  for (let i = 0; i <= m; i++) d[i][0] = i;
  for (let j = 0; j <= n; j++) d[0][j] = j;
  for (let i = 1; i <= m; i++) {
    for (let j = 1; j <= n; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
    }
  }
  return d[m][n];
}

const LABELS = {
  peinture: 'peinture',
  sculpture: 'sculpture',
  photographie: 'photographie',
  arts_numeriques: 'artsnumeriques',
  street_art: 'streetart',
  design: 'design',
  spectacle_vivant: 'spectaclevivant',
  exposition: 'exposition',
};

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

const files = fs.readdirSync(LOGOS_DIR).filter(f => f.toLowerCase().endsWith('.svg'));
const SIZE = 128; // final PNG size
const ICON_FRAC = 0.56; // icon occupies this fraction of the circle diameter

async function run() {
  for (const file of files) {
    const base = normalizeKey(path.basename(file, '.svg'));
    let bestKey = null, bestDist = Infinity;
    for (const [key, label] of Object.entries(LABELS)) {
      const dist = levenshtein(base, label);
      if (dist < bestDist) { bestDist = dist; bestKey = key; }
    }
    if (bestKey === null || bestDist > 4) {
      console.log('SKIP (no match):', file);
      continue;
    }
    const color = CATEGORIES[bestKey].color;
    const svgContent = fs.readFileSync(path.join(LOGOS_DIR, file), 'utf8');
    // Recolor black -> white, but leave each path's own fill-opacity
    // untouched — some icons use varying opacity across paths for a
    // deliberate layered/see-through look; stripping it flattens that
    // into an illegible solid blob.
    const recoloredSvg = svgContent.replace(/fill="black"/g, 'fill="#ffffff"');

    const iconSize = Math.round(SIZE * ICON_FRAC);
    const iconBuffer = await sharp(Buffer.from(recoloredSvg))
      .resize(iconSize, iconSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png()
      .toBuffer();

    const circleSvg = `<svg width="${SIZE}" height="${SIZE}"><circle cx="${SIZE / 2}" cy="${SIZE / 2}" r="${SIZE / 2}" fill="${color}"/></svg>`;
    const offset = Math.round((SIZE - iconSize) / 2);

    await sharp(Buffer.from(circleSvg))
      .composite([{ input: iconBuffer, left: offset, top: offset }])
      .png()
      .toFile(path.join(OUT_DIR, bestKey + '.png'));

    console.log('OK:', file, '->', bestKey + '.png', '(dist=' + bestDist + ')');
  }
}

run().catch(e => { console.error(e); process.exit(1); });
