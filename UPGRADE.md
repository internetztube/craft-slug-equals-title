# Upgrading to 4.0 (Craft 6)

Version 4 is the same plugin, built natively for Craft 6 (a Laravel plugin, no Yii2 adapter). The switch next to the
slug field, the defaults per section and entry type, and the remembered choice per entry work as in 3.1. This page lists what you may have to
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

Move `config/slug-equals-title.php` to `config/craft/slug-equals-title.php`. `enabledSections` is as before: sections by
handle or UID, with `section:entryType` for one entry type of a section. `enabledCategoryGroups` and `enabledProductTypes`
are ignored (see Element types); remove them when convenient.

## Command line

Craft 6 runs commands with `php artisan`. The plugin has no commands of its own; what changes is Craft's resave:

| 3.x                                            | 4.x                                                |
|------------------------------------------------|----------------------------------------------------|
| `php craft resave/entries --section=news`      | `php artisan craft:resave:entries --section=news`  |

## The editor

Craft 6 has a new editor (edit pages) and keeps the previous one for slideouts. The switch is in both, in the slug
field next to its input, and while it is on “Overwritten by title” takes the input's place, as in 3.x.

Craft 6's edit page posts only the fields that changed, with the entry's id (directly, or through a provisional draft
after an autosave). The plugin treats such a save without the switch in the request as “the switch as it was shown”:
the remembered choice of the entry, or the default of its section. Toggling the switch posts it and is remembered.
In 3.x a web save without the field left the slug alone; on Craft 6 that is no longer telling, so any web save that
names the entry follows its remembered choice. Requests that do not name it (index actions on many entries, other
entries a plugin saves along) still leave the slug alone.

## Element types

- Entries: as before.
- Categories and Craft Commerce products: not on 4.x. Craft 6 has them only through compatibility layers (Craft's Yii2
  adapter; Commerce has no Craft 6 release), and 4.x is a native Craft 6 plugin without one. Their settings in the
  project config or a config file are ignored. 3.x keeps supporting them on Craft 5.

## PHP: namespaces

The namespace follows Craft 6's style: `internetztube\slugEqualsTitle\…` is now `Internetztube\SlugEqualsTitle\…`.

| 3.x                                                   | 4.x                                           |
|-------------------------------------------------------|-----------------------------------------------|
| `internetztube\slugEqualsTitle\SlugEqualsTitle`       | `Internetztube\SlugEqualsTitle\SlugEqualsTitle` |
| `…\services\SlugService`                              | `…\Slugs` (a singleton: `app(Slugs::class)`)   |
| `…\models\Settings`                                   | `…\Settings` (a `PluginSettings`)              |

The form field of the switch keeps its name, `slugEqualsTitle_shouldRewrite`.
