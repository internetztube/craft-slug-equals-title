<?php

namespace internetztube\slugEqualsTitle\services;
use Craft;
use craft\base\Component;

class ElementsTypeService extends Component
{
    public function allAvalible()
    {
        $elementTypes = \Craft::$app->elements->getAllElementTypes();
        $result = [];
        foreach ($elementTypes as $elementType) {
            $reflect = new \ReflectionClass($elementType);
            $result[] = [
                'name' => $reflect->getShortName(),
                'className' => $elementType,
            ];
        }
        return $result;
    }
}
