<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Form\Controls\Choice;
use CraftCms\Cms\Form\Enums\ControlMode;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\Nodes\Field;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Sections;

use function CraftCms\Cms\t;

/**
 * The plugin's settings page: a checkbox list of the sections, each section with several entry types listing them
 * under it. A list set in `config/craft/slug-equals-title.php` wins; it is shown read-only with a hint.
 */
final class SettingsForm
{
    public static function make(Settings $settings): Form
    {
        $current = $settings->enabledSections;
        // an option's value is the UID; a type the setting names by handle (the config file) takes the handle, so that
        // it shows as ticked (Craft fills the form from the settings as they are)
        $value = fn(string $uid, string $handle): string => in_array($handle, $current, true) ? $handle : $uid;
        $options = [];
        /** @var list<Section> $sections */
        $sections = array_values(Sections::getAllSections()->all());
        foreach ($sections as $section) {
            $options[] = ['label' => (string)$section->name, 'value' => $value((string)$section->uid, (string)$section->handle)];
            // a section with several entry types: each of them on its own, under the section
            if (count($section->getEntryTypes()) > 1) {
                foreach ($section->getEntryTypes() as $entryType) {
                    $options[] = ['label' => '↳ ' . (string)$entryType->name, 'value' => $value("$section->uid:$entryType->uid", "$section->handle:$entryType->handle")];
                }
            }
        }
        $control = Choice::make('enabledSections')->multiple()->options($options)->value($current);
        $field = Field::make(t('Sections'), $control);
        $field->instructions(t('Tick a section for all its entries, or only some of its entry types.', category: 'slug-equals-title'));
        $overrides = config('craft.slug-equals-title', []);
        if (is_array($overrides) && array_key_exists('enabledSections', $overrides)) {
            $control->mode(ControlMode::Disabled);
            $field->warning(t('This is being overridden by the {name} setting in config/craft/slug-equals-title.php.', ['name' => 'enabledSections'], 'slug-equals-title'));
        }
        return Form::make([$field]);
    }
}
