<?php

namespace internetztube\slugEqualsTitle\models;

use craft\base\Model;

class Settings extends Model
{
    public $enabledSections = [];
    public $enabledCategoryGroups = [];
    public $enabledProductTypes = [];

    public function rules()
    {
        return [];
    }
}
