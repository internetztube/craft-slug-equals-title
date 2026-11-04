<?php

declare(strict_types=1);

/**
 * Smoke test against the seeded Craft 6 dev project (dev/seed.php), on the console: saves without an edit form, so the
 * plugin follows the remembered choice of each element or the default of its type. Exits non-zero on the first failed
 * assertion. Elements it creates are titled "Smoke: …" and removed at the end (and at the start, after an aborted run).
 *
 * Run: dev/dev.sh smoke   (or: php dev/smoke.php <craft-project-dir>, after seeding)
 */

require __DIR__ . '/craft.php';

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Internetztube\SlugEqualsTitle\Database\Migrations\Install;
use Internetztube\SlugEqualsTitle\Settings;
use Internetztube\SlugEqualsTitle\Slugs;

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

$slugs = app(Slugs::class);
$settings = app(Settings::class);
$primary = Sites::getPrimarySite();
$de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('Run the seed first');

$cleanup = function(): void {
    foreach (Entry::find()->drafts(null)->provisionalDrafts(null)->title('Smoke:*')->status(null)->siteId('*')->unique()->all() as $old) {
        Elements::deleteElement($old, true);
    }
};
$cleanup();
Schema::hasTable(Slugs::TABLE) || (new Install())->up(); // after a run aborted in the Install checks

$save = function(ElementInterface $element, bool $validate = true): ElementInterface {
    Elements::saveElement($element, $validate) or throw new RuntimeException(json_encode($element->errors()->all()));
    return $element;
};
/** The element as stored, in its site or another one. */
$reload = fn(ElementInterface $element, ?int $siteId = null): ElementInterface => $element::find()->id($element->id)->siteId($siteId ?? $element->siteId)->status(null)->drafts(null)->one() ?? throw new RuntimeException("Element $element->id is gone");
$make = function(string $section, string $title, array $attributes = []) use ($save): Entry {
    $sectionModel = Sections::getSectionByHandle($section) ?? throw new RuntimeException("No section $section");
    $entry = new Entry($attributes + ['sectionId' => $sectionModel->id, 'typeId' => $sectionModel->getEntryTypes()[0]->id, 'title' => $title]);
    $save($entry);
    return $entry;
};
$rows = fn(ElementInterface $element): array => DB::table(Slugs::TABLE)->where('elementId', $element->id)->pluck('enabled')->map(fn($v): bool => (bool)$v)->all();

echo "Settings\n";
$newsSection = Sections::getSectionByHandle('news') ?? throw new RuntimeException('Run the seed first');
$pagesSection = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('Run the seed first');
$landing = EntryTypes::getEntryTypeByHandle('landingPage') ?? throw new RuntimeException('Run the seed first');
check($settings->enabledSections === [$newsSection->uid], 'the seeded settings: News on by default, stored by UID');
$model = new Settings();
$model->enabledSections = '';
check($model->enabledSections === [] && $model->validate(), 'an empty checkbox list ("" or null) normalizes to an empty list');
$model->enabledSections = [';', 'news', 3];
check($model->enabledSections === ['news'], 'the ";" placeholder of settings saved before 3.1 and non-strings are dropped');
check(count($pagesSection->getEntryTypes()) === 2, 'the Pages section has two entry types (Page, Landing page)');
check(Slugs::settingKeys(new Asset()) === [] && !$slugs->isEnabledByDefault(new Asset()) && !$slugs->supports(new Asset()), 'other element types (assets, users) have no setting and are never followed');

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

echo "Stop words\n";
$settings->stopWords = ['en' => ['a', 'and', 'the'], 'de' => ['ein', 'in']];
check($slugs->titleForSlug('A weekend in Hong Kong and more', 'en') === 'weekend in Hong Kong more', 'the stop words of the site language fall out of the title: whole words, any case');
check($slugs->titleForSlug('The The', 'en') === 'The The', 'a title of nothing but stop words keeps every word');
check($slugs->titleForSlug('Ein Tag in Wien', 'de-CH') === 'Tag Wien', '"de-CH" falls back to the words of "de"');
check($slugs->titleForSlug('Andante', 'en') === 'Andante' && $slugs->titleForSlug('Brand new', 'fr') === 'Brand new', 'inside a word nothing is dropped, and a language without words keeps the title');
$stopped = $make('news', 'Smoke: The road and the hills');
check($reload($stopped)->slug === 'smoke-road-hills', 'a News entry gets its slug without them');
$settings->stopWords = [];
$stopped->title = 'Smoke: The road and the hills again';
$save($stopped);
check($reload($stopped)->slug === 'smoke-the-road-and-the-hills-again', 'without words, every word stays (the default)');
$model = new Settings();
$model->stopWords = ['en' => 'A, and  the,', 'de' => '', 'fr' => ['Le', 'le'], '' => 'x'];
check($model->stopWords === ['en' => 'a, and, the', 'fr' => 'le'] && $model->validate(), 'the settings page posts a comma-separated line per language, the config file lists: one line of trimmed, lower-cased, unique words each; languages without words are dropped');
check(count(Slugs::siteLanguages()) === 2 && Slugs::siteLanguages()[1]['id'] === 'de', 'the settings page gets a line per site language (en, de)');

echo "Entry types and handles\n";
$landingEntry = $make('pages', 'Smoke: Landing', ['typeId' => $landing->id, 'slug' => 'smoke-landing']);
check(Slugs::settingKeys($landingEntry) === [$pagesSection->uid, 'pages', "$pagesSection->uid:$landing->uid", 'pages:landingPage'], 'an entry matches its section and its entry type, by UID and by handle (' . implode(', ', Slugs::settingKeys($landingEntry)) . ')');
$settings->enabledSections = [$newsSection->uid, "$pagesSection->uid:$landing->uid"];
check($slugs->isEnabledByDefault($landingEntry) && !$slugs->isEnabledByDefault($p), 'a single entry type of a section can be on while the rest of the section is off');
$landingEntry->title = 'Smoke: Landing renamed';
$save($landingEntry);
check($reload($landingEntry)->slug === 'smoke-landing-renamed', 'and its entries follow their title');
$settings->enabledSections = ['news', 'pages:landingPage'];
check($slugs->isEnabledByDefault($a) && $slugs->isEnabledByDefault($landingEntry) && !$slugs->isEnabledByDefault($p), 'handles work too (config/craft/slug-equals-title.php, settings saved before 3.1)');
$settings->enabledSections = [$newsSection->uid];

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
check($rows($a) === [true], 'one row per element: the latest choice replaces the earlier one');
$a->title = 'Smoke: Back on';
$save($a);
check($reload($a)->slug === 'smoke-back-on', 'switched on again, the entry follows its title again');
check($slugs->isEnabled($reload($a)) && $slugs->isEnabled($reload($p)), 'isEnabled() reads the remembered choice');

echo "Resaving\n";
DB::table('elements_sites')->where(['elementId' => $a->id, 'siteId' => $primary->id])->update(['slug' => 'smoke-stale']);
Elements::resaveElements(Entry::find()->id($a->id)->status(null));
check($reload($a)->slug === 'smoke-back-on', 'php artisan craft:resave:entries brings a stale slug back in line with the title');

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
$draft = Drafts::createDraft($a, null, 'Smoke draft');
check($slugs->isEnabled($draft), 'a draft takes the remembered choice of its canonical entry');
$draft->title = 'Smoke: Draft title';
$save($draft);
check($reload($draft)->slug === 'smoke-draft-title', 'a draft follows its title');
$slugs->setEnabled($draft, false);
$draft->slug = 'smoke-draft-own';
$save($draft);
check($reload($draft)->slug === 'smoke-draft-own' && $slugs->isEnabled($reload($a)), "the draft's own choice wins over the canonical entry's, which keeps its own");
$draftId = $draft->id;
Drafts::applyDraft($draft);
$applied = $reload($a);
check($applied->title === 'Smoke: Draft title' && $applied->slug === 'smoke-draft-title', 'applying the draft saves the canonical entry with its own choice: the slug follows the title');
check(!DB::table(Slugs::TABLE)->where('elementId', $draftId)->exists(), "the draft's row went with the draft");
$revision = Entry::find()->revisionOf($a)->status(null)->orderBy('elements.dateCreated', 'desc')->one();
check($revision instanceof Entry && $revision->getIsRevision() && $rows($revision) === [], 'saves create revisions; the plugin leaves them alone');

echo "Empty titles\n";
$e = $make('news', 'Smoke: Titled');
$e->title = '';
$save($e, false);
check($reload($e)->slug === 'smoke-titled', 'an empty title leaves the slug alone (#19)');

echo "Nested entries\n";
$page = $make('pages', 'Smoke: With blocks', ['slug' => 'smoke-blocks']);
$page->setFieldValue('blocks', ['new1' => ['type' => 'textBlock', 'enabled' => true, 'title' => 'Smoke: A block', 'fields' => ['body' => 'A block of text.']]]);
$save($page);
$blocksField = Fields::getFieldByHandle('blocks') ?? throw new RuntimeException('No blocks field');
$block = Entry::find()->ownerId($page->id)->fieldId($blocksField->id)->status(null)->one();
check($block instanceof Entry && !$slugs->supports($block) && !$slugs->isEnabled($block), 'nested entries (Matrix blocks) have no section: not supported, never followed');
check($reload($page)->slug === 'smoke-blocks', 'the page keeps its slug');

echo "Install\n";
$install = new Install();
$install->down();
check(!Schema::hasTable(Slugs::TABLE), 'uninstalling drops the table');
$install->up();
check(Schema::hasTable(Slugs::TABLE), 'and installing creates it again');
// what the seed remembered (dev/seed.php), for the suites that follow
$about = Entry::find()->section('pages')->slug('about')->status(null)->one();
if ($about instanceof Entry) {
    $slugs->setEnabled($about, false);
}

$cleanup();
echo "All ok\n";
