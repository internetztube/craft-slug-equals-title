<?php

namespace internetztube\slugEqualsTitle\assetBundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ExcludeFromRewriteAssetBundle extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = '@internetztube/slugEqualsTitle/resources/exclude-from-rewrite/';
        $this->depends = [
            CpAsset::class,
        ];
        $this->js = [
            'app.js',
        ];
        $this->css = [
            'app.css',
        ];
        parent::init();
    }
}
