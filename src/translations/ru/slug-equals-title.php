<?php

declare(strict_types=1);

return [
    'Slug equals title' => 'Slug равен заголовку',
    'Overwritten by title' => 'Перезаписывается заголовком',
    'For the types you tick, the slug of a new element follows its title. Every element can decide for itself with the switch in its slug field.' => 'Для отмеченных типов slug нового элемента следует за его заголовком. Каждый элемент может решить сам переключателем в поле slug.',
    'This is being overridden by the {name} setting in config/slug-equals-title.php.' => 'Это переопределяется настройкой {name} в config/slug-equals-title.php.',
    'Tick a section for all its entries, or only some of its entry types.' => 'Отметьте раздел для всех его записей или только некоторые типы записей.',
    'Stop words' => 'Стоп-слова',
    'Words dropped from the title before it becomes the slug, per language (de for every German site, whatever the country): whole words, comma-separated, any case. Leave empty to keep every word.' => 'Слова, убираемые из заголовка до того, как он станет слагом, для каждого языка (de для всех немецких сайтов, независимо от страны): целые слова, через запятую, без учёта регистра. Оставьте пустым, чтобы сохранить все слова.',
];
