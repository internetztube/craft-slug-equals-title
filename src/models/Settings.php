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
        parent::setAttributes($values, $safeOnly);
    }

    /** @return list<array<int|string, mixed>> */
    protected function defineRules(): array
    {
        return [[self::LISTS, 'each', 'rule' => ['string']]];
    }
}
