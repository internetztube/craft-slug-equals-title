# Upgrading to 4.0 (Craft 6)

Version 4 is the same plugin, built for Craft 6. The switch next to the slug field, the defaults per section, entry
type and category group, and the remembered choice per element work as in 3.1. This page lists what you may have to
change in your own config and scripts.

## Requirements

- Craft 6 and PHP 8.5.
- Sites that stay on Craft 5 keep using 3.x.
- Update to 3.1.0 or later before Craft 6: the Craft 6 version expects 3.1's table and settings and ships no older
  migrations.

## Update

```bash
composer require internetztube/craft-slug-equals-title:^4.0
php artisan craft:migrate:all
```

The remembered choices (which entries follow their title and which keep their slug) and the settings carry over.

## Config file

Move `config/slug-equals-title.php` to `config/craft/slug-equals-title.php`. The keys are the same:
`enabledSections`, `enabledCategoryGroups`, `enabledProductTypes`, by handle or UID, with `section:entryType` for one
entry type of a section.

## Command line

Craft 6 runs commands with `php artisan`. The plugin has no commands of its own; what changes is Craft's resave:

| 3.x                                            | 4.x                                                |
|------------------------------------------------|----------------------------------------------------|
| `php craft resave/entries --section=news`      | `php artisan craft:resave:entries --section=news`  |

## The editor

Craft 6 has a new editor (edit pages) and keeps the previous one for slideouts. The switch is in both, next to the
slug field. One difference in what it looks like: on edit pages the slug input stays visible while the switch is on
(the previous editor showed “Overwritten by title” in its place); the slug is set from the title on save all the same.

Craft 6's editor posts only the fields that changed. The plugin treats an editor save without the switch in the request
as “the switch as it was shown”: the remembered choice of the element, or the default of its type. Toggling the switch
posts it and is remembered. Saves from other web requests (queue jobs, index actions, imports, other plugins) still
leave the slug alone, as since 1.x.

## Element types

- Entries: as before.
- Categories: Craft 6 has them only through Craft's Yii2 adapter (`craftcms/yii2-adapter`). With it installed, category
  groups appear in the settings and categories get the switch, as in 3.x.
- Craft Commerce products: Commerce has no Craft 6 release yet. Product types return with it; the setting
  `enabledProductTypes` stays in place.

## PHP: namespaces

The namespace follows Craft 6's style: `internetztube\slugEqualsTitle\…` is now `Internetztube\SlugEqualsTitle\…`.

| 3.x                                                   | 4.x                                           |
|-------------------------------------------------------|-----------------------------------------------|
| `internetztube\slugEqualsTitle\SlugEqualsTitle`       | `Internetztube\SlugEqualsTitle\SlugEqualsTitle` |
| `…\services\SlugService`                              | `…\Slugs` (a singleton: `app(Slugs::class)`)   |
| `…\models\Settings`                                   | `…\Settings` (a `PluginSettings`)              |

The form field of the switch keeps its name, `slugEqualsTitle_shouldRewrite`.
