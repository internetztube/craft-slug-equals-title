<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\I18N;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Str;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Whether an entry's slug follows its title: the entry's remembered choice (its own, else its canonical entry's), else
 * the default of its section or entry type from the plugin settings.
 */
#[Singleton]
final class Slugs
{
    /** One row per element that was saved from an edit form: `elementId`, `enabled`. Rows go with their element. */
    public const TABLE = 'slugequalstitle_elements';

    /** Entries of a section; not nested entries (Matrix: no section) and not other element types. */
    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry && $element->getSection() !== null;
    }

    /**
     * Every value in the settings that means this entry's type: its section's UID (what the settings page stores) and
     * handle (config/craft/slug-equals-title.php, settings saved before 3.1), and "section:entryType" as UIDs and as
     * handles. Empty for nested entries (no section) and other element types.
     * @return list<string>
     */
    public static function settingKeys(ElementInterface $element): array
    {
        if (!$element instanceof Entry || ($section = $element->getSection()) === null) {
            return [];
        }
        $entryType = $element->getType();
        return [(string)$section->uid, (string)$section->handle, "$section->uid:$entryType->uid", "$section->handle:$entryType->handle"];
    }

    /**
     * The title without the stop words of the site's language (de-AT and de-DE both use "de"): whole words, any case.
     * The title as it is when no words are set, or when nothing but stop words would be left.
     */
    public function titleForSlug(string $title, ?string $language): string
    {
        $language ??= '';
        $lines = app(Settings::class)->stopWords;
        $line = $lines[strtolower(explode('-', $language)[0])] ?? '';
        if ($line === '') {
            return $title;
        }
        $words = explode(', ', $line);
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn(string $word): string => preg_quote($word, '/'), $words)) . ')(?![\p{L}\p{N}])/iu';
        $stripped = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace($pattern, ' ', $title)));
        return preg_match('/[\p{L}\p{N}]/u', $stripped) === 1 ? $stripped : $title;
    }

    /**
     * The languages of the sites, for the settings page (a line of stop words each): "de" for de-AT and de-DE alike,
     * with the language's display name. Craft has no such grouping of its own.
     * @return list<array{id: string, name: string}>
     */
    public static function siteLanguages(): array
    {
        $languages = [];
        /** @var list<Site> $sites */
        $sites = Sites::getAllSites()->all();
        foreach ($sites as $site) {
            $language = strtolower(explode('-', (string)$site->language)[0]);
            $languages[$language] ??= ['id' => $language, 'name' => I18N::getLocaleById($language)->getDisplayName(app()->getLocale())];
        }
        return array_values($languages);
    }

    /** The default for the entry's section or entry type, from the settings (see settingKeys()). */
    public function isEnabledByDefault(ElementInterface $element): bool
    {
        return array_intersect(self::settingKeys($element), app(Settings::class)->enabledSections) !== [];
    }

    /** The element's remembered choice (a draft falls back to its canonical element's), else the type's default. */
    public function isEnabled(ElementInterface $element): bool
    {
        $ids = array_values(array_unique(array_filter([$element->id, $element->getCanonicalId()])));
        $rows = $ids ? DB::table(self::TABLE)->whereIn('elementId', $ids)->pluck('enabled', 'elementId')->all() : [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                return (bool)$rows[$id];
            }
        }
        return $this->isEnabledByDefault($element);
    }

    /** Remembers the element's choice. */
    public function setEnabled(ElementInterface $element, bool $enabled): void
    {
        $now = now()->format('Y-m-d H:i:s');
        DB::table(self::TABLE)->upsert(
            [['elementId' => $element->id, 'enabled' => $enabled, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => (string)Str::uuid()]],
            ['elementId'],
            ['enabled', 'dateUpdated'],
        );
    }
}
