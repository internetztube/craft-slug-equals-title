<?php

declare(strict_types=1);

/**
 * Slug Equals Title config.php
 *
 * Copy this file to `config/slug-equals-title.php` in your Craft project and adjust it. Values set here override the
 * plugin settings in the control panel (they are shown there, read-only). Like every Craft config file it supports
 * multi-environment arrays (`'*' => [...], 'production' => [...]`).
 *
 * Each list names the types whose elements get the slug from the title by default. Every element can still decide
 * for itself with the switch in its slug field.
 */

return [
    // Section handles
    'enabledSections' => ['news'],

    // Category group handles
    'enabledCategoryGroups' => [],

    // Craft Commerce product type handles
    'enabledProductTypes' => [],
];
