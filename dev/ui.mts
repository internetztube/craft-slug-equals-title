/**
 * Control panel tests in a real browser (headless Chrome over the DevTools protocol, dev/browser.mts): what a person
 * sees and does on an edit page. The switch in the slug field, "Overwritten by title" in place of the slug input,
 * saving with the switch on and off, the slideout editor, the German site, a type without a slug field,
 * and the settings page.
 *
 *   dev/dev.sh ui                  (started by dev/dev.sh ci as well)
 *   ONLY="slideout" dev/dev.sh ui  one scenario (a substring of its name)
 *
 * Env: CHROME (path to Chrome), CP_PASSWORD (the admin's password, default: the dev password).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluate, launchChrome, login, open, PAGE_HELPERS, resize, sleep } from './browser.mts';

const [projectDir, baseUrl = 'http://localhost:8080'] = process.argv.slice(2);
if (!projectDir) {
    console.error('Usage: node dev/ui.mts <craft-project-dir> [base-url]');
    process.exit(1);
}
const here = dirname(fileURLToPath(import.meta.url));
const password = process.env.CP_PASSWORD ?? 'password';
const only = process.env.ONLY ?? '';

/** The demo elements and two fresh fixtures (dev/targets.php). */
interface Targets {
    news: { id: number, path: string };
    about: { path: string };
    home: { path: string };
    category: { path: string };
    product: { path: string } | null;
    settings: string;
    pagesIndex: string;
    fixtures: { news: { id: number, path: string, pathDe: string }, page: { id: number, path: string } };
}
const targets: Targets = JSON.parse(execFileSync('php', [join(here, 'targets.php'), projectDir, 'fixtures'], { encoding: 'utf8' }));

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
    await evaluate(browser, PAGE_HELPERS);
};
const run = (code: string) => evaluate(browser, code);
/** Submits the page's form, waits for the page Craft redirects to (the index, the plugin list) and opens `path` again. */
const saveAndReload = async (path: string) => {
    const loaded = browser.once('Page.loadEventFired');
    await run(`$('#main-form').trigger('submit');`);
    await loaded;
    await sleep(500);
    await visit(path);
};
/** The switch's state and the slug field, as the page shows them. */
const ui = (): Promise<{ present: boolean, inSlugField: boolean, on: boolean, slugDisabled: boolean, slugHidden: boolean, note: string | null, slug: string, title: string }> => run(`
    const u = slugUi();
    return { present: !!u.lightswitch, inSlugField: u.inSlugField, on: u.on, slugDisabled: !!u.slugInput?.disabled, slugHidden: u.slugHidden, note: u.note, slug: u.slugInput?.value ?? '', title: u.titleInput?.value ?? '' };
`);
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
    await scenario('The switch in the slug field', async () => {
        await visit(targets.fixtures.news.path);
        const u = await ui();
        check(u.present && u.inSlugField, 'a News entry has the "Slug equals title" switch in the slug field, next to the input');
        check(u.on && u.slugHidden && u.slugDisabled && u.note === 'Overwritten by title', 'on: the slug input is replaced by "Overwritten by title"');
        await run(`type(slugUi().titleInput, 'UI: Typed a new title!');`);
        await sleep(300);
        await saveAndReload(targets.fixtures.news.path);
        const saved = await ui();
        check(saved.title === 'UI: Typed a new title!' && saved.slug === 'ui-typed-a-new-title' && saved.on, 'saved: the slug equals the title, the switch stays on');
    });

    await scenario('Switching off keeps a slug of your own', async () => {
        await visit(targets.fixtures.news.path);
        await run(`$(slugUi().lightswitch).data('lightswitch').toggle();`);
        await sleep(300);
        let u = await ui();
        check(!u.on && !u.slugHidden && !u.slugDisabled && u.note === null, 'switched off, the slug input is back and free');
        await run(`type(slugUi().slugInput, 'ui-my-own-slug');`);
        await run(`type(slugUi().titleInput, 'UI: Another title');`);
        await sleep(300);
        u = await ui();
        check(u.slug === 'ui-my-own-slug', `the title no longer touches the slug (${u.slug})`);
        await saveAndReload(targets.fixtures.news.path);
        u = await ui();
        check(u.title === 'UI: Another title' && u.slug === 'ui-my-own-slug' && !u.on, 'saved: the slug of your own is kept, the switch stays off');
        await run(`$(slugUi().lightswitch).data('lightswitch').toggle();`);
        await sleep(300);
        u = await ui();
        check(u.on && u.slugHidden && u.note === 'Overwritten by title', 'switched on again, "Overwritten by title" takes the input\'s place');
        await saveAndReload(targets.fixtures.news.path);
        u = await ui();
        check(u.slug === 'ui-another-title' && u.on, 'saved: the slug follows the title again');
    });

    await scenario('A section that is off', async () => {
        await visit(targets.fixtures.page.path);
        const u = await ui();
        check(u.present && u.inSlugField && !u.on && !u.slugHidden && u.slug === 'ui-page', 'a Pages entry has the switch off and its slug free');
        await run(`type(slugUi().titleInput, 'UI: Renamed page');`);
        await sleep(300);
        check((await ui()).slug === 'ui-page', 'the title leaves the slug alone');
        await visit(targets.about.path);
        const about = await ui();
        check(!about.on && about.slug === 'about', 'the demo page "About us" keeps its slug "about"');
    });

    await scenario('A type without a slug field', async () => {
        await visit(targets.home.path);
        const u = await ui();
        check(!u.present, 'the Home single (its entry type hides the slug field) shows no switch');
        await visit(targets.category.path);
        const c = await ui();
        check(c.present && c.inSlugField && c.on, 'a category of the Topics group has the switch in its slug field, on');
        if (targets.product) {
            await visit(targets.product.path);
            const p = await ui();
            check(p.present && p.inSlugField && p.on && p.slug === 'linen-shirt', 'a product of the Clothing type has it too, on');
        }
    });

    await scenario('The slideout editor', async () => {
        await visit(targets.pagesIndex);
        await run(`
            window.slideout = Craft.createElementEditor('craft\\\\elements\\\\Entry', { elementId: ${targets.fixtures.page.id}, siteId: ${await run(`return Craft.siteId;`)} });
            await until(() => document.querySelector('.slideout [data-attribute="slug"]'), 'the slideout');
            await sleep(500);
        `);
        const u: { inSlugField: boolean, on: boolean, slugHidden: boolean } = await run(`
            const u = slugUi(document.querySelector('.slideout'));
            return { inSlugField: u.inSlugField, on: u.on, slugHidden: u.slugHidden };
        `);
        check(u.inSlugField && !u.on && !u.slugHidden, 'the slideout has the switch in its slug field (off, as remembered for the page)');
        await run(`
            const root = document.querySelector('.slideout');
            $(slugUi(root).lightswitch).data('lightswitch').toggle();
            await sleep(300);
            type(slugUi(root).titleInput, 'UI: Saved from the slideout');
            await sleep(300);
        `);
        const after: { on: boolean, slugHidden: boolean, note: string | null } = await run(`
            const u = slugUi(document.querySelector('.slideout'));
            return { on: u.on, slugHidden: u.slugHidden, note: u.note };
        `);
        check(after.on && after.slugHidden && after.note === 'Overwritten by title', 'switched on in the slideout: "Overwritten by title" takes the input\'s place');
        await run(`
            slideout.submit();
            await sleep(1500);
            // closes when the save is through; the edit page below is the real check
            await until(() => !$$('.slideout').length, 'the slideout to close', 15000).catch(() => {});
        `);
        await visit(targets.fixtures.page.path);
        const saved = await ui();
        check(saved.title === 'UI: Saved from the slideout' && saved.slug === 'ui-saved-from-the-slideout' && saved.on, 'saved from the slideout: the slug equals the title, the switch is on');
    });

    await scenario('The German site', async () => {
        await visit(targets.fixtures.news.pathDe);
        if (!(await ui()).on) {
            await run(`$(slugUi().lightswitch).data('lightswitch').toggle();`); // the switch is per entry, not per site: the scenario before may have left it off
            await sleep(300);
        }
        await run(`type(slugUi().titleInput, 'UI: Straße über München');`);
        await sleep(300);
        await saveAndReload(targets.fixtures.news.pathDe);
        check((await ui()).slug === 'ui-strasse-ueber-muenchen', `the saved slug is transliterated the German way (${(await ui()).slug})`);
    });

    await scenario('The settings page', async () => {
        await visit(targets.settings);
        const page: { news: boolean, pages: boolean, topicsDisabled: boolean, note: boolean } = await run(`
            const box = (name, value) => document.querySelector('input[name="settings[' + name + '][]"][value="' + value + '"]');
            return { news: box('enabledSections', 'news').checked, pages: box('enabledSections', 'pages').checked, topicsDisabled: box('enabledCategoryGroups', 'topics').disabled && box('enabledCategoryGroups', 'topics').checked, note: document.body.textContent.includes('config/slug-equals-title.php') };
        `);
        check(page.news && !page.pages, 'Sections: News is ticked, Pages is not');
        check(page.topicsDisabled && page.note, 'Category Groups: Topics is ticked but read-only, with the note about config/slug-equals-title.php');
        await run(`document.querySelector('input[name="settings[enabledSections][]"][value="pages"]').click();`);
        await saveAndReload(targets.settings);
        check(await run(`return document.querySelector('input[name="settings[enabledSections][]"][value="pages"]').checked;`), 'ticking Pages and saving keeps it ticked');
        await visit(targets.fixtures.page.path);
        check((await ui()).on, 'the page entry already switched on (remembered) is still on');
        await visit(targets.settings);
        await run(`document.querySelector('input[name="settings[enabledSections][]"][value="pages"]').click();`);
        await saveAndReload(targets.settings);
        check(!(await run(`return document.querySelector('input[name="settings[enabledSections][]"][value="pages"]').checked;`)), 'unticked again');
    });
} finally {
    await browser.close();
    execFileSync('php', [join(here, 'targets.php'), projectDir, 'cleanup']);
}

console.log(`${passed} ok, ${failures} failed`);
process.exit(failures ? 1 : 0);
