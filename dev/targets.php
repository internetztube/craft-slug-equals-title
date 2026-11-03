<?php

declare(strict_types=1);

/**
 * Prints the control panel paths and ids the Chrome suites use (dev/ui.mts, dev/screenshots.mts), as JSON: the demo
 * elements of dev/seed.php, and with `fixtures` two fresh entries titled "UI: …" for the tests to change (`cleanup`
 * removes them).
 *
 * Run: php dev/targets.php <craft-project-dir> [fixtures|cleanup]
 */

require __DIR__ . '/craft.php';

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Internetztube\SlugEqualsTitle\Slugs;
use Internetztube\SlugEqualsTitle\SlugEqualsTitle;

/** The edit page path ("/admin/…") of an entry, in its site. */
function editPath(Entry $entry, string $siteHandle = ''): string
{
    return '/admin/entries/' . $entry->getSection()?->handle . '/' . $entry->id . ($siteHandle !== '' ? "?site=$siteHandle" : '');
}

function entry(string $section, string $slug): Entry
{
    $entry = Entry::find()->section($section)->slug($slug)->status(null)->one();
    return $entry instanceof Entry ? $entry : throw new RuntimeException("No entry $section/$slug (run dev/dev.sh seed)");
}

$mode = $argv[2] ?? '';
foreach (Entry::find()->title('UI:*')->status(null)->drafts(null)->provisionalDrafts(null)->siteId('*')->unique()->all() as $old) {
    Elements::deleteElement($old, true);
}
if ($mode === 'cleanup') {
    exit;
}

$de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('No German site (run dev/dev.sh seed)');
$news = Sections::getSectionByHandle('news') ?? throw new RuntimeException('Run the seed first');
$pages = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$targets = [
    'news' => ['id' => entry('news', 'a-weekend-in-hong-kong')->id, 'path' => editPath(entry('news', 'a-weekend-in-hong-kong'))],
    'about' => ['path' => editPath(entry('pages', 'about'))],
    'home' => ['path' => editPath(Entry::find()->section('home')->status(null)->one() ?? throw new RuntimeException('No Home entry (run dev/dev.sh seed)'))],
    'settings' => '/admin/settings/plugins/slug-equals-title',
    'uids' => ['news' => $news->uid, 'pages' => $pages->uid, 'landingPage' => EntryTypes::getEntryTypeByHandle('landingPage')?->uid],
    'adapter' => class_exists(Slugs::CATEGORY),
];
if ($mode === 'fixtures') {
    // the seeded settings (dev/seed.php): a run that failed on the settings page may have left others
    $plugin = Plugins::getPlugin('slug-equals-title') ?? throw new RuntimeException('Plugin not installed');
    assert($plugin instanceof SlugEqualsTitle);
    if ($plugin->getSettings()->enabledSections !== [$news->uid]) {
        Plugins::savePluginSettings($plugin, ['enabledSections' => [$news->uid], 'enabledCategoryGroups' => [], 'enabledProductTypes' => []]);
        ProjectConfig::saveModifiedConfigData();
    }
    $make = function(string $section, string $title, array $attributes = []): Entry {
        $sectionModel = Sections::getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
        $entry = new Entry($attributes + ['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title]);
        Elements::saveElement($entry) or throw new RuntimeException(json_encode($entry->errors()->all()));
        return $entry;
    };
    $newsEntry = $make('news', 'UI: News entry');
    $page = $make('pages', 'UI: Page', ['slug' => 'ui-page']);
    $targets['fixtures'] = [
        'news' => ['id' => $newsEntry->id, 'path' => editPath($newsEntry), 'pathDe' => editPath($newsEntry, 'de')],
        'page' => ['id' => $page->id, 'path' => editPath($page)],
    ];
}
echo json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
