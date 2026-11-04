<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle\models;

use craft\base\Model;

/**
 * Which types get the slug from the title by default. Each element decides for itself with the switch on its edit
 * form; these are the defaults for elements that never made a choice. Set per environment in config/slug-equals-title.php.
 */
final class Settings extends Model
{
    /** @var string[] Handles of the sections whose entries follow the title by default. */
    public array $enabledSections = [];

    /** @var string[] Handles of the category groups whose categories follow the title by default. */
    public array $enabledCategoryGroups = [];

    /** @var string[] Handles of the Commerce product types whose products follow the title by default. */
    public array $enabledProductTypes = [];

    /**
     * @var array<string, list<string>> Words dropped from the title before it becomes the slug, per site language
     * ("de-CH" falls back to "de"): whole words, any case. Empty: every word stays.
     */
    public array $stopWords = [];

    public const LISTS = ['enabledSections', 'enabledCategoryGroups', 'enabledProductTypes'];

    /**
     * @inheritdoc
     * @param array<string, mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (self::LISTS as $name) {
            if (array_key_exists($name, $values)) {
                // an empty checkbox list posts "" (Craft's hidden input); settings saved before 3.1 carried a ";" placeholder
                $list = is_array($values[$name]) ? $values[$name] : [];
                $values[$name] = array_values(array_filter($list, fn(mixed $handle): bool => is_string($handle) && $handle !== '' && $handle !== ';'));
            }
        }
        if (array_key_exists('stopWords', $values)) {
            $values['stopWords'] = self::wordsPerLanguage($values['stopWords']);
        }
        parent::setAttributes($values, $safeOnly);
    }

    /**
     * The settings page posts a comma-separated line per language, the config file lists; both become lower-cased
     * unique words, languages without words are dropped.
     * @return array<string, list<string>>
     */
    private static function wordsPerLanguage(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $language => $words) {
            $list = is_array($words) ? $words : (preg_split('/[\s,;]+/u', is_string($words) ? $words : '') ?: []);
            $list = array_filter(array_map(fn(mixed $word): string => mb_strtolower(trim(is_string($word) ? $word : '')), $list));
            if ($list !== [] && is_string($language) && $language !== '') {
                $result[$language] = array_values(array_unique($list));
            }
        }
        return $result;
    }

    /** @return list<array<int|string, mixed>> */
    protected function defineRules(): array
    {
        return [[self::LISTS, 'each', 'rule' => ['string']], [['stopWords'], 'safe']];
    }
}
