/**
 * Headless Chrome over the DevTools protocol, no packages (Node 24, runs as TypeScript without a build step): what
 * dev/ui.mts (the control panel tests) and dev/screenshots.mts drive. Env: CHROME (path to Chrome; otherwise the usual
 * places are tried).
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export interface Browser {
    send: (method: string, params?: object) => Promise<any>;
    once: (event: string) => Promise<any>;
    close: () => Promise<void>;
}

export interface Size {
    width: number;
    height: number;
    scale?: number;
}

export function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Chrome's executable: CHROME, else the usual places (macOS app, Linux packages). */
export function chromePath(): string {
    const candidates = [
        process.env.CHROME,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    ];
    const found = candidates.find((path): path is string => !!path && existsSync(path));
    if (!found) {
        throw new Error('Chrome not found (set CHROME to its path)');
    }
    return found;
}

export async function launchChrome(debuggingPort: number): Promise<Browser> {
    const profile = mkdtempSync(join(tmpdir(), 'slug-equals-title-chrome-'));
    const chrome = spawn(chromePath(), [
        '--headless=new', `--remote-debugging-port=${debuggingPort}`, `--user-data-dir=${profile}`,
        '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--force-color-profile=srgb',
        '--allow-file-access-from-files', '--no-sandbox', 'about:blank',
    ], { stdio: 'ignore' });

    let targets: { type: string, webSocketDebuggerUrl: string }[] = [];
    // up to 30 s: a CI runner busy with the coverage run can be slow to start it
    for (let attempt = 0; attempt < 300 && !targets.some((t) => t.type === 'page'); attempt++) {
        await sleep(100);
        targets = await fetch(`http://127.0.0.1:${debuggingPort}/json/list`).then((r) => r.json()).catch(() => []);
    }
    const page = targets.find((t) => t.type === 'page');
    if (!page) {
        chrome.kill();
        throw new Error('Chrome did not start');
    }

    const socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.onopen = resolve;
        socket.onerror = reject;
    });
    let lastId = 0;
    const pending = new Map<number, { resolve: (value: any) => void, reject: (error: Error) => void }>();
    const waiting = new Map<string, ((params: any) => void)[]>();
    const send = (method: string, params: object = {}) => new Promise<any>((resolve, reject) => {
        const id = ++lastId;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
    socket.onmessage = (message) => {
        const data = JSON.parse(String(message.data));
        if (data.id && pending.has(data.id)) {
            const { resolve, reject } = pending.get(data.id) ?? { resolve: () => {}, reject: () => {} };
            pending.delete(data.id);
            data.error ? reject(new Error(data.error.message)) : resolve(data.result);
        } else if (data.method === 'Page.javascriptDialogOpening') {
            send('Page.handleJavaScriptDialog', { accept: true }); // confirm(): "OK", like a user would
        } else if (data.method && waiting.has(data.method)) {
            for (const callback of waiting.get(data.method) ?? []) callback(data.params);
            waiting.delete(data.method);
        }
    };
    return {
        send,
        once: (event: string) => new Promise((resolve) => waiting.set(event, [...(waiting.get(event) ?? []), resolve])),
        close: async () => {
            socket.close();
            const exited = new Promise((resolve) => chrome.once('exit', resolve));
            chrome.kill();
            await exited;
            // Chrome's helper processes may still write to the profile after the main one exited; a leftover temp
            // profile is harmless, so cleaning up never fails the suite
            try {
                rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
            } catch {
                // left to the OS's temp cleanup
            }
        },
    };
}

/** Runs JavaScript in the page (top-level await allowed) and returns its value. */
export async function evaluate(browser: Browser, code: string): Promise<any> {
    const result = await browser.send('Runtime.evaluate', {
        expression: `(async () => { ${code} })()`,
        awaitPromise: true,
        returnByValue: true,
    });
    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
    }
    return result.result?.value;
}

export async function open(browser: Browser, url: string): Promise<void> {
    const loaded = browser.once('Page.loadEventFired');
    await browser.send('Page.navigate', { url });
    await loaded;
    await sleep(800);
}

export async function resize(browser: Browser, size: Size): Promise<void> {
    await browser.send('Emulation.setDeviceMetricsOverride', {
        width: size.width, height: size.height, deviceScaleFactor: size.scale ?? 1, mobile: false,
    });
}

/** A fresh control panel session for this user. */
export async function login(browser: Browser, baseUrl: string, username: string, password: string): Promise<void> {
    await browser.send('Network.clearBrowserCookies');
    await open(browser, `${baseUrl}/admin/login`);
    const status = await evaluate(browser, `
        const response = await fetch(Craft.getActionUrl('users/login'), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': Craft.csrfTokenValue },
            body: JSON.stringify({ loginName: ${JSON.stringify(username)}, password: ${JSON.stringify(password)} }),
        });
        return response.status;
    `);
    if (status !== 200) {
        throw new Error(`Login as ${username} failed (HTTP ${status})`);
    }
}

/** Page helpers for the control panel, defined once per page load (window.*). */
export const PAGE_HELPERS = `
window.sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
window.until = async (test, what, timeout = 15000) => {
    const start = Date.now();
    while (!test()) {
        if (Date.now() - start > timeout) throw new Error('Timed out waiting for ' + what);
        await sleep(100);
    }
};
window.$$ = (selector, root = document) => [...root.querySelectorAll(selector)].filter((e) => e.getClientRects().length > 0);
/**
 * The plugin's switch and the slug field on Craft 6's editor (a Vue form of web components): the slug field
 * (\`craft-field#form-slug\`), the switch's field right after it, its button (\`role="switch"\`) and hidden input.
 */
window.slugUi = () => {
    const slugField = document.querySelector('craft-field#form-slug');
    const field = document.querySelector('craft-field#form-slugEqualsTitle_shouldRewrite');
    // the plugin's script moves the switch out of its field into the slug field's input
    const button = document.querySelector('craft-switch[name="slugEqualsTitle_shouldRewrite"] [role="switch"]');
    return {
        slugField,
        field,
        button,
        on: button?.getAttribute('aria-checked') === 'true',
        slugInput: slugField?.querySelector('input') ?? null,
        titleInput: document.querySelector('craft-field#form-title input') ?? null,
        /** the switch sits inside the slug field, next to its input */
        inSlugField: !!slugField && !!button && slugField.contains(button),
        /** the input is out of the way while the switch is on; the note takes its place */
        inputHidden: !!(slugField?.querySelector('craft-input')?.hidden),
        note: slugField?.querySelector('.slug-equals-title-row > span:not([hidden])')?.textContent ?? null,
    };
};
/** Types into an input like a person (the form listens for \`input\`). */
window.type = (input, text) => {
    input.focus();
    input.value = text;
    input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
};
/** Saves the page's form (the primary button) and waits for the editor's notice. */
window.save = async () => {
    const before = notices().length;
    const button = [...document.querySelectorAll('craft-button[type="submit"]')].find((b) => /^\s*Save\s*$/.test(b.textContent));
    if (!button) throw new Error('No Save button');
    button.click();
    await until(() => notices().some((n) => /saved/i.test(n)) || notices().length > before, 'the save notice');
    await sleep(500);
};
/** Texts of the notices on the page. */
window.notices = () => $$('.notification, craft-notification, craft-toast, [role="status"]').map((n) => n.textContent.replace(/\\s+/g, ' ').trim());
window.confirm = () => true;
// trial and dev mode notices are not part of what the plugin looks like
const style = document.createElement('style');
style.textContent = '#trial-info, #devmode { display: none !important; }';
document.head.append(style);
`;
