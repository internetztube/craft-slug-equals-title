<?php

declare(strict_types=1);

/**
 * Slug Equals Title config.php
 *
 * Copy this file to `config/slug-equals-title.php` in your Craft project and adjust it. Values set here override the
 * plugin settings in the control panel (they are shown there, read-only). Like every Craft config file it supports
 * multi-environment arrays (`'*' => [...], 'production' => [...]`).
 *
 * Each list names the types whose elements get the slug from the title by default, by handle or by UID (the settings
 * page stores UIDs). A section lists all its entries; "section:entryType" only the entries of that type. Every element
 * can still decide for itself with the switch in its slug field.
 */

return [
    // Sections, or single entry types of a section
    'enabledSections' => ['news', 'pages:landingPage'],

    // Category groups
    'enabledCategoryGroups' => [],

    // Craft Commerce product types
    'enabledProductTypes' => [],
];
