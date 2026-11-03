<?php

declare(strict_types=1);

namespace Internetztube\SlugEqualsTitle;

use CraftCms\Cms\Form\Controls\Choice;
use CraftCms\Cms\Form\Enums\ControlMode;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\Nodes\Field;
use CraftCms\Cms\Section\Data\Section;

use function CraftCms\Cms\t;

/**
 * The plugin's settings page: a checkbox list per element type (sections with their entry types under them, category
 * groups, product types). Lists set in `config/craft/slug-equals-title.php` win; they are shown read-only with a hint.
 */
final class SettingsForm
{
    public static function make(Settings $settings): Form
    {
        $overrides = config('craft.slug-equals-title', []);
        $overrides = is_array($overrides) ? array_keys($overrides) : [];
        $form = Form::make();
        // a group without types yet (no category groups made) gets no list
        $groups = array_filter(Slugs::settingGroups(), static fn(array $group): bool => $group['types'] !== []);
        foreach ($groups as $name => $group) {
            $options = [];
            $current = $settings->$name;
            // an option's value is the UID; a type the setting names by handle (the config file) takes the handle,
            // so that it shows as ticked (Craft fills the form from the settings as they are)
            $value = fn(string $uid, string $handle): string => in_array($handle, $current, true) ? $handle : $uid;
            foreach ($group['types'] as $type) {
                $options[] = ['label' => (string)$type->name, 'value' => $value((string)$type->uid, (string)$type->handle)];
                // a section with several entry types: each of them on its own, under the section
                if ($type instanceof Section && count($type->getEntryTypes()) > 1) {
                    foreach ($type->getEntryTypes() as $entryType) {
                        $options[] = ['label' => '↳ ' . (string)$entryType->name, 'value' => $value("$type->uid:$entryType->uid", "$type->handle:$entryType->handle")];
                    }
                }
            }
            $control = Choice::make($name)->multiple()->options($options)->value($current);
            $field = Field::make($group['label'], $control);
            if ($name === 'enabledSections') {
                $field->instructions(t('Tick a section for all its entries, or only some of its entry types.', category: 'slug-equals-title'));
            }
            if (in_array($name, $overrides, true)) {
                $control->mode(ControlMode::Disabled);
                $field->warning(t('This is being overridden by the {name} setting in config/craft/slug-equals-title.php.', ['name' => $name], 'slug-equals-title'));
            }
            $form->add($field);
        }
        return $form;
    }
}
