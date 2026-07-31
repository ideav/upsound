<?php
/**
 * Проверка синхронизации артистов и треков (issue #35) без обращения к боевому API.
 * Запуск: php experiments/test_issue35_sync.php
 *
 * HTTP-слой подменяется через $GLOBALS['usync_http_handler'] (POST) и
 * $GLOBALS['usync_http_get_handler'] (GET), поэтому проверяются реальные адреса и
 * параметры запросов, порядок вызовов и работа со списками.
 *
 * Ключевое поведение: запись трека в ups (165906636) создаёт импорт статистики
 * (autoParent + createParent), синхронизация её только НАХОДИТ и ДОПИСЫВАЕТ реквизиты.
 */

$tmp = sys_get_temp_dir() . '/usync_test_' . getmypid();
@mkdir($tmp, 0777, true);

define('USYNC_ARTISTS_FILE', $tmp . '/artists.txt');
define('USYNC_TRACKS_FILE',  $tmp . '/tracks.txt');
putenv('USYNC_TOKEN_UPSOUND=test-token-upsound');
putenv('USYNC_TOKEN_UPS=test-token-ups');
require_once __DIR__ . '/../upsound_sync.php';

# _xsrf запрашивается у базы на лету; подставляем готовое значение, чтобы не считать
# лишний запрос в каждом разделе. Отдельно это поведение проверяет раздел 10.
$GLOBALS['usync_xsrf'] = array('upsound' => 'xsrf-upsound', 'ups' => 'xsrf-ups');

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

/** Ответ чтения: одна запись со своими реквизитами. */
function record($id, $value, array $reqs = array())
{
    return array(
        'object' => array(array('id' => (string)$id, 'up' => '1', 'val' => $value)),
        'reqs'   => array((string)$id => $reqs),
    );
}

/** Ответы _m_new: ключ — "{db}:{таблица}:{значение}". */
$responses = array();
/** Записи, которые видит чтение: ключ — "{db}:{таблица}:{значение}". */
$records = array();

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
    if (preg_match('#/([a-z]+)/_m_set/(\d+)#', $url, $m)) {
        if (isset($responses['set:' . $m[2]])) {
            return array('body' => $responses['set:' . $m[2]], 'error' => '', 'code' => 200);
        }
        return array('body' => json_encode(array('id' => 1000 + count($calls), 'obj' => $m[2], 'next_act' => 'nul')),
                     'error' => '', 'code' => 200);
    }
    return array('body' => '', 'error' => 'unexpected url ' . $url, 'code' => 0);
};

$GLOBALS['usync_http_get_handler'] = function ($url, $token) use (&$calls, &$records) {
    $calls[] = array('url' => $url, 'get' => true, 'token' => $token);
    if (preg_match('#/([a-z]+)/xsrf\?JSON$#', $url, $m)) {
        return array('body' => json_encode(array('_xsrf' => 'xsrf-' . $m[1], 'token' => $token,
                                                 'user' => 'ftp', 'role' => 'ftp')),
                     'error' => '', 'code' => 200);
    }
    if (preg_match('#/([a-z]+)/object/(\d+)\?JSON&LIMIT=2&F_\d+=(.*)$#', $url, $m)) {
        $key = $m[1] . ':' . $m[2] . ':' . rawurldecode($m[3]);
        if (isset($records[$key])) {
            return array('body' => json_encode($records[$key]), 'error' => '', 'code' => 200);
        }
        return array('body' => json_encode(array('object' => null)), 'error' => '', 'code' => 200);
    }
    return array('body' => '', 'error' => 'unexpected url ' . $url, 'code' => 0);
};

$tracks = array(
    'RUA1B2500001' => array('isrc' => 'RUA1B2500001', 'title' => 'Первый трек',
                            'artist' => 'COLDLEEN', 'album' => 'Дебют', 'upc' => '4620000000001'),
);

echo "1. Новый артист создаётся в обеих базах; трек создаётся в upsound и дописывается в ups\n";
# Запись в ups уже заведена импортом статистики — со значением ISRC и без реквизитов.
$records['ups:165906636:RUA1B2500001'] = record(9001, 'RUA1B2500001');
$stats = usync_sync($tracks);
assertEquals(1, $stats['artists_new'], 'создан один артист');
assertEquals(1, $stats['tracks_synced'], 'один трек синхронизирован');
assertEquals(0, $stats['tracks_missing'], 'запись в ups найдена');
assertEquals(7, count($calls), 'выполнено 7 запросов: артист x2, трек в upsound, поиск в ups, поиск и создание альбома, дозапись');

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

assertEquals('https://upsound.ideav.online/ups/object/165906636?JSON&LIMIT=2&F_165906636=RUA1B2500001',
    $calls[3]['url'], 'запись трека в ups ищется по точному значению ISRC');
assertEquals('https://upsound.ideav.online/ups/object/310?JSON&LIMIT=2&F_310=%D0%94%D0%B5%D0%B1%D1%8E%D1%82',
    $calls[4]['url'], 'альбом в ups сначала ищется в справочнике');
assertEquals('https://upsound.ideav.online/ups/_m_new/310?JSON', $calls[5]['url'], 'отсутствующий альбом заводится');
assertEquals('Дебют', $calls[5]['post']['t310'], 'альбом заводится по названию');

assertEquals('https://upsound.ideav.online/ups/_m_set/9001?JSON', $calls[6]['url'], 'реквизиты дописываются найденной записи');
assertEquals('Первый трек', $calls[6]['post']['t165912542'], 'название трека дописано в ups');
assertEquals('4620000000001', $calls[6]['post']['t165912544'], 'числовой UPC дописан в ups');
assertEquals(1002, $calls[6]['post']['t165912539'], 'ссылка на артиста ups дописана');
assertEquals(1006, $calls[6]['post']['t165912540'], 'альбом передан ссылкой (ID записи), а не именем');
assertEquals(1003, $calls[6]['post']['t180202603'], 'реквизит ID = ID трека из upsound');

$new_in_ups = array_filter($calls, function ($call) {
    return strpos($call['url'], '_m_new/165906636') !== false;
});
assertEquals(0, count($new_in_ups), 'запись трека в ups не создаётся — её заводит импорт статистики');

assertEquals("COLDLEEN\r\n", file_get_contents(USYNC_ARTISTS_FILE), 'артист дописан в logs/artists.txt');
assertEquals("RUA1B2500001\r\n", file_get_contents(USYNC_TRACKS_FILE), 'ISRC дописан в logs/tracks.txt');

echo "\n2. Повторный запуск с теми же данными не обращается к API\n";
$calls = array();
$stats = usync_sync($tracks);
assertEquals(0, count($calls), 'запросов нет — всё есть в списках');
assertEquals(1, $stats['tracks_known'], 'трек учтён как уже известный');

echo "\n3. Заполненные реквизиты не перезаписываются — дописывается только ID\n";
$calls = array();
$responses['upsound:10869:COLDLEEN'] = json_encode(array('id' => 1001, 'obj' => 10869, 'warning' => 'Запись уже существует'));
$responses['ups:308:COLDLEEN']       = json_encode(array('id' => 1002, 'obj' => 308,   'warning' => 'Запись уже существует'));
$records['ups:165906636:RUA1B2500002'] = record(9002, 'RUA1B2500002', array(
    165912542    => 'Второй трек',
    'ref_165912539' => '308:1002',
    165912539    => 'COLDLEEN',
    'ref_165912540' => '310:1006,1007',
    165912540    => 'Дебют,Сборник',
    165912544    => '4620000000002',
));
$stats = usync_sync(array(
    'RUA1B2500002' => array('isrc' => 'RUA1B2500002', 'title' => 'Второй трек',
                            'artist' => 'COLDLEEN', 'album' => 'Дебют', 'upc' => '4620000000002'),
));
assertEquals(0, $stats['artists_new'], 'артист заново не создаётся');
assertEquals(1, $stats['tracks_synced'], 'трек синхронизирован');
assertEquals(1001, $calls[2]['post']['t16543'], 'использован ID существующего артиста в upsound');
$set = $calls[count($calls) - 1];
assertEquals('https://upsound.ideav.online/ups/_m_set/9002?JSON', $set['url'], 'дозапись идёт в найденную запись');
assertEquals(array('t180202603', 'token', '_xsrf'), array_keys($set['post']),
    'передан только ID: множественные ссылки Artist и Album Title перезаписью были бы обрезаны');
assertEquals(1003, $set['post']['t180202603'], 'ID трека из upsound');

echo "\n4. Реквизиты уже заполнены целиком: изменяющий запрос не отправляется\n";
$calls = array();
$records['ups:165906636:RUA1B2500005'] = record(9005, 'RUA1B2500005', array(
    165912542 => 'Пятый трек',
    180202603 => '1003',
));
$responses['upsound:291:RUA1B2500005'] = json_encode(array('id' => 1003, 'obj' => 291, 'warning' => 'Запись уже существует'));
$stats = usync_sync(array(
    'RUA1B2500005' => array('isrc' => 'RUA1B2500005', 'title' => 'Пятый трек',
                            'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals(1, $stats['tracks_synced'], 'трек учтён синхронизированным');
$sets = array_filter($calls, function ($call) { return strpos($call['url'], '_m_set') !== false; });
assertEquals(0, count($sets), 'дописывать нечего — запрос на изменение не отправлен');

echo "\n5. Записи в ups нет: трек не попадает в список и будет повторён\n";
$calls = array();
$stats = usync_sync(array(
    'RUA1B2500006' => array('isrc' => 'RUA1B2500006', 'title' => 'Шестой трек',
                            'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals(1, $stats['tracks_missing'], 'учтено как отсутствие записи, а не как ошибка');
assertEquals(0, $stats['tracks_failed'], 'это не ошибка API');
assertEquals(false, strpos(file_get_contents(USYNC_TRACKS_FILE), 'RUA1B2500006') !== false,
    'трек без записи в ups не дописан в список');

echo "\n6. Ошибка API: запись не попадает в список и будет повторена\n";
$calls = array();
$responses['ups:308:Новый Артист'] = json_encode(array('error' => 'У вас нет прав на создание объектов этого типа'));
$records['ups:165906636:RUA1B2500003'] = record(9003, 'RUA1B2500003');
$stats = usync_sync(array(
    'RUA1B2500003' => array('isrc' => 'RUA1B2500003', 'title' => 'Третий трек',
                            'artist' => 'Новый Артист', 'album' => 'Альбом', 'upc' => '1'),
));
assertEquals(1, $stats['artists_failed'], 'ошибка артиста учтена');
assertEquals(1, $stats['tracks_failed'], 'трек без артиста не синхронизирован');
assertEquals(false, strpos(file_get_contents(USYNC_ARTISTS_FILE), 'Новый Артист') !== false,
    'артист с ошибкой не дописан в список');
assertEquals(false, strpos(file_get_contents(USYNC_TRACKS_FILE), 'RUA1B2500003') !== false,
    'трек с ошибкой не дописан в список');

echo "\n7. Пустое имя артиста: ссылка на артиста не дописывается\n";
$calls = array();
$records['ups:165906636:RUA1B2500004'] = record(9004, 'RUA1B2500004');
$stats = usync_sync(array(
    'RUA1B2500004' => array('isrc' => 'RUA1B2500004', 'title' => 'Без артиста',
                            'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals(1, $stats['tracks_synced'], 'трек синхронизирован');
assertEquals(3, count($calls), 'создание в upsound, поиск в ups и дозапись');
assertEquals(false, isset($calls[0]['post']['t16543']), 'ссылка на артиста в upsound не передаётся');
assertEquals(false, isset($calls[2]['post']['t165912539']), 'ссылка на артиста в ups не дописывается');
assertEquals(false, isset($calls[2]['post']['t165912544']), 'пустой UPC в ups не дописывается');

echo "\n8. Списки, набранные вручную: BOM, переносы CRLF, нет переноса в конце\n";
$calls = array();
file_put_contents(USYNC_ARTISTS_FILE, "\xEF\xBB\xBFCOLDLEEN\r\nColeFace");   # без переноса в конце
file_put_contents(USYNC_TRACKS_FILE,  "AEA0D2138701\r\nAEA0D2138702");       # без переноса в конце
$records['ups:165906636:AEA0D2138703'] = record(9010, 'AEA0D2138703');
$stats = usync_sync(array(
    'AEA0D2138701' => array('isrc' => 'AEA0D2138701', 'title' => 'Старый трек',
                            'artist' => 'COLDLEEN', 'album' => '', 'upc' => ''),
    'AEA0D2138703' => array('isrc' => 'AEA0D2138703', 'title' => 'Новый трек',
                            'artist' => 'ColeFace', 'album' => '', 'upc' => ''),
));
assertEquals(0, $stats['artists_new'], 'артисты из списка распознаны, несмотря на BOM и CRLF');
assertEquals(1, $stats['tracks_known'], 'известный ISRC распознан');
assertEquals(1, $stats['tracks_synced'], 'синхронизирован только новый трек');
assertEquals("AEA0D2138701\r\nAEA0D2138702\r\nAEA0D2138703\r\n", file_get_contents(USYNC_TRACKS_FILE),
    'новый ISRC дописан с новой строки и в том же формате переносов');

echo "\n9. Список с переносами LF: формат файла сохраняется\n";
file_put_contents(USYNC_TRACKS_FILE, "AEA0D2138701\n");
$records['ups:165906636:AEA0D2138704'] = record(9011, 'AEA0D2138704');
usync_sync(array(
    'AEA0D2138704' => array('isrc' => 'AEA0D2138704', 'title' => '', 'artist' => '', 'album' => '', 'upc' => ''),
));
assertEquals("AEA0D2138701\nAEA0D2138704\n", file_get_contents(USYNC_TRACKS_FILE), 'дописано с переносом LF');

echo "\n10. _xsrf запрашивается у базы на лету и переиспользуется\n";
$calls = array();
unset($GLOBALS['usync_xsrf']);
assertEquals('xsrf-ups', usync_xsrf('ups'), '_xsrf получен от самой базы по токену доступа');
assertEquals('https://upsound.ideav.online/ups/xsrf?JSON', $calls[0]['url'], 'запрошен эндпоинт нужной базы');
assertEquals('test-token-ups', $calls[0]['token'], 'запрос авторизован токеном этой базы');
assertEquals(1, count($calls), 'выполнен один запрос');
usync_xsrf('ups');
assertEquals(1, count($calls), 'повторно не запрашивается — значение держится до конца работы скрипта');

$calls = array();
$records['ups:165906636:RUA1B2500007'] = record(9007, 'RUA1B2500007');
usync_sync(array(
    'RUA1B2500007' => array('isrc' => 'RUA1B2500007', 'title' => 'Седьмой трек',
                            'artist' => '', 'album' => '', 'upc' => ''),
));
$set = $calls[count($calls) - 1];
assertEquals('xsrf-ups', $set['post']['_xsrf'], 'полученный _xsrf уходит в команды изменения');
assertEquals('test-token-ups', $set['post']['token'], 'токен доступа берётся из конфигурации');

echo "\n11. Токен не задан: понятная ошибка вместо запроса с пустым токеном\n";
$calls = array();
putenv('USYNC_TOKEN_UPSOUND=');
$result = usync_sync_artist('Артист без токена');
assertEquals(true, isset($result['error']), 'синхронизация артиста возвращает ошибку');
assertEquals('Не задан токен доступа к БД upsound (USYNC_TOKEN_UPSOUND или upsound_sync.config.php)',
    $result['error'], 'в ошибке сказано, что и где задать');
assertEquals(0, count($calls), 'запрос с пустым токеном не отправляется');
putenv('USYNC_TOKEN_UPSOUND=test-token-upsound');

echo "\n12. В скриптах загрузки не осталось зашитых секретов\n";
foreach (array('ftplist2025.php', 'ftplist25.php') as $script) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $script);
    assertEquals(false, strpos($source, 'TCAFK2135340y') !== false, "$script: нет зашитого токена");
    assertEquals(1, preg_match('/usync_token\(\'ups\'\)/', $source), "$script: токен берётся из конфигурации");
    assertEquals(1, preg_match('/usync_xsrf\(\'ups\'\)/', $source), "$script: _xsrf запрашивается на лету");
    assertEquals(1, preg_match('/usync_secret\(\'s?ftp\', \'pass\'\)/', $source), "$script: пароль берётся из конфигурации");
}
assertEquals(true, file_exists(dirname(__DIR__) . '/upsound_sync.config.sample.php'), 'образец конфигурации в репозитории');
assertEquals(false, file_exists(dirname(__DIR__) . '/upsound_sync.config.php'), 'сам конфиг в репозиторий не попал');
assertEquals(1, preg_match('/^upsound_sync\.config\.php$/m', file_get_contents(dirname(__DIR__) . '/.gitignore')),
    'конфиг перечислен в .gitignore');

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n" . ($failed ? "ПРОВАЛЕНО проверок: $failed\n" : "Все проверки пройдены\n");
exit($failed ? 1 : 0);
