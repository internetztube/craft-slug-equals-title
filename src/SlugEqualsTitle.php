<?php

namespace internetztube\slugEqualsTitle;


use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;

use yii\base\Event;
use craft\base\Element as BaseElement;
use craft\events\ModelEvent;


class SlugEqualsTitle extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.0';

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(BaseElement::class, BaseElement::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            $element = $event->sender;
            $element->slug = $element->title;
        });
    }
}
