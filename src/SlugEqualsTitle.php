<?php

namespace internetztube\slugEqualsTitle;


use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;

use internetztube\slugEqualsTitle\services\ElementsTypeService;
use yii\base\Event;
use craft\base\Element as BaseElement;
use craft\events\ModelEvent;
use internetztube\slugEqualsTitle\models\Settings;

class SlugEqualsTitle extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.0';

    public function init()
    {
        parent::init();
        $this->hasCpSettings = true;
        self::$plugin = $this;

        $this->setComponents([
            'elementsType' => ElementsTypeService::class,
        ]);

        Event::on(BaseElement::class, BaseElement::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            $enabledElementsTypes = $this->getSettings()->enabledElementsTypes;
            $element = $event->sender;
            if (in_array(get_class($element), $enabledElementsTypes)) {
                $element->slug = $element->title;
            }
        });
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }

    protected function settingsHtml(): string
    {
        $elementTypes = $this->elementsType->allAvalible();
        $enabledElementsTypes = $this->getSettings()->enabledElementsTypes;

        $elementTypes = array_map(function($row) use ($enabledElementsTypes) {
            return [
                'label' => $row['name'],
                'value' => $row['className'],
                'checked' => in_array($row['className'], $enabledElementsTypes),
            ];
        }, $elementTypes);

        return Craft::$app->view->renderTemplate('slug-equals-title/settings', [
            'elementsTypes' => $elementTypes,
        ]);
    }
}