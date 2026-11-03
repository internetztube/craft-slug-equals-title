<!--
Text for the Plugin Store listing on plugins.craftcms.com (Craft Console → Plugins → Slug Equals Title).
Short description: The slug follows the title. Per section by default, per entry when you want.
Screenshots, in this order: edit-page, own-slug, slideout, front-end, settings (docs/screenshots/*.jpg); cover: docs/screenshots/share.png.
Paste everything below this comment into “Long description”.
-->

# The slug follows the title

Craft fills in the slug once, when an entry is new. Rename the entry later and the slug stays behind. Slug Equals Title keeps the two together: for the sections, category groups and product types you pick, every save sets the slug from the title. Each entry has a switch in its slug field to keep a slug of its own.

## What you get

- **A switch in the slug field.** A native Craft lightswitch next to the slug input of entries, categories and Commerce products, on edit pages and in slideouts. While it is on, the slug input is locked and shows the slug the title will get, as you type.
- **Defaults per type.** Tick the sections, category groups and product types in the settings, or set them per environment in `config/slug-equals-title.php`.
- **Remembered per element.** The switch is stored with the element, so `resave/entries` and other console saves do what the editor decided. Drafts take the choice of their entry.
- **Craft’s rules.** Slugs are made the way Craft makes them for new entries: the site’s language, `limitAutoSlugsToAscii`, and a unique URI when two titles collide.
- **Nothing else is touched.** Elements switched off, revisions, Matrix blocks and elements without a title keep their slug.

## Tested

Three test suites on every push, on PHP 8.2 and 8.3, MySQL and PostgreSQL: the console, the control panel over HTTP, the control panel in a real browser. 100 % code coverage.

## Requirements

Craft CMS 5.0 or later. For Craft 4 use version 2.x, for Craft 3 version 1.x.

[Documentation](https://github.com/internetztube/craft-slug-equals-title#readme) · [Tour with screenshots](https://github.com/internetztube/craft-slug-equals-title/blob/3.x/docs/README.md) · [Changelog](https://github.com/internetztube/craft-slug-equals-title/blob/3.x/CHANGELOG.md)
