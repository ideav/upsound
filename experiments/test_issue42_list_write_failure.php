<?php
/**
 * Провал работы со списками logs/artists.txt и logs/tracks.txt должен быть виден в логе
 * (issue #42). Запуск: php experiments/test_issue42_list_write_failure.php
 *
 * Поводом стал боевой прогон 02.08.2026: синхронизация отчиталась «артистов создано 125,
 * ошибок 0», а в logs/ не попало ни одного имени — прав на каталог не было, а
 * usync_append_list() возвращала false молча.
 *
 * Пути специально сделаны недоступными для записи, причём двумя разными способами,
 * чтобы проверить обе ветки отказа:
 *   USYNC_ARTISTS_FILE — существующий КАТАЛОГ: fopen($dir, 'a') вернёт false;
 *   USYNC_TRACKS_FILE  — путь внутри обычного ФАЙЛА: mkdir не создаст каталог.
 * Такой отказ не обойти правами root, поэтому тест одинаково работает в любом окружении.
 */

$tmp = sys_get_temp_dir() . '/usync_test42_' . getmypid();
@mkdir($tmp, 0777, true);

# Списки — как на сервере: файл существует, но закрыт на запись, и каталог, куда нельзя
# добавить файл. Под root права не действуют, поэтому там берём то, что не обойти и ему:
# каталог вместо файла (открыть на дозапись нельзя никому).
file_put_contents($tmp . '/artists.txt', '');
@chmod($tmp . '/artists.txt', 0444);
@mkdir($tmp . '/ro', 0555, true);
@chmod($tmp . '/ro', 0555);

$permissions_work = !is_writable($tmp . '/artists.txt');
if (!$permissions_work) {
    echo "  Запущено под root: права не действуют, проверяем на каталоге вместо файла.\n"
       . "  Сценарий с правами: docker run --user \"\$(id -u):\$(id -g)\" …\n\n";
}

define('USYNC_ARTISTS_FILE', $permissions_work ? $tmp . '/artists.txt' : $tmp);
define('USYNC_TRACKS_FILE',  $permissions_work ? $tmp . '/ro/tracks.txt' : __FILE__ . '/tracks.txt');
putenv('USYNC_TOKEN_UPSOUND=test-token-upsound');
putenv('USYNC_TOKEN_UPS=test-token-ups');
require_once __DIR__ . '/../upsound_sync.php';

$GLOBALS['usync_xsrf'] = array('upsound' => 'xsrf-upsound', 'ups' => 'xsrf-ups');

$failed = 0;

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

/** Сколько строк лога содержат подстроку. */
function countLines(array $log, $needle)
{
    $found = 0;
    foreach ($log as $line) {
        if (strpos($line, $needle) !== false) {
            $found++;
        }
    }
    return $found;
}

# Запись трека в ups заводит импорт статистики — здесь она уже есть и пуста.
$GLOBALS['usync_http_get_handler'] = function ($url, $token) {
    if (preg_match('#/ups/object/165906636\?.*F_165906636=([A-Z0-9]+)#', $url, $m)) {
        return array('body' => json_encode(array(
            'object' => array(array('id' => '500', 'up' => '1', 'val' => $m[1])),
            'reqs'   => array('500' => array()),
        )), 'error' => '', 'code' => 200);
    }
    return array('body' => json_encode(array('object' => array())), 'error' => '', 'code' => 200);
};
$GLOBALS['usync_http_handler'] = function ($url, array $post) {
    static $id = 700;
    return array('body' => json_encode(array('id' => ++$id)), 'error' => '', 'code' => 200);
};

/** Три трека трёх разных артистов. */
function tracks()
{
    $tracks = array();
    foreach (array('RUA1B2500001' => 'COLDLEEN', 'RUA1B2500002' => 'MFP', 'RUA1B2500003' => 'GOTR') as $isrc => $artist) {
        $tracks[$isrc] = array('isrc' => $isrc, 'title' => 'Song', 'artist' => $artist, 'album' => '', 'upc' => '123');
    }
    return $tracks;
}

echo "1. Список не дописывается: синхронизация идёт, но в лог попадает предупреждение\n";

$log = array();
$logger = function ($line) use (&$log) { $log[] = $line; };
$GLOBALS['usync_list_warned'] = array();

$stats = usync_sync(tracks(), $logger);

assertEquals(3, $stats['artists_new'],   'все три артиста синхронизированы');
assertEquals(3, $stats['tracks_synced'], 'все три трека синхронизированы');
assertEquals(6, $stats['unlisted'],      'все шесть значений учтены как не дописанные в списки');

# Причина отказа зависит от окружения (нет прав на файл, нет прав на каталог, каталог
# вместо файла), поэтому проверяем не формулировку ветки, а главное: о каждом файле
# сказано ровно один раз и назван путь.
assertEquals(2, countLines($log, 'Список '),
    'по одной строке на файл, а не на каждое из шести значений');
assertEquals(1, countLines($log, USYNC_ARTISTS_FILE), 'назван путь к списку артистов');
assertEquals(1, countLines($log, USYNC_TRACKS_FILE),  'назван путь к списку треков');
assertEquals(true, countLines($log, 'проверьте') >= 2, 'в обеих строках сказано, что проверить');
assertEquals(1, countLines($log, 'в списки не дописано значений — 6'),
    'итог синхронизации называет число не дописанных значений');
assertEquals(1, countLines($log, 'следующий запуск проверит их заново'),
    'в логе сказано, чем это грозит следующему запуску');

echo "\n2. Нечитаемый список: пустой результат не выдаётся за «ничего ещё не сделано»\n";

$log = array();
$GLOBALS['usync_list_warned'] = array();

$list = usync_read_list($tmp, $logger); # каталог вместо файла: file() вернёт false
assertEquals(array(), $list, 'нечитаемый список даёт пустой набор значений');
assertEquals(1, countLines($log, 'прочитать не удалось'), 'о нечитаемом списке сказано в лог');
assertEquals(1, countLines($log, 'считается новым'), 'сказано, к чему это приведёт');

usync_read_list($tmp, $logger);
assertEquals(1, countLines($log, 'прочитать не удалось'), 'повторное чтение того же файла лог не засоряет');

echo "\n3. Отсутствующий список — не беда: его заводит первый же успешный запуск\n";

$log = array();
$GLOBALS['usync_list_warned'] = array();

$list = usync_read_list($tmp . '/never-existed.txt', $logger);
assertEquals(array(), $list, 'списка нет — набор значений пуст');
assertEquals(0, count($log), 'о ненайденном файле в лог не пишем');

echo "\n4. Холостой прогон: списки не трогаются, ложных предупреждений нет\n";

$log = array();
$GLOBALS['usync_list_warned'] = array();
putenv('USYNC_DRY_RUN=1');

$stats = usync_sync(tracks(), $logger);

assertEquals(0, $stats['unlisted'], 'в холостом прогоне не дописанных значений нет');
assertEquals(0, countLines($log, 'дозапись') + countLines($log, 'создать каталог')
              + countLines($log, 'записать значение'),
    'в холостом прогоне о правах на запись не предупреждаем: писать никто и не собирался');
assertEquals(0, countLines($log, 'в списки не дописано'), 'итог холостого прогона о списках молчит');

putenv('USYNC_DRY_RUN=0');

@rmdir($tmp);

echo "\n" . ($failed ? "ПРОВАЛЕНО проверок: $failed\n" : "Все проверки пройдены\n");
exit($failed ? 1 : 0);
