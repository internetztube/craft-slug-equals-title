<?php

declare(strict_types=1);

return [
    'Slug equals title' => 'Slug เท่ากับชื่อเรื่อง',
    'Overwritten by title' => 'ถูกแทนที่ด้วยชื่อเรื่อง',
    'For the types you tick, the slug of a new element follows its title. Every element can decide for itself with the switch in its slug field.' => 'สำหรับประเภทที่คุณเลือก slug ของรายการใหม่จะตามชื่อเรื่อง แต่ละรายการตัดสินใจเองได้ด้วยสวิตช์ในช่อง slug',
    'This is being overridden by the {name} setting in config/slug-equals-title.php.' => 'ค่านี้ถูกแทนที่ด้วยการตั้งค่า {name} ใน config/slug-equals-title.php',
    'Tick a section for all its entries, or only some of its entry types.' => 'เลือกส่วนสำหรับรายการทั้งหมด หรือเลือกเฉพาะบางประเภทรายการ',
    'Stop words' => 'คำหยุด',
    'Words dropped from the title before it becomes the slug, per language (de for every German site, whatever the country): whole words, comma-separated, any case. Leave empty to keep every word.' => 'คำที่ตัดออกจากชื่อเรื่องก่อนจะกลายเป็น slug ตามภาษา (de สำหรับไซต์ภาษาเยอรมันทุกไซต์ ไม่ว่าประเทศใด): ทั้งคำ คั่นด้วยจุลภาค ไม่สนตัวพิมพ์ใหญ่เล็ก เว้นว่างไว้เพื่อเก็บทุกคำ',
];
