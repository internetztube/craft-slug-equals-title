<?php

declare(strict_types=1);

/**
 * The Craft 6 control panel over HTTP, against a running dev server (dev/dev.sh e2e): logs in, reads the edit pages
 * (the Inertia editor's page JSON) and saves elements, drafts and settings the way the control panel does (the same
 * action requests and params). Catches what the console smoke cannot: the switch as a node of the sidebar form, its
 * param on its way through Craft's controllers, drafts and slideouts, what the settings page posts, the revision view.
 *
 *   php dev/e2e.php <projectDir> [baseUrl]   (default http://localhost:8130, admin / password)
 */

require __DIR__ . '/craft.php';

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\DB;
use Internetztube\SlugEqualsTitle\SlugEqualsTitle;
use Internetztube\SlugEqualsTitle\Slugs;

$base = rtrim($argv[2] ?? 'http://localhost:8130', '/');

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/** Minimal HTTP client with a cookie jar and Laravel's XSRF token, like the control panel's JavaScript. */
final class Http
{
    public string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'e2e-cookies');
    }

    /** Laravel's CSRF token, from the XSRF-TOKEN cookie the last response set. */
    private function xsrf(): ?string
    {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode("\t", $line);
            if (($parts[5] ?? '') === 'XSRF-TOKEN') {
                return urldecode($parts[6] ?? '');
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string> $headers
     * @return array{0: int, 1: string, 2: array<string, mixed>} status, body, decoded JSON (or [])
     */
    public function request(string $method, string $path, array $data = [], bool $json = true, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers[] = 'X-Requested-With: XMLHttpRequest';
        if ($json) {
            $headers[] = 'Accept: application/json';
        }
        if ($token = $this->xsrf()) {
            $headers[] = 'X-XSRF-TOKEN: ' . $token;
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'slug-equals-title-e2e',
        ]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = $json ? (json_decode($body, true) ?: []) : [];
        return [$status, $body, is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed> $data */
    public function action(string $route, array $data = [], string $method = 'POST', array $headers = []): array
    {
        $query = $method === 'GET' && $data ? '?' . http_build_query($data) : '';
        return $this->request($method, '/admin/actions/' . $route . $query, $data, true, $headers);
    }

    /** A control panel page: its HTML and, for an Inertia page, the page JSON. */
    public function page(string $path): array
    {
        [$status, $html] = $this->request('GET', $path, [], false);
        $json = preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m) ? (json_decode($m[1], true) ?: []) : [];
        return [$status, $html, is_array($json) ? $json : []];
    }
}

/** The nodes of a form payload, flattened: [path (dot-joined), control component, label, mode, props]. */
function nodes(?array $form): array
{
    $out = [];
    $walk = function (array $nodes) use (&$walk, &$out): void {
        foreach ($nodes as $node) {
            $control = $node['control'] ?? null;
            $out[] = ['path' => is_array($control) ? implode('.', $control['path'] ?? []) : null, 'component' => $control['component'] ?? null, 'label' => $node['props']['label'] ?? null, 'mode' => $control['mode'] ?? null, 'props' => $control['props'] ?? [], 'node' => $node['props'] ?? []];
            if (!empty($node['children'])) {
                $walk($node['children']);
            }
        }
    };
    $walk($form['nodes'] ?? []);
    return $out;
}

$slugs = app(Slugs::class);
$de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('Run the seed first');
$newsSection = Sections::getSectionByHandle('news') ?? throw new RuntimeException('Run the seed first');
$pagesSection = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$landing = EntryTypes::getEntryTypeByHandle('landingPage') ?? throw new RuntimeException('Run the seed first');
$reload = fn(ElementInterface $element, ?int $siteId = null): ElementInterface => $element::find()->id($element->id)->siteId($siteId ?? $element->siteId)->status(null)->drafts(null)->one() ?? throw new RuntimeException("Element $element->id is gone");
$rows = fn(ElementInterface $element): array => DB::table(Slugs::TABLE)->where('elementId', $element->id)->pluck('enabled')->map(fn($v): bool => (bool)$v)->all();
$editPath = fn(ElementInterface $element): string => '/admin/entries/' . $element->getSection()?->handle . '/' . $element->id . ($element->siteId === $de->id ? '?site=de' : '');
/** Our switch among a page's sidebar form nodes, and the slug node before it. */
$switchNode = function (array $page): array {
    $nodes = nodes($page['props']['sidebarForm'] ?? null);
    $slugAt = null;
    $switchAt = null;
    foreach ($nodes as $i => $node) {
        if ($node['path'] === 'slug') {
            $slugAt = $i;
        }
        if ($node['path'] === SlugEqualsTitle::PARAM) {
            $switchAt = $i;
        }
    }
    return ['slugAt' => $slugAt, 'switchAt' => $switchAt, 'node' => $switchAt !== null ? $nodes[$switchAt] : null, 'on' => $page['props']['sidebarForm']['values'][SlugEqualsTitle::PARAM] ?? null];
};

// --- fixtures (leftovers of an aborted run first)
echo "Fixtures\n";
foreach (Entry::find()->title('E2E:*')->status(null)->drafts(null)->provisionalDrafts(null)->siteId('*')->unique()->all() as $old) {
    Elements::deleteElement($old, true);
}
$make = function(string $section, string $title, array $attributes = []): Entry {
    $sectionModel = Sections::getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
    $entry = new Entry($attributes + ['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title]);
    Elements::saveElement($entry) or throw new RuntimeException(json_encode($entry->errors()->all()));
    return $entry;
};
$news = $make('news', 'E2E: News entry');
$page = $make('pages', 'E2E: Page', ['slug' => 'e2e-page']);
check($news->slug === 'e2e-news-entry' && $page->slug === 'e2e-page', 'a News entry (on by default) and a Pages entry (off) with its own slug');

// --- login like the control panel does
echo "Login\n";
$http = new Http($base);
[$status] = $http->request('GET', '/admin/login', [], false);
check($status === 200, "the login page gives a session and a CSRF cookie (HTTP $status)");
[$status, $body, $login] = $http->action('users/login', ['loginName' => 'admin', 'password' => 'password']);
check($status === 200 && ($login['user']['username'] ?? '') === 'admin', "login succeeds (HTTP $status: " . substr($body, 0, 120) . ')');

// --- the edit page: Craft 6's Inertia editor renders the sidebar as a form; the switch is one of its fields
echo "Edit page\n";
[$status, , $json] = $http->page($editPath($news));
check($status === 200 && ($json['component'] ?? '') === 'elements/Edit', "the News entry's edit page renders (HTTP $status)");
$s = $switchNode($json);
check($s['node'] !== null && $s['node']['component'] === 'craft:lightswitch' && $s['node']['label'] === 'Slug equals title', 'its sidebar form has the "Slug equals title" lightswitch');
check($s['slugAt'] !== null && $s['switchAt'] === $s['slugAt'] + 1, 'right after the slug field');
check($s['on'] === true && $s['node']['mode'] === 'editable', 'on, editable');
[$status, , $json] = $http->page($editPath($page));
$s = $switchNode($json);
check($status === 200 && $s['node'] !== null && $s['on'] === false, 'the Pages entry\'s edit page has the switch off');
[$status, , $json] = $http->page($editPath($reload($news, $de->id)));
$s = $switchNode($json);
check($status === 200 && $s['node'] !== null && $s['on'] === true, 'the German site\'s edit page has the switch too (the choice is per entry, not per site)');
$home = Entry::find()->section('home')->status(null)->one() ?? throw new RuntimeException('No Home entry (run the seed)');
[$status, , $json] = $http->page($editPath($home));
$s = $switchNode($json);
check($status === 200 && $s['slugAt'] === null && $s['node'] === null, 'the single\'s edit page has no slug field (its entry type hides it) and so no switch');
$revision = Entry::find()->revisionOf($news)->status(null)->orderBy('elements.dateCreated', 'desc')->one() ?? throw new RuntimeException('No revision');
[$status, , $json] = $http->page($editPath($news) . '?revisionId=' . $revision->revisionId);
$s = $switchNode($json);
check($status === 200 && $s['node'] !== null && $s['node']['mode'] !== 'editable', 'a revision shows the switch read-only (' . var_export($s['node']['mode'] ?? null, true) . ')');

// --- saving like the editor does: elements/save with the form's values
echo "Saving\n";
/** The editor's own save: a provisional draft with the changed values, then applying it (no switch unless given). */
$editorSave = function (Entry $entry, array $params) use ($http): array {
    [$status, $body, $json] = $http->action('elements/save-draft', $params + ['elementId' => $entry->getCanonicalId(), 'siteId' => $entry->siteId, 'provisional' => 1]);
    if ($status !== 200 || empty($json['draftId'])) {
        return [$status, $body, $json];
    }
    return $http->action('elements/apply-draft', $params + ['elementId' => $entry->getCanonicalId(), 'siteId' => $entry->siteId, 'draftId' => $json['draftId'], 'provisional' => 1]);
};
$saveEntry = fn(Entry $entry, array $params) => $http->action('elements/save', $params + ['elementId' => $entry->getCanonicalId(), 'siteId' => $entry->siteId, 'title' => $reload($entry)->title, 'slug' => $reload($entry)->slug]);
[$status, $body, $json] = $saveEntry($news, ['title' => 'E2E: News renamed', 'slug' => 'e2e-typed-by-hand', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']), "saving the News entry with the switch on succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
check($reload($news)->slug === 'e2e-news-renamed', "the slug follows the new title, whatever the slug input held (title: {$reload($news)->title}, slug: {$reload($news)->slug})");
check($rows($news) === [true], 'and the choice is remembered (on)');
check($reload($news, $de->id)->slug === 'e2e-news-entry' && count($rows($news)) === 1, 'the German site keeps its slug (titles are per site; it still has the old one); one row for the entry, not one per site');
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News renamed', 'slug' => 'e2e-my-own', SlugEqualsTitle::PARAM => false]);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-my-own', 'with the switch off (the editor posts false), the slug typed into the form is kept');
check(!$slugs->isEnabled($reload($news)), 'and the choice is remembered (off)');
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News renamed again']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-my-own', 'a save without the switch in the request follows the remembered choice: off, so the slug stays');
// Craft 6's editor saves through the draft actions and posts only what changed: a switch left as it was is not in
// the request, and the save follows what it showed
[$status, , $json] = $editorSave($news, ['title' => 'E2E: News renamed by the editor', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-news-renamed-by-the-editor', 'the editor (a provisional draft, then applied) switches the entry on again');
[$status, , $json] = $editorSave($news, ['title' => 'E2E: News renamed by the editor again']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-news-renamed-by-the-editor-again', 'an editor save without the switch in the request (unchanged, so not posted) follows the remembered choice: the slug follows the title');
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News saved directly']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-news-saved-directly', "the editor's direct save (elements/save with only the changed values, when nothing was autosaved before) follows it too");
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News renamed', 'slug' => 'e2e-my-own', SlugEqualsTitle::PARAM => false]);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-my-own', 'switched off again');
[$status, , $json] = $saveEntry($page, ['title' => 'E2E: Page renamed', SlugEqualsTitle::PARAM => true]);
check($status === 200 && empty($json['errors']) && $reload($page)->slug === 'e2e-page-renamed', 'the Pages entry switched on follows its title although its section is off');
[$status, , $json] = $saveEntry($page, ['title' => 'E2E: Page renamed twice']);
check($status === 200 && empty($json['errors']) && $reload($page)->slug === 'e2e-page-renamed-twice', 'a web save without the switch in the request follows the remembered choice: on, so the slug follows the title');

// --- drafts, as the editor autosaves and publishes them
echo "Drafts\n";
[$status, $body, $json] = $http->action('elements/save-draft', ['elementId' => $news->id, 'siteId' => $news->siteId, 'title' => 'E2E: Draft title', 'slug' => 'e2e-my-own', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && !empty($json['draftId']), "autosaving a draft with the switch on succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
$draft = Entry::find()->draftId($json['draftId'])->status(null)->one() ?? throw new RuntimeException('No draft');
check($draft->slug === 'e2e-draft-title', 'the draft\'s slug follows the draft\'s title');
check($rows($draft) === [true], 'the draft remembers its own choice');
check(!$slugs->isEnabled($reload($news)), 'the canonical entry keeps its own (off)');
[$status, $body, $json] = $http->action('elements/apply-draft', ['elementId' => $news->id, 'draftId' => $draft->draftId, 'siteId' => $news->siteId, 'title' => 'E2E: Published title', 'slug' => 'e2e-draft-title', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']), "publishing the draft succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
$published = $reload($news);
check($published->title === 'E2E: Published title' && $published->slug === 'e2e-published-title' && $slugs->isEnabled($published), 'the canonical entry has the draft\'s title, a slug from it, and the switch on');
// the editor's own flow for an entry that never made a choice (no row): a provisional draft, then apply, both without
// the switch (left as it was, so not posted): the section's default decides
$fresh = $make('news', 'E2E: Fresh entry');
[$status, $body, $json] = $http->action('elements/save-draft', ['elementId' => $fresh->id, 'siteId' => $fresh->siteId, 'title' => 'E2E: Fresh entry renamed', 'provisional' => 1]);
$freshDraft = !empty($json['draftId']) ? Entry::find()->draftId($json['draftId'])->provisionalDrafts(null)->status(null)->one() : null;
check($status === 200 && $freshDraft !== null && $freshDraft->slug === 'e2e-fresh-entry-renamed', "the editor's provisional draft of an entry without a remembered choice follows the title (the section's default; HTTP $status, slug " . ($freshDraft?->slug ?? '-') . ': ' . substr($body, 0, 150) . ')');
[$status, $body, $json] = $http->action('elements/apply-draft', ['elementId' => $fresh->id, 'draftId' => $freshDraft?->draftId, 'siteId' => $fresh->siteId, 'title' => 'E2E: Fresh entry renamed', 'provisional' => 1]);
check($status === 200 && empty($json['errors']) && $reload($fresh)->slug === 'e2e-fresh-entry-renamed', "and applying it gives the entry that slug (HTTP $status, slug " . $reload($fresh)->slug . ': ' . substr($body, 0, 150) . ')');
Elements::deleteElement($fresh, true);

// --- the slideout editor (Craft's legacy editor, HTML): the switch next to the slug field, with its script
// the editor of an element the plugin does not support (the user's account) is left as it is: no switch
[$status, , $json] = $http->page('/admin/myaccount');
check($status === 200 && $switchNode($json)['switchAt'] === null, 'the editor of an element the plugin does not support (the user account) has no switch (' . (isset($json['props']['sidebarForm']) ? 'sidebar form' : 'no sidebar form') . ')');

echo "Slideout\n";
$slideout = ['X-Craft-Container-Id: e2e-slideout', 'X-Craft-Container-Type: slideout'];
[$status, $body, $json] = $http->action('elements/edit', ['elementType' => Entry::class, 'elementId' => $page->id, 'siteId' => $page->siteId], 'GET', $slideout);
check($status === 200 && !empty($json['sidebar']), "the slideout screen renders (HTTP $status: " . substr($body, 0, 200) . ')');
check(preg_match('/data-slug-equals-title[^>]*>.*?role="switch"[^>]*aria-checked="true"/s', (string)$json['sidebar']) === 1 && str_contains((string)$json['sidebar'], 'name="' . $json['namespace'] . '[' . SlugEqualsTitle::PARAM . ']"'), 'its sidebar has the switch (on: remembered) with its input, namespaced like the rest of the slideout');
check(str_contains((string)($json['bodyHtml'] ?? ''), "querySelectorAll('[data-slug-equals-title]"), 'and the script that moves it into the slug field comes with the screen');
$page->setFieldValue('blocks', ['new1' => ['type' => 'textBlock', 'enabled' => true, 'title' => 'E2E: A block', 'fields' => ['body' => 'x']]]);
Elements::saveElement($page) or throw new RuntimeException(json_encode($page->errors()->all()));
$blocksField = Fields::getFieldByHandle('blocks') ?? throw new RuntimeException('No blocks field');
$block = Entry::find()->ownerId($page->id)->fieldId($blocksField->id)->status(null)->one() ?? throw new RuntimeException('No block');
[$status, $body, $json] = $http->action('elements/edit', ['elementType' => Entry::class, 'elementId' => $block->id, 'siteId' => $block->siteId], 'GET', $slideout);
check($status === 200 && isset($json['sidebar']) && !str_contains((string)$json['sidebar'], 'data-slug-equals-title'), "a Matrix block's slideout (a nested entry, no section) has no switch (HTTP $status)");
[$status, , $json] = $http->page((string)parse_url((string)$block->getCpEditUrl(), PHP_URL_PATH));
check($status === 200 && $switchNode($json)['switchAt'] === null, "a Matrix block's own edit page (Craft's generic element editor) has no switch either (HTTP $status)");

// --- the settings page: what it shows and what it saves (last: it writes project config)
echo "Settings page\n";
[$status, , $json] = $http->page('/admin/settings/plugins/slug-equals-title');
check($status === 200 && !empty($json['props']['form']), "the settings page renders (HTTP $status)");
$form = $json['props']['form'];
$byPath = [];
foreach (nodes($form) as $node) {
    $byPath[$node['path']] = $node;
}
$sections = $byPath['settings.enabledSections'] ?? null;
$optionValues = array_column($sections['props']['options'] ?? [], 'value', 'label');
check($sections !== null && $sections['component'] === 'craft:choice' && ($optionValues['News'] ?? null) === $newsSection->uid && ($optionValues['Pages'] ?? null) === $pagesSection->uid, 'Sections are listed by UID');
check(($optionValues['↳ Landing page'] ?? null) === "$pagesSection->uid:$landing->uid" && !isset($optionValues['↳ Article']), 'the entry types of Pages (two of them) are listed under it, by "section:entryType" UIDs; News with one entry type lists none');
check(($form['values']['settings']['enabledSections'] ?? null) === [$newsSection->uid], 'News is ticked, Pages not');
// config/craft/slug-equals-title.php wins over the stored settings: named by handle, read-only on the page
$configFile = $projectDir . '/config/craft/slug-equals-title.php';
@mkdir(dirname($configFile));
file_put_contents($configFile, "<?php\n\nreturn ['enabledSections' => ['news', 'pages:landingPage']];\n");
try {
    [$status, , $json] = $http->page('/admin/settings/plugins/slug-equals-title');
    $byPath = [];
    foreach (nodes($json['props']['form'] ?? null) as $node) {
        $byPath[$node['path']] = $node;
    }
    $sections = $byPath['settings.enabledSections'] ?? null;
    $optionValues = array_column($sections['props']['options'] ?? [], 'value', 'label');
    check($status === 200 && $sections !== null && $sections['mode'] === 'disabled' && str_contains((string)($sections['node']['warning'] ?? ''), 'config/craft/slug-equals-title.php'), 'with the config file in place, Sections is read-only with a note (' . json_encode($sections['node'] ?? null) . ')');
    check(($optionValues['News'] ?? null) === 'news' && ($optionValues['↳ Landing page'] ?? null) === 'pages:landingPage' && ($optionValues['Pages'] ?? null) === $pagesSection->uid && ($json['props']['form']['values']['settings']['enabledSections'] ?? null) === ['news', 'pages:landingPage'], 'News and Landing page (named by handle in the file) are ticked, Pages (by UID) is not');
    $landingEntry = $make('pages', 'E2E: Landing', ['typeId' => $landing->id, 'slug' => 'e2e-landing']);
    [$status, , $json] = $editorSave($landingEntry, ['title' => 'E2E: Landing renamed']);
    check($status === 200 && empty($json['errors']) && $reload($landingEntry)->slug === 'e2e-landing-renamed', 'a Landing page entry follows its title: the file turned its entry type on');
    Elements::deleteElement($landingEntry, true);
} finally {
    unlink($configFile);
}
[$status, $body, $json] = $http->request('POST', '/admin/settings/plugins/slug-equals-title', ['settings' => ['enabledSections' => [$newsSection->uid, "$pagesSection->uid:$landing->uid"]]]);
check($status === 200, "saving the settings succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
$stored = ProjectConfig::get('plugins.slug-equals-title.settings', true);
check(($stored['enabledSections'] ?? null) === [$newsSection->uid, "$pagesSection->uid:$landing->uid"], 'the ticked section and entry type are stored by UID');
[$status] = $http->request('POST', '/admin/settings/plugins/slug-equals-title', ['settings' => ['enabledSections' => [$newsSection->uid]]]);
check($status === 200, 'the seeded settings are back');

// --- cleanup
foreach ([$news, $page] as $entry) {
    Elements::deleteElement($entry, true);
}
unlink($http->jar);
echo "All ok\n";
