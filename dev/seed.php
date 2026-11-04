<?php

declare(strict_types=1);

/**
 * Seeds the Craft 6 dev project for plugin development and the test suites. Idempotent.
 *
 * Two sites (English, German), three sections ("News", a channel the plugin is on for by default; "Pages", a structure
 * it is off for, with two entry types; "Home", a single whose entry type hides the slug field), a Matrix field with
 * titled blocks on Pages (nested entries, which the plugin leaves alone), the demo site "Transit" with a little content
 * for the screenshots.
 *
 * Run: dev/dev.sh seed   (or: php dev/seed.php <craft-project-dir>)
 */

require __DIR__ . '/craft.php';

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Internetztube\SlugEqualsTitle\Slugs;
use Internetztube\SlugEqualsTitle\SlugEqualsTitle;

function fail(object $model): never
{
    $errors = method_exists($model, 'errors') ? $model->errors()->all() : [];
    throw new RuntimeException($model::class . ': ' . json_encode($errors));
}

/** The two sites: the primary one (English) and "Transit (Deutsch)" (de), in the primary site's group. */
function ensureSites(): array
{
    $primary = Sites::getPrimarySite();
    // the base URLs follow APP_URL (the dev server's port may change between projects)
    $base = rtrim((string)config('app.url'), '/') . '/';
    if ($primary->getBaseUrl() !== $base) {
        $primary->baseUrl = $base;
        Sites::saveSite($primary) or fail($primary);
    }
    $de = Sites::getSiteByHandle('de');
    if ($de && $de->getBaseUrl() !== $base . 'de/') {
        $de->baseUrl = $base . 'de/';
        Sites::saveSite($de) or fail($de);
    }
    if (!$de) {
        $de = new Site(['groupId' => $primary->groupId, 'name' => 'Transit (Deutsch)', 'handle' => 'de', 'language' => 'de', 'hasUrls' => true, 'baseUrl' => rtrim((string)$primary->getBaseUrl(), '/') . '/de/']);
        Sites::saveSite($de) or fail($de);
        $de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('site de');
    }
    return [$primary, $de];
}

/**
 * Creates (or returns) a field.
 * @param class-string<FieldInterface> $type
 * @param array<string, mixed> $settings
 */
function ensureField(string $type, string $handle, string $name, array $settings = []): FieldInterface
{
    if ($field = Fields::getFieldByHandle($handle)) {
        return $field;
    }
    $field = Fields::createField(['type' => $type, 'name' => $name, 'handle' => $handle, 'settings' => $settings]);
    Fields::saveField($field) or fail($field);
    Fields::refreshFields();
    return Fields::getFieldByHandle($handle) ?? throw new RuntimeException("field $handle");
}

/** Creates (or returns) a titled entry type with the given fields. */
function ensureEntryType(string $handle, string $name, array $fields = [], bool $showSlugField = true): EntryType
{
    if ($entryType = EntryTypes::getEntryTypeByHandle($handle)) {
        return $entryType;
    }
    $entryType = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => true, 'showSlugField' => $showSlugField]);
    $entryType->setFieldLayout(FieldLayout::make(Entry::class)->tab('Content', function (FieldLayoutTab $tab) use ($fields): void {
        $tab->add(new EntryTitleField(['required' => true]));
        foreach ($fields as $field) {
            $tab->add(CustomField::make($field));
        }
    }));
    EntryTypes::saveEntryType($entryType) or fail($entryType);
    return EntryTypes::getEntryTypeByHandle($handle) ?? throw new RuntimeException("entry type $handle");
}

/** Appends a field to the first tab of the entry type's field layout (no-op if already there). */
function addFieldToLayout(FieldInterface $field, EntryType $entryType): void
{
    $layout = $entryType->getFieldLayout();
    if ($layout->getFieldByHandle((string)$field->handle)) {
        return;
    }
    $layout->getTabs()[0]->add(CustomField::make($field));
    $entryType->setFieldLayout($layout);
    EntryTypes::saveEntryType($entryType) or fail($entryType);
}

/**
 * Creates (or returns) a section with URLs in both sites.
 * @param list<EntryType> $entryTypes
 * @param list<Site> $sites
 */
function ensureSection(string $handle, string $name, SectionType $type, array $entryTypes, array $sites): Section
{
    if ($section = Sections::getSectionByHandle($handle)) {
        return $section;
    }
    $siteSettings = [];
    foreach ($sites as $site) {
        $siteSettings[$site->id] = new SectionSiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => $type === SectionType::Single ? '__home__' : "$handle/{slug}",
            'template' => '',
        ]);
    }
    $section = new Section(['name' => $name, 'handle' => $handle, 'type' => $type, 'entryTypes' => $entryTypes, 'siteSettings' => $siteSettings]);
    Sections::saveSection($section) or fail($section);
    return Sections::getSectionByHandle($handle) ?? throw new RuntimeException("section $handle");
}

/**
 * Creates (or updates) an entry by section and title. Values are always (re)applied: a field created earlier in the
 * same PHP process is not always writable yet, so dev.sh runs this seed twice and the second, fresh process writes
 * the content for sure.
 * @param array<string, mixed> $attributes
 * @param array<string, mixed> $fields
 */
function ensureEntry(string $section, string $title, array $attributes = [], array $fields = []): Entry
{
    $entry = Entry::find()->section($section)->title($title)->status(null)->one();
    if (!$entry instanceof Entry) {
        $sectionModel = Sections::getSectionByHandle($section) ?? throw new RuntimeException("section $section");
        $entry = new Entry($attributes + ['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title]);
    } else {
        foreach ($attributes as $attribute => $value) {
            $entry->$attribute = $value;
        }
    }
    $entry->setFieldValues($fields);
    Elements::saveElement($entry) or fail($entry);
    return $entry;
}

// --- structure
[$primary, $de] = ensureSites();
$sites = [$primary, $de];
if (ProjectConfig::get('system.name') !== 'Transit') {
    ProjectConfig::set('system.name', 'Transit'); // the control panel's header
}
$intro = ensureField(PlainText::class, 'intro', 'Intro', ['multiline' => true, 'initialRows' => 2]);
$body = ensureField(PlainText::class, 'body', 'Body', ['multiline' => true, 'initialRows' => 8]);
$articleType = ensureEntryType('article', 'Article', [$intro, $body]);
$news = ensureSection('news', 'News', SectionType::Channel, [$articleType], $sites);
$pageType = ensureEntryType('page', 'Page', [$intro, $body]);
$landingType = ensureEntryType('landingPage', 'Landing page', [$intro]);
$pages = ensureSection('pages', 'Pages', SectionType::Structure, [$pageType, $landingType], $sites);
ensureSection('home', 'Home', SectionType::Single, [ensureEntryType('home', 'Home', [], showSlugField: false)], $sites);
$blockType = ensureEntryType('textBlock', 'Text block', [$body]);
$blocks = ensureField(Matrix::class, 'blocks', 'Blocks', ['entryTypes' => [$blockType->uid]]);
addFieldToLayout($blocks, $pageType);


// --- plugin settings (by UID, as the settings page stores them): News on by default
$plugin = Plugins::getPlugin('slug-equals-title') ?? throw new RuntimeException('Plugin not installed');
assert($plugin instanceof SlugEqualsTitle);
$settings = ['enabledSections' => [$news->uid]];
if ($plugin->getSettings()->enabledSections !== $settings['enabledSections']) {
    Plugins::savePluginSettings($plugin, $settings) or fail($plugin->getSettings());
}
ProjectConfig::saveModifiedConfigData(); // a bootstrapped script never terminates the app, which would write it
@unlink($projectDir . '/config/craft/slug-equals-title.php'); // the suites write it for their config-file checks

// --- demo content (what the screenshots show)
ensureEntry('news', 'Vienna by night: 24 hours between Naschmarkt and the Danube', [], [
    'intro' => 'Start at the Naschmarkt when the stalls close, end on the Danube island when the sun comes up. A day in Vienna that never sits down.',
    'body' => "Evening at the market\nThe Naschmarkt empties around six. What stays open are the bars between the stalls, and that is where the night starts: a glass of Grüner Veltliner, a plate of olives, the trams rattling past on the Wienzeile.\n\nMidnight on the Ring\nWalk the Ringstraße once, counterclockwise. The opera, the museums, the parliament, all lit, all empty.",
]);
ensureEntry('news', 'A weekend in Hong Kong', [], [
    'intro' => 'Two days between the harbour and the hills: ferries, temples, a mountain tram, and more dim sum than planned.',
    'body' => "Saturday\nTake the Star Ferry to Tsim Sha Tsui before the heat sets in. The temples in Sham Shui Po are a short walk from the market streets, and the incense coils hang low enough to touch.\n\nSunday\nThe Peak Tram queue is shorter at eight. Come down on foot through the Morning Trail and let the city reappear one tower at a time.",
]);
$about = ensureEntry('pages', 'About us & how we travel', ['slug' => 'about'], [
    'intro' => 'Transit is a small journal about cities seen from their trains, ferries and night buses.',
    'body' => 'We travel slowly and write it down. The photos are our own.',
]);
app(Slugs::class)->setEnabled($about, false); // a page that keeps its own slug
ensureEntry('pages', 'Contact', [], ['intro' => 'Write to hello@transit.test.']);
ensureEntry('home', 'Home');
echo "Seeded.\n";
