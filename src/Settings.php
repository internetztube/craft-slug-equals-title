<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/**
 * Which sections get the slug from the title by default. Each entry decides for itself with the switch on its edit
 * form; this is the default for entries that never made a choice. Values are UIDs (the settings page) or handles
 * (config/craft/slug-equals-title.php, settings saved before 3.1); a section lists all its entries, "section:entryType"
 * only the entries of that type. Other keys of older settings (category groups, product types of 3.x) are ignored.
 */
class Settings extends PluginSettings
{
    /** @var list<string> Sections (or "section:entryType") whose entries follow the title by default. */
    public array $enabledSections = [] {
        set(mixed $value) => self::handles($value);
    }

    /**
     * @var array<string, string> Words dropped from the title before it becomes the slug, per language: one line each,
     * "a, and, the", as the settings page edits it. Keyed by language ("de"), not locale: Craft keeps de-AT and de-DE
     * apart, the words do not (a locale key counts as its language). Empty: every word stays.
     */
    public array $stopWords = [] {
        set(mixed $value) => self::linesPerLanguage($value);
    }

    /**
     * A list of handles or UIDs: an empty checkbox list posts "" or null, settings saved before 3.1 carried a ";"
     * placeholder.
     * @return list<string>
     */
    private static function handles(mixed $value): array
    {
        $list = is_array($value) ? $value : [];
        return array_values(array_filter($list, fn(mixed $handle): bool => is_string($handle) && $handle !== '' && $handle !== ';'));
    }

    /**
     * The settings page posts a comma-separated line per language, the config file lists: both become one line of
     * lower-cased unique words, "a, and, the"; languages without words are dropped, a locale ("de-CH") counts as its
     * language ("de").
     * @return array<string, string>
     */
    private static function linesPerLanguage(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $language => $words) {
            $list = is_array($words) ? $words : (preg_split('/[\s,;]+/u', is_string($words) ? $words : '') ?: []);
            $list = array_filter(array_map(fn(mixed $word): string => mb_strtolower(trim(is_string($word) ? $word : '')), $list));
            $language = is_string($language) ? strtolower(explode('-', $language)[0]) : '';
            if ($list !== [] && $language !== '') {
                $result[$language] = implode(', ', array_unique(array_filter([...explode(', ', $result[$language] ?? ''), ...$list])));
            }
        }
        return $result;
    }

    /** @return array<string, list<string>> */
    #[Override]
    public function getRules(): array
    {
        return [
            'enabledSections' => ['array'],
            'enabledSections.*' => ['string'],
            'stopWords' => ['array'],
            'stopWords.*' => ['string'],
        ];
    }
}
