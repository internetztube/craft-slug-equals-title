<?php

declare(strict_types=1);

/**
 * Slug Equals Title config
 *
 * Copy this file to `config/craft/slug-equals-title.php` in your Craft project and adjust it. Values set here override
 * the plugin settings in the control panel (they are shown there, read-only).
 *
 * The list names the sections whose entries get the slug from the title by default, by handle or by UID (the settings
 * page stores UIDs). A section lists all its entries; "section:entryType" only the entries of that type. Every entry
 * can still decide for itself with the switch in its slug field.
 */

return [
    // Sections, or single entry types of a section
    'enabledSections' => ['news', 'pages:landingPage'],

    // Words dropped from the title before it becomes the slug, per language: whole words, any case. Keyed by language,
    // not locale: "de" serves de-AT, de-CH and de-DE alike (Craft keeps them apart, the words do not; a key like
    // "de-CH" counts as "de"). Nothing is dropped unless you list words. Below, a starting point for every language
    // Craft ships, as examples rather than defaults: uncomment yours and trim it to taste. Japanese, Korean, Chinese
    // and Thai have no spaces between words, so there is nothing to drop there.
    // 'stopWords' => [
    //     'ar' => ['و', 'في', 'من', 'على', 'إلى', 'عن', 'مع', 'أو'],
    //     'cs' => ['a', 'i', 'v', 've', 'na', 'do', 'z', 'ze', 's', 'se', 'o', 'k', 'ke', 'u', 'že', 'pro'],
    //     'cy' => ['a', 'ac', 'y', 'yr', 'yn', 'i', 'o', 'am', 'ar', 'gan', 'â'],
    //     'da' => ['og', 'i', 'på', 'af', 'til', 'en', 'et', 'den', 'det', 'de', 'med', 'for', 'om', 'er'],
    //     'de' => ['der', 'die', 'das', 'ein', 'eine', 'und', 'oder', 'von', 'vom', 'zu', 'zum', 'zur', 'mit', 'für', 'im', 'in', 'am', 'an', 'auf', 'aus', 'bei', 'ist', 'sind'],
    //     'el' => ['ο', 'η', 'το', 'οι', 'τα', 'και', 'σε', 'με', 'για', 'από', 'στο', 'στη', 'στην', 'στον'],
    //     'en' => ['a', 'an', 'and', 'the', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'or', 'from', 'is', 'are'],
    //     'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'y', 'o', 'de', 'del', 'en', 'con', 'por', 'para', 'a', 'al', 'es'],
    //     'fa' => ['و', 'در', 'از', 'به', 'با', 'که', 'را', 'یا'],
    //     'fr' => ['le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'd', 'et', 'ou', 'à', 'au', 'aux', 'en', 'pour', 'avec', 'sur', 'par', 'est'],
    //     'he' => ['של', 'על', 'את', 'עם', 'או', 'גם', 'אל', 'מן', 'זה'],
    //     'hu' => ['a', 'az', 'és', 'vagy', 'egy', 'hogy', 'nem', 'van', 'is', 'meg'],
    //     'id' => ['dan', 'di', 'ke', 'dari', 'yang', 'untuk', 'dengan', 'atau', 'ini', 'itu', 'pada'],
    //     'is' => ['og', 'í', 'á', 'að', 'um', 'af', 'til', 'við', 'er', 'með', 'eða', 'frá'],
    //     'it' => ['il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'una', 'uno', 'e', 'ed', 'o', 'di', 'del', 'della', 'dei', 'delle', 'a', 'al', 'alla', 'in', 'nel', 'nella', 'con', 'per', 'su', 'è'],
    //     'ky' => ['жана', 'менен', 'үчүн', 'бул'],
    //     'nb' => ['og', 'i', 'på', 'av', 'til', 'en', 'et', 'den', 'det', 'de', 'med', 'for', 'om', 'er'],
    //     'nl' => ['de', 'het', 'een', 'en', 'of', 'van', 'in', 'op', 'met', 'voor', 'te', 'aan', 'bij', 'is'],
    //     'nn' => ['og', 'i', 'på', 'av', 'til', 'ein', 'ei', 'eit', 'den', 'det', 'dei', 'med', 'for', 'om', 'er'],
    //     'pl' => ['i', 'a', 'oraz', 'lub', 'w', 'we', 'na', 'do', 'z', 'ze', 'o', 'u', 'dla', 'od', 'po', 'że', 'jest'],
    //     'pt' => ['o', 'a', 'os', 'as', 'um', 'uma', 'e', 'ou', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'com', 'para', 'por', 'é'],
    //     'ru' => ['и', 'в', 'во', 'на', 'с', 'со', 'к', 'ко', 'о', 'об', 'от', 'до', 'из', 'у', 'за', 'по', 'для', 'или', 'не', 'это'],
    //     'sk' => ['a', 'i', 'v', 'vo', 'na', 'do', 'z', 'zo', 's', 'so', 'o', 'k', 'ku', 'u', 'že', 'pre'],
    //     'sv' => ['och', 'i', 'på', 'av', 'till', 'en', 'ett', 'den', 'det', 'de', 'med', 'för', 'om', 'är', 'eller'],
    //     'tr' => ['ve', 'ile', 'bir', 'bu', 'şu', 'için', 'de', 'da', 'veya'],
    //     'uk' => ['і', 'й', 'та', 'в', 'у', 'на', 'з', 'із', 'зі', 'до', 'від', 'по', 'для', 'про', 'або', 'не', 'це'],
    // ],
];
