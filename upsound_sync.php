<?php
/**
 * Синхронизация артистов и треков, полученных по FTP/SFTP, с базами upsound и ups.
 * Issue #35.
 *
 * Порядок работы (по заданию):
 *  1. Артист создаётся в БД upsound, таблица Artist (10869) — передаётся только имя.
 *  2. Тот же артист создаётся в БД ups, таблица Artist (308), реквизит ID = ID записи из п.1.
 *  3. Трек создаётся в БД upsound, таблица ISRC (291), и в БД ups, таблица ISRC (165906636).
 *
 * Списки logs/artists.txt и logs/tracks.txt — это перечни уже синхронизированных имён и ISRC:
 * с ними сверяемся перед обращением к API, а новые записи дописываем после успешной синхронизации.
 *
 * Все четыре таблицы объявлены уникальными, поэтому команда _m_new на существующем значении
 * ничего не создаёт, а возвращает ID существующей записи и ключ warning. Благодаря этому
 * повторный запуск после сбоя безопасен.
 */

# ############################## Конфигурация ##############################

# Пути и адрес можно переопределить до подключения файла (используется в тестах).
if (!defined('USYNC_BASE_URL'))     define('USYNC_BASE_URL', 'https://upsound.ideav.online');
if (!defined('USYNC_LOG_DIR'))      define('USYNC_LOG_DIR',      __DIR__ . '/logs');
if (!defined('USYNC_ARTISTS_FILE')) define('USYNC_ARTISTS_FILE', USYNC_LOG_DIR . '/artists.txt');
if (!defined('USYNC_TRACKS_FILE'))  define('USYNC_TRACKS_FILE',  USYNC_LOG_DIR . '/tracks.txt');
if (!defined('USYNC_TIMEOUT'))      define('USYNC_TIMEOUT', 30);

# Таблицы и реквизиты БД upsound
define('USYNC_UPSOUND_ARTIST',      10869); # Artist
define('USYNC_UPSOUND_ISRC',          291); # ISRC
define('USYNC_UPSOUND_ISRC_UPC',      293); # UPC, SHORT
define('USYNC_UPSOUND_ISRC_ARTIST', 16543); # Artist, ссылка на 10869
define('USYNC_UPSOUND_ISRC_ALBUM',  10868); # Album, ссылка на 294
define('USYNC_UPSOUND_ISRC_TITLE',    298); # Song Title, CHARS

# Таблицы и реквизиты БД ups
define('USYNC_UPS_ARTIST',           308); # Artist
define('USYNC_UPS_ARTIST_ID',  165939785); # ID, SHORT — сюда пишем ID артиста из upsound
define('USYNC_UPS_ISRC',       165906636); # ISRC
define('USYNC_UPS_ISRC_TITLE', 165912542); # Title, CHARS
define('USYNC_UPS_ISRC_ARTIST',165912539); # Artist, ссылка на 308
define('USYNC_UPS_ISRC_ALBUM', 165912540); # Album Title, ссылка на 310
define('USYNC_UPS_ISRC_UPC',   165912544); # upc, NUMBER

/**
 * Токен доступа к API конкретной БД.
 * Порядок: переменная окружения USYNC_TOKEN_UPSOUND / USYNC_TOKEN_UPS,
 * затем файл upsound_sync.config.php (не хранится в репозитории), затем токен по умолчанию.
 */
function usync_token($db)
{
    $env = getenv('USYNC_TOKEN_' . strtoupper($db));
    if ($env !== false && $env !== '') {
        return $env;
    }
    $config = usync_config();
    if (isset($config['tokens'][$db])) {
        return $config['tokens'][$db];
    }
    return 'TCAFK2135340y';
}

/** Токен XSRF: команды _m_* без него отвечают 403. По умолчанию совпадает с токеном доступа. */
function usync_xsrf($db)
{
    $env = getenv('USYNC_XSRF_' . strtoupper($db));
    if ($env !== false && $env !== '') {
        return $env;
    }
    $config = usync_config();
    if (isset($config['xsrf'][$db])) {
        return $config['xsrf'][$db];
    }
    return usync_token($db);
}

function usync_config()
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/upsound_sync.config.php';
        $config = file_exists($file) ? include $file : array();
        if (!is_array($config)) {
            $config = array();
        }
    }
    return $config;
}

/** Режим проверки: запросы к API не отправляются, файлы списков не дополняются. */
function usync_dry_run()
{
    $env = getenv('USYNC_DRY_RUN');
    return ($env !== false && $env !== '' && $env !== '0');
}

# ################################ Транспорт ################################

/**
 * POST-запрос к API. Подменяется в тестах через $GLOBALS['usync_http_handler'].
 * Возвращает array(body, error, code).
 */
function usync_http_post($url, array $post)
{
    if (isset($GLOBALS['usync_http_handler']) && is_callable($GLOBALS['usync_http_handler'])) {
        return call_user_func($GLOBALS['usync_http_handler'], $url, $post);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, USYNC_TIMEOUT);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('body' => $body, 'error' => $error, 'code' => $code);
}

/**
 * Команда API. Возвращает разобранный JSON либо array('error' => текст).
 */
function usync_api($db, $cmd, $id, array $params)
{
    $params['token'] = usync_token($db);
    $params['_xsrf'] = usync_xsrf($db);
    $url = USYNC_BASE_URL . "/$db/$cmd/$id?JSON";

    $response = usync_http_post($url, $params);
    if (!empty($response['error'])) {
        return array('error' => 'CURL: ' . $response['error']);
    }
    $json = json_decode(isset($response['body']) ? $response['body'] : '', true);
    if (!is_array($json)) {
        return array('error' => 'Некорректный ответ API: ' . substr((string)@$response['body'], 0, 200));
    }
    # Ошибки авторизации приходят списком: [{"error":"..."}]
    if (isset($json[0]) && is_array($json[0]) && isset($json[0]['error'])) {
        return array('error' => $json[0]['error']);
    }
    if (isset($json['error'])) {
        return array('error' => $json['error']);
    }
    return $json;
}

/**
 * Создать запись в уникальной таблице или получить ID уже существующей.
 * $reqs — реквизиты вида array('t293' => 'значение', 'NEW_10868' => 'имя ссылки').
 * Возвращает array('id' => int, 'existed' => bool) либо array('error' => текст).
 */
function usync_create($db, $table, $value, array $reqs = array())
{
    $value = trim($value);
    if ($value === '') {
        return array('error' => 'Пустое значение записи');
    }
    $params = array('up' => 1, "t$table" => $value);
    foreach ($reqs as $key => $req_value) {
        if ($req_value !== '' && $req_value !== null) {
            $params[$key] = $req_value;
        }
    }
    if (usync_dry_run()) {
        return array('id' => 0, 'existed' => false, 'dry_run' => true);
    }
    $result = usync_api($db, '_m_new', $table, $params);
    if (isset($result['error'])) {
        return $result;
    }
    # Ключ id — это ID записи и при создании, и при возврате уже существующей.
    # Ключ obj на ветке "запись уже существует" содержит ID таблицы, поэтому не используется.
    if (!isset($result['id']) || (int)$result['id'] === 0) {
        return array('error' => 'API не вернул ID записи');
    }
    return array('id' => (int)$result['id'], 'existed' => isset($result['warning']));
}

# ############################## Файлы списков ##############################

/** Прочитать список в виде array(значение => true). */
function usync_read_list($file)
{
    $list = array();
    if (!file_exists($file)) {
        return $list;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $list;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $list[$line] = true;
        }
    }
    return $list;
}

/** Дописать значение в список. */
function usync_append_list($file, $value)
{
    if (usync_dry_run()) {
        return true;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $h = @fopen($file, 'a');
    if (!$h) {
        return false;
    }
    fwrite($h, $value . "\n");
    fclose($h);
    return true;
}

function usync_log($logger, $message)
{
    if (is_callable($logger)) {
        call_user_func($logger, "  $message");
    }
}

# ############################### Синхронизация ###############################

/**
 * Пункты 1 и 2 задания: артист в upsound, затем он же в ups с реквизитом ID.
 * Возвращает array('upsound' => id, 'ups' => id) либо array('error' => текст).
 */
function usync_sync_artist($name, $logger = null)
{
    $upsound = usync_create('upsound', USYNC_UPSOUND_ARTIST, $name);
    if (isset($upsound['error'])) {
        usync_log($logger, "Артист '$name': upsound — " . $upsound['error']);
        return array('error' => $upsound['error']);
    }

    $ups = usync_create('ups', USYNC_UPS_ARTIST, $name, array(
        't' . USYNC_UPS_ARTIST_ID => $upsound['id'],
    ));
    if (isset($ups['error'])) {
        usync_log($logger, "Артист '$name': ups — " . $ups['error']);
        return array('error' => $ups['error']);
    }

    usync_log($logger, "Артист '$name': upsound ID=" . $upsound['id']
        . ($upsound['existed'] ? ' (уже был)' : ' (создан)')
        . ', ups ID=' . $ups['id'] . ($ups['existed'] ? ' (уже был)' : ' (создан)'));

    return array('upsound' => $upsound['id'], 'ups' => $ups['id']);
}

/**
 * Пункт 3 задания: трек в upsound (291) и в ups (165906636).
 * $artist_ids — array('upsound' => id, 'ups' => id) или null, если артист неизвестен.
 */
function usync_sync_track($isrc, array $track, $artist_ids, $logger = null)
{
    $title = isset($track['title']) ? trim($track['title']) : '';
    $album = isset($track['album']) ? trim($track['album']) : '';
    $upc   = isset($track['upc'])   ? trim($track['upc'])   : '';

    $upsound_reqs = array(
        't' . USYNC_UPSOUND_ISRC_TITLE => $title,
        't' . USYNC_UPSOUND_ISRC_UPC   => $upc,
    );
    if ($album !== '') {
        $upsound_reqs['NEW_' . USYNC_UPSOUND_ISRC_ALBUM] = $album;
    }
    if ($artist_ids) {
        $upsound_reqs['t' . USYNC_UPSOUND_ISRC_ARTIST] = $artist_ids['upsound'];
    }
    $upsound = usync_create('upsound', USYNC_UPSOUND_ISRC, $isrc, $upsound_reqs);
    if (isset($upsound['error'])) {
        usync_log($logger, "Трек $isrc: upsound — " . $upsound['error']);
        return array('error' => $upsound['error']);
    }

    # В ups реквизит upc числовой, поэтому нечисловые значения не передаём.
    $ups_reqs = array(
        't' . USYNC_UPS_ISRC_TITLE => $title,
        't' . USYNC_UPS_ISRC_UPC   => preg_match('/^\d+$/', $upc) ? $upc : '',
    );
    if ($album !== '') {
        $ups_reqs['NEW_' . USYNC_UPS_ISRC_ALBUM] = $album;
    }
    if ($artist_ids) {
        $ups_reqs['t' . USYNC_UPS_ISRC_ARTIST] = $artist_ids['ups'];
    }
    $ups = usync_create('ups', USYNC_UPS_ISRC, $isrc, $ups_reqs);
    if (isset($ups['error'])) {
        usync_log($logger, "Трек $isrc: ups — " . $ups['error']);
        return array('error' => $ups['error']);
    }

    usync_log($logger, "Трек $isrc: upsound ID=" . $upsound['id'] . ', ups ID=' . $ups['id']);
    return array('upsound' => $upsound['id'], 'ups' => $ups['id']);
}

/**
 * Точка входа. $tracks — array(ISRC => array(isrc, title, artist, album, upc)).
 * Возвращает статистику выполнения.
 */
function usync_sync(array $tracks, $logger = null)
{
    $stats = array(
        'artists_new'    => 0,
        'artists_failed' => 0,
        'tracks_new'     => 0,
        'tracks_failed'  => 0,
        'tracks_known'   => 0,
    );

    if (usync_dry_run()) {
        usync_log($logger, 'Синхронизация в режиме проверки (USYNC_DRY_RUN): запросы к API не отправляются');
    }
    if (!count($tracks)) {
        usync_log($logger, 'Синхронизация: треков для проверки нет');
        return $stats;
    }

    $known_artists = usync_read_list(USYNC_ARTISTS_FILE);
    $known_tracks  = usync_read_list(USYNC_TRACKS_FILE);
    $artist_ids    = array(); # имя => array('upsound' => id, 'ups' => id)

    # Пункты 1 и 2: артисты, которых ещё нет в списке logs/artists.txt
    foreach ($tracks as $track) {
        $name = isset($track['artist']) ? trim($track['artist']) : '';
        if ($name === '' || isset($known_artists[$name]) || isset($artist_ids[$name])) {
            continue;
        }
        $result = usync_sync_artist($name, $logger);
        if (isset($result['error'])) {
            $stats['artists_failed']++;
            continue;
        }
        $artist_ids[$name] = $result;
        $known_artists[$name] = true;
        usync_append_list(USYNC_ARTISTS_FILE, $name);
        $stats['artists_new']++;
    }

    # Пункт 3: треки, которых ещё нет в списке logs/tracks.txt
    foreach ($tracks as $isrc => $track) {
        $isrc = trim($isrc);
        if ($isrc === '') {
            continue;
        }
        if (isset($known_tracks[$isrc])) {
            $stats['tracks_known']++;
            continue;
        }

        # Артист трека мог быть синхронизирован в прошлые запуски — тогда ID берём по имени.
        $name = isset($track['artist']) ? trim($track['artist']) : '';
        $ids  = null;
        if ($name !== '') {
            if (!isset($artist_ids[$name])) {
                $result = usync_sync_artist($name, $logger);
                if (isset($result['error'])) {
                    usync_log($logger, "Трек $isrc пропущен: не удалось получить ID артиста '$name'");
                    $stats['tracks_failed']++;
                    continue;
                }
                $artist_ids[$name] = $result;
            }
            $ids = $artist_ids[$name];
        }

        $result = usync_sync_track($isrc, $track, $ids, $logger);
        if (isset($result['error'])) {
            $stats['tracks_failed']++;
            continue;
        }
        $known_tracks[$isrc] = true;
        usync_append_list(USYNC_TRACKS_FILE, $isrc);
        $stats['tracks_new']++;
    }

    usync_log($logger, 'Синхронизация: артистов создано ' . $stats['artists_new']
        . ', ошибок ' . $stats['artists_failed']
        . '; треков создано ' . $stats['tracks_new']
        . ', ошибок ' . $stats['tracks_failed']
        . ', уже было ' . $stats['tracks_known']);

    return $stats;
}
