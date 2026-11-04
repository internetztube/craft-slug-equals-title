# Slug Equals Title for Craft CMS 5

**The slug follows the title. Per section by default, per entry when you want.**

Craft fills in the slug once, when an entry is new. Rename the entry later and the slug stays behind. This plugin keeps the two together: for the sections, category groups and product types you pick, every save sets the slug from the title. Each entry has a switch in its slug field to opt out (or in) and keep a slug of its own.

![Slug Equals Title: the switch in the slug field, a slug of your own, the defaults per section](docs/screenshots/cover.jpg)

[Tour with screenshots](docs/README.md) · [Installation](#installation) · [How it decides](#how-it-decides) · [Configuration](#configuration)

## What you get

- **A switch in the slug field.** A native Craft lightswitch next to the slug input of entries, categories and Commerce products, on edit pages and in slideouts. While it is on, the slug input is locked and shows the slug the title will get, as you type.
- **Defaults per type.** Tick the sections, category groups and product types in the plugin settings, or only some entry types of a section. Or set them per environment in `config/slug-equals-title.php`. Elements of those types follow their title unless their switch says otherwise.
- **Remembered per element.** The switch is stored with the element, so `php craft resave/entries` and other console saves do what the editor decided. Drafts take the choice of their entry until they make their own.
- **Craft's rules.** The slug is made the way Craft makes it for new entries: the site's language (ß → ss, ü → ue in German sites), `limitAutoSlugsToAscii`, and a unique URI (`-2`) when two titles collide.
- **Stop words, optional.** Nothing is dropped unless you list words. List them per language in the settings (or the config file) and they fall out of the title before it becomes the slug: "A weekend in Hong Kong" → `weekend-hong-kong`. Whole words, any case; a title of nothing but stop words keeps every word. The config file ships example lists for every language Craft speaks, commented out.
- **Nothing else is touched.** Elements switched off, revisions, nested entries (Matrix) and elements without a title keep their slug.

## Tested

Three test suites run on every push, on PHP 8.2 and 8.3, on MySQL 8 and PostgreSQL 14: the console, the control panel over HTTP, and the control panel in headless Chrome. 100 % line coverage of `src/`. [`dev/TESTS.md`](dev/TESTS.md) lists every check in one sentence.

## Requirements

Craft CMS 5.0 or later, PHP 8.2 or later. For Craft 4, use the 2.x releases; for Craft 3, 1.x.

## Installation

Install from the Plugin Store in the control panel, or with Composer:

```sh
composer require internetztube/craft-slug-equals-title
php craft plugin/install slug-equals-title
```

Then tick the sections (category groups, product types) under Settings → Slug Equals Title.

Updating from 3.0: the remembered choices move to a new table (`slugequalstitle_elements`) with the update's migration; nothing to do.

## How it decides

On every save of an entry, category or product with a title, the plugin asks in this order:

1. **The edit form.** Saved from an edit page or a slideout, the switch decides, and the choice is remembered for the element.
2. **The console.** Saved by `resave/entries`, a migration or another console command, the element's remembered choice decides; an element that never made one follows the plugin settings for its section, category group or product type. A draft without a choice takes its canonical entry's.
3. **Anything else** (queue jobs, index actions, imports, other plugins saving in a web request) leaves the slug alone.

When the answer is yes, the slug is set from the title with Craft's own `ElementHelper::generateSlug()` in the site's language, and Craft's validators normalize it and keep the URI unique. When the answer is no, the slug stays what it is.

So after turning a section on, `php craft resave/entries --section=news` brings every entry in line, except the ones an editor switched off.

## Configuration

Copy [`src/config.php`](src/config.php) to `config/slug-equals-title.php` to set the defaults per environment. Settings from the file win over the control panel and are shown there read-only:

```php
return [
    'enabledSections' => ['news', 'pages:landingPage'],   // sections, or "section:entryType"
    'enabledCategoryGroups' => ['topics'],               // category groups
    'enabledProductTypes' => [],                         // Craft Commerce product types
];
```

Types are named by handle here; the settings page stores UIDs (a renamed handle keeps its setting), and both are accepted everywhere. Settings saved by 3.0 are converted to UIDs by the update.

**Stop words are optional.** Nothing is dropped until you list words, so an update changes no slug. To drop them, list them per language, on the settings page or in the config file. Per language, not per site: Craft keeps de-AT and de-DE apart as languages, the words do not, so `de` serves every German site and you enter them once:

```php
'stopWords' => [
    'en' => ['a', 'an', 'and', 'the', 'of'],
    'de' => ['der', 'die', 'das', 'und'],
],
```

[`src/config.php`](src/config.php) carries an example list for every language Craft speaks, commented out: uncomment yours and trim it. Japanese, Korean, Chinese and Thai have no spaces between words, so there is nothing to drop there.


## Supported element types

Entries (of a section; Matrix blocks are entries without one and are left alone), categories, and Craft Commerce products. Entry types that hide the slug field show no switch.

## Screenshots

Every part of it, shown in the dev project: [docs/README.md](docs/README.md).

## Development

`dev/dev.sh` creates a throwaway Craft 5 project with Craft Commerce, CKEditor and a German site, seeds the sections and the demo content (a travel journal, "Transit", with the author's own photos) and starts a server at http://localhost:8080/admin (admin / password).

```sh
dev/dev.sh                      # setup + serve (PostgreSQL)
DB_DRIVER=mysql PROJECT_DIR=../craft-slug-equals-title-dev-mysql PORT=8081 dev/dev.sh
dev/dev.sh smoke                # seed and run dev/smoke.php (the console)
dev/dev.sh e2e                  # the control panel over HTTP
dev/dev.sh ui                   # the control panel in headless Chrome
dev/dev.sh screenshots          # retake docs/screenshots
vendor/bin/phpstan              # static analysis
```

GitHub Actions runs PHPStan and the three suites with coverage on PHP 8.2 and 8.3, on MySQL 8 and PostgreSQL 14.

## Support

Please report issues and feature requests on [GitHub](https://github.com/internetztube/craft-slug-equals-title/issues).

Icon made by [Freepik](https://www.flaticon.com/authors/freepik) from [www.flaticon.com](https://www.flaticon.com/), licensed [CC 3.0 BY](http://creativecommons.org/licenses/by/3.0/).

Brought to you by [Frederic Koeberl](https://frederickoeberl.com/)
