<?php

namespace internetztube\slugEqualsTitle;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\TemplateEvent;
use craft\helpers\StringHelper;
use craft\helpers\ElementHelper;
use craft\web\View;
use internetztube\slugEqualsTitle\assetBundles\ExcludeFromRewriteAssetBundle;
use internetztube\slugEqualsTitle\services\ElementStatusService;
use yii\base\Event;
use internetztube\slugEqualsTitle\models\Settings;

class SlugEqualsTitle extends Plugin
{
    public static $plugin;
    public string $schemaVersion = '1.0.1';
    private static string $inputName = 'slugEqualsTitle_shouldRewrite';

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
                $toOverwrite = Craft::$app->request->getBodyParam(self::$inputName, "") === "1";
            }
            if (!$toOverwrite) return;

            $element->slug = ElementHelper::generateSlug($element->title, null, $element->site->language);
        };

        $afterSafeCallback = function(Event $event) {
            if (Craft::$app->request->isConsoleRequest) return;
            $element = $event->sender;
            $toOverwrite = Craft::$app->request->getBodyParam(self::$inputName, null);
            if (is_null($toOverwrite)) return;
            $this->elementStatus->setElementStatus($element, $toOverwrite === "1");
        };

        $injectableHtml = function(Element $element) {
            $uid = StringHelper::randomString(12);
            $isEnabledForOverwrite = $this->elementStatus->isEnabledForOverwrite($element);
            $html = sprintf('<meta name="slugEqualsTitleOverwriteEnabled" content="%s" data-uid="%s">', $isEnabledForOverwrite ? 'true' : 'false', $uid);
            $html .= sprintf('<input type="hidden" name="%s" value="" class="slugEqualsTitleInput" data-uid="%s">', self::$inputName, $uid);
            $html .= sprintf('<script>
              (function() {
                const callback = () => {
                  const $metaTag = document.querySelector(\'meta[name*=slugEqualsTitleOverwriteEnabled][data-uid=%s]\')
                  const $slugField = $metaTag.parentNode.querySelector(\'.meta div[data-attribute=slug]\')
                  const $toggleInput = document.querySelector(\'.slugEqualsTitleInput[data-uid=%s]\')
                  console.log($toggleInput)
                  const isEnabled = $metaTag.content === \'true\'
                  window.SlugEqualsTitle($slugField, $toggleInput, isEnabled, "%s")
                }
                if (document.readyState === \'complete\') { callback() }
                window.addEventListener(\'load\', callback)
              })()
              </script>', $uid, $uid, self::$inputName);
            return $html;
        };

        Event::on(Category::class, Category::EVENT_BEFORE_SAVE, $beforeSafeCallback);
        Event::on(Category::class, Category::EVENT_AFTER_SAVE, $afterSafeCallback);

        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $beforeSafeCallback);
        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, $afterSafeCallback);

        // Unified Element Editor
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function (DefineHtmlEvent $event) use ($injectableHtml) {
            $element = $event->sender ?? null;
            if ($element instanceof Entry || $element instanceof Category) {
                $event->html .= $injectableHtml($element);
            }
        });

        // Craft Commerce
        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) use ($injectableHtml) {
                if ($event->template !== 'commerce/products/_edit') { return; }
                $event->sender->registerHtml($injectableHtml($event->variables['product']));
            });
            Event::on(\craft\commerce\elements\Product::class, \craft\commerce\elements\Product::EVENT_AFTER_SAVE, $afterSafeCallback);
            Event::on(\craft\commerce\elements\Product::class, \craft\commerce\elements\Product::EVENT_BEFORE_SAVE, $beforeSafeCallback);
        }

        // We need to have the Asset Bundle on every cp page because of the new unified element editor.
        if (Craft::$app->request->isCpRequest) {
            Craft::$app->view->registerAssetBundle(ExcludeFromRewriteAssetBundle::class);
        }
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $data = $this->elementStatus->getTemplateVariables();
        return Craft::$app->view->renderTemplate('slug-equals-title/settings', $data);
    }
}
