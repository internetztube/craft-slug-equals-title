<?php

declare(strict_types=1);

namespace internetztube\slugEqualsTitle;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\web\Request as WebRequest;
use internetztube\slugEqualsTitle\models\Settings;
use internetztube\slugEqualsTitle\services\SlugService;
use yii\base\Event;

/**
 * Keeps the slug of entries, categories and products equal to their title. On by default per section, category group
 * or product type (the plugin settings), and decided per element with a switch in the slug field. The choice made
 * on an edit form is remembered (services/SlugService); console saves (resave, migrations) follow it, other saves
 * without a form (queue jobs, index actions, other plugins) leave the slug alone, as since 1.x.
 *
 * @property-read SlugService $slugs
 * @method Settings getSettings()
 */
class SlugEqualsTitle extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    /** The switch's input on edit forms: "1" (slug from the title) or "" (keep the slug); the name since 1.x. */
    public const PARAM = 'slugEqualsTitle_shouldRewrite';

    /**
     * Moves the switch into the slug field, next to its input, and while it is on replaces the input with
     * "Overwritten by title" (the server sets the slug on save). Runs once per page and per slideout.
     */
    private const JS = <<<'JS'
for (const holder of document.querySelectorAll('[data-slug-equals-title]:not(.slug-equals-title)')) {
    holder.classList.add('slug-equals-title');
    const slugField = holder.parentElement.querySelector('[data-attribute="slug"]');
    const lightswitch = holder.querySelector('.lightswitch');
    if (!slugField) {
        holder.remove(); // this type hides its slug field
        continue;
    }
    const slug = slugField.querySelector('input');
    const box = slug.parentElement; // the field's input container: the input or the note, then the switch
    const note = document.createElement('span');
    note.className = 'light';
    note.style.flex = '1';
    note.textContent = holder.dataset.slugEqualsTitle;
    box.style.display = 'flex';
    box.style.alignItems = 'center';
    box.style.gap = 'var(--s, 8px)';
    slug.style.flex = '1 1 auto';
    slug.style.minWidth = '0';
    lightswitch.style.flex = 'none'; // its own width, never squeezed by the input
    box.append(note, lightswitch);
    holder.remove();
    const apply = () => {
        const on = lightswitch.getAttribute('aria-checked') === 'true';
        slug.disabled = on; // not posted: the server sets it from the title
        slug.classList.toggle('hidden', on);
        note.classList.toggle('hidden', !on);
    };
    apply();
    $(lightswitch).on('change', apply);
}
JS;

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return ['components' => ['slugs' => SlugService::class]];
    }

    public function init(): void
    {
        parent::init();
        foreach (SlugService::elementTypes() as $class) {
            Event::on($class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event): void {
                if ($event->sender instanceof ElementInterface) {
                    $this->applyTitle($event->sender);
                }
            });
            Event::on($class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event): void {
                if ($event->sender instanceof ElementInterface) {
                    $this->remember($event->sender);
                }
            });
            Event::on($class, Element::EVENT_DEFINE_META_FIELDS_HTML, function(DefineHtmlEvent $event): void {
                if ($event->sender instanceof ElementInterface) {
                    $event->html .= $this->switchHtml($event->sender, $event->static);
                }
            });
        }
    }

    /** Sets the slug from the title when the form's switch, or on the console the remembered choice, says so. */
    private function applyTitle(ElementInterface $element): void
    {
        if ($element->getIsRevision() || !$this->slugs->supports($element) || (string)$element->title === '') {
            return;
        }
        $follow = $this->requestChoice($element) ?? (Craft::$app->getRequest()->getIsConsoleRequest() && $this->slugs->isEnabled($element));
        if ($follow) {
            // normalized and made unique by Craft's own slug and URI validators, which run after this event
            $language = $element->getSite()->language;
            $element->slug = ElementHelper::generateSlug($this->slugs->titleForSlug((string)$element->title, $language), null, $language);
        }
    }

    /** Remembers the switch of an edit form (once per element, not again for every site it propagates to). */
    private function remember(ElementInterface $element): void
    {
        $choice = $this->requestChoice($element);
        if ($choice === null || $element->getIsRevision() || $element->propagating || !$this->slugs->supports($element)) {
            return;
        }
        $this->slugs->setEnabled($element, $choice);
    }

    /** The switch as posted with this element's edit form; null without a form (console, queue, another element's form). */
    private function requestChoice(ElementInterface $element): ?bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof WebRequest) {
            return null;
        }
        $value = $request->getBodyParam(self::PARAM);
        $formElementId = $request->getBodyParam('elementId');
        if ($value === null || (is_numeric($formElementId) && (int)$formElementId !== $element->getCanonicalId())) {
            return null;
        }
        return (bool)$value;
    }

    /** The "Slug equals title" switch for the meta pane (self::JS moves it under the slug field). */
    private function switchHtml(ElementInterface $element, bool $static): string
    {
        if (!$this->slugs->supports($element)) {
            return '';
        }
        $view = Craft::$app->getView();
        $view->registerJs(self::JS);
        $label = Craft::t('slug-equals-title', 'Slug equals title');
        // rendered after Craft's meta fields; self::JS moves the switch into the slug field, next to its input
        return Html::tag('div', Cp::lightswitchHtml([
            'id' => 'slugEqualsTitle',
            'name' => self::PARAM,
            'on' => $this->slugs->isEnabled($element),
            'disabled' => $static,
            'containerAttributes' => ['title' => $label, 'aria' => ['label' => $label]],
        ]), ['class' => 'hidden', 'data' => ['slug-equals-title' => Craft::t('slug-equals-title', 'Overwritten by title')]]);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $fromFile = Craft::$app->getConfig()->getConfigFromFile('slug-equals-title');
        return Craft::$app->getView()->renderTemplate('slug-equals-title/settings.twig', [
            'settings' => $this->getSettings(),
            'groups' => SlugService::settingGroups(),
            'languages' => SlugService::siteLanguages(),
            'overrides' => is_array($fromFile) ? array_keys($fromFile) : [],
        ]);
    }
}
