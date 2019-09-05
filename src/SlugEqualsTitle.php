<?php

namespace internetztube\slugEqualsTitle;

use Craft;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\web\View;
use internetztube\slugEqualsTitle\assetBundles\ExcludeFromRewriteAssetBundle;
use internetztube\slugEqualsTitle\services\ElementStatusService;
use yii\base\Event;
use internetztube\slugEqualsTitle\models\Settings;

class SlugEqualsTitle extends Plugin
{
    public static $plugin;
    public $schemaVersion = '1.0.1';

    public function init()
    {
        parent::init();
        $this->hasCpSettings = true;
        self::$plugin = $this;

        if (Craft::$app->request->isCpRequest) {
            Craft::$app->view->registerAssetBundle(ExcludeFromRewriteAssetBundle::class);
        }

        $this->setComponents([
            'elementStatus' => ElementStatusService::class,
        ]);

        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, function(Event $event) {
            $element = $event->sender;
            if (Craft::$app->request->isConsoleRequest) {
                $toOverwrite = $this->elementStatus->isEnabledForOverwrite($element);
            } else {
                $toOverwrite = Craft::$app->request->getBodyParam('slugEqualsTitle_shouldRewrite', "") === "1";
            }
            if (!$toOverwrite) return;
            $element->slug = $element->title;
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, function(Event $event) {
            if (Craft::$app->request->isConsoleRequest) return;
            $element = $event->sender;
            $toOverwrite = Craft::$app->request->getBodyParam('slugEqualsTitle_shouldRewrite', null);
            if (is_null($toOverwrite)) return;
            $this->elementStatus->setElementStatus($element, $toOverwrite === "1");
        });

        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function ($event) {
            if (!($event->template === 'entries/_edit' && Craft::$app->request->isCpRequest)) return;
            /** @var View $view */
            $view = $event->sender;
            $element = $event->variables['entry'];
            $isEnabledForOverwrite = $this->elementStatus->isEnabledForOverwrite($element);
            $view->registerMetaTag([
                'name' => 'slugEqualsTitleOverwriteEnabled',
                'content' => $isEnabledForOverwrite ? 'true' : 'false'
            ]);
        });
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }

    protected function settingsHtml(): string
    {
        $sections = Craft::$app->sections->getAllSections();
        $enabledSections = $this->getSettings()->enabledSections;
        $sections = array_map(function($row) use ($enabledSections) {
            return [
                'label' => $row['name'],
                'value' => $row['handle'],
                'checked' => in_array($row['handle'], $enabledSections),
            ];
        }, $sections);

        return Craft::$app->view->renderTemplate('slug-equals-title/settings', [
            'sections' => $sections,
        ]);
    }
}
