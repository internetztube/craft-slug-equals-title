<?php

namespace internetztube\slugEqualsTitle\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\services\ProductTypes;
use craft\elements\Category;
use craft\elements\Entry;
use internetztube\slugEqualsTitle\records\ElementStatus;
use internetztube\slugEqualsTitle\SlugEqualsTitle;

class ElementStatusService extends Component
{
    public function mapping()
    {
        $result = [
            [
                'className' => Category::class,
                'settingName' => 'enabledCategoryGroups',
                'templateVariableName' => 'categoryGroups',
                'all' => Craft::$app->categories->getAllGroups(),
                'typeFromElement' => function (Category $category) { return $category->group; },
            ],
            [
                'className' => Entry::class,
                'settingName' => 'enabledSections',
                'templateVariableName' => 'sections',
                'all' => Craft::$app->sections->getAllSections(),
                'typeFromElement' => function (Entry $entry) { return $entry->section; },
            ],
        ];

        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            $result[] = [
                'className' => Product::class,
                'settingName' => 'enabledProductTypes',
                'templateVariableName' => 'productTypes',
                'all' => (new ProductTypes())->allProductTypes,
                'typeFromElement' => function (Product $product) { return $product->type; },
            ];;
        }
        return $result;
    }

    public function getTemplateVariables()
    {
        $selectOptionsBuilder = function (array $all, array $currentlySelected) {
            return array_map(function ($row) use ($currentlySelected) {
                $checked = in_array($row['handle'], $currentlySelected);
                return ['label' => $row['name'], 'value' => $row['handle'], 'checked' => $checked];
            }, $all);
        };

        $result = [];
        foreach ($this->mapping() as $row) {
            $result[$row['templateVariableName']] = $selectOptionsBuilder(
                $row['all'],
                SlugEqualsTitle::$plugin->getSettings()->{$row['settingName']}
            );
        }
        return $result;
    }

    private function getMappingFromElement(Element $element): ?array
    {
        $class = get_class($element);
        foreach ($this->mapping() as $row) {
            if ($row['className'] === $class) return $row;
        }
        return null;
    }

    private function isTypeEnabledForOverwrite(Element $element, array $mapping)
    {
        $enabledTypeHandles = SlugEqualsTitle::$plugin->getSettings()->{$mapping['settingName']};
        $elementType = $mapping['typeFromElement']($element);

        foreach ($enabledTypeHandles as $enabledTypeHandle) {
            if ($enabledTypeHandle === $elementType->handle) return true;
        }
        return false;
    }

    public function isEnabledForOverwrite(Element $element)
    {
        $elementStatus = $this->isEnabled($element);
        if (is_bool($elementStatus)) {
            return $elementStatus;
        }

        $mapping = $this->getMappingFromElement($element);
        if (!$mapping) return false;
        return $this->isTypeEnabledForOverwrite($element, $mapping);
    }

    private function isEnabled(Element $element)
    {
        $record = ElementStatus::find()
            ->where(['elementId' => $element->id])
            ->one();

        if (!$record) {
            return null;
        }
        return (bool)$record->enabled;
    }

    public function setElementStatus(Element $element, bool $enabledForOverwrite)
    {
        $record = ElementStatus::find()
            ->where(['elementId' => $element->id])
            ->one();

        if (!$record) {
            $record = new ElementStatus();
            $record->setAttribute('elementId', $element->id);
            $record->setAttribute('enabled', $enabledForOverwrite);
        }

        $record->setAttribute('enabled', $enabledForOverwrite);
        return $record->save();
    }
}
