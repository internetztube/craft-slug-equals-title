<?php

namespace internetztube\slugEqualsTitle\services;

use craft\base\Component;
use craft\elements\Entry;
use internetztube\slugEqualsTitle\records\ElementStatus;
use internetztube\slugEqualsTitle\SlugEqualsTitle;

class ElementStatusService extends Component
{
    public function isEnabledForOverwrite(Entry $element)
    {
        $elementStatus = $this->isEnabled($element);
        if (is_bool($elementStatus)) {
            return $elementStatus;
        }
        if ($this->isSectionEnabledForOverwrite($element->section->handle)) {
            return true;
        }
        return false;
    }

    private function isSectionEnabledForOverwrite(string $handle)
    {
        $enabledSections = SlugEqualsTitle::$plugin->getSettings()->enabledSections;
        return in_array($handle, $enabledSections);
    }

    private function isEnabled(Entry $element)
    {
        $record = ElementStatus::find()
            ->where(['elementId' => $element->id])
            ->one();

        if (!$record) {
            return null;
        }
        return (bool) $record->enabled;
    }

    public function setElementStatus(Entry $element, bool $enabledForOverwrite)
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
