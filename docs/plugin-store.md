<!--
Text for the Plugin Store listing on plugins.craftcms.com (Craft Console → Plugins → Slug Equals Title), Craft 6 edition.
Short description: The slug follows the title. Per section by default, per entry when you want.
Screenshots, in this order: edit-page, own-slug, settings (docs/screenshots/*.jpg); cover: docs/screenshots/cover.jpg.
Paste everything below this comment into “Long description”.
-->

# The slug follows the title

Craft fills in the slug once, when an entry is new. Rename the entry later and the slug stays behind. Slug Equals Title keeps the two together: for the sections, entry types and category groups you pick, every save from the editor sets the slug from the title. Each entry has a switch next to its slug field to keep a slug of its own.

## What you get

- **A switch next to the slug field.** A native Craft lightswitch in the editor sidebar of entries, on edit pages and in slideouts. While it is on, every save sets the slug from the title.
- **Defaults per type.** Tick the sections, single entry types of a section, and category groups in the settings, or set them per environment in `config/craft/slug-equals-title.php`.
- **Remembered per element.** The switch is stored with the element, so later saves and `craft:resave:entries` do what the editor decided. Drafts take the choice of their entry.
- **Craft’s rules.** Slugs are made the way Craft makes them for new entries: the site’s language, `limitAutoSlugsToAscii`, and a unique URI when two titles collide.
- **Nothing else is touched.** Elements switched off, revisions, Matrix blocks and elements without a title keep their slug.

## Tested

Three test suites on every push, on PHP 8.5, MySQL and PostgreSQL: the console, the control panel over HTTP, the control panel in a real browser. 100 % code coverage.

## Requirements

Craft CMS 6 and PHP 8.5. Categories need Craft’s Yii2 adapter; Craft Commerce products return once Commerce exists for Craft 6. For Craft 5 use version 3.x, for Craft 4 version 2.x, for Craft 3 version 1.x.

[Documentation](https://github.com/internetztube/craft-slug-equals-title/blob/4.x/README.md) · [Tour with screenshots](https://github.com/internetztube/craft-slug-equals-title/blob/4.x/docs/README.md) · [Upgrade notes](https://github.com/internetztube/craft-slug-equals-title/blob/4.x/UPGRADE.md) · [Changelog](https://github.com/internetztube/craft-slug-equals-title/blob/4.x/CHANGELOG.md)
