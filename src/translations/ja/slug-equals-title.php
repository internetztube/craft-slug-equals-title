<?php

declare(strict_types=1);

return [
    'Slug equals title' => 'スラッグをタイトルと同じにする',
    'Overwritten by title' => 'タイトルで上書き',
    'For the types you tick, the slug of a new element follows its title. Every element can decide for itself with the switch in its slug field.' => 'チェックしたタイプでは、新しい要素のスラッグはタイトルに従います。各要素はスラッグフィールドのスイッチで個別に決められます。',
    'This is being overridden by the {name} setting in config/slug-equals-title.php.' => 'これは config/slug-equals-title.php の {name} 設定で上書きされています。',
    'Tick a section for all its entries, or only some of its entry types.' => 'セクションにチェックを入れるとそのすべてのエントリが対象になります。一部のエントリタイプだけを選ぶこともできます。',
    'Stop words' => 'ストップワード',
    'Words dropped from the title before it becomes the slug, per site language: whole words, comma-separated, any case. Leave empty to keep every word.' => 'タイトルがスラッグになる前に取り除く語を、サイトの言語ごとに指定します。語単位、カンマ区切り、大文字小文字は区別しません。空のままにすると、すべての語が残ります。',
];
