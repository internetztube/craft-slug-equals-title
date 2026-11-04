/**
 * Documentation screenshots of the plugin in the seeded dev project, taken with headless Chrome over the DevTools
 * protocol (dev/browser.mts, no packages; Node 24 runs it as TypeScript, no build step): the whole control panel page
 * in a browser window, a ring on the feature and a zoomed inset of it.
 *
 *   dev/dev.sh screenshots              all shots into docs/screenshots/
 *   ONLY=settings dev/dev.sh screenshots
 *
 * Env: CHROME (path to Chrome), CP_PASSWORD (the admin's password, default: the dev password).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { evaluate, launchChrome, login, open, PAGE_HELPERS as CP_HELPERS, resize, type Browser } from './browser.mts';

const [projectDir, baseUrl = 'http://localhost:8080', outDir = 'docs/screenshots'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/screenshots.mts <craft-project-dir> [base-url] [out-dir]');
    process.exit(1);
}

/** Browser viewport of the control panel, in CSS pixels (a laptop's), captured at 3× so the insets stay sharp. */
const VIEWPORT = { width: 1440, height: 860, scale: 3 };
/** The framed image: canvas size in CSS pixels and its device scale (2400 × 1800 px). */
const FRAME = { width: 1600, height: 1200, scale: 1.5 };
/** Where the browser window sits, in frame pixels: below the copy, as large as fits, centred. */
const STAGE = { left: 88, top: 248, width: 1424, height: 900 };
/** Height of the window's title bar, in frame pixels. */
const BAR = 36;
/** The zoomed inset: at most this much of the window, at most this many frame pixels per CSS pixel, this far from the edge. */
const INSET = { maxWidth: 0.42, maxHeight: 0.5, maxZoom: 2, margin: 28, pad: 10 };
const DEBUGGING_PORT = 9333;

/** Paths of the demo elements, from dev/targets.php. */
interface Targets {
    news: { path: string };
    about: { path: string };
    settings: string;
    pagesIndex: string;
}

interface Shot {
    /** file name in docs/screenshots (without .jpg) */
    name: string;
    /** headline above the window */
    title: string;
    /** one line below the headline */
    text: string;
    /** page to open */
    path: string;
    /** two or three key points next to the shot */
    points: string[];
    /** JavaScript expression for groups of elements that get a ring; the inset zooms into the first group */
    highlight: string;
    /** false: rings only, no zoomed inset (the page shows the feature big enough) */
    inset?: boolean;
    /** JavaScript run in the page before the capture (may await) */
    act?: string;
    /** JavaScript expression for the part of the page the cover shows as a close-up (COVER_SHOTS) */
    cover?: string;
    /** under that close-up */
    caption?: string;
}

/** A box in CSS pixels. */
interface Region {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** What a shot shows, in CSS pixels of the viewport. */
interface Area {
    viewport: { width: number, height: number };
    scroll: { x: number, y: number };
    highlights: Region[];
    target: Region | null;
    inset?: Region;
}

/** A shot as taken: its page and inset as files in the work directory. */
interface Taken {
    shot: Shot;
    base: string;
    inset: string | null;
    cover: string | null;
    coverRegion: Region | null;
    area: Area;
    address: string;
}

const here = dirname(fileURLToPath(import.meta.url));
const targets: Targets = JSON.parse(execFileSync('php', [join(here, 'targets.php'), projectDir], { encoding: 'utf8' }));
const password = process.env.CP_PASSWORD ?? 'password';
const only = (process.env.ONLY ?? '').split(',').filter(Boolean);

const PAGE_HELPERS = CP_HELPERS + `
/** The box around one or more elements, in CSS pixels of the viewport. */
window.regionOf = (elements, padding = 0) => {
    const list = [elements].flat(3).filter(Boolean);
    if (!list.length) throw new Error('Nothing to frame');
    const boxes = list.map((e) => e.getBoundingClientRect());
    const left = Math.min(...boxes.map((b) => b.left)), top = Math.min(...boxes.map((b) => b.top));
    const right = Math.max(...boxes.map((b) => b.right)), bottom = Math.max(...boxes.map((b) => b.bottom));
    return { x: left - padding, y: top - padding, width: right - left + 2 * padding, height: bottom - top + 2 * padding };
};
`;

/** The slug field with the switch in it, inside `root`. */
const SWITCH_AND_SLUG = (root = 'document') => `[[slugUi(${root}).slugField]]`;

const SHOTS: Shot[] = [
    {
        name: 'edit-page',
        title: 'The slug follows the title',
        text: 'Switched on, the slug field reads “Overwritten by title”: every save sets the slug from the title, and Craft keeps it unique.',
        path: targets.news.path,
        points: ['On by default for the sections you pick', 'One switch per entry, in the slug field', 'Craft’s own slug rules, in the site’s language'],
        highlight: SWITCH_AND_SLUG(),
        cover: `[slugUi().slugField.closest('.meta')]`,
        caption: 'The switch in the slug field',
    },
    {
        name: 'own-slug',
        title: 'Or keep a slug of your own',
        text: 'Switch it off for one entry: the slug field is free again and stays what you type, whatever the title does.',
        path: targets.about.path,
        points: ['Remembered per entry, drafts included', 'Sections that are off start like this', 'resave/entries respects it'],
        highlight: SWITCH_AND_SLUG(),
        cover: `[slugUi().slugField.closest('.meta')]`,
        caption: 'Or keep a slug of your own',
    },
    {
        name: 'slideout',
        title: 'In slideouts too',
        text: 'Wherever Craft edits an element, the switch sits in its slug field: edit pages, slideouts, categories and products.',
        path: targets.pagesIndex,
        points: ['Entries, categories, Commerce products', 'Edit pages and slideouts', 'Read-only on revisions'],
        act: `
            const row = [...document.querySelectorAll('.elements table tbody tr')].find((r) => r.textContent.includes('Contact'));
            Craft.createElementEditor('craft\\\\elements\\\\Entry', { elementId: Number(row.dataset.id), siteId: Craft.siteId });
            await until(() => document.querySelector('.slideout [data-attribute="slug"]'), 'the slideout');
            await sleep(900);
        `,
        highlight: SWITCH_AND_SLUG("document.querySelector('.slideout')"),
    },
    {
        name: 'settings',
        title: 'Defaults per section',
        text: 'Pick the sections, category groups and product types whose elements follow their title, or single entry types of a section. Or set them per environment in config/slug-equals-title.php.',
        path: targets.settings,
        points: ['Sections, entry types, category groups, product types', 'Overridable in a config file', 'Every element can still decide for itself'],
        highlight: `[$$('#content .field')]`,
        inset: false,
        // the fields span the content pane; the close-up takes their left 800px, enough for the longest line
        cover: `(() => { const r = regionOf($$('#content .field')); return [{ getBoundingClientRect: () => ({ left: r.x, top: r.y, right: r.x + 800, bottom: r.y + r.height }) }]; })()`,
        caption: 'Defaults per section, entry type, group',
    },
];

// ---------------------------------------------------------------------------------------------------------------
// Capture
// ---------------------------------------------------------------------------------------------------------------

async function capture(browser: Browser, clip: Region): Promise<Buffer> {
    const { data } = await browser.send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 } });
    return Buffer.from(data, 'base64');
}

async function captureViewport(browser: Browser, format: 'png' | 'jpeg' = 'png'): Promise<Buffer> {
    const { data } = await browser.send('Page.captureScreenshot', format === 'png' ? { format } : { format, quality: 90 });
    return Buffer.from(data, 'base64');
}

/** Scrolls the page so the shot's highlight is in view and measures the highlights and the inset's target. */
async function measure(browser: Browser, shot: Shot): Promise<Area> {
    const area: Area = await evaluate(browser, `
        const groups = (${shot.highlight}).filter((group) => [group].flat().filter(Boolean).length).map((group) => regionOf(group, 6));
        const first = groups[0];
        if (first && (first.y < 0 || first.y + first.height > innerHeight)) {
            window.scrollBy(0, Math.round(first.y) - 60);
            await sleep(400);
        }
        const again = (${shot.highlight}).filter((group) => [group].flat().filter(Boolean).length).map((group) => regionOf(group, 6));
        return { viewport: { width: innerWidth, height: innerHeight }, scroll: { x: window.scrollX, y: window.scrollY }, highlights: again, target: again[0] ?? null };
    `);
    const clip = (r: Region): Region => {
        const x = Math.max(r.x, 0), y = Math.max(r.y, 0);
        const right = Math.min(r.x + r.width, area.viewport.width), bottom = Math.min(r.y + r.height, area.viewport.height);
        return { x, y, width: right - x, height: bottom - y };
    };
    const visible = (r: Region) => r.width > 0 && r.height > 0;
    area.highlights = area.highlights.map(clip).filter(visible);
    const target = area.target && clip(area.target);
    area.target = target && visible(target) ? target : null;
    if (area.target && shot.inset !== false) {
        const t = area.target, pad = INSET.pad;
        area.inset = clip({ x: t.x - pad, y: t.y - pad, width: t.width + 2 * pad, height: t.height + 2 * pad });
    }
    return area;
}

// ---------------------------------------------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------------------------------------------

function escapeHtml(text: string): string {
    return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c);
}

/** The plugin icon (src/icon.svg, white) as a data URL for the frames. */
const PLUGIN_ICON = `data:image/svg+xml;base64,${readFileSync(join(here, '..', 'src', 'icon.svg')).toString('base64')}`;

/** The accent of the rings and the inset: the icon's green. */
const ACCENT = '#2ec27e';

/** Background of the frames: near-black, one green glow from the icon's colour, a faint cool one, a fine grid. */
const BACKDROP_CSS = `
    background:
        radial-gradient(900px 640px at 0% 0%, rgba(46, 194, 126, .30), transparent 70%),
        radial-gradient(800px 600px at 100% 100%, rgba(72, 118, 255, .18), transparent 70%),
        linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
        linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px) 0 0 / 48px 48px,
        linear-gradient(165deg, #0c1510 0%, #10201a 60%, #0c1712 100%);
`;
const GRAIN = `url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/></filter><rect width='100%' height='100%' filter='url(%23n)' opacity='.5'/></svg>")`;

const PAGE_CSS = `
    * { box-sizing: border-box; }
    body { margin: 0; width: ${FRAME.width}px; height: ${FRAME.height}px; overflow: hidden; position: relative; color: #fff;
        font-family: -apple-system, 'SF Pro Display', 'Inter', 'Helvetica Neue', sans-serif; -webkit-font-smoothing: antialiased; ${BACKDROP_CSS} }
    body::after { content: ''; position: absolute; inset: 0; background: ${GRAIN}; opacity: .07; mix-blend-mode: overlay; pointer-events: none; z-index: 5; }
    .brand { display: flex; align-items: center; gap: 12px; font-size: 16px; font-weight: 600; letter-spacing: .01em; color: rgba(255, 255, 255, .85); }
    .brand img { width: 34px; height: 34px; padding: 5px; border-radius: 9px; background: linear-gradient(135deg, #2ec27e, #178a55); box-shadow: 0 6px 16px rgba(0, 0, 0, .4); }
    h1 { margin: 0; font-weight: 800; letter-spacing: -.03em; line-height: 1.05; }
    p { margin: 0; color: #c4d4cc; }
    .window { position: relative; border-radius: 14px; overflow: hidden; background: #fff;
        box-shadow: 0 70px 140px -40px rgba(0, 0, 0, .75), 0 30px 60px -30px rgba(0, 0, 0, .6), 0 0 0 1px rgba(255, 255, 255, .14); }
    .bar { height: ${BAR}px; display: flex; align-items: center; gap: 7px; padding: 0 14px; background: #f3f4f7; border-bottom: 1px solid #e3e5ec; }
    .dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
    .address { margin-left: 12px; font-size: 12.5px; color: #7a8296; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .shot { position: relative; }
    .shot > img { display: block; }
    .ring { position: absolute; border-radius: 8px; border: 2.5px solid ${ACCENT}; box-shadow: 0 0 0 4px rgba(46, 194, 126, .2), 0 6px 18px rgba(46, 194, 126, .3); }
    .link { position: absolute; left: 0; top: 0; overflow: visible; pointer-events: none; }
    .inset { position: absolute; border-radius: 12px; overflow: hidden; background: #fff;
        box-shadow: 0 0 0 3px ${ACCENT}, 0 0 0 8px rgba(46, 194, 126, .22), 0 40px 80px -20px rgba(0, 0, 0, .75); }
    .inset img { display: block; }
`;

/** The window's size and place on the stage, in frame pixels: the whole viewport, as large as fits, centred. */
function layoutOf(area: Area) {
    const scale = Math.min(STAGE.width / area.viewport.width, (STAGE.height - BAR) / area.viewport.height);
    const width = Math.round(area.viewport.width * scale), height = Math.round(area.viewport.height * scale);
    return { scale, width, height, left: Math.round(STAGE.left + (STAGE.width - width) / 2), top: Math.round(STAGE.top + (STAGE.height - height - BAR) / 2) };
}

const scaled = (r: Region, scale: number): Region => ({ x: r.x * scale, y: r.y * scale, width: r.width * scale, height: r.height * scale });

/**
 * The zoomed inset: the first highlight captured at 3× and shown larger, in the corner of the window farthest from it,
 * with a line from the ring to it. Nothing when the page already shows it big enough.
 */
function insetHtml(area: Area, insetUrl: string, scale: number, width: number, height: number): string {
    if (!area.inset || !area.target) return '';
    const zoom = Math.min(INSET.maxZoom, INSET.maxWidth * width / area.inset.width, INSET.maxHeight * height / area.inset.height);
    if (zoom < scale * 1.35) return '';
    const iw = Math.round(area.inset.width * zoom), ih = Math.round(area.inset.height * zoom);
    const ring = scaled(area.target, scale);
    const m = INSET.margin;
    const corners = [{ x: m, y: m }, { x: width - m - iw, y: m }, { x: m, y: height - m - ih }, { x: width - m - iw, y: height - m - ih }];
    const overlap = (c: { x: number, y: number }) => Math.max(0, Math.min(c.x + iw, ring.x + ring.width) - Math.max(c.x, ring.x))
        * Math.max(0, Math.min(c.y + ih, ring.y + ring.height) - Math.max(c.y, ring.y));
    const distance = (c: { x: number, y: number }) => Math.hypot(c.x + iw / 2 - ring.x - ring.width / 2, c.y + ih / 2 - ring.y - ring.height / 2);
    // least over the ring, then the nearest corner (a short line)
    const at = corners.sort((a, b) => overlap(a) - overlap(b) || distance(a) - distance(b))[0];
    const dx = at.x + iw / 2 - ring.x - ring.width / 2, dy = at.y + ih / 2 - ring.y - ring.height / 2;
    const horizontal = Math.abs(dx) > Math.abs(dy);
    const start = horizontal
        ? { x: dx > 0 ? ring.x + ring.width : ring.x, y: ring.y + ring.height / 2 }
        : { x: ring.x + ring.width / 2, y: dy > 0 ? ring.y + ring.height : ring.y };
    const end = horizontal
        ? { x: dx > 0 ? at.x : at.x + iw, y: at.y + ih / 2 }
        : { x: at.x + iw / 2, y: dy > 0 ? at.y : at.y + ih };
    const apart = horizontal ? (dx > 0 ? end.x > start.x : end.x < start.x) : (dy > 0 ? end.y > start.y : end.y < start.y);
    const line = apart
        ? `<svg class="link" width="${width}" height="${height}"><line x1="${start.x}" y1="${start.y}" x2="${end.x}" y2="${end.y}" stroke="${ACCENT}" stroke-width="2.5" stroke-opacity=".9"/><circle cx="${start.x}" cy="${start.y}" r="5" fill="${ACCENT}"/></svg>`
        : '';
    return `${line}<div class="inset" style="left:${at.x}px;top:${at.y}px;width:${iw}px;height:${ih}px"><img src="${insetUrl}" alt="" style="width:${iw}px;height:${ih}px"></div>`;
}

/** The whole page in a browser window, with rings on the highlights and the zoomed inset; `address` in its title bar. */
function windowHtml(imageUrl: string, area: Area, address: string, scale: number, inset = ''): string {
    const width = Math.round(area.viewport.width * scale), height = Math.round(area.viewport.height * scale);
    const rings = area.highlights.map((h) => scaled(h, scale))
        .map((h) => `<span class="ring" style="left:${h.x}px;top:${h.y}px;width:${h.width}px;height:${h.height}px"></span>`)
        .join('');
    return `<div class="window" style="width:${width}px">
        <div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span><span class="address">${escapeHtml(address)}</span></div>
        <div class="shot" style="width:${width}px;height:${height}px"><img src="${imageUrl}" alt="" style="width:${width}px;height:${height}px">${rings}${inset}</div>
    </div>`;
}

function frameHtml({ shot, base, inset, area, address }: Taken): string {
    const box = layoutOf(area);
    const insetMarkup = inset ? insetHtml(area, pathToFileURL(inset).href, box.scale, box.width, box.height) : '';
    const points = shot.points.map((point) => `<li>${escapeHtml(point)}</li>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .copy { position: absolute; left: ${STAGE.left}px; right: ${STAGE.left}px; top: 0; height: ${STAGE.top}px; padding-bottom: 30px;
            display: grid; grid-template-columns: 1fr 460px; column-gap: 64px; align-content: end; align-items: end; }
        .copy .brand, .copy h1, .copy p { grid-column: 1; }
        .copy ul { grid-column: 2; grid-row: 1 / span 3; align-self: end; margin: 0; padding: 0; list-style: none; display: grid; gap: 11px; }
        h1 { font-size: 48px; margin: 16px 0 8px; }
        p { font-size: 19px; line-height: 1.45; text-wrap: balance; }
        li { position: relative; padding-left: 32px; font-size: 16.5px; line-height: 1.3; color: #e7f0ea; }
        li::before { content: ''; position: absolute; left: 0; top: 0; width: 21px; height: 21px; border-radius: 50%;
            background: rgba(46, 194, 126, .2) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M6 12.5l4 4 8-9' fill='none' stroke='%2358e0a0' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") center / 14px no-repeat; }
        .stage { position: absolute; left: ${box.left}px; top: ${box.top}px; }
    </style></head><body>
        <div class="copy">
            <div class="brand"><img src="${PLUGIN_ICON}" alt="">Slug Equals Title</div>
            <h1>${escapeHtml(shot.title)}</h1>
            <p>${escapeHtml(shot.text)}</p>
            <ul>${points}</ul>
        </div>
        <div class="stage">${windowHtml(pathToFileURL(base).href, area, address, box.scale, insetMarkup)}</div>
    </body></html>`;
}

/** The three close-ups of the cover: two stacked on the left, the tall one on the right. */
const COVER_SHOTS = ['edit-page', 'own-slug', 'settings'];
/** What the cover promises, under the title. */
const COVER_CHIPS = ['Entries, categories, products', 'Per section, entry type or element', 'Three test suites, 100 % covered', 'Craft CMS 5'];

/** The cover for the README and the Plugin Store: the plugin's name over three close-ups of the control panel. */
function coverHtml(cards: (Taken & { cover: string, coverRegion: Region })[]): string {
    const top = 450, bottom = 1150, gap = 32, caption = 40, margin = 88;
    type Card = typeof cards[number];
    const ratio = (card: Card) => card.coverRegion.height / card.coverRegion.width;
    const height = (card: Card, w: number) => Math.round(ratio(card) * w);
    const [first, second, tall] = cards;
    const layout = (width: number) => {
        const right = ((width - gap) * (ratio(first) + ratio(second)) + caption + gap) / (ratio(first) + ratio(second) + ratio(tall));
        const left = width - gap - right;
        const x = Math.round((FRAME.width - width) / 2);
        return {
            slots: [
                { card: first, left: x, top, width: Math.round(left) },
                { card: second, left: x, top: top + caption + height(first, left) + gap, width: Math.round(left) },
                { card: tall, left: x + Math.round(left) + gap, top, width: Math.round(right) },
            ],
            bottom: top + caption + height(tall, right),
        };
    };
    // as wide as the stage allows, and no taller than the stage
    const maxWidth = FRAME.width - 2 * margin;
    let { slots, bottom: end } = layout(maxWidth);
    if (end !== bottom) {
        ({ slots } = layout(Math.min(maxWidth, Math.round(maxWidth * (bottom - top) / (end - top)))));
    }
    const crops = slots.map(({ card, left, top, width }) => `<figure class="crop" style="left:${left}px;top:${top}px;width:${width}px">
        <figcaption><i></i>${escapeHtml(card.shot.caption ?? card.shot.title)}</figcaption>
        <div class="pic"><img src="${pathToFileURL(card.cover).href}" alt="" style="width:${width}px;height:${height(card, width)}px"></div>
    </figure>`).join('');
    const chips = COVER_CHIPS.map((chip) => `<span>${escapeHtml(chip)}</span>`).join('');
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        ${PAGE_CSS}
        .head { position: absolute; left: 0; right: 0; top: 72px; text-align: center; }
        .head img { width: 84px; height: 84px; border-radius: 22px; box-shadow: 0 20px 50px rgba(0, 0, 0, .5); }
        h1 { margin: 22px 0 12px; font-size: 72px; letter-spacing: -.035em; }
        p { font-size: 25px; }
        .chips { margin-top: 24px; display: flex; justify-content: center; gap: 12px; }
        .chips span { padding: 8px 16px; border-radius: 99px; font-size: 15px; font-weight: 600; color: #e7f0ea;
            background: rgba(255, 255, 255, .08); border: 1px solid rgba(255, 255, 255, .16); }
        .spot { position: absolute; left: 50%; top: ${top + 120}px; width: 1500px; height: 700px; transform: translateX(-50%); pointer-events: none;
            background: radial-gradient(closest-side, rgba(46, 194, 126, .16), transparent); filter: blur(40px); }
        .crop { position: absolute; margin: 0; }
        .pic { border-radius: 14px; overflow: hidden; background: #fff;
            box-shadow: 0 60px 120px -30px rgba(0, 0, 0, .8), 0 20px 40px -20px rgba(0, 0, 0, .6), 0 0 0 1px rgba(255, 255, 255, .16), inset 0 1px 0 rgba(255, 255, 255, .6); }
        .pic img { display: block; }
        figcaption { height: ${caption}px; display: flex; align-items: center; gap: 9px; font-size: 15px; font-weight: 600; letter-spacing: .01em; color: #eef1f8; }
        figcaption i { width: 9px; height: 9px; border-radius: 50%; background: ${ACCENT}; box-shadow: 0 0 10px rgba(46, 194, 126, .9); }
    </style></head><body>
        <div class="head">
            <img src="${PLUGIN_ICON}" alt=""><h1>Slug Equals Title</h1>
            <p>The slug follows the title in Craft CMS. Per section by default, per entry when you want.</p>
            <div class="chips">${chips}</div>
        </div>
        <div class="spot"></div>
        ${crops}
    </body></html>`;
}

// ---------------------------------------------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------------------------------------------

mkdirSync(outDir, { recursive: true });
const workDir = mkdtempSync(join(tmpdir(), 'slug-equals-title-shots-'));
console.log('Starting Chrome');
const browser = await launchChrome(DEBUGGING_PORT);
await browser.send('Page.enable');
try {
    await resize(browser, VIEWPORT);
    await login(browser, baseUrl, 'admin', password);
    const taken: Taken[] = [];
    for (const shot of SHOTS) {
        if (only.length && !only.includes(shot.name)) {
            continue;
        }
        await open(browser, baseUrl + shot.path);
        await evaluate(browser, PAGE_HELPERS);
        if (shot.act) {
            await evaluate(browser, shot.act).catch(async (error) => {
                writeFileSync(join(outDir, `failed-${shot.name}.png`), await captureViewport(browser));
                throw error;
            });
        }
        const area = await measure(browser, shot);
        const base = join(workDir, `${shot.name}.png`);
        writeFileSync(base, await captureViewport(browser));
        let inset = null;
        if (area.inset) {
            inset = join(workDir, `${shot.name}-inset.png`);
            writeFileSync(inset, await capture(browser, { ...area.inset, x: area.inset.x + area.scroll.x, y: area.inset.y + area.scroll.y }));
        }
        let cover = null, coverRegion = null;
        if (shot.cover) {
            coverRegion = await evaluate(browser, `return regionOf(${shot.cover}, 4);`) as Region;
            cover = join(workDir, `${shot.name}-cover.png`);
            writeFileSync(cover, await capture(browser, { ...coverRegion, x: coverRegion.x + area.scroll.x, y: coverRegion.y + area.scroll.y }));
        }
        taken.push({ shot, base, inset, cover, coverRegion, area, address: `example.test${shot.path.replace(/\?.*$/, '')}` });
        console.log(`  shot ${shot.name}`);
    }

    await resize(browser, FRAME);
    for (const card of taken) {
        const html = join(workDir, `${card.shot.name}.html`);
        writeFileSync(html, frameHtml(card));
        await open(browser, pathToFileURL(html).href);
        const target = resolve(outDir, `${card.shot.name}.jpg`);
        writeFileSync(target, await captureViewport(browser, 'jpeg'));
        console.log(`  framed ${target}`);
    }

    // the cover: three of the shots, when they were all taken in this run
    const coverCards = COVER_SHOTS.map((name) => taken.find((t) => t.shot.name === name && t.cover));
    if (coverCards.every(Boolean)) {
        const html = join(workDir, 'cover.html');
        writeFileSync(html, coverHtml(coverCards as (Taken & { cover: string, coverRegion: Region })[]));
        await open(browser, pathToFileURL(html).href);
        writeFileSync(resolve(outDir, 'cover.jpg'), await captureViewport(browser, 'jpeg'));
        console.log(`  cover ${resolve(outDir, 'cover.jpg')}`);
    }
} finally {
    await browser.close();
    rmSync(workDir, { recursive: true, force: true });
}
