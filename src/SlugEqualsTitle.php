<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Cp\Components\Lightswitch as LightswitchComponent;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementEditorPayloadResolving;
use CraftCms\Cms\Element\Events\ElementMetaFieldsHtmlResolving;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Form\Controls\Lightswitch;
use CraftCms\Cms\Form\Enums\ControlMode;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\FormContext;
use CraftCms\Cms\Form\FormPayload;
use CraftCms\Cms\Form\FormResolver;
use CraftCms\Cms\Form\Nodes\Field;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\View\Enums\Position;
use Illuminate\Support\Facades\Event;
use Override;

use function CraftCms\Cms\t;

/**
 * Keeps the slug of entries equal to their title. On by default per section or entry type (the plugin settings), and
 * decided per entry with a switch in the slug field. The choice made on an edit form is remembered (Slugs); console saves (resave, migrations) follow it, other
 * saves without a form (queue jobs, index actions, other plugins) leave the slug alone, as since 1.x.
 *
 * @method Settings getSettings()
 */
class SlugEqualsTitle extends Plugin
{
    /** 3.1's schema: 4.0 needs no migration of its own (minVersionRequired makes sure 3.1's ran). */
    public string $schemaVersion = '1.2.0';
    public string $minVersionRequired = '3.1.0';

    public bool $hasCpSettings = true;

    /** The switch's input on edit forms: "1" (slug from the title) or "" (keep the slug); the name since 1.x. */
    public const PARAM = 'slugEqualsTitle_shouldRewrite';

    /**
     * The legacy editor (slideouts): moves the switch into the slug field, next to its input, and while it is on
     * replaces the input with "Overwritten by title" (the server sets the slug on save).
     */
    private const JS = <<<'JS'
for (const holder of document.querySelectorAll('[data-slug-equals-title]:not(.slug-equals-title)')) {
    holder.classList.add('slug-equals-title');
    const slugField = holder.parentElement.querySelector('[data-attribute="slug"]');
    const lightswitch = holder.querySelector('craft-switch');
    const button = lightswitch.querySelector('[role="switch"]');
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
        const on = button.getAttribute('aria-checked') === 'true';
        slug.disabled = on; // not posted: the server sets it from the title
        slug.classList.toggle('hidden', on);
        note.classList.toggle('hidden', !on);
    };
    apply();
    lightswitch.addEventListener('change', apply);
    button.addEventListener('click', () => setTimeout(apply, 0));
}
JS;

    /**
     * Craft 6's editor (edit pages): the switch's field comes right after the slug field in the sidebar form; this hides
     * that field and lays the slug field's input out as a row: the input (or, while the switch is on, "Overwritten by
     * title": the server sets the slug on save) and the switch next to it. Runs once per page and watches the
     * document: the editor re-renders, and Inertia swaps pages without a reload.
     */
    private const JS_EDITOR = <<<'JS'
(() => {
    if (window.slugEqualsTitleEditor) {
        return;
    }
    window.slugEqualsTitleEditor = true;
    const apply = () => {
        for (const field of document.querySelectorAll('craft-field[id$="slugEqualsTitle_shouldRewrite"]')) {
            const slugField = field.previousElementSibling;
            const input = slugField?.querySelector('craft-input'); // the slug field's input component
            const fresh = field.querySelector('craft-switch'); // still in its own field: new, or re-rendered by the editor
            if (!input || !/slug$/.test(slugField.id) || (!fresh && input.parentElement?.classList.contains('slug-equals-title-row'))) {
                continue;
            }
            field.hidden = true; // its label would say the same twice; the switch keeps it as its accessible name
            field.style.display = 'none';
            let row = slugField.querySelector(':scope > .slug-equals-title-row');
            if (!row) {
                row = document.createElement('div');
                row.className = 'slug-equals-title-row';
                row.slot = 'input';
                row.style.cssText = 'display: flex; align-items: center; gap: var(--s, 8px); flex: 1 1 auto'; // the field's slot is a flex row itself
                const note = document.createElement('span');
                note.className = 'light';
                note.style.flex = '1';
                note.textContent = __NOTE__;
                input.replaceWith(row);
                row.append(input, note);
                input.style.flex = '1 1 auto';
                input.style.minWidth = '0';
            }
            if (fresh) {
                row.querySelector('craft-switch')?.remove(); // the editor made a new one: the old is no longer bound
                fresh.style.flex = 'none';
                row.append(fresh);
                const button = fresh.querySelector('[role="switch"]');
                const sync = () => {
                    const on = button?.getAttribute('aria-checked') === 'true';
                    input.hidden = on; // not typed while on: the server sets it from the title
                    row.querySelector('span').hidden = !on;
                };
                sync();
                // the web component sets aria-checked when it upgrades and on every toggle: click, keyboard, the editor
                if (button) {
                    new MutationObserver(sync).observe(button, { attributes: true, attributeFilter: ['aria-checked'] });
                }
            }
        }
    };
    apply();
    new MutationObserver(apply).observe(document.body, { childList: true, subtree: true });
})();
JS;

    #[Override]
    protected static function createSettings(): ?PluginSettings
    {
        return new Settings();
    }

    #[Override]
    public function settingsForm(FormContext $context = new FormContext()): ?Form
    {
        return SettingsForm::make($this->getSettings());
    }

    public function boot(): void
    {
        $this->app->singleton(Settings::class, fn(): Settings => $this->getSettings());
        if (!app()->runningInConsole() && request()->isCpRequest()) {
            // every control panel page: Inertia swaps edit pages in without a reload, so the script has to be there first
            HtmlStack::jsWithVars(fn(string $note): string => str_replace('__NOTE__', $note, self::JS_EDITOR), [t('Overwritten by title', category: 'slug-equals-title')], Position::Ready, 'slug-equals-title-editor');
        }
        Event::listen(fn(ElementSaving $event) => $this->applyTitle($event->element));
        Event::listen(fn(ElementSaved $event) => $this->remember($event->element));
        // the legacy editor (slideouts): the switch as HTML next to Craft's meta fields
        Event::listen(function (ElementMetaFieldsHtmlResolving $event): void {
            $event->html .= $this->switchHtml($event->element, $event->static);
        });
        // the Inertia editor (edit pages): the switch as a node of the sidebar form, after the slug field
        Event::listen(function (ElementEditorPayloadResolving $event): void {
            $event->data = $this->withSwitchNode($event->element, $event->data);
        });
    }

    /**
     * Sets the slug from the title when the form's switch says so. Craft 6's editor posts only what changed, so a
     * switch left as it was is not in the request: a save through Craft's element actions then follows the remembered
     * choice (what the switch showed), as does the console (resave, migrations).
     */
    private function applyTitle(ElementInterface $element): void
    {
        $slugs = app(Slugs::class);
        if ($element->getIsRevision() || !$slugs->supports($element) || (string)$element->title === '') {
            return;
        }
        $follow = $this->requestChoice($element) ?? ((app()->runningInConsole() || $this->isEditorSave($element)) && $slugs->isEnabled($element));
        if ($follow) {
            // normalized and made unique by Craft's own slug and URI validators, which run after this event
            $element->slug = ElementHelper::generateSlug((string)$element->title, null, $element->getSite()->language);
        }
    }

    /** Remembers the switch of an edit form (once per element, not again for every site it propagates to). */
    private function remember(ElementInterface $element): void
    {
        $slugs = app(Slugs::class);
        $choice = $this->requestChoice($element);
        if ($choice === null || $element->getIsRevision() || $element->propagating || !$slugs->supports($element)) {
            return;
        }
        $slugs->setEnabled($element, $choice);
    }

    /** The switch as posted with this element's edit form; null without it (console, another element's form, unchanged). */
    private function requestChoice(ElementInterface $element): ?bool
    {
        if (app()->runningInConsole()) {
            return null;
        }
        $value = request()->input(self::PARAM);
        if ($value === null || !$this->isFormOf($element)) {
            return null;
        }
        return (bool)$value;
    }

    /**
     * A web save whose request names this element: Craft's edit page posts only the changed values, with the entry's
     * id (`elementId` to the element actions, `entryId` to `entries/save-entry`). Saves of other elements in the same
     * request (a plugin saving along) and requests without the id (index actions on many elements) are not the
     * editor's.
     */
    private function isEditorSave(ElementInterface $element): bool
    {
        return !app()->runningInConsole() && $this->requestElementId() === $element->getCanonicalId();
    }

    /** Whether the request's form is about this element (another element saved in the same request by a plugin is not). */
    private function isFormOf(ElementInterface $element): bool
    {
        $id = $this->requestElementId();
        return $id === null || $id === $element->getCanonicalId();
    }

    /** The id the request's form is about, if it names one. */
    private function requestElementId(): ?int
    {
        $id = request()->input('elementId') ?? request()->input('entryId');
        return is_numeric($id) ? (int)$id : null;
    }

    /** The "Slug equals title" switch for the legacy editor's meta pane (self::JS moves it into the slug field). */
    private function switchHtml(ElementInterface $element, bool $static): string
    {
        if (!app(Slugs::class)->supports($element)) {
            return '';
        }
        HtmlStack::js(self::JS, Position::Ready, 'slug-equals-title');
        $label = t('Slug equals title', category: 'slug-equals-title');
        return Html::tag('div', LightswitchComponent::make()
            ->id('slugEqualsTitle')
            ->name(self::PARAM)
            ->on(app(Slugs::class)->isEnabled($element))
            ->disabled($static)
            ->buttonAttributes(['title' => $label, 'aria' => ['label' => $label]])
            ->toHtml(), ['class' => 'hidden', 'data' => ['slug-equals-title' => t('Overwritten by title', category: 'slug-equals-title')]]);
    }

    /**
     * The Inertia editor's sidebar form with the switch as a field right after the slug field (none when the type
     * hides its slug field).
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withSwitchNode(ElementInterface $element, array $data): array
    {
        $payload = $data['sidebarForm'] ?? null;
        if (!$payload instanceof FormPayload || !app(Slugs::class)->supports($element)) {
            return $data;
        }
        $slugIndex = null;
        $slugControl = null;
        foreach ($payload->nodes as $index => $node) {
            if ($node->control !== null && $node->control->path === ['slug']) {
                $slugIndex = $index;
                $slugControl = $node->control;
                break;
            }
        }
        if ($slugIndex === null || $slugControl === null) {
            return $data;
        }
        $mode = $slugControl->mode;
        $field = Field::make(t('Slug equals title', category: 'slug-equals-title'), Lightswitch::make(self::PARAM)
            ->value(app(Slugs::class)->isEnabled($element))
            ->mode($mode));
        $extra = app(FormResolver::class)->resolve(Form::make([$field]), new FormContext(namespace: $payload->scope, mode: $mode));
        $data['sidebarForm'] = new FormPayload(
            scope: $payload->scope,
            refreshable: $payload->refreshable,
            nodes: [...array_slice($payload->nodes, 0, $slugIndex + 1), ...$extra->nodes, ...array_slice($payload->nodes, $slugIndex + 1)],
            values: [...$payload->values, ...$extra->values],
            errors: $payload->errors,
            globalErrors: $payload->globalErrors,
        );
        return $data;
    }
}
