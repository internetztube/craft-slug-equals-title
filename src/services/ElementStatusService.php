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
                'template' => 'categories/_edit',
                'elementType' => 'category',
                'class' => Category::class,
                'settingName' => 'enabledCategoryGroups',
                'templateVariableName' => 'categoryGroups',
                'all' => Craft::$app->categories->getAllGroups(),
                'typeIdFromElement' => function (Category $category) {
                    return $category->group;
                },
                'eventClass' => Category::class,
                'eventNameAfterSafe' => Category::EVENT_AFTER_SAVE,
                'eventNameBeforeSafe' => Category::EVENT_BEFORE_SAVE,
            ],
            [
                'template' => 'entries/_edit',
                'elementType' => 'entry',
                'class' => Entry::class,
                'settingName' => 'enabledSections',
                'templateVariableName' => 'sections',
                'all' => Craft::$app->sections->getAllSections(),
                'typeIdFromElement' => function (Entry $entry) {
                    return $entry->section;
                },
                'eventClass' => Entry::class,
                'eventNameAfterSafe' => Entry::EVENT_AFTER_SAVE,
                'eventNameBeforeSafe' => Entry::EVENT_BEFORE_SAVE,
            ],
        ];


        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            $result[] = $this->commerceMapping();
        }
        return $result;
    }

    private function commerceMapping() {
        return [
            'template' => 'commerce/products/_edit',
            'elementType' => 'product',
            'class' => Product::class,
            'settingName' => 'enabledProductTypes',
            'templateVariableName' => 'productTypes',
            'all' => (new ProductTypes())->allProductTypes,
            'typeIdFromElement' => function (Product $product) {
                return $product->type;
            },
            'eventClass' => Product::class,
            'eventNameAfterSafe' => Product::EVENT_AFTER_SAVE,
            'eventNameBeforeSafe' => Product::EVENT_BEFORE_SAVE,
        ];
    }

    /**
     * Checks if template should have a toggle.
     * @param string $template
     * @return bool
     */
    public function isTemplateEnabledForOverwrite(string $template)
    {
        if (!Craft::$app->request->isCpRequest) return false;
        foreach ($this->mapping() as $row) {
            if ($row['template'] === $template) return true;
        }
        return false;
    }

    public function getTemplateVariables()
    {
        $selectOptionsBuilder = function(array $all, array $currentlySelected) {
            return array_map(function($row) use ($currentlySelected) {
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

    /**
     * Returns the element from the View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE event.
     * @param $event
     */
    public function getElementFromEventVariables(array $variables): ?Element
    {
        foreach ($this->mapping() as $row) {
            if (isset($variables[$row['elementType']])) {
                return $variables[$row['elementType']];
            }
        }
        return null;
    }

    private function getMappingFromElement(Element $element): ?array
    {
        $class = get_class($element);
        foreach ($this->mapping() as $row) {
            if ($row['class'] === $class) return $row;
        }
        return null;
    }

    private function isTypeEnabledForOverwrite(Element $element, array $mapping)
    {
        $enabledTypeHandles = SlugEqualsTitle::$plugin->getSettings()->{$mapping['settingName']};
        $elementType = $mapping['typeIdFromElement']($element);

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
