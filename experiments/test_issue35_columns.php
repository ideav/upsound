<?php
/**
 * Проверка того, что ftplist2025.php и ftplist25.php читают ISRC, название, артиста,
 * альбом и UPC из тех колонок выгрузки, где они действительно лежат (issue #35).
 * Запуск: php experiments/test_issue35_columns.php
 *
 * Номера колонок берутся из САМИХ скриптов (регулярками по исходнику), а имена колонок —
 * из заголовка приложенных к issue выгрузок. Поэтому проверка ловит и правку индексов,
 * сделанную «на глаз»: колонки streams/platform/country раньше уезжали в название,
 * артиста и альбом.
 */

$root     = dirname(__DIR__);
$fixtures = __DIR__ . '/fixtures';
$failed   = 0;

function assertEquals($expected, $actual, $message)
{
    global $failed;
    if ($expected === $actual) {
        echo "  OK   $message\n";
        return;
    }
    $failed++;
    echo "  FAIL $message\n";
    echo "       ожидалось: " . var_export($expected, true) . "\n";
    echo "       получено:  " . var_export($actual, true) . "\n";
}

/** Индексы, которые скрипт присваивает переменным $isrc/$title/$artist/$album/$upc. */
function extract_indexes($source, $array_name)
{
    $indexes = array();
    foreach (array('isrc', 'title', 'artist', 'album', 'upc') as $field) {
        $pattern = '/\$' . $field . '\s*=\s*isset\(\$' . $array_name . '\[(\d+)\]\)/';
        if (!preg_match($pattern, $source, $m)) {
            return array('error' => "не найдено присваивание \$$field из \$$array_name");
        }
        $indexes[$field] = (int)$m[1];
    }
    return $indexes;
}

/** Имя колонки по её позиции в заголовке выгрузки. */
function header_columns($file)
{
    $handle = fopen($file, 'r');
    $header = fgets($handle);
    fclose($handle);
    return array_map('trim', explode("\t", $header));
}

# Какое поле выгрузки должно попасть в какую переменную.
$expected = array(
    'isrc'   => 'isrc',
    'title'  => 'track',
    'artist' => 'artist',
    'album'  => 'album',
    'upc'    => 'upc',
);

echo "1. ftplist25.php читает выгрузку spd_upsound_*.tsv как есть\n";
$columns = header_columns($fixtures . '/spd_upsound_2026_03_31.tsv');
assertEquals(array('track', 'artist', 'album_id', 'album', 'country', 'platform', 'isrc', 'upc', 'streams'),
    $columns, 'состав колонок выгрузки');

$indexes = extract_indexes(file_get_contents($root . '/ftplist25.php'), 'parts');
assertEquals(false, isset($indexes['error']), 'присваивания найдены в исходнике' . (isset($indexes['error']) ? ': ' . $indexes['error'] : ''));
foreach ($expected as $field => $column) {
    assertEquals($column, isset($columns[$indexes[$field]]) ? $columns[$indexes[$field]] : null,
        "\$$field берётся из колонки $column");
}

echo "\n2. ftplist2025.php читает выгрузку report_5273_* с вырезанной колонкой album_id\n";
$columns = header_columns($fixtures . '/report_5273_2026_04_02.tsv');
assertEquals(array('track', 'artist', 'album_id', 'album', 'country', 'platform', 'isrc', 'upc', 'streams'),
    $columns, 'состав колонок выгрузки');

# Скрипт вырезает из строки третью колонку (индекс 2, album_id) до explode,
# поэтому индексы после неё смещены на единицу.
$shifted = $columns;
array_splice($shifted, 2, 1);
assertEquals(array('track', 'artist', 'album', 'country', 'platform', 'isrc', 'upc', 'streams'),
    $shifted, 'после вырезания album_id остаются восемь колонок');

$indexes = extract_indexes(file_get_contents($root . '/ftplist2025.php'), 'tmp');
assertEquals(false, isset($indexes['error']), 'присваивания найдены в исходнике' . (isset($indexes['error']) ? ': ' . $indexes['error'] : ''));
foreach ($expected as $field => $column) {
    assertEquals($column, isset($shifted[$indexes[$field]]) ? $shifted[$indexes[$field]] : null,
        "\$$field берётся из колонки $column");
}

echo "\n3. На реальных строках выгрузки значения осмысленны\n";
$handle = fopen($fixtures . '/spd_upsound_2026_03_31.tsv', 'r');
fgets($handle); # заголовок
$row = array_map('trim', explode("\t", fgets($handle)));
fclose($handle);
$indexes = extract_indexes(file_get_contents($root . '/ftplist25.php'), 'parts');
assertEquals('RUAGV2513406', $row[$indexes['isrc']], 'ISRC первой строки');
assertEquals('120 BPM',      $row[$indexes['title']],  'название трека, а не число прослушиваний');
assertEquals('KIRILIK',      $row[$indexes['artist']], 'артист, а не площадка (umavk)');
assertEquals('',             $row[$indexes['album']],  'альбом (в этой строке пуст), а не код страны (am)');

echo "\n4. В импорт статистики по-прежнему уходят streams, platform и country\n";
# Строка импорта: ISRC;дата;Quantity;3rd Party Retailer;Code — порядок реквизитов
# подчинённой таблицы Stats (10667430) в ups.
$source = file_get_contents($root . '/ftplist25.php');
assertEquals(1, preg_match('/\$data \.= trim\(\$parts\[6\]\)\.";\$date;"\.trim\(\$parts\[8\]\)\.";"\.trim\(\$parts\[5\]\)\.";"\.trim\(\$parts\[4\]\)/', $source),
    'ftplist25.php: isrc;date;streams;platform;country');
$source = file_get_contents($root . '/ftplist2025.php');
assertEquals(1, preg_match('/\$data \.= \$tmp\[5\]\.";\$date;"\.\$tmp\[7\]\.";"\.\$tmp\[4\]\.";"\.\$tmp\[3\]/', $source),
    'ftplist2025.php: isrc;date;streams;platform;country');

echo "\n" . ($failed ? "ПРОВАЛЕНО проверок: $failed\n" : "Все проверки пройдены\n");
exit($failed ? 1 : 0);
