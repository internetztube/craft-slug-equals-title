<?php

declare(strict_types=1);

/**
 * Smoke test against a seeded Craft project (dev/seed.php), on the console: saves without an edit form, so the plugin
 * follows the remembered choice of each element or the default of its type. Exits non-zero on the first failed
 * assertion. Elements it creates are titled "Smoke: …" and removed at the end (and at the start, after an aborted run).
 *
 * Run: dev/dev.sh smoke   (or: php dev/smoke.php <craft-project-dir>, after seeding)
 */

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use internetztube\slugEqualsTitle\migrations\Install;
use internetztube\slugEqualsTitle\migrations\m261103_120000_elements_table;
use internetztube\slugEqualsTitle\models\Settings;
use internetztube\slugEqualsTitle\services\SlugService;
use internetztube\slugEqualsTitle\SlugEqualsTitle;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/smoke.php <craft-project-dir>');
require $projectDir . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

$elements = Craft::$app->getElements();
$db = Craft::$app->getDb();
$plugin = SlugEqualsTitle::getInstance();
$slugs = $plugin->slugs;
$settings = $plugin->getSettings();
$primary = Craft::$app->getSites()->getPrimarySite();
$de = Craft::$app->getSites()->getSiteByHandle('de') ?? throw new RuntimeException('Run the seed first');
$commerce = Craft::$app->getPlugins()->isPluginEnabled('commerce');
$productClass = 'craft\\commerce\\elements\\Product';

$cleanup = function() use ($elements, $commerce, $productClass): void {
    $queries = [Entry::find()->drafts(null)->provisionalDrafts(null), Category::find()];
    if ($commerce) {
        $queries[] = $productClass::find();
    }
    foreach ($queries as $query) {
        foreach ($query->title('Smoke:*')->status(null)->siteId('*')->unique()->all() as $old) {
            $elements->deleteElement($old, true);
        }
    }
};
$cleanup();
$db->tableExists(SlugService::TABLE) || (new Install())->safeUp(); // after a run aborted in the Install checks

$save = function(ElementInterface $element, bool $validate = true) use ($elements): ElementInterface {
    $elements->saveElement($element, $validate) or throw new RuntimeException(json_encode($element->getErrors()));
    return $element;
};
/** The element as stored, in its site or another one. */
$reload = fn(ElementInterface $element, ?int $siteId = null): ElementInterface => $element::find()->id($element->id)->siteId($siteId ?? $element->siteId)->status(null)->drafts(null)->one() ?? throw new RuntimeException("Element $element->id is gone");
$make = function(string $section, string $title, array $attributes = []) use ($save): Entry {
    $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
    $entry = new Entry(['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title] + $attributes);
    $save($entry);
    return $entry;
};
$rows = fn(ElementInterface $element): array => (new Query())->select(['enabled'])->from(SlugService::TABLE)->where(['elementId' => $element->id])->column();

echo "Settings\n";
check($settings->enabledSections === ['news'] && $settings->enabledProductTypes === ($commerce ? ['clothing'] : []), 'the seeded settings: News' . ($commerce ? ' and Clothing' : '') . ' on by default');
check($settings->enabledCategoryGroups === ['topics'] && Craft::$app->getProjectConfig()->get('plugins.slug-equals-title.settings.enabledCategoryGroups') === null, 'config/slug-equals-title.php overrides the category groups of the stored settings');
$model = new Settings();
$model->setAttributes(['enabledSections' => '', 'enabledCategoryGroups' => [';', 'topics', 3], 'enabledProductTypes' => null], false);
check($model->enabledSections === [] && $model->enabledCategoryGroups === ['topics'] && $model->enabledProductTypes === [] && $model->validate(), 'an empty checkbox list, the ";" placeholder of 3.x settings and null normalize to lists of handles');
check(in_array(Entry::class, SlugService::elementTypes(), true) && in_array(Category::class, SlugService::elementTypes(), true) && in_array($productClass, SlugService::elementTypes(), true) === $commerce, 'entries, categories' . ($commerce ? ' and products' : '') . ' are the supported element types');
check(array_keys(SlugService::settingGroups()) === array_merge(['enabledSections', 'enabledCategoryGroups'], $commerce ? ['enabledProductTypes'] : []), 'the settings page lists sections, category groups' . ($commerce ? ' and product types' : ''));
check(in_array('news', array_column(SlugService::settingGroups()['enabledSections']['types'], 'handle'), true), 'with the sections of the site');

echo "Defaults per type\n";
$a = $make('news', 'Smoke: Hello, World!');
check($a->slug === 'smoke-hello-world', "a new News entry gets its slug from the title ($a->slug)");
$a->title = 'Smoke: Hello again';
$save($a);
check($reload($a)->slug === 'smoke-hello-again', 'a changed title changes the slug');
$a->slug = 'smoke-by-hand';
$save($a);
check($reload($a)->slug === 'smoke-hello-again', 'a slug set by hand is overwritten while the entry follows its title');
$p = $make('pages', 'Smoke: A page', ['slug' => 'smoke-page']);
check($p->slug === 'smoke-page', 'a Pages entry (off by default) keeps the slug it was given');
$p->title = 'Smoke: A renamed page';
$save($p);
check($reload($p)->slug === 'smoke-page', 'and keeps it when its title changes');
check($slugs->isEnabled($a) && !$slugs->isEnabled($p) && $slugs->isEnabledByDefault($a) && !$slugs->isEnabledByDefault($p), 'isEnabled() follows the defaults of the types while nothing is remembered');
check($rows($a) === [] && $rows($p) === [], 'console saves remember nothing (no edit form)');

echo "Remembered choice\n";
$slugs->setEnabled($p, true);
$p->title = 'Smoke: Now following';
$save($p);
check($reload($p)->slug === 'smoke-now-following', 'an entry switched on follows its title although its section is off');
$slugs->setEnabled($a, false);
$a->slug = 'smoke-mine';
$save($a);
check($reload($a)->slug === 'smoke-mine', 'an entry switched off keeps its own slug although its section is on');
$slugs->setEnabled($a, true);
check(count($rows($a)) === 1 && (bool)$rows($a)[0], 'one row per element: the latest choice replaces the earlier one');
$a->title = 'Smoke: Back on';
$save($a);
check($reload($a)->slug === 'smoke-back-on', 'switched on again, the entry follows its title again');
check($slugs->isEnabled($reload($a)) && !$slugs->isEnabled($reload($p)) === false, 'isEnabled() reads the remembered choice');

echo "Resaving\n";
Db::update('{{%elements_sites}}', ['slug' => 'smoke-stale'], ['elementId' => $a->id, 'siteId' => $primary->id]);
$elements->resaveElements(Entry::find()->id($a->id)->status(null));
check($reload($a)->slug === 'smoke-back-on', 'php craft resave/entries brings a stale slug back in line with the title');

echo "Uniqueness and normalization\n";
$b1 = $make('news', 'Smoke: Same title');
$b2 = $make('news', 'Smoke: Same title');
check($b1->slug === 'smoke-same-title' && $b2->slug === 'smoke-same-title-2' && $b2->uri === 'news/smoke-same-title-2', "two entries with the same title get unique slugs and URIs ($b1->slug, $b2->slug)");
$c = $make('news', 'Smoke: Dots. Slashes/and "quotes" (brackets)');
check($c->slug === 'smoke-dots-slashes-and-quotes-brackets', "punctuation is dropped like Craft does for new entries ($c->slug)");

echo "Sites\n";
$g = $make('news', 'Smoke: Straße über München');
check($g->slug === 'smoke-strasse-uber-munchen', "the English site transliterates with Craft's default map ($g->slug)");
$gDe = $reload($g, $de->id);
$gDe->title = 'Smoke: Straße über München';
$save($gDe);
check($reload($g, $de->id)->slug === 'smoke-strasse-ueber-muenchen', "the German site transliterates the German way ({$reload($g, $de->id)->slug})");
check($reload($g)->slug === 'smoke-strasse-uber-munchen', 'the English slug is untouched by the German save');

echo "Drafts and revisions\n";
$drafts = Craft::$app->getDrafts();
$draft = $drafts->createDraft($a, null, 'Smoke draft');
check($slugs->isEnabled($draft), 'a draft takes the remembered choice of its canonical entry');
$draft->title = 'Smoke: Draft title';
$save($draft);
check($reload($draft)->slug === 'smoke-draft-title', 'a draft follows its title');
$slugs->setEnabled($draft, false);
$draft->slug = 'smoke-draft-own';
$save($draft);
check($reload($draft)->slug === 'smoke-draft-own' && $slugs->isEnabled($reload($a)), "the draft's own choice wins over the canonical entry's, which keeps its own");
$draftId = $draft->id;
$drafts->applyDraft($draft);
$applied = $reload($a);
check($applied->title === 'Smoke: Draft title' && $applied->slug === 'smoke-draft-title', 'applying the draft saves the canonical entry with its own choice: the slug follows the title');
check(!(new Query())->from(SlugService::TABLE)->where(['elementId' => $draftId])->exists(), "the draft's row went with the draft");
$revision = Entry::find()->revisionOf($a)->status(null)->orderBy(['dateCreated' => SORT_DESC])->one();
check($revision !== null && $revision->getIsRevision() && $rows($revision) === [], 'saves create revisions; the plugin leaves them alone');

echo "Empty titles\n";
$e = $make('news', 'Smoke: Titled');
$e->title = '';
$save($e, false);
check($reload($e)->slug === 'smoke-titled', 'an empty title leaves the slug alone (#19)');

echo "Nested entries\n";
$page = $make('pages', 'Smoke: With blocks', ['slug' => 'smoke-blocks']);
$page->setFieldValue('blocks', ['new1' => ['type' => 'textBlock', 'enabled' => true, 'title' => 'Smoke: A block', 'fields' => []]]);
$save($page);
$block = Entry::find()->ownerId($page->id)->field('blocks')->status(null)->one();
check($block !== null && !$slugs->supports($block) && !$slugs->isEnabled($block), 'nested entries (Matrix blocks) have no section: not supported, never followed');
check($reload($page)->slug === 'smoke-blocks', 'the page keeps its slug');

echo "Categories\n";
$topics = Craft::$app->getCategories()->getGroupByHandle('topics');
$regions = Craft::$app->getCategories()->getGroupByHandle('regions');
$t = $save(new Category(['groupId' => $topics->id, 'title' => 'Smoke: Topic!']));
check($t->slug === 'smoke-topic' && SlugService::typeHandle($t) === 'topics', "a category of a group that is on follows its title ($t->slug)");
$t->title = 'Smoke: Another topic';
$save($t);
check($reload($t)->slug === 'smoke-another-topic', 'and when its title changes');
$r = $save(new Category(['groupId' => $regions->id, 'title' => 'Smoke: Region', 'slug' => 'smoke-somewhere']));
$r->title = 'Smoke: Renamed region';
$save($r);
check($reload($r)->slug === 'smoke-somewhere', 'a category of a group that is off keeps its slug');

if ($commerce) {
    echo "Products\n";
    $type = \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeByHandle('clothing');
    $product = new $productClass(['typeId' => $type->id, 'title' => 'Smoke: Linen shirt', 'slug' => 'smoke-shirt']);
    $product->setVariants([new \craft\commerce\elements\Variant(['sku' => 'SMOKE-1', 'price' => 10.0])]);
    $save($product);
    check($reload($product)->slug === 'smoke-linen-shirt' && SlugService::typeHandle($product) === 'clothing', "a product of a type that is on follows its title ({$reload($product)->slug})");
    $slugs->setEnabled($product, false);
    $product = $reload($product);
    $product->slug = 'smoke-shirt';
    $save($product);
    check($reload($product)->slug === 'smoke-shirt', 'switched off, a product keeps its slug');
}

echo "Migration from 3.x\n";
$m1 = $make('news', 'Smoke: Migrated');
$m2 = $make('pages', 'Smoke: Migrated page', ['slug' => 'smoke-migrated-page']);
$m3 = $make('pages', 'Smoke: Migrated lower-case', ['slug' => 'smoke-migrated-lower']);
$oldTable = function(string $name, array $rows) use ($db): void {
    $db->createCommand()->dropTableIfExists($name)->execute(); // a leftover of an aborted run
    // as 3.x created it: no unique index, so an element could have several rows; no foreign key here, so a row of an
    // element that is gone can be seeded
    $db->createCommand()->createTable($name, [
        'id' => 'pk',
        'dateCreated' => 'datetime NOT NULL',
        'dateUpdated' => 'datetime NOT NULL',
        'uid' => 'char(36) NOT NULL',
        'elementId' => 'integer NOT NULL',
        'enabled' => 'boolean NOT NULL',
    ])->execute();
    foreach ($rows as [$elementId, $enabled]) {
        Db::insert($name, ['elementId' => $elementId, 'enabled' => $enabled, 'uid' => craft\helpers\StringHelper::UUID()]);
    }
    $db->getSchema()->refresh();
};
$oldTable('{{%slugEqualsTitle_shouldRewrite}}', [[$m1->id, true], [$m1->id, false], [$m2->id, true], [$a->id, false], [2147483000, true]]);
ob_start();
$migrated = (new m261103_120000_elements_table())->up();
$log = ob_get_clean();
if ($migrated === false) {
    echo $log;
}
check($migrated !== false && !$db->tableExists('{{%slugEqualsTitle_shouldRewrite}}') && $db->tableExists(SlugService::TABLE), 'the 3.x table is folded into the new one and dropped');
check($rows($m1) === [false] || $rows($m1) === ['0'] || $rows($m1) === [0], 'of several rows of an element, the latest choice wins (off)');
check((bool)($rows($m2)[0] ?? false) && (bool)($rows($a)[0] ?? false), 'a single row migrates; a choice already in the new table is kept over the old one');
check(!(new Query())->from(SlugService::TABLE)->where(['elementId' => 2147483000])->exists(), 'rows of elements that are gone are left behind');
$m1->title = 'Smoke: Migrated and renamed';
$save($m1);
check($reload($m1)->slug === 'smoke-migrated', 'the migrated choice is in effect: the entry keeps its slug');
$oldTable('{{%slugequalstitle_shouldrewrite}}', [[$m3->id, true]]);
ob_start();
$migrated = (new m261103_120000_elements_table())->up();
ob_end_clean();
check($migrated !== false && !$db->tableExists('{{%slugequalstitle_shouldrewrite}}') && $slugs->isEnabled($m3), 'a dump that lower-cased the table name (MySQL on macOS or Windows, #21) migrates too');
ob_start();
$down = (new m261103_120000_elements_table())->down();
ob_end_clean();
check($down === false, 'the migration cannot be reverted');

echo "Install\n";
$install = new Install();
check($install->safeUp() && $db->tableExists(SlugService::TABLE), 'installing over an existing table is a no-op');
$install->safeDown();
$db->getSchema()->refresh();
check(!$db->tableExists(SlugService::TABLE), 'uninstalling drops the table');
check($install->safeUp() && $db->tableExists(SlugService::TABLE), 'and installing creates it again');
$install->safeDown();
$db->getSchema()->refresh();
ob_start();
$migrated = (new m261103_120000_elements_table())->up();
ob_end_clean();
$db->getSchema()->refresh();
check($migrated !== false && $db->tableExists(SlugService::TABLE), 'the 3.x migration creates the table when it is missing (a plugin installed, uninstalled and updated in between)');
// what the seed remembered (dev/seed.php), for the suites that follow
$about = Entry::find()->section('pages')->slug('about')->status(null)->one();
if ($about) {
    $slugs->setEnabled($about, false);
}

$cleanup();
echo "All ok\n";
