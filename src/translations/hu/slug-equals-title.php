<?php

declare(strict_types=1);

return [
    'Slug equals title' => 'A slug megegyezik a címmel',
    'Overwritten by title' => 'A cím felülírja',
    'For the types you tick, the slug of a new element follows its title. Every element can decide for itself with the switch in its slug field.' => 'A bejelölt típusoknál az új elem slugja a címét követi. Minden elem maga dönthet a slug mezőjében lévő kapcsolóval.',
    'This is being overridden by the {name} setting in config/slug-equals-title.php.' => 'Ezt felülírja a {name} beállítás a config/slug-equals-title.php fájlban.',
    'Tick a section for all its entries, or only some of its entry types.' => 'Jelölj be egy szekciót az összes bejegyzéséhez, vagy csak néhány bejegyzéstípusához.',
    'Stop words' => 'Stopszavak',
    'Words dropped from the title before it becomes the slug, per site language: whole words, comma-separated, any case. Leave empty to keep every word.' => 'A címből a slug elkészítése előtt kihagyott szavak, webhelynyelvenként: egész szavak, vesszővel elválasztva, kis- és nagybetűtől függetlenül. Hagyja üresen, hogy minden szó megmaradjon.',
];
