<?php

declare(strict_types=1);

/**
 * Seeds a Craft project for plugin development and the test suites. Idempotent.
 *
 * Two sites (English, German), three sections ("News", a channel the plugin is on for by default; "Pages", a structure
 * it is off for; "Home", a single whose entry type hides the slug field), a Matrix field with titled blocks on Pages
 * (nested entries, which the plugin leaves alone), the category group "Topics" (on by default through
 * config/slug-equals-title.php, so the settings page shows an override), and with Craft Commerce the product type
 * "Clothing" (on by default). Plus demo content for the screenshots: a travel journal "Transit" with a photo, an intro,
 * a rich text body and topics per article. Photos by Frederic Köberl (unsplash.com/@internetztube) are downloaded on the
 * first run; without network a drawn picture takes their place. The site's templates come from dev/templates.
 *
 * Run: dev/dev.sh seed   (or: php dev/seed.php <craft-project-dir>)
 */

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\models\Volume;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use internetztube\slugEqualsTitle\SlugEqualsTitle;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/seed.php <craft-project-dir>');
require $projectDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

function fail(object $model): never
{
    throw new RuntimeException(get_class($model) . ': ' . print_r($model->getErrors(), true));
}

/** The two sites: the primary one (English) and "German" (de), in the primary site's group. */
function ensureSites(): array
{
    $sites = Craft::$app->getSites();
    $primary = $sites->getPrimarySite();
    $de = $sites->getSiteByHandle('de');
    if (!$de) {
        $de = new Site(['groupId' => $primary->groupId, 'name' => 'Transit (Deutsch)', 'handle' => 'de', 'language' => 'de', 'hasUrls' => true, 'baseUrl' => rtrim((string)$primary->getBaseUrl(), '/') . '/de/']);
        $sites->saveSite($de) or fail($de);
    }
    return [$primary, $de];
}

/** Creates (or returns) an entry type; titled, with the field layout's title field. */
function ensureEntryType(string $name, string $handle, bool $showSlugField = true): EntryType
{
    $entries = Craft::$app->getEntries();
    if ($type = $entries->getEntryTypeByHandle($handle)) {
        return $type;
    }
    $type = new EntryType(['name' => $name, 'handle' => $handle, 'hasTitleField' => true, 'showSlugField' => $showSlugField]);
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements([new EntryTitleField()]);
    $layout->setTabs([$tab]);
    $type->setFieldLayout($layout);
    $entries->saveEntryType($type) or fail($type);
    return $type;
}

/** Creates (or returns) a section with URLs in both sites. */
function ensureSection(string $name, string $handle, string $type, EntryType $entryType, array $sites): Section
{
    $entries = Craft::$app->getEntries();
    if ($section = $entries->getSectionByHandle($handle)) {
        return $section;
    }
    $section = new Section(['name' => $name, 'handle' => $handle, 'type' => $type, 'enableVersioning' => true]);
    $siteSettings = [];
    foreach ($sites as $site) {
        $siteSettings[$site->id] = new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => $type === Section::TYPE_SINGLE ? '__home__' : "$handle/{slug}",
            'template' => $type === Section::TYPE_SINGLE ? 'index' : '_element',
        ]);
    }
    $section->setSiteSettings($siteSettings);
    $section->setEntryTypes([$entryType]);
    $entries->saveSection($section) or fail($section);
    return $entries->getSectionByHandle($handle) ?? fail($section);
}

/** Creates (or returns) a Matrix field with one titled block type, for nested entries. */
function ensureMatrixField(string $name, string $handle, EntryType $blockType): Matrix
{
    $fields = Craft::$app->getFields();
    if ($field = $fields->getFieldByHandle($handle)) {
        assert($field instanceof Matrix);
        return $field;
    }
    $field = new Matrix(['name' => $name, 'handle' => $handle]);
    $field->setEntryTypes([$blockType]);
    $fields->saveField($field) or fail($field);
    $field = $fields->getFieldByHandle($handle);
    assert($field instanceof Matrix);
    return $field;
}

/** Creates (or updates) a field; `$config` needs at least `name` and `handle`. */
function ensureField(string $class, array $config): FieldInterface
{
    if ($field = Craft::$app->getFields()->getFieldByHandle($config['handle'])) {
        $stale = array_filter($config, fn($value, string $key): bool => ($field->$key ?? null) != $value, ARRAY_FILTER_USE_BOTH);
        if ($stale) {
            $field->setAttributes($stale, false);
            Craft::$app->getFields()->saveField($field) or fail($field);
        }
        return $field;
    }
    $field = new $class($config);
    Craft::$app->getFields()->saveField($field) or fail($field);
    return $field;
}

/** Appends a field to the first tab of the entry type's field layout (no-op if already there). */
function addFieldToLayout(FieldInterface $field, EntryType $entryType): void
{
    $layout = $entryType->getFieldLayout();
    if ($layout->getFieldByHandle($field->handle)) {
        return;
    }
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($field)]);
    $layout->setTabs($layout->getTabs());
    $entryType->setFieldLayout($layout);
    Craft::$app->getEntries()->saveEntryType($entryType) or fail($entryType);
}

/** Creates (or returns) a local volume under web/uploads. */
function ensureVolume(string $handle, string $name): Volume
{
    $volumes = Craft::$app->getVolumes();
    if ($volume = $volumes->getVolumeByHandle($handle)) {
        return $volume;
    }
    $fsService = Craft::$app->getFs();
    if (!$fs = $fsService->getFilesystemByHandle($handle)) {
        $fs = $fsService->createFilesystem(['type' => Local::class, 'name' => $name, 'handle' => $handle, 'hasUrls' => true, 'settings' => ['path' => "@webroot/uploads/$handle", 'url' => "@web/uploads/$handle"]]);
        $fsService->saveFilesystem($fs) or fail($fs);
    }
    $volume = new Volume(['name' => $name, 'handle' => $handle, 'fsHandle' => $handle]);
    $volumes->saveVolume($volume) or fail($volume);
    return $volume;
}

/** A photo by Frederic Köberl from Unsplash (images.unsplash.com/<path>, 1600px wide), or a drawn gradient when offline. */
function ensurePhoto(string $filename, string $title, string $imagePath): Asset
{
    $volume = Craft::$app->getVolumes()->getVolumeByHandle('photos') ?? throw new RuntimeException('No photos volume');
    if ($asset = Asset::find()->volumeId($volume->id)->filename($filename)->one()) {
        return $asset;
    }
    if ($volume->fileExists($filename)) {
        $volume->deleteFile($filename); // left from an earlier install
    }
    $tmp = sys_get_temp_dir() . '/seed-' . uniqid() . '-' . $filename;
    try {
        Craft::createGuzzleClient(['timeout' => 30])->get("https://images.unsplash.com/$imagePath?w=1600&q=80&fm=jpg&fit=max", ['sink' => $tmp]);
    } catch (Throwable) {
        // offline
    }
    if (!@getimagesize($tmp)) {
        $image = imagecreatetruecolor(1600, 1000);
        for ($y = 0; $y < 1000; $y++) {
            imageline($image, 0, $y, 1600, $y, imagecolorallocate($image, (int)(30 + $y / 20), (int)(80 + $y / 12), (int)(70 + $y / 25)));
        }
        imagejpeg($image, $tmp, 80);
    }
    $asset = new Asset(['tempFilePath' => $tmp, 'filename' => $filename, 'title' => $title, 'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id, 'volumeId' => $volume->id, 'avoidFilenameConflicts' => true]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    Craft::$app->getElements()->saveElement($asset) or fail($asset);
    return $asset;
}

/** Creates (or returns) a category group with URLs in both sites. */
function ensureCategoryGroup(string $name, string $handle, array $sites): CategoryGroup
{
    $categories = Craft::$app->getCategories();
    if ($group = $categories->getGroupByHandle($handle)) {
        return $group;
    }
    $group = new CategoryGroup(['name' => $name, 'handle' => $handle]);
    $siteSettings = [];
    foreach ($sites as $site) {
        $siteSettings[$site->id] = new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => "$handle/{slug}", 'template' => '_element']);
    }
    $group->setSiteSettings($siteSettings);
    $categories->saveGroup($group) or fail($group);
    return $categories->getGroupByHandle($handle) ?? fail($group);
}

/** Creates (or returns) a Commerce product type with URLs in both sites, when Commerce is installed. */
function ensureProductType(string $name, string $handle, array $sites): ?object
{
    if (!Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
        return null;
    }
    $commerce = \craft\commerce\Plugin::getInstance();
    if ($type = $commerce->getProductTypes()->getProductTypeByHandle($handle)) {
        return $type;
    }
    $type = new \craft\commerce\models\ProductType(['name' => $name, 'handle' => $handle, 'hasDimensions' => false, 'maxVariants' => 1, 'hasVariantTitleField' => false, 'variantTitleFormat' => '{product.title}']);
    $siteSettings = [];
    foreach ($sites as $site) {
        $siteSettings[$site->id] = new \craft\commerce\models\ProductTypeSite(['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => "$handle/{slug}", 'template' => '_element']);
    }
    $type->setSiteSettings($siteSettings);
    $commerce->getProductTypes()->saveProductType($type) or fail($type);
    return $commerce->getProductTypes()->getProductTypeByHandle($handle);
}

/**
 * Creates (or updates) an entry by section and title. Values are always (re)applied: a field created earlier in the
 * same PHP process is not always writable yet (Craft caches field layouts per request), so dev.sh runs this seed
 * twice and the second, fresh process writes the content for sure.
 */
function ensureEntry(string $section, string $title, array $attributes = [], array $fields = []): Entry
{
    $entry = Entry::find()->section($section)->title($title)->status(null)->one();
    if (!$entry) {
        $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);
        $entry = new Entry(['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title]);
    }
    foreach ($attributes as $name => $value) {
        $entry->$name = $value;
    }
    $entry->setFieldValues($fields);
    Craft::$app->getElements()->saveElement($entry) or fail($entry);
    return $entry;
}

function ensureCategory(string $group, string $title): Category
{
    $category = Category::find()->group($group)->title($title)->status(null)->one();
    if (!$category) {
        $category = new Category(['groupId' => Craft::$app->getCategories()->getGroupByHandle($group)->id, 'title' => $title]);
        Craft::$app->getElements()->saveElement($category) or fail($category);
    }
    return $category;
}

// --- structure
[$primary, $de] = ensureSites();
// the demo site's names, in the control panel's header and site switcher
foreach ([$primary->handle => 'Transit', 'de' => 'Transit (Deutsch)'] as $handle => $name) {
    $site = Craft::$app->getSites()->getSiteByHandle($handle);
    if ($site->name !== $name) {
        $site->name = $name;
        Craft::$app->getSites()->saveSite($site) or fail($site);
    }
}
if (Craft::$app->getProjectConfig()->get('system.name') !== 'Transit') {
    Craft::$app->getProjectConfig()->set('system.name', 'Transit'); // the control panel's header
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}
$group = $primary->getGroup();
if ($group->name !== 'Transit') {
    $group->name = 'Transit';
    Craft::$app->getSites()->saveGroup($group) or fail($group);
}
$sites = [$primary, $de];
$articleType = ensureEntryType('Article', 'article');
$news = ensureSection('News', 'news', Section::TYPE_CHANNEL, $articleType, $sites);
$pageType = ensureEntryType('Page', 'page');
$landingType = ensureEntryType('Landing page', 'landingPage');
$pages = ensureSection('Pages', 'pages', Section::TYPE_STRUCTURE, $pageType, $sites);
if (count($pages->getEntryTypes()) < 2) {
    $pages->setEntryTypes([$pageType, $landingType]); // a section with two entry types: the settings list them under it
    Craft::$app->getEntries()->saveSection($pages) or fail($pages);
}
ensureSection('Home', 'home', Section::TYPE_SINGLE, ensureEntryType('Home', 'home', showSlugField: false), $sites);
addFieldToLayout(ensureMatrixField('Blocks', 'blocks', ensureEntryType('Text block', 'textBlock')), $pageType);
$topics = ensureCategoryGroup('Topics', 'topics', $sites);
ensureCategoryGroup('Regions', 'regions', $sites);
$productType = ensureProductType('Clothing', 'clothing', $sites);
// the article's content fields, so the edit page looks like a real site's
$photos = ensureVolume('photos', 'Photos');
$heroImage = ensureField(AssetsField::class, ['name' => 'Hero image', 'handle' => 'heroImage', 'sources' => ["volume:$photos->uid"], 'defaultUploadLocationSource' => "volume:$photos->uid", 'maxRelations' => 1, 'viewMode' => 'large', 'allowedKinds' => ['image'], 'restrictFiles' => true]);
$intro = ensureField(PlainText::class, ['name' => 'Intro', 'handle' => 'intro', 'multiline' => true, 'initialRows' => 2, 'translationMethod' => 'site']);
$body = ensureField(class_exists(\craft\ckeditor\Field::class) ? \craft\ckeditor\Field::class : PlainText::class, ['name' => 'Body', 'handle' => 'body', 'translationMethod' => 'site'] + (class_exists(\craft\ckeditor\Field::class) ? [] : ['multiline' => true, 'initialRows' => 8]));
$topicsField = ensureField(CategoriesField::class, ['name' => 'Topics', 'handle' => 'topics', 'source' => "group:$topics->uid"]);
foreach ([$heroImage, $intro, $body, $topicsField] as $field) {
    addFieldToLayout($field, $articleType);
}
addFieldToLayout($intro, $pageType);
addFieldToLayout($body, $pageType);
// the demo site's templates (dev/templates, copied by dev.sh): entries, categories and products on _element.twig
foreach ([...Craft::$app->getEntries()->getAllSections(), ...Craft::$app->getCategories()->getAllGroups(), ...($productType ? [$productType] : [])] as $typeModel) {
    $template = $typeModel instanceof Section && $typeModel->type === Section::TYPE_SINGLE ? 'index' : '_element';
    $changed = false;
    foreach ($typeModel->getSiteSettings() as $siteSettings) {
        if ($siteSettings->template !== $template) {
            $siteSettings->template = $template;
            $changed = true;
        }
    }
    if ($changed) {
        match (true) {
            $typeModel instanceof Section => Craft::$app->getEntries()->saveSection($typeModel),
            $typeModel instanceof CategoryGroup => Craft::$app->getCategories()->saveGroup($typeModel),
            default => \craft\commerce\Plugin::getInstance()->getProductTypes()->saveProductType($typeModel),
        } or fail($typeModel);
    }
}

// --- plugin settings (by UID, as the settings page stores them): News and Clothing in the control panel settings,
// Topics through the config file (by handle)
$plugin = SlugEqualsTitle::getInstance();
$settings = ['enabledSections' => [$news->uid], 'enabledCategoryGroups' => [], 'enabledProductTypes' => $productType ? [$productType->uid] : []];
$current = $plugin->getSettings()->toArray();
if ($current['enabledSections'] !== $settings['enabledSections'] || $current['enabledProductTypes'] !== $settings['enabledProductTypes']) {
    Craft::$app->getPlugins()->savePluginSettings($plugin, $settings) or fail($plugin->getSettings());
    Craft::$app->getProjectConfig()->saveModifiedConfigData(); // a bootstrapped script never fires EVENT_AFTER_REQUEST, which would write it
}
file_put_contents($projectDir . '/config/slug-equals-title.php', "<?php\n\n// written by dev/seed.php: the Topics group is on by default, read-only on the settings page\nreturn ['enabledCategoryGroups' => ['topics']];\n");

// --- demo content (what the screenshots show); photos by Frederic Köberl on Unsplash
$cityGuides = ensureCategory('topics', 'City guides');
$nightLife = ensureCategory('topics', 'Night life');
ensureCategory('regions', 'Asia');
$temple = ensurePhoto('hong-kong-temple.jpg', 'At a Hong Kong temple', 'photo-1748012727730-f5cee47b3a52');
$clock = ensurePhoto('train-under-the-station-clock.jpg', 'Train under the station clock', 'photo-1684934899514-772e03714de5');
ensureEntry('news', 'Vienna by night: 24 hours between Naschmarkt and the Danube', [], [
    'heroImage' => [$clock->id],
    'topics' => [$cityGuides->id, $nightLife->id],
    'intro' => 'Start at the Naschmarkt when the stalls close, end on the Danube island when the sun comes up. A day in Vienna that never sits down.',
    'body' => '<h2>Evening at the market</h2><p>The Naschmarkt empties around six. What stays open are the bars between the stalls, and that is where the night starts: a glass of Grüner Veltliner, a plate of olives, the trams rattling past on the Wienzeile.</p><h2>Midnight on the Ring</h2><p>Walk the Ringstraße once, counterclockwise. The opera, the museums, the parliament, all lit, all empty. The night buses leave from the Oper; the last tram is the one to catch if you still want the Prater.</p><h2>Sunrise on the island</h2><p>The Donauinsel at five in the morning belongs to rowers and people who did not go home. Bring a jacket, the river wind is cold even in July.</p>',
]);
ensureEntry('news', 'A weekend in Hong Kong', [], [
    'heroImage' => [$temple->id],
    'topics' => [$cityGuides->id],
    'intro' => 'Two days between the harbour and the hills: ferries, temples, a mountain tram, and more dim sum than planned.',
    'body' => '<h2>Saturday</h2><p>Take the Star Ferry to Tsim Sha Tsui before the heat sets in. The temples in Sham Shui Po are a short walk from the market streets, and the incense coils hang low enough to touch.</p><h2>Sunday</h2><p>The Peak Tram queue is shorter at eight. Come down on foot through the Morning Trail and let the city reappear one tower at a time.</p>',
]);
$about = ensureEntry('pages', 'About us & how we travel', ['slug' => 'about'], [
    'intro' => 'Transit is a small journal about cities seen from their trains, ferries and night buses.',
    'body' => '<p>We travel slowly and write it down. The photos are our own.</p>',
]);
$plugin->slugs->setEnabled($about, false); // a page that keeps its own slug
ensureEntry('pages', 'Contact', [], ['intro' => 'Write to hello@transit.test.']);
ensureEntry('home', 'Home');
if ($productType) {
    $product = \craft\commerce\elements\Product::find()->type('clothing')->title('Linen shirt')->status(null)->one();
    if (!$product) {
        $product = new \craft\commerce\elements\Product(['typeId' => $productType->id, 'title' => 'Linen shirt']);
        $variant = new \craft\commerce\elements\Variant(['sku' => 'LINEN-1', 'price' => 49.0]);
        $product->setVariants([$variant]);
        Craft::$app->getElements()->saveElement($product) or fail($product);
    }
}
echo "Seeded.\n";
