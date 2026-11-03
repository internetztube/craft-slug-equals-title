<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use internetztube\slugEqualsTitle\SlugEqualsTitle;
use yii\base\InvalidConfigException;

/**
 * Whether an element's slug follows its title: the element's remembered choice (its own, else its canonical
 * element's), else the default of its section (or entry type), category group or product type from the plugin settings.
 */
final class SlugService extends Component
{
    /** One row per element that was saved from an edit form: `elementId`, `enabled`. Rows go with their element. */
    public const TABLE = '{{%slugequalstitle_elements}}';

    /**
     * Element types with a title, a slug field and a type (section, category group, product type) the settings list.
     * @return list<class-string<ElementInterface>>
     */
    public static function elementTypes(): array
    {
        /** @var list<class-string<ElementInterface>> $types */
        $types = array_values(array_filter([Entry::class, Category::class, Product::class], 'class_exists'));
        return $types;
    }

    /**
     * The plugin settings with the types they list, for the settings page: setting name => label and types.
     * @return array<string, array{label: string, types: array<object>}>
     */
    public static function settingGroups(): array
    {
        $groups = [
            'enabledSections' => ['label' => Craft::t('app', 'Sections'), 'types' => Craft::$app->getEntries()->getAllSections()],
            'enabledCategoryGroups' => ['label' => Craft::t('app', 'Category Groups'), 'types' => Craft::$app->getCategories()->getAllGroups()],
        ];
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            $groups['enabledProductTypes'] = ['label' => Craft::t('commerce', 'Product Types'), 'types' => Commerce::getInstance()->getProductTypes()->getAllProductTypes()];
        }
        return $groups;
    }

    /** The handle of the element's section, category group or product type; null for nested entries. */
    public static function typeHandle(ElementInterface $element): ?string
    {
        return match (true) {
            $element instanceof Entry => $element->getSection()?->handle,
            $element instanceof Category => $element->getGroup()->handle,
            $element instanceof Product => $element->getType()->handle,
            default => null,
        };
    }

    /** The plugin setting that lists the enabled types of the element's class. */
    public static function settingName(ElementInterface $element): ?string
    {
        return match (true) {
            $element instanceof Entry => 'enabledSections',
            $element instanceof Category => 'enabledCategoryGroups',
            $element instanceof Product => 'enabledProductTypes',
            default => null,
        };
    }

    /**
     * Every value in a setting that means this element's type: the type's UID (what the settings page stores) and
     * handle (config/slug-equals-title.php, settings saved before 3.1), and for entries also "section:entryType" as
     * UIDs and as handles. Empty for nested entries (no section).
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
            $element instanceof Category => $element->getGroup(),
            $element instanceof Product => $element->getType(),
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
        $enabled = (SlugEqualsTitle::getInstance() ?? throw new InvalidConfigException('Plugin not loaded'))->getSettings()->$setting;
        return array_intersect(self::settingKeys($element), $enabled) !== [];
    }

    /** The element's remembered choice (a draft falls back to its canonical element's), else the type's default. */
    public function isEnabled(ElementInterface $element): bool
    {
        $ids = array_values(array_unique(array_filter([$element->id, $element->getCanonicalId()])));
        $rows = $ids ? (new Query())->select(['elementId', 'enabled'])->from(self::TABLE)->where(['elementId' => $ids])->pairs() : [];
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
        Db::upsert(self::TABLE, ['elementId' => $element->id, 'enabled' => $enabled]);
    }
}
