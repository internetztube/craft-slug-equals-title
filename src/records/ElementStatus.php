<?php

namespace internetztube\slugEqualsTitle\records;

use craft\db\ActiveRecord;

class ElementStatus extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%slugEqualsTitle_shouldRewrite}}';
    }
}
