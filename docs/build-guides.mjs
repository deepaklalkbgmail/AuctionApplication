/**
 * Build the five role guides as PDFs.
 *
 *     node docs/build-guides.mjs
 *
 * Output: docs/guides/CricAuction-<Role>-Guide.pdf
 *
 * Screenshots come from docs/screens/ (see docs/capture-screens.mjs) and
 * are embedded as data URIs, so each PDF is one self-contained file.
 *
 * The logo is docs/brand/deam-logo.png if it exists, otherwise
 * docs/brand/deam-logo.svg. Drop the real artwork in as a PNG and re-run.
 */

import { chromium } from 'playwright';
import { readFileSync, existsSync, mkdirSync, statSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { GUIDES, COMPANY, PRODUCT } from './guide-content.mjs';

const HERE    = dirname(fileURLToPath(import.meta.url));
const SCREENS = join(HERE, 'screens');
const OUT     = join(HERE, 'guides');
const CHROME  = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

mkdirSync(OUT, { recursive: true });

const VERSION = process.env.GUIDE_VERSION || '1.0';
const ISSUED  = new Date().toLocaleDateString('en-GB', {
    day: 'numeric', month: 'long', year: 'numeric',
});

// ------------------------------------------------------------------ assets

/** The brand mark, as a data URI. Prefers a real PNG over the rebuild. */
function logoDataUri() {
    const png = join(HERE, 'brand', 'deam-logo.png');

    if (existsSync(png)) {
        return `data:image/png;base64,${readFileSync(png).toString('base64')}`;
    }

    const svg = readFileSync(join(HERE, 'brand', 'deam-logo.svg'));

    return `data:image/svg+xml;base64,${svg.toString('base64')}`;
}

const LOGO = logoDataUri();

/** Replace SCREEN:name placeholders with embedded images. */
function embedScreens(html) {
    const missing = new Set();

    const out = html.replace(/SCREEN:([a-z0-9-]+)/g, (_, name) => {
        const file = join(SCREENS, `${name}.png`);

        if (!existsSync(file)) {
            missing.add(name);
            return '';
        }

        return `data:image/png;base64,${readFileSync(file).toString('base64')}`;
    });

    if (missing.size > 0) {
        throw new Error(
            `Missing screenshots: ${[...missing].join(', ')}\n` +
            'Run:  php -S 127.0.0.1:8088 -t public &  node docs/capture-screens.mjs'
        );
    }

    return out;
}

// --------------------------------------------------------------------- CSS
//
//  Print CSS. Liberation Sans is metric-compatible with Arial and is
//  present on effectively every Linux box, which matters because the PDF
//  is generated on a server, not on a designer's laptop.

const css = (accent) => `
@page { size: A4; margin: 20mm 17mm 20mm 17mm; }
@page :first { margin: 0; }

* { box-sizing: border-box; }

html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

body {
    margin: 0;
    font-family: "Liberation Sans", Arial, Helvetica, sans-serif;
    font-size: 10.3pt;
    line-height: 1.58;
    color: #1b2330;
}

p { margin: 0 0 0.72em; }
strong { color: #0d1520; }
em { color: #2b3547; }

a { color: ${accent}; text-decoration: none; }

/* ------------------------------------------------------------- cover */
.cover {
    page-break-after: always;
    height: 297mm;
    padding: 26mm 22mm 20mm;
    display: flex;
    flex-direction: column;
    position: relative;
    background:
        radial-gradient(70mm 70mm at 88% 6%, ${accent}14, transparent 70%),
        #ffffff;
}
.cover::before {
    content: "";
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 7mm;
    background: linear-gradient(180deg, ${accent}, ${accent}66);
}
.cover-logo { width: 34mm; height: 34mm; }
.cover-company {
    margin-top: 7mm;
    font-size: 13pt; font-weight: 700; letter-spacing: .01em; color: #0d1520;
}
.cover-company span { display: block; font-size: 8.6pt; font-weight: 600; letter-spacing: .16em;
    text-transform: uppercase; color: #7b8798; margin-top: 1.5mm; }

.cover-mid { margin-top: auto; }
.cover-product {
    font-size: 10pt; font-weight: 700; letter-spacing: .22em;
    text-transform: uppercase; color: ${accent};
}
.cover-title {
    margin: 3mm 0 0;
    font-size: 33pt; line-height: 1.06; font-weight: 700; letter-spacing: -.02em;
    color: #0d1520;
}
.cover-tagline {
    margin: 5mm 0 0; font-size: 12.5pt; line-height: 1.5; color: #46536a; max-width: 120mm;
}
.cover-rule { margin: 9mm 0 6mm; width: 40mm; height: 2.6pt; background: ${accent}; border-radius: 2pt; }
.cover-for { font-size: 10pt; color: #46536a; max-width: 120mm; }
.cover-for b { color: #0d1520; }

.cover-foot {
    margin-top: auto; padding-top: 10mm;
    display: flex; justify-content: space-between; align-items: flex-end;
    border-top: .6pt solid #dde3ec;
    font-size: 8.8pt; color: #7b8798;
}
.cover-foot b { color: #46536a; }

/* ---------------------------------------------------------- contents */
.toc { page-break-after: always; }
.toc h2 { margin-top: 0; }
.toc ol { margin: 0; padding-left: 0; counter-reset: toc; list-style: none; }
.toc li {
    counter-increment: toc;
    padding: 2.4mm 0 2.4mm 11mm;
    border-bottom: .5pt solid #e8ecf3;
    position: relative;
    font-size: 10.6pt;
}
.toc li::before {
    content: counter(toc);
    position: absolute; left: 0; top: 2.2mm;
    width: 7mm; height: 7mm; border-radius: 50%;
    background: ${accent}18; color: ${accent};
    font-size: 8.6pt; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* ---------------------------------------------------------- headings */
h1, h2, h3 { color: #0d1520; font-weight: 700; letter-spacing: -.01em; }

h2 {
    font-size: 17pt;
    margin: 0 0 4mm;
    padding-bottom: 2.4mm;
    border-bottom: 2pt solid ${accent}33;
    page-break-after: avoid;
}
h2 .num {
    display: inline-block; min-width: 8.5mm;
    color: ${accent};
}
h3 {
    font-size: 11.6pt;
    margin: 6mm 0 2mm;
    page-break-after: avoid;
}

section.chapter { page-break-before: always; }
section.chapter:first-of-type { page-break-before: avoid; }

/* ------------------------------------------------------------- lists */
ul, ol { margin: 0 0 .8em; padding-left: 5.5mm; }
li { margin-bottom: .34em; }

ol.steps { counter-reset: step; list-style: none; padding-left: 0; }
ol.steps li {
    counter-increment: step;
    position: relative; padding-left: 9mm; margin-bottom: 2.2mm;
}
ol.steps li::before {
    content: counter(step);
    position: absolute; left: 0; top: .2mm;
    width: 6.2mm; height: 6.2mm; border-radius: 50%;
    background: ${accent}; color: #fff;
    font-size: 8.2pt; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

ul.checks, ul.advice, ol.gaps { padding-left: 0; list-style: none; }
ul.checks li, ul.advice li {
    position: relative; padding-left: 7mm; margin-bottom: 1.8mm;
}
ul.checks li::before {
    content: "";
    position: absolute; left: 0; top: 1.5mm;
    width: 3.6mm; height: 3.6mm; border: 1.1pt solid ${accent}; border-radius: 1pt;
}
ul.advice li::before {
    content: "";
    position: absolute; left: .8mm; top: 2.1mm;
    width: 2mm; height: 2mm; border-radius: 50%; background: ${accent};
}
ol.gaps { counter-reset: gap; }
ol.gaps li {
    counter-increment: gap;
    position: relative; padding-left: 9mm; margin-bottom: 2.2mm;
}
ol.gaps li::before {
    content: counter(gap);
    position: absolute; left: 0; top: .2mm;
    width: 6.2mm; height: 6.2mm; border-radius: 1.4mm;
    background: #eef1f6; color: #46536a;
    font-size: 8.2pt; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* ------------------------------------------------------------ tables */
table {
    width: 100%; border-collapse: collapse;
    margin: 0 0 4mm; font-size: 9.5pt;
    page-break-inside: avoid;
}
thead { display: table-header-group; }
th {
    text-align: left; font-size: 8.1pt; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em; color: #6a7789;
    padding: 2mm 2.6mm; border-bottom: 1pt solid #cfd7e2; vertical-align: bottom;
}
td {
    padding: 2.2mm 2.6mm; border-bottom: .5pt solid #e8ecf3; vertical-align: top;
}
tbody tr:nth-child(even) { background: #f7f9fc; }
table.tight { font-size: 9pt; }
table.glossary td:first-child { font-weight: 700; color: #0d1520; white-space: nowrap; }

/* -------------------------------------------------------------- dl */
dl.facts {
    margin: 0 0 4mm; padding: 3mm 4mm;
    background: #f7f9fc; border-left: 2.4pt solid ${accent}55; border-radius: 0 2mm 2mm 0;
    display: grid; grid-template-columns: 52mm 1fr; gap: 1.4mm 5mm;
    font-size: 9.6pt; page-break-inside: avoid;
}
dl.facts dt { font-weight: 700; color: #0d1520; }
dl.facts dd { margin: 0; color: #384358; }

/* --------------------------------------------------------- callouts */
.callout {
    margin: 0 0 4.5mm; padding: 3.2mm 4mm 3.4mm;
    border-radius: 2mm; border-left: 3pt solid;
    page-break-inside: avoid; font-size: 9.7pt;
}
.callout-title {
    margin: 0 0 1.2mm; font-weight: 700; font-size: 9.4pt;
    letter-spacing: .01em;
}
.callout-body p:last-child { margin-bottom: 0; }
.callout.note { background: #f2f6fc; border-color: #4a7fd0; }
.callout.note .callout-title { color: #26518f; }
.callout.tip  { background: #f1faf3; border-color: #35a05f; }
.callout.tip  .callout-title { color: #1f7042; }
.callout.warn { background: #fdf7ec; border-color: #d8931f; }
.callout.warn .callout-title { color: #97620a; }
.callout.stop { background: #fdf2f2; border-color: #cf4747; }
.callout.stop .callout-title { color: #a02626; }

blockquote {
    margin: 0 0 4mm; padding: 2.6mm 4mm;
    border-left: 2.4pt solid #cfd7e2; background: #f7f9fc;
    font-style: italic; color: #46536a; font-size: 9.8pt;
}

/* ---------------------------------------------------------- figures */
figure.shot {
    margin: 0 0 5mm; page-break-inside: avoid; text-align: center;
}
figure.shot img {
    /* A cap on height, not just width. Scaled to the width of the page, a
       tall screenshot can be taller than the page it sits on — which
       pushes it to the next page and leaves the previous one half empty.
       Bounding the height makes a tall image narrow instead. */
    width: auto; height: auto;
    max-width: var(--w, 100%);
    max-height: 152mm;
    border: .6pt solid #ccd4e0; border-radius: 1.6mm;
}
figure.shot figcaption {
    margin-top: 1.6mm; font-size: 8.5pt; color: #7b8798; line-height: 1.45;
    text-align: left;
}

/* ------------------------------------------------------------- misc */
code {
    font-family: "DejaVu Sans Mono", "Liberation Mono", monospace;
    font-size: .88em; background: #eef1f6; padding: .3mm 1.2mm;
    border-radius: .8mm; color: #23405f;
}
pre {
    margin: 0 0 4mm; padding: 3mm 3.6mm;
    background: #f5f7fa; border: .5pt solid #e0e6ee; border-radius: 1.6mm;
    font-size: 8.3pt; line-height: 1.5; overflow-wrap: break-word;
    page-break-inside: avoid;
}
pre code { background: none; padding: 0; color: #2b3547; }

.kbd {
    display: inline-block; min-width: 5mm; text-align: center;
    padding: .2mm 1.4mm; border: .6pt solid #b9c3d2; border-bottom-width: 1.4pt;
    border-radius: 1mm; background: #fff; font-weight: 700; font-size: .9em;
}

.pill {
    display: inline-block; padding: .3mm 2mm; border-radius: 3mm;
    font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
}
.pill-green { background: #dff5e6; color: #1f7042; }
.pill-amber { background: #fdf0d8; color: #97620a; }
.pill-grey  { background: #eceff4; color: #5a6577; }

.yes { color: #1f7042; font-weight: 700; }
.no  { color: #a02626; font-weight: 700; }
.muted { color: #7b8798; font-size: 9.4pt; }

.formula {
    margin: 0 0 4mm; padding: 3.4mm 4mm; text-align: center;
    background: #f7f9fc; border: .5pt dashed #b9c3d2; border-radius: 2mm;
    font-size: 10pt; color: #23405f;
}

/* The season flow, admin guide */
.flow { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; margin: 5mm 0 5mm; page-break-inside: avoid; }
.flow-head {
    margin: 0 0 2mm; font-size: 8.2pt; font-weight: 700; letter-spacing: .12em;
    text-transform: uppercase; color: #7b8798;
}
.flow-box {
    padding: 2.4mm 3mm; margin-bottom: 2mm; border-radius: 1.6mm;
    background: ${accent}12; border-left: 2.4pt solid ${accent};
    font-size: 9.2pt; font-weight: 700; color: #0d1520;
}
.flow-box.strong { background: ${accent}26; }
.flow-box.light { background: #f2f4f8; border-left-color: #b9c3d2; font-weight: 600; color: #38435a; }
.flow-box span { display: block; font-weight: 400; font-size: 8.4pt; color: #5a6577; margin-top: .6mm; }
`;

// -------------------------------------------------------------- rendering

function renderGuide(guide) {
    const sections = guide.sections
        .map((s, i) => `
<section class="chapter">
  <h2><span class="num">${i + 1}</span>${s.title}</h2>
  ${s.body}
</section>`)
        .join('');

    const toc = guide.sections.map((s) => `<li>${s.title}</li>`).join('');

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${PRODUCT} — ${guide.title}</title>
<style>${css(guide.accent)}</style>
</head>
<body>

<div class="cover">
  <img class="cover-logo" src="${LOGO}" alt="">
  <p class="cover-company">${COMPANY}<span>Software Development</span></p>

  <div class="cover-mid">
    <p class="cover-product">${PRODUCT} — Cricket Auction &amp; Live Scoring</p>
    <h1 class="cover-title">${guide.title}</h1>
    <p class="cover-tagline">${guide.tagline}</p>
    <div class="cover-rule"></div>
    <p class="cover-for"><b>Who this is for.</b> ${guide.audience}</p>
  </div>

  <div class="cover-foot">
    <span>Version ${VERSION} &nbsp;·&nbsp; ${ISSUED}</span>
    <span>Prepared by <b>${COMPANY}</b></span>
  </div>
</div>

<div class="toc">
  <h2><span class="num">&nbsp;</span>What is in this guide</h2>
  <ol>${toc}</ol>
</div>

${sections}

</body>
</html>`;
}

const footer = (guide) => `
<div style="width:100%;font-family:'Liberation Sans',Arial,sans-serif;font-size:7.4pt;
            color:#8a94a4;padding:0 17mm;display:flex;justify-content:space-between;
            border-top:.5pt solid #e2e7ef;padding-top:3mm;">
  <span>${COMPANY} &nbsp;·&nbsp; ${PRODUCT} ${guide.role} Guide</span>
  <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
</div>`;

// --------------------------------------------------------------------- run

const browser = await chromium.launch({ executablePath: CHROME });
const results = [];

for (const guide of GUIDES) {
    const page = await browser.newPage();
    const html = embedScreens(renderGuide(guide));

    await page.setContent(html, { waitUntil: 'networkidle' });
    await page.emulateMedia({ media: 'print' });

    const file = join(OUT, `${PRODUCT}-${guide.title.replace(/\s+/g, '-')}.pdf`);

    await page.pdf({
        path: file,
        format: 'A4',
        printBackground: true,
        displayHeaderFooter: true,
        headerTemplate: '<div></div>',
        footerTemplate: footer(guide),
        margin: { top: '20mm', bottom: '20mm', left: '0', right: '0' },
    });

    await page.close();

    const kb = Math.round(statSync(file).size / 1024);
    results.push({ role: guide.role, file, kb, sections: guide.sections.length });
    console.log(`  ${guide.role.padEnd(15)} ${String(kb).padStart(5)} KB   ${guide.sections.length} sections`);
}

await browser.close();

console.log(`\n${results.length} guides written to docs/guides/`);
