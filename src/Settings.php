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
        ];
    }
}
