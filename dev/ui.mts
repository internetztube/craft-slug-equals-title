/**
 * Control panel tests in a real browser (headless Chrome over the DevTools protocol, dev/browser.mts): what a person
 * sees and does on Craft 6's editor. The switch right after the slug field, saving with the switch on and off, a
 * section that is off, a type without a slug field, the German site, and the settings page.
 *
 *   dev/dev.sh ui                  (started by dev/dev.sh ci as well)
 *   ONLY="settings" dev/dev.sh ui  one scenario (a substring of its name)
 *
 * Env: CHROME (path to Chrome), CP_PASSWORD (the admin's password, default: the dev password).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluate, launchChrome, login, open, PAGE_HELPERS, resize, sleep } from './browser.mts';

const [projectDir, baseUrl = 'http://localhost:8130'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/ui.mts <craft-project-dir> [base-url]');
    process.exit(1);
}
const here = dirname(fileURLToPath(import.meta.url));
const password = process.env.CP_PASSWORD ?? 'password';
const only = process.env.ONLY ?? '';
const php = process.env.PHP ?? '/opt/homebrew/opt/php/bin/php';

/** The demo elements and two fresh fixtures (dev/targets.php). */
interface Targets {
    news: { id: number, path: string };
    about: { path: string };
    home: { path: string };
    settings: string;
    uids: { news: string, pages: string, landingPage: string };
    fixtures: { news: { id: number, path: string, pathDe: string }, page: { id: number, path: string } };
}
const targets: Targets = JSON.parse(execFileSync(php, [join(here, 'targets.php'), projectDir, 'fixtures'], { encoding: 'utf8' }));

let failures = 0;
let passed = 0;
function check(ok: boolean, what: string): void {
    console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${what}`);
    ok ? passed++ : failures++;
    if (!ok) {
        throw new Error(what); // the scenario stops here; its page is kept as a screenshot
    }
}

console.log('Starting Chrome');
const browser = await launchChrome(9334);
await browser.send('Page.enable');
await resize(browser, { width: 1400, height: 1100 });
await login(browser, baseUrl, 'admin', password);
const visit = async (path: string) => {
    await open(browser, baseUrl + path);
    await sleep(700); // the Vue editor renders after the page loads
    await evaluate(browser, PAGE_HELPERS);
};
const run = (code: string) => evaluate(browser, code);
/** The switch and the slug field, as the page shows them. */
const ui = (): Promise<{ present: boolean, inSlugField: boolean, inputHidden: boolean, note: string | null, on: boolean, slug: string, title: string, hasSlugField: boolean }> => run(`
    const u = slugUi();
    return { present: !!u.button, inSlugField: u.inSlugField, inputHidden: u.inputHidden, note: u.note, on: u.on, slug: u.slugInput?.value ?? '', title: u.titleInput?.value ?? '', hasSlugField: !!u.slugField };
`);
/** Saves (the editor stays on the page) and opens the page again, so what is read is what was stored. */
const saveAndReload = async (path: string) => {
    await run('await save();');
    await sleep(800); // the editor follows the save with a layout refresh and a redirect; a reload in between can 404
    await visit(path);
};
const failureDir = join(projectDir, 'storage', 'ui-failures');

async function scenario(name: string, body: () => Promise<void>): Promise<void> {
    if (only && !name.includes(only)) {
        return;
    }
    console.log(name);
    try {
        await body();
    } catch (error) {
        if (!(error instanceof Error) || !error.message.startsWith('  ')) {
            failures++;
            console.log(`  FAIL ${error instanceof Error ? error.message : String(error)}`);
        }
        try {
            mkdirSync(failureDir, { recursive: true });
            const file = join(failureDir, name.replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '.png');
            const { data } = await browser.send('Page.captureScreenshot', { format: 'png' });
            writeFileSync(file, Buffer.from(data, 'base64'));
            console.log(`       (page saved to ${file})`);
        } catch {
            // the page is gone (navigated or closed); nothing to save
        }
    }
}

try {
    await scenario('The switch after the slug field', async () => {
        await visit(targets.fixtures.news.path);
        const u = await ui();
        check(u.present && u.inSlugField, 'a News entry has the "Slug equals title" switch inside the slug field, next to its input');
        check(u.inputHidden && u.note === 'Overwritten by title', 'on: "Overwritten by title" stands in for the input');
        check(u.on && u.slug === 'ui-news-entry', `on, with the slug from the title (${u.slug})`);
        await run(`type(slugUi().titleInput, 'UI: Typed a new title!');`);
        await saveAndReload(targets.fixtures.news.path);
        const saved = await ui();
        check(saved.title === 'UI: Typed a new title!' && saved.slug === 'ui-typed-a-new-title' && saved.on, 'saved: the slug equals the title, the switch stays on');
    });

    await scenario('Switching off keeps a slug of your own', async () => {
        await visit(targets.fixtures.news.path);
        await run(`slugUi().button.click(); await sleep(300);`);
        let u = await ui();
        check(!u.on, 'switched off');
        await run(`type(slugUi().slugInput, 'ui-my-own-slug'); type(slugUi().titleInput, 'UI: Another title');`);
        await saveAndReload(targets.fixtures.news.path);
        u = await ui();
        check(u.title === 'UI: Another title' && u.slug === 'ui-my-own-slug' && !u.on, 'saved: the slug of your own is kept, the switch stays off');
        await run(`slugUi().button.click(); await sleep(300);`);
        await saveAndReload(targets.fixtures.news.path);
        u = await ui();
        check(u.slug === 'ui-another-title' && u.on, 'switched on again and saved: the slug follows the title again');
    });

    await scenario('A section that is off', async () => {
        await visit(targets.fixtures.page.path);
        const u = await ui();
        check(u.present && u.inSlugField && !u.inputHidden && !u.on && u.slug === 'ui-page', 'a Pages entry has the switch off and its own slug');
        await run(`type(slugUi().titleInput, 'UI: Renamed page');`);
        await saveAndReload(targets.fixtures.page.path);
        check((await ui()).slug === 'ui-page', 'saved: the title leaves the slug alone');
        await visit(targets.about.path);
        const about = await ui();
        check(!about.on && about.slug === 'about', 'the demo page "About us" keeps its slug "about"');
    });

    await scenario('A type without a slug field', async () => {
        await visit(targets.home.path);
        const u = await ui();
        check(!u.hasSlugField && !u.present, 'the Home single (its entry type hides the slug field) shows no slug field and no switch');
    });

    await scenario('The German site', async () => {
        await visit(targets.fixtures.news.pathDe);
        if (!(await ui()).on) {
            await run(`slugUi().button.click(); await sleep(300);`); // the switch is per entry, not per site: the scenario before may have left it off
        }
        await run(`type(slugUi().titleInput, 'UI: Straße über München');`);
        await saveAndReload(targets.fixtures.news.pathDe);
        check((await ui()).slug === 'ui-strasse-ueber-muenchen', `the saved slug is transliterated the German way (${(await ui()).slug})`);
    });

    await scenario('The settings page', async () => {
        const { news, pages, landingPage } = targets.uids;
        await visit(targets.settings);
        const page: { news: boolean, pages: boolean, landing: boolean } = await run(`
            window.box = (name, value) => document.querySelector('input[name="settings[' + name + '][]"][value="' + value + '"]');
            return { news: box('enabledSections', '${news}').checked, pages: box('enabledSections', '${pages}').checked, landing: box('enabledSections', '${pages}:${landingPage}').checked };
        `);
        check(page.news && !page.pages && !page.landing, 'Sections: News is ticked, Pages and its entry type "Landing page" are not');
        await run(`box('enabledSections', '${pages}:${landingPage}').click(); await sleep(200);`);
        await saveAndReload(targets.settings);
        check(await run(`return document.querySelector('input[name="settings[enabledSections][]"][value="${pages}:${landingPage}"]').checked;`), 'ticking "Landing page" and saving keeps it ticked');
        await run(`document.querySelector('input[name="settings[enabledSections][]"][value="${pages}:${landingPage}"]').click(); await sleep(200);`);
        await saveAndReload(targets.settings);
        check(!(await run(`return document.querySelector('input[name="settings[enabledSections][]"][value="${pages}:${landingPage}"]').checked;`)), 'unticked again');
    });
} finally {
    await browser.close();
    execFileSync(php, [join(here, 'targets.php'), projectDir, 'cleanup']);
}

console.log(`${passed} ok, ${failures} failed`);
process.exit(failures ? 1 : 0);
