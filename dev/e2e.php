<?php

declare(strict_types=1);

/**
 * The control panel over HTTP, against a running dev server (dev/dev.sh e2e): logs in, renders edit pages, and saves
 * elements, drafts and settings the way the control panel's JavaScript does (the same action requests and params).
 * Catches what the console smoke cannot: the switch's param on its way through Craft's controllers, drafts and
 * slideouts, what the settings page posts, the revision view.
 *
 *   php dev/e2e.php <projectDir> [baseUrl]   (default http://localhost:8080, admin / password)
 */

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use internetztube\slugEqualsTitle\services\SlugService;
use internetztube\slugEqualsTitle\SlugEqualsTitle;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/e2e.php <craft-project-dir> [base-url]');
require $projectDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$base = rtrim($argv[2] ?? 'http://localhost:8080', '/');

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/** Minimal HTTP client with a cookie jar and Craft's CSRF token, like the control panel's JavaScript. */
final class Http
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'e2e-cookies');
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
        if ($this->csrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($method === 'POST') {
            // JSON body, like Craft.sendActionRequest() in the control panel (keeps true/false/null as such)
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
            CURLOPT_USERAGENT => 'slug-equals-title-e2e', // Craft refuses sessions without a user agent
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
        // like the control panel's JS: a CP action request; a GET (a screen for a slideout) carries its params in the URL
        $query = $method === 'GET' && $data ? '&' . http_build_query($data) : '';
        return $this->request($method, '/index.php?p=admin/actions/' . $route . $query, $data, true, $headers);
    }

    public function page(string $path): array
    {
        return $this->request('GET', $path, [], false);
    }
}

$elements = Craft::$app->getElements();
$plugin = SlugEqualsTitle::getInstance();
$slugs = $plugin->slugs;
$de = Craft::$app->getSites()->getSiteByHandle('de') ?? throw new RuntimeException('Run the seed first');
$commerce = Craft::$app->getPlugins()->isPluginEnabled('commerce');
$newsUid = Craft::$app->getEntries()->getSectionByHandle('news')?->uid ?? throw new RuntimeException('Run the seed first');
$pagesUid = Craft::$app->getEntries()->getSectionByHandle('pages')?->uid ?? throw new RuntimeException('Run the seed first');
$landingUid = Craft::$app->getEntries()->getEntryTypeByHandle('landingPage')?->uid ?? throw new RuntimeException('Run the seed first');
$topicsUid = Craft::$app->getCategories()->getGroupByHandle('topics')?->uid ?? throw new RuntimeException('Run the seed first');
$clothingUid = $commerce ? \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeByHandle('clothing')->uid : null;
$reload = fn(ElementInterface $element, ?int $siteId = null): ElementInterface => $element::find()->id($element->id)->siteId($siteId ?? $element->siteId)->status(null)->drafts(null)->one() ?? throw new RuntimeException("Element $element->id is gone");
$rows = fn(ElementInterface $element): array => (new Query())->select(['enabled'])->from(SlugService::TABLE)->where(['elementId' => $element->id])->column();
$editPath = function(ElementInterface $element): string {
    $url = $element->getCpEditUrl() ?? throw new RuntimeException("$element has no edit page");
    return (string)parse_url($url, PHP_URL_PATH) . (($query = parse_url($url, PHP_URL_QUERY)) ? "?$query" : '');
};

// --- fixtures (leftovers of an aborted run first)
echo "Fixtures\n";
foreach (Entry::find()->title('E2E:*')->status(null)->drafts(null)->provisionalDrafts(null)->siteId('*')->unique()->all() as $old) {
    $elements->deleteElement($old, true);
}
foreach (Category::find()->title('E2E:*')->status(null)->siteId('*')->unique()->all() as $old) {
    $elements->deleteElement($old, true);
}
$make = function(string $section, string $title, array $attributes = []) use ($elements): Entry {
    $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
    $entry = new Entry(['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title] + $attributes);
    $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    return $entry;
};
$news = $make('news', 'E2E: News entry');
$page = $make('pages', 'E2E: Page', ['slug' => 'e2e-page']);
check($news->slug === 'e2e-news-entry' && $page->slug === 'e2e-page', 'a News entry (on by default) and a Pages entry (off) with its own slug');

// --- login like the control panel does
echo "Login\n";
$http = new Http($base);
[$status, , $info] = $http->action('users/session-info', [], 'GET'); // a POST without a token is rejected by CSRF validation
check($status === 200 && !empty($info['csrfTokenValue']), "session-info gives a CSRF token (HTTP $status)");
$http->csrf = $info['csrfTokenValue'];
[$status, $body, $login] = $http->action('users/login', ['loginName' => 'admin', 'password' => 'password']);
check($status === 200 && !empty($login['success'] ?? $login['returnUrl'] ?? null), "login succeeds (HTTP $status: " . substr($body, 0, 120) . ')');
[$status, , $info] = $http->action('users/session-info', [], 'GET');
check(($info['isGuest'] ?? true) === false && ($info['username'] ?? '') === 'admin', 'session belongs to admin');
$http->csrf = $info['csrfTokenValue'] ?? $http->csrf;

// --- the edit page
echo "Edit page\n";
/** The switch as rendered: on, off, or null when it is not on the page. */
$switchOn = function(string $html): ?bool {
    if (!preg_match('/<div[^>]*data-slug-equals-title[^>]*>.*?<button[^>]*class="lightswitch[^"]*"[^>]*aria-checked="(true|false)"/s', $html, $m)) {
        return null;
    }
    return $m[1] === 'true';
};
[$status, $html] = $http->page($editPath($news));
check($status === 200, "the News entry's edit page renders (HTTP $status)");
check($switchOn($html) === true, 'it has the "Slug equals title" switch, on');
check(str_contains($html, 'name="' . SlugEqualsTitle::PARAM . '"') && str_contains($html, "querySelectorAll('[data-slug-equals-title]"), 'with the switch\'s input and the script that moves it into the slug field');
check(str_contains($html, 'data-slug-equals-title="Overwritten by title"'), 'and the text shown in place of the slug while the switch is on');
[$status, $html] = $http->page($editPath($page));
check($status === 200 && $switchOn($html) === false, 'the Pages entry\'s edit page has the switch off');
[$status, $html] = $http->page($editPath($reload($news, $de->id)));
check($status === 200 && $switchOn($html) === true, 'the German site\'s edit page has the switch too (the choice is per entry, not per site)');
$home = Entry::find()->section('home')->status(null)->one() ?? throw new RuntimeException('No Home entry (run the seed)');
[$status, $html] = $http->page($editPath($home));
check($status === 200 && $switchOn($html) === false, 'the single\'s edit page renders the switch too (its entry type hides the slug field; the script removes the switch with it)');
$revision = Entry::find()->revisionOf($news)->status(null)->orderBy(['dateCreated' => SORT_DESC])->one() ?? throw new RuntimeException('No revision');
[$status, $html] = $http->page($editPath($news) . (str_contains($editPath($news), '?') ? '&' : '?') . 'revisionId=' . $revision->revisionId);
$readOnly = preg_match('/<div[^>]*data-slug-equals-title[^>]*>.*?<button[^>]*class="lightswitch[^"]*noteditable/s', $html) === 1;
check($status === 200 && $readOnly, "a revision shows the switch read-only (HTTP $status, switch: " . var_export($switchOn($html), true) . ($status === 200 ? '' : ': ' . preg_replace('/\s+/', ' ', substr(strip_tags($html), 0, 400))) . ')');

// --- saving like the edit page does: elements/save with the form's params
echo "Saving\n";
// the form's fields as the edit page would post them now (its current title and slug), overridden by $params
$saveEntry = fn(Entry $entry, array $params) => $http->action('elements/save', $params + ['elementId' => $entry->getCanonicalId(), 'siteId' => $entry->siteId, 'title' => $reload($entry)->title, 'slug' => $reload($entry)->slug]);
[$status, $body, $json] = $saveEntry($news, ['title' => 'E2E: News renamed', 'slug' => 'e2e-typed-by-hand', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']), "saving the News entry with the switch on succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
check($reload($news)->slug === 'e2e-news-renamed', "the slug follows the new title, whatever the slug input held (title: {$reload($news)->title}, slug: {$reload($news)->slug}, rows: " . json_encode($rows($news)) . ', response: ' . substr($body, 0, 300) . ')');
check($rows($news) === ['1'] || $rows($news) === [true] || $rows($news) === [1], 'and the choice is remembered (on)');
check($reload($news, $de->id)->slug === 'e2e-news-entry' && count($rows($news)) === 1, 'the German site keeps its slug (titles are per site; it still has the old one); one row for the entry, not one per site');
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News renamed', 'slug' => 'e2e-my-own', SlugEqualsTitle::PARAM => '']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-my-own', 'with the switch off, the slug typed into the form is kept');
check(!$slugs->isEnabled($reload($news)), 'and the choice is remembered (off)');
[$status, , $json] = $saveEntry($news, ['title' => 'E2E: News renamed again']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-my-own', 'a web save without the switch (another form, an index action) leaves the slug alone');
[$status, , $json] = $saveEntry($page, ['title' => 'E2E: Page renamed', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']) && $reload($page)->slug === 'e2e-page-renamed', 'the Pages entry switched on follows its title although its section is off');
[$status, , $json] = $saveEntry($page, ['title' => 'E2E: Page renamed twice']);
check($status === 200 && empty($json['errors']) && $reload($page)->slug === 'e2e-page-renamed', 'a web save without the switch leaves the slug alone even with the switch on (only the console follows the remembered choice)');
$categoryGroup = Craft::$app->getCategories()->getGroupByHandle('regions') ?? throw new RuntimeException('No Regions group');
$category = new Category(['groupId' => $categoryGroup->id, 'title' => 'E2E: Region', 'slug' => 'e2e-region']);
$elements->saveElement($category) or throw new RuntimeException(json_encode($category->getErrors()));
[$status, $html] = $http->page($editPath($category));
check($status === 200 && $switchOn($html) === false, 'a category\'s edit page has the switch (off: its group is off)');
[$status, , $json] = $http->action('elements/save', ['elementId' => $category->id, 'siteId' => $category->siteId, 'title' => 'E2E: Region renamed', 'slug' => 'e2e-region', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']) && $reload($category)->slug === 'e2e-region-renamed', 'a category saved with the switch on follows its title');
if ($commerce) {
    $product = \craft\commerce\elements\Product::find()->type('clothing')->status(null)->one() ?? throw new RuntimeException('No product (run the seed)');
    [$status, $html] = $http->page($editPath($product));
    check($status === 200 && $switchOn($html) === true, 'a product\'s edit page has the switch (on: its type is on)');
}

// --- drafts, as the edit page autosaves and publishes them
echo "Drafts\n";
[$status, $body, $json] = $http->action('elements/save-draft', ['elementId' => $news->id, 'siteId' => $news->siteId, 'title' => 'E2E: Draft title', 'slug' => 'e2e-my-own', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && !empty($json['draftId']), "autosaving a draft with the switch on succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
$draft = Entry::find()->draftId($json['draftId'])->status(null)->one() ?? throw new RuntimeException('No draft');
check($draft->slug === 'e2e-draft-title', 'the draft\'s slug follows the draft\'s title');
check($rows($draft) === ['1'] || $rows($draft) === [true] || $rows($draft) === [1], 'the draft remembers its own choice');
check(!$slugs->isEnabled($reload($news)), 'the canonical entry keeps its own (off)');
[$status, $body, $json] = $http->action('elements/apply-draft', ['elementId' => $news->id, 'draftId' => $draft->draftId, 'siteId' => $news->siteId, 'title' => 'E2E: Published title', 'slug' => 'e2e-draft-title', SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']), "publishing the draft succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
$published = $reload($news);
check($published->title === 'E2E: Published title' && $published->slug === 'e2e-published-title' && $slugs->isEnabled($published), 'the canonical entry has the draft\'s title, a slug from it, and the switch on');

// --- the slideout editor (Craft's element editor in a slideout asks for the same screen as JSON)
echo "Slideout\n";
[$status, $body, $json] = $http->action('elements/edit', ['elementType' => Entry::class, 'elementId' => $page->id, 'siteId' => $page->siteId], 'GET', ['X-Craft-Namespace: e2e-slideout', 'X-Craft-Container-Id: e2e-slideout', 'X-Craft-Container-Type: slideout']);
check($status === 200 && !empty($json['sidebar']), "the slideout screen renders (HTTP $status: " . substr($body, 0, 200) . ')');
check($switchOn((string)$json['sidebar']) === true && str_contains((string)$json['sidebar'], 'name="' . $json['namespace'] . '[' . SlugEqualsTitle::PARAM . ']"'), 'its sidebar has the switch (on: remembered) with its input, namespaced like the rest of the slideout (Craft strips it on submit)');
check(str_contains((string)($json['bodyHtml'] ?? $json['html'] ?? '') . (string)($json['headHtml'] ?? ''), "querySelectorAll('[data-slug-equals-title]"), 'and the script comes with the screen');
$page->setFieldValue('blocks', ['new1' => ['type' => 'textBlock', 'enabled' => true, 'title' => 'E2E: A block', 'fields' => []]]);
$elements->saveElement($page) or throw new RuntimeException(json_encode($page->getErrors()));
$block = Entry::find()->ownerId($page->id)->field('blocks')->status(null)->one() ?? throw new RuntimeException('No block');
[$status, $body, $json] = $http->action('elements/edit', ['elementType' => Entry::class, 'elementId' => $block->id, 'siteId' => $block->siteId], 'GET', ['X-Craft-Namespace: e2e-block', 'X-Craft-Container-Id: e2e-block', 'X-Craft-Container-Type: slideout']);
check($status === 200 && isset($json['sidebar']) && $switchOn((string)$json['sidebar']) === null, "a Matrix block's slideout (a nested entry, no section) has no switch (HTTP $status)");

// --- the settings page: what it shows and what it saves (last: it writes project config)
echo "Settings page\n";
[$status, $html] = $http->page('/admin/settings/plugins/slug-equals-title');
check($status === 200, "the settings page renders (HTTP $status)");
$box = fn(string $name, string $value): ?string => preg_match('/<input[^>]*name="settings\[' . preg_quote($name, '/') . '\]\[\]"[^>]*value="' . preg_quote($value, '/') . '"[^>]*>/', $html, $m) || preg_match('/<input[^>]*value="' . preg_quote($value, '/') . '"[^>]*name="settings\[' . preg_quote($name, '/') . '\]\[\]"[^>]*>/', $html, $m) ? $m[0] : null;
check(str_contains((string)$box('enabledSections', $newsUid), 'checked') && $box('enabledSections', $pagesUid) !== null && !str_contains((string)$box('enabledSections', $pagesUid), 'checked'), 'Sections are listed by UID: News ticked, Pages not');
check($box('enabledSections', "$pagesUid:$landingUid") !== null && !str_contains($html, "value=\"$newsUid:"), 'the entry types of Pages (two of them) are listed under it, by "section:entryType" UIDs; News with one entry type lists none');
check(preg_match('/<input[^>]*value="' . preg_quote($newsUid, '/') . '"[^>]*data-reverse-target="[^"]*section-news-types"/', $html) === 1 || preg_match('/data-reverse-target="[^"]*section-pages-types"/', $html) === 1, 'a section checkbox hides its entry types while it is ticked (Craft\'s own field toggle)');
check(str_contains((string)$box('enabledCategoryGroups', $topicsUid), 'disabled') && str_contains((string)$box('enabledCategoryGroups', $topicsUid), 'checked') && str_contains($html, 'config/slug-equals-title.php'), 'Category Groups is read-only with a note: set in config/slug-equals-title.php (its handle there matches the group)');
check(($commerce && str_contains($html, 'settings[enabledProductTypes][]')) || (!$commerce && !str_contains($html, 'enabledProductTypes')), 'Product Types are listed only with Commerce');
$lang = Craft::$app->getSites()->getPrimarySite()->language;
check(str_contains($html, "name=\"settings[stopWords][$lang]\"") && str_contains($html, 'name="settings[stopWords][de]"'), "Stop words: a line per site language ($lang, de), empty (" . (preg_match_all('/name="settings\[stopWords\]\[[^"]*\]"/', $html, $m) ? implode(', ', $m[0]) : 'none') . ')');
[$status, $body, $json] = $http->action('plugins/save-plugin-settings', ['pluginHandle' => 'slug-equals-title', 'settings' => ['enabledSections' => [$newsUid, "$pagesUid:$landingUid"], 'enabledCategoryGroups' => '', 'enabledProductTypes' => '', 'stopWords' => [$lang => 'A, and, the', 'de' => '']]]);
check($status === 200 && empty($json['errors']), "saving the settings succeeds (HTTP $status: " . substr($body, 0, 200) . ')');
usleep(500000); // the YAML is written as the request ends, after the response
$stored = Craft::$app->getProjectConfig()->get('plugins.slug-equals-title.settings', true);
check(($stored['enabledSections'] ?? null) === [$newsUid, "$pagesUid:$landingUid"] && empty($stored['enabledProductTypes']), 'the ticked section and entry type are stored by UID; an empty list is stored as none');
// the YAML packs associative arrays (`__assoc__`); Craft unpacks them when it applies the config
check(\craft\helpers\ProjectConfig::unpackAssociativeArray($stored['stopWords'] ?? []) === [$lang => ['a', 'and', 'the']], 'the stop words are stored per language as lower-cased words; a language left empty is not stored');
[$status, , $json] = $http->action('elements/save', ['elementId' => $news->id, 'siteId' => $news->siteId, 'title' => 'E2E: The news and the hills', 'slug' => $reload($news)->slug, SlugEqualsTitle::PARAM => '1']);
check($status === 200 && empty($json['errors']) && $reload($news)->slug === 'e2e-news-hills', 'and they are in effect: an entry saved with the switch on gets its slug without them (' . $reload($news)->slug . ')');
[$status, $html] = $http->page('/admin/settings/plugins/slug-equals-title');
check(preg_match('/name="settings\[stopWords\]\[' . preg_quote($lang, '/') . '\]"[^>]*value="a, and, the"|value="a, and, the"[^>]*name="settings\[stopWords\]\[' . preg_quote($lang, '/') . '\]"/', $html) === 1, 'and shown again as a comma-separated line');
[$status, $body, $json] = $http->action('plugins/save-plugin-settings', ['pluginHandle' => 'slug-equals-title', 'settings' => ['enabledSections' => [$newsUid], 'enabledCategoryGroups' => '', 'enabledProductTypes' => $commerce ? [$clothingUid] : '', 'stopWords' => '']]);
usleep(500000); // the YAML is written as the request ends, after the response
Craft::$app->getProjectConfig()->reset(); // this process memoized the YAML at the previous read
check($status === 200 && empty($json['errors']) && empty(Craft::$app->getProjectConfig()->get('plugins.slug-equals-title.settings.stopWords', true)), 'the seeded settings are back, no stop words');

// --- cleanup
foreach ([$news, $page] as $entry) {
    $elements->deleteElement($entry, true);
}
$elements->deleteElement($category, true);
unlink($http->jar);
echo "All ok\n";
