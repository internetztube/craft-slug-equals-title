<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/**
 * Which types get the slug from the title by default. Each element decides for itself with the switch on its edit
 * form; these are the defaults for elements that never made a choice. Values are UIDs (the settings page) or handles
 * (config/craft/slug-equals-title.php, settings saved before 3.1); a section lists all its entries, "section:entryType"
 * only the entries of that type.
 */
class Settings extends PluginSettings
{
    public const LISTS = ['enabledSections', 'enabledCategoryGroups', 'enabledProductTypes'];

    /** @var list<string> Sections (or "section:entryType") whose entries follow the title by default. */
    public array $enabledSections = [] {
        set(mixed $value) => self::handles($value);
    }

    /** @var list<string> Category groups whose categories follow the title by default. */
    public array $enabledCategoryGroups = [] {
        set(mixed $value) => self::handles($value);
    }

    /** @var list<string> Commerce product types whose products follow the title by default. */
    public array $enabledProductTypes = [] {
        set(mixed $value) => self::handles($value);
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

    /** @return array<string, list<string>> */
    #[Override]
    public function getRules(): array
    {
        return [
            'enabledSections' => ['array'],
            'enabledSections.*' => ['string'],
            'enabledCategoryGroups' => ['array'],
            'enabledCategoryGroups.*' => ['string'],
            'enabledProductTypes' => ['array'],
            'enabledProductTypes.*' => ['string'],
        ];
    }
}
