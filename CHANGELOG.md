# Slug Equals Title Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased

### Added
- Stop words, optional: words listed per site language in the settings (a line per language) or in `config/craft/slug-equals-title.php` fall out of the title before it becomes the slug, as whole words in any case. Off until you list words, so the update changes no slug and needs no migration; a title of nothing but stop words keeps every word. The config example lists a starting point for every language Craft ships, commented out. [#17](https://github.com/internetztube/craft-slug-equals-title/issues/17)

## 4.0.0-alpha.1 - 2026-11-04

The plugin for Craft 6. Everything 3.1 does, on Craft 6's new editor and its slideouts. See [`UPGRADE.md`](UPGRADE.md).

### Breaking changes
- **Craft 6 and PHP 8.5.** Sites on Craft 5 stay on 3.x. Update to 3.1.0 first: 4.0 expects its table and settings.
- **The config file lives at `config/craft/slug-equals-title.php`** (Craft 6's place for plugin config); the keys are the same.
- **The namespace is `Internetztube\SlugEqualsTitle`** (Craft 6's style); the service is `Slugs`, a singleton.
- **Entries only.** A native Craft 6 plugin, without Craft's Yii2 adapter: categories and Craft Commerce products (Craft 6 has neither natively) are not supported; `enabledCategoryGroups` and `enabledProductTypes` are ignored.

### Changed
- On Craft 6's edit pages the switch is a field of the editor's sidebar form that the plugin's script moves into the slug input, next to the “Regenerate slug” button; while it is on, “Overwritten by title” takes the input's place, as in the slideouts and in 3.x.
- Craft 6's edit page posts only what changed, with the entry's id: a save without the switch in the request follows the choice the switch showed (remembered or default), a toggled switch is remembered. Requests that do not name the entry (index actions, other elements saved along) leave the slug alone.
- The settings page is Craft 6's form: a checkbox list of the sections, a section's entry types under it, read-only when the config file sets it.

## 3.1.0 - 2026-11-03

A rewrite under the hood that keeps the behaviour: less code (no asset bundle, no build step, no record class) and three test suites.

### Added
- The switch is a native Craft lightswitch in the slug field, next to its input, on edit pages and in slideouts. While it is on, the slug field reads “Overwritten by title”, as before. Revisions show it read-only. [#23](https://github.com/internetztube/craft-slug-equals-title/pull/23)
- A section with several entry types lists them under it on the settings page: tick the section for all its entries, or only some of its entry types (`section:entryType` in the config file).
- `config/slug-equals-title.php` sets the defaults per environment (see [`src/config.php`](src/config.php)); the settings page shows overridden lists read-only.
- A draft follows the choice of its canonical entry until its own switch is changed.
- Translations into every language Craft ships (31).
- Three test suites run on every push, on PHP 8.2 and 8.3, MySQL and PostgreSQL, with 100 % line coverage of `src/`: the console, the control panel over HTTP, and the control panel in headless Chrome. [`dev/TESTS.md`](dev/TESTS.md) lists every check.

### Changed
- Entry types that hide the slug field (and product types with the slug field off) show no switch.
- Nested entries (Matrix) are never touched: they have no section.
- The choice is remembered for the element the form is about, not for other elements a plugin saves in the same request; drafts and revisions never leave rows behind.
- Settings name sections, category groups and product types by UID, as Craft does, so a renamed handle keeps its setting; the update converts stored settings, and the config file may still use handles. The stray `;` the settings page stored in every list is dropped.

### Fixed
- The plugin's table is `slugequalstitle_elements` now (lower-case, one row per element). A MySQL dump from macOS or Windows lower-cased the old `slugEqualsTitle_shouldRewrite`, which made the plugin fail on Linux with “Base table or view not found”. The update's migration moves the remembered choices over. [#21](https://github.com/internetztube/craft-slug-equals-title/issues/21)
- When two elements get the same title, Craft's own URI check now makes the second slug unique (`-2`), as it does for new entries.
- The plugin icon has the same finish as the author's other plugins.
- The asset bundle is no longer registered on every control panel page. [#12](https://github.com/internetztube/craft-slug-equals-title/issues/12)

## 3.0.1 - 2026-01-15
### Fixed
- Fixed Issue which occurred when Entry does not have a Entry Type. [#22](https://github.com/internetztube/craft-slug-equals-title/pull/22) Thank you  [@ishetnogferre](https://github.com/ishetnogferre)!

## 3.0.0-beta.1 - 2024-04-09
### Added
- Added support for [Craft CMS 5.0.0](https://github.com/craftcms/cms/releases/tag/5.0.0).
- Added support for [Craft Commerce 5.0.0-beta.2](https://github.com/craftcms/commerce/releases/tag/5.0.0-beta.2).

## 2.0.1 - 2023-07-20
### Fixed
- Fixed bug that occurred when title was empty. [#19](https://github.com/internetztube/craft-slug-equals-title/issues/19) [#20](https://github.com/internetztube/craft-slug-equals-title/pull/20) Thank you [@jorisnoo](https://github.com/jorisnoo)!

## 2.0.0 - 2023-02-09
### Fixed
- Use `ElementHelper::generateSlug` instead of `StringHelper::slugify`. Thank you, [@engram-design](https://github.com/engram-design). [#18](https://github.com/internetztube/craft-slug-equals-title/pull/18)

## 2.0.0-beta.1 - 2022-04-02
### Added
- Added support for [Craft CMS 4.0.0-beta.3](https://github.com/craftcms/cms/releases/tag/4.0.0-beta.3).
- Added support for [Unified Element Editor](https://craftcms.com/docs/4.x/extend/updating-plugins.html#unified-element-editor).
- Added support for [Craft Commerce 4.0.0-beta.1](https://github.com/craftcms/commerce/releases/tag/4.0.0-beta.1).

## 1.3.4 - 2021-11-28
### Fixed
- Use `StringHelper::slugify` before setting `$entry->slug`. Fix [#15](https://github.com/internetztube/craft-slug-equals-title/issues/15).

## 1.3.3 - 2021-10-17
### Updated
- Updated Icon.

## 1.3.2 - 2021-09-06
### Fixed
- Fix categories. [#14](https://github.com/internetztube/craft-slug-equals-title/issues/14)

## 1.3.1 - 2021-02-02
### Fixed
- Only register Asset Bundle when necessary. [#12](https://github.com/internetztube/craft-slug-equals-title/issues/12)

## 1.3.0 - 2021-01-04
### Added
- Added support for Commerce Products and Categories.

## 1.2.1 - 2020-08-18
### Fixed
- Fixed a bug that caused the lightswitch to disappear on Craft <= 3.4. Thanks @skoften! [#10](https://github.com/internetztube/craft-slug-equals-title/issues/10)

## 1.2.0 - 2020-07-31
### Added
- Support for `limitAutoSlugsToAscii`. [#8](https://github.com/internetztube/craft-slug-equals-title/issues/8)

## 1.1.5 - 2020-07-29
### Fixed
- Update element id JavaScript selector (`entryId` -> `sourceId`)

## 1.1.4 - 2019-09-05
### Fixed
- Fixed bug that occurred while resaving entries via console. [#3](https://github.com/internetztube/craft-slug-equals-title/issues/3)

## 1.1.3 - 2019-08-18
### Fixed
- Fix CP slug field overwritten status bug.

## 1.1.2 - 2019-08-15
### Fixed
- Logic of get parameter was adjusted.

### Changed
- Icon changed.

## 1.1.1 - 2019-08-15
### Added
- Support for disabling overwrite for a single entry
- Support for section types

### Removed
- Support for non-entry elements

## 1.0.1 - 2019-07-14
### Added
- Support for selectable ElementsTypes in CP.

## 1.0.0 - 2019-07-14
### Added
- Initial release
