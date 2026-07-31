<?php
/**
 * Проверка синхронизации артистов и треков (issue #35) без обращения к боевому API.
 * Запуск: php experiments/test_issue35_sync.php
 *
 * HTTP-слой подменяется через $GLOBALS['usync_http_handler'], поэтому проверяются
 * реальные адреса и параметры запросов, порядок вызовов и работа со списками.
 */

$tmp = sys_get_temp_dir() . '/usync_test_' . getmypid();
@mkdir($tmp, 0777, true);

define('USYNC_ARTISTS_FILE', $tmp . '/artists.txt');
define('USYNC_TRACKS_FILE',  $tmp . '/tracks.txt');
require_once __DIR__ . '/../upsound_sync.php';

$failed = 0;
$calls  = array();

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

/** Ответы API: ключ — "{db}:{таблица}:{значение}". */
$responses = array();

$GLOBALS['usync_http_handler'] = function ($url, array $post) use (&$calls, &$responses) {
    $calls[] = array('url' => $url, 'post' => $post);
    if (preg_match('#/([a-z]+)/_m_new/(\d+)#', $url, $m)) {
        $db    = $m[1];
        $table = $m[2];
        $key   = "$db:$table:" . $post["t$table"];
        if (isset($responses[$key])) {
            return array('body' => $responses[$key], 'error' => '', 'code' => 200);
        }
        return array('body' => json_encode(array('id' => 1000 + count($calls), 'obj' => 1000 + count($calls))),
                     'error' => '', 'code' => 200);
    }
    return array('body' => '', 'error' => 'unexpected url ' . $url, 'code' => 0);
};

$tracks = array(
    'RUA1B2500001' => array('isrc' => 'RUA1B2500001', 'title' => 'Первый трек',
                            'artist' => 'COLDLEEN', 'album' => 'Дебют', 'upc' => '4620000000001'),
);

echo "1. Новый артист и новый трек создаются в обеих базах\n";
$stats = usync_sync($tracks);
assertEquals(1, $stats['artists_new'], 'создан один артист');
assertEquals(1, $stats['tracks_new'], 'создан один трек');
assertEquals(4, count($calls), 'выполнено 4 запроса: артист x2, трек x2');

assertEquals('https://upsound.ideav.online/upsound/_m_new/10869?JSON', $calls[0]['url'], 'артист создаётся в upsound');
assertEquals('COLDLEEN', $calls[0]['post']['t10869'], 'в upsound передаётся только имя артиста');
assertEquals(array('up', 't10869', 'token', '_xsrf'), array_keys($calls[0]['post']), 'лишние реквизиты артисту не передаются');
assertEquals(1, $calls[0]['post']['up'], 'артист создаётся в корне (up=1)');

assertEquals('https://upsound.ideav.online/ups/_m_new/308?JSON', $calls[1]['url'], 'артист создаётся в ups');
assertEquals('COLDLEEN', $calls[1]['post']['t308'], 'в ups передаётся имя артиста');
assertEquals(1001, $calls[1]['post']['t165939785'], 'в ups реквизит ID = ID артиста из upsound');

assertEquals('https://upsound.ideav.online/upsound/_m_new/291?JSON', $calls[2]['url'], 'трек создаётся в upsound');
assertEquals('RUA1B2500001', $calls[2]['post']['t291'], 'ISRC — значение записи трека в upsound');
assertEquals('Первый трек', $calls[2]['post']['t298'], 'название трека в upsound');
assertEquals('4620000000001', $calls[2]['post']['t293'], 'UPC в upsound');
assertEquals(1001, $calls[2]['post']['t16543'], 'трек в upsound ссылается на артиста upsound');
assertEquals('Дебют', $calls[2]['post']['NEW_10868'], 'альбом в upsound создаётся по названию');

assertEquals('https://upsound.ideav.online/ups/_m_new/165906636?JSON', $calls[3]['url'], 'трек создаётся в ups');
assertEquals('RUA1B2500001', $calls[3]['post']['t165906636'], 'ISRC — значение записи трека в ups');
assertEquals('Первый трек', $calls[3]['post']['t165912542'], 'название трека в ups');
assertEquals(1002, $calls[3]['post']['t165912539'], 'трек в ups ссылается на артиста ups');
assertEquals('Дебют', $calls[3]['post']['NEW_165912540'], 'альбом в ups создаётся по названию');
assertEquals('4620000000001', $calls[3]['post']['t165912544'], 'числовой UPC передаётся в ups');

assertEquals("COLDLEEN\r\n", file_get_contents(USYNC_ARTISTS_FILE), 'артист дописан в logs/artists.txt');
assertEquals("RUA1B2500001\r\n", file_get_contents(USYNC_TRACKS_FILE), 'ISRC дописан в logs/tracks.txt');

echo "\n2. Повторный запуск с теми же данными не обращается к API\n";
$calls = array();
$stats = usync_sync($tracks);
assertEquals(0, count($calls), 'запросов нет — всё есть в списках');
assertEquals(1, $stats['tracks_known'], 'трек учтён как уже известный');

echo "\n3. Новый трек известного артиста: ID артиста запрашивается повторно\n";
$calls = array();
$responses['upsound:10869:COLDLEEN'] = json_encode(array('id' => 1001, 'obj' => 10869, 'warning' => 'Запись уже существует'));
$responses['ups:308:COLDLEEN']       = json_encode(array('id' => 1002, 'obj' => 308,   'warning' => 'Запись уже существует'));
$stats = usync_sync(array(
    'RUA1B2500002' => array('isrc' => 'RUA1B2500002', 'title' => 'Второй трек',
                            'artist' => 'COLDLEEN', 'album' => '', 'upc' => 'нет'),
));
assertEquals(0, $stats['artists_new'], 'артист заново не создаётся');
assertEquals(1, $stats['tracks_new'], 'трек создан');
assertEquals(4, count($calls), 'ID существующего артиста получен теми же командами _m_new');
assertEquals(1001, $calls[2]['post']['t16543'], 'использован ID существующего артиста в upsound');
assertEquals(1002, $calls[3]['post']['t165912539'], 'использован ID существующего артиста в ups');
assertEquals(false, isset($calls[2]['post']['NEW_10868']), 'пустой альбом не передаётся');
assertEquals(false, isset($calls[3]['post']['t165912544']), 'нечисловой UPC в ups не передаётся');

echo "\n4. Ошибка API: запись не попадает в список и будет повторена\n";
$calls = array();
$responses['ups:308:Новый Артист'] = json_encode(array('error' => 'У вас нет прав на создание объектов этого типа'));
$stats = usync_sync(array(
    'RUA1B2500003' => array('isrc' => 'RUA1B2500003', 'title' => 'Третий трек',
                            'artist' => 'Новый Артист', 'album' => 'Альбом', 'upc' => '1'),
));
assertEquals(1, $stats['artists_failed'], 'ошибка артиста учтена');
assertEquals(1, $stats['tracks_failed'], 'трек без артиста не создан');
assertEquals(false, strpos(file_get_contents(USYNC_ARTISTS_FILE), 'Новый Артист') !== false,
    'артист с ошибкой не дописан в список');
assertEquals(false, strpos(file_get_contents(USYNC_TRACKS_FILE), 'RUA1B2500003') !== false,
    'трек с ошибкой не дописан в список');

echo "\n5. Пустое имя артиста: трек создаётся без ссылки на артиста\n";
$calls = array();
$stats = usync_sync(array(
    'RUA1B2500004' => array('isrc' => 'RUA1B2500004', 'title' => 'Без артиста',
                            'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals(1, $stats['tracks_new'], 'трек создан');
assertEquals(2, count($calls), 'выполнены только два запроса на трек');
assertEquals(false, isset($calls[0]['post']['t16543']), 'ссылка на артиста в upsound не передаётся');
assertEquals(false, isset($calls[1]['post']['t165912539']), 'ссылка на артиста в ups не передаётся');

echo "\n6. Списки, набранные вручную: BOM, переносы CRLF, нет переноса в конце\n";
$calls = array();
file_put_contents(USYNC_ARTISTS_FILE, "\xEF\xBB\xBFCOLDLEEN\r\nColeFace");   # без переноса в конце
file_put_contents(USYNC_TRACKS_FILE,  "AEA0D2138701\r\nAEA0D2138702");       # без переноса в конце
$stats = usync_sync(array(
    'AEA0D2138701' => array('isrc' => 'AEA0D2138701', 'title' => 'Старый трек',
                            'artist' => 'COLDLEEN', 'album' => '', 'upc' => ''),
    'AEA0D2138703' => array('isrc' => 'AEA0D2138703', 'title' => 'Новый трек',
                            'artist' => 'ColeFace', 'album' => '', 'upc' => ''),
));
assertEquals(0, $stats['artists_new'], 'артисты из списка распознаны, несмотря на BOM и CRLF');
assertEquals(1, $stats['tracks_known'], 'известный ISRC распознан');
assertEquals(1, $stats['tracks_new'], 'создан только новый трек');
assertEquals("AEA0D2138701\r\nAEA0D2138702\r\nAEA0D2138703\r\n", file_get_contents(USYNC_TRACKS_FILE),
    'новый ISRC дописан с новой строки и в том же формате переносов');

echo "\n7. Список с переносами LF: формат файла сохраняется\n";
file_put_contents(USYNC_TRACKS_FILE, "AEA0D2138701\n");
usync_sync(array(
    'AEA0D2138704' => array('isrc' => 'AEA0D2138704', 'title' => '', 'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals("AEA0D2138701\nAEA0D2138704\n", file_get_contents(USYNC_TRACKS_FILE), 'дописано с переносом LF');

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n" . ($failed ? "ПРОВАЛЕНО проверок: $failed\n" : "Все проверки пройдены\n");
exit($failed ? 1 : 0);
