<?php

declare(strict_types=1);

/**
 * Prints the control panel paths and ids the Chrome suites use (dev/ui.mts, dev/screenshots.mts), as JSON: the demo
 * elements of dev/seed.php, and with `fixtures` two fresh entries titled "UI: …" for the tests to change (`cleanup`
 * removes them).
 *
 * Run: php dev/targets.php <craft-project-dir> [fixtures|cleanup]
 */

use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/targets.php <craft-project-dir> [fixtures|cleanup]');
require $projectDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** The edit page path ("/admin/…") of an element, in its site. */
function editPath(ElementInterface $element): string
{
    $url = $element->getCpEditUrl() ?? throw new RuntimeException("$element has no edit page");
    $path = (string)parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    return $query ? "$path?$query" : $path;
}

function entry(string $section, string $title): Entry
{
    return Entry::find()->section($section)->title($title)->status(null)->one() ?? throw new RuntimeException("No entry \"$title\" (run dev/dev.sh seed)");
}

$elements = Craft::$app->getElements();
$mode = $argv[2] ?? '';
foreach (Entry::find()->title('UI:*')->status(null)->drafts(null)->provisionalDrafts(null)->siteId('*')->unique()->all() as $old) {
    $elements->deleteElement($old, true);
}
if ($mode === 'cleanup') {
    exit;
}

$de = Craft::$app->getSites()->getSiteByHandle('de') ?? throw new RuntimeException('No German site (run dev/dev.sh seed)');
$targets = [
    'news' => ['id' => entry('news', 'A weekend in Hong Kong')->id, 'path' => editPath(entry('news', 'A weekend in Hong Kong'))],
    'about' => ['path' => editPath(entry('pages', 'About us & how we travel'))],
    'home' => ['path' => editPath(entry('home', 'Home'))],
    'category' => ['path' => editPath(Category::find()->group('topics')->title('City guides')->one() ?? throw new RuntimeException('No category (run the seed)'))],
    'product' => null,
    'settings' => '/admin/settings/plugins/slug-equals-title',
    'pagesIndex' => '/admin/entries/pages',
];
if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
    $product = \craft\commerce\elements\Product::find()->type('clothing')->status(null)->one();
    $targets['product'] = $product ? ['path' => editPath($product)] : null;
}
if ($mode === 'fixtures') {
    // the seeded settings (dev/seed.php): a run that failed on the settings page may have left others
    $plugin = internetztube\slugEqualsTitle\SlugEqualsTitle::getInstance();
    $seeded = ['enabledSections' => ['news'], 'enabledCategoryGroups' => [], 'enabledProductTypes' => $targets['product'] ? ['clothing'] : []];
    if ($plugin->getSettings()->enabledSections !== $seeded['enabledSections'] || $plugin->getSettings()->enabledProductTypes !== $seeded['enabledProductTypes']) {
        Craft::$app->getPlugins()->savePluginSettings($plugin, $seeded);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    }
    $make = function(string $section, string $title, array $attributes = []) use ($elements): Entry {
        $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
        $entry = new Entry(['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title] + $attributes);
        $elements->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
        return $entry;
    };
    $news = $make('news', 'UI: News entry');
    $page = $make('pages', 'UI: Page', ['slug' => 'ui-page']);
    $newsDe = Entry::find()->id($news->id)->siteId($de->id)->status(null)->one() ?? throw new RuntimeException('No German version');
    $targets['fixtures'] = [
        'news' => ['id' => $news->id, 'path' => editPath($news), 'pathDe' => editPath($newsDe)],
        'page' => ['id' => $page->id, 'path' => editPath($page)],
    ];
}
echo json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
