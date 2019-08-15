<?php

namespace internetztube\slugEqualsTitle\models;

use craft\base\Model;

class Settings extends Model
{
    public $enabledSections = [];

    public function rules()
    {
        return [];
    }
}
