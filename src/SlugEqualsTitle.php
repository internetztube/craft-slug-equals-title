<?php

namespace internetztube\slugEqualsTitle;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\commerce\services\ProductTypes;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\StringHelper;
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

        $this->setComponents([
            'elementStatus' => ElementStatusService::class,
        ]);

        $beforeSafeCallback = function(Event $event) {
            /** @var Element $element */
            $element = $event->sender;
            if (Craft::$app->request->isConsoleRequest) {
                $toOverwrite = $this->elementStatus->isEnabledForOverwrite($element);
            } else {
                $toOverwrite = Craft::$app->request->getBodyParam('slugEqualsTitle_shouldRewrite', "") === "1";
            }
            if (!$toOverwrite) return;

            $slug = $element->title;
            if (Craft::$app->getConfig()->getGeneral()->limitAutoSlugsToAscii) {
                $slug = StringHelper::toAscii($slug, $element->site->language);
            }

            $element->slug = $slug;
        };

        $afterSafeCallback = function(Event $event) {
            if (Craft::$app->request->isConsoleRequest) return;
            $element = $event->sender;
            $toOverwrite = Craft::$app->request->getBodyParam('slugEqualsTitle_shouldRewrite', null);
            if (is_null($toOverwrite)) return;
            $this->elementStatus->setElementStatus($element, $toOverwrite === "1");
        };

        foreach ($this->elementStatus->mapping() as $mapping) {
            Event::on($mapping['eventClass'], $mapping['eventNameAfterSafe'], $afterSafeCallback);
            Event::on($mapping['eventClass'], $mapping['eventNameBeforeSafe'], $beforeSafeCallback);
        }

        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function ($event) {
            if (!$this->elementStatus->isTemplateEnabledForOverwrite($event->template)) return;
            Craft::$app->view->registerAssetBundle(ExcludeFromRewriteAssetBundle::class);
            /** @var View $view */
            $view = $event->sender;
            $element = $this->elementStatus->getElementFromEventVariables($event->variables);
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
        $data = $this->elementStatus->getTemplateVariables();
        return Craft::$app->view->renderTemplate('slug-equals-title/settings', $data);
    }
}
