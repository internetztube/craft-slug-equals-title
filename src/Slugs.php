<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Str;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

use function CraftCms\Cms\t;

/**
 * Whether an element's slug follows its title: the element's remembered choice (its own, else its canonical
 * element's), else the default of its section (or entry type), category group or product type from the plugin settings.
 */
#[Singleton]
final class Slugs
{
    /** One row per element that was saved from an edit form: `elementId`, `enabled`. Rows go with their element. */
    public const TABLE = 'slugequalstitle_elements';

    /** Categories exist in Craft 6 only through Craft's Yii2 adapter; Commerce has no Craft 6 release yet. */
    public const CATEGORY = 'craft\\elements\\Category';
    public const PRODUCT = 'craft\\commerce\\elements\\Product';

    /**
     * Element types with a title, a slug field and a type (section, category group, product type) the settings list.
     * @return list<class-string<ElementInterface>>
     */
    public static function elementTypes(): array
    {
        /** @var list<class-string<ElementInterface>> $types */
        $types = array_values(array_filter([Entry::class, self::CATEGORY, self::PRODUCT], 'class_exists'));
        return $types;
    }

    /**
     * The plugin settings with the types they list, for the settings page: setting name => label and types (sections,
     * category groups, product types: objects with a name, a handle and a UID).
     * @return array<string, array{label: string, types: list<Section|object{name: string, handle: string, uid: string|null}>}>
     */
    public static function settingGroups(): array
    {
        /** @var list<Section> $sections */
        $sections = array_values(Sections::getAllSections()->all());
        $groups = [
            'enabledSections' => ['label' => t('Sections'), 'types' => $sections],
        ];
        if (class_exists(self::CATEGORY) && class_exists(\Craft::class)) {
            /** @var list<object{name: string, handle: string, uid: string|null}> $categoryGroups the adapter's models */
            $categoryGroups = array_values(\Craft::$app->getCategories()->getAllGroups());
            $groups['enabledCategoryGroups'] = ['label' => t('Category Groups'), 'types' => $categoryGroups];
        }
        // ponytail: no product types list until Craft Commerce ships for Craft 6 (enabledProductTypes is accepted by handle meanwhile)
        return $groups;
    }

    /** The handle of the element's section, category group or product type; null for nested entries. */
    public static function typeHandle(ElementInterface $element): ?string
    {
        return match (true) {
            $element instanceof Entry => $element->getSection()?->handle,
            is_a($element, self::CATEGORY) => $element->getGroup()->handle,
            is_a($element, self::PRODUCT) => $element->getType()->handle,
            default => null,
        };
    }

    /** The plugin setting that lists the enabled types of the element's class. */
    public static function settingName(ElementInterface $element): ?string
    {
        return match (true) {
            $element instanceof Entry => 'enabledSections',
            is_a($element, self::CATEGORY) => 'enabledCategoryGroups',
            is_a($element, self::PRODUCT) => 'enabledProductTypes',
            default => null,
        };
    }

    /**
     * Every value in a setting that means this element's type: the type's UID (what the settings page stores) and
     * handle (config/craft/slug-equals-title.php, settings saved before 3.1), and for entries also "section:entryType"
     * as UIDs and as handles. Empty for nested entries (no section).
     * @return list<string>
     */
    public static function settingKeys(ElementInterface $element): array
    {
        if ($element instanceof Entry) {
            $section = $element->getSection();
            if ($section === null) {
                return [];
            }
            $entryType = $element->getType();
            return [(string)$section->uid, (string)$section->handle, "$section->uid:$entryType->uid", "$section->handle:$entryType->handle"];
        }
        $type = match (true) {
            is_a($element, self::CATEGORY) => $element->getGroup(),
            is_a($element, self::PRODUCT) => $element->getType(),
            default => null,
        };
        return $type === null ? [] : [(string)$type->uid, (string)$type->handle];
    }

    /** Entries of a section, categories and products; not nested entries (Matrix) and not other element types. */
    public function supports(ElementInterface $element): bool
    {
        return self::typeHandle($element) !== null;
    }

    /** The default for the element's type, from the settings (see settingKeys()). */
    public function isEnabledByDefault(ElementInterface $element): bool
    {
        $setting = self::settingName($element);
        if ($setting === null) {
            return false;
        }
        return array_intersect(self::settingKeys($element), app(Settings::class)->$setting) !== [];
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
