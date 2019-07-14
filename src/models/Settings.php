<?php

namespace internetztube\slugEqualsTitle\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public $enabledElementsTypes = [];

    public function rules()
    {
        return [];
    }
}
