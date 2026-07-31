<?php
/**
 * Синхронизация артистов и треков, полученных по FTP/SFTP, с базами upsound и ups.
 * Issue #35.
 *
 * Порядок работы (по заданию):
 *  1. Артист создаётся в БД upsound, таблица Artist (10869) — передаётся только имя.
 *  2. Тот же артист создаётся в БД ups, таблица Artist (308), реквизит ID = ID записи из п.1.
 *  3. Трек создаётся в БД upsound, таблица ISRC (291).
 *  4. В БД ups запись трека (ISRC, 165906636) УЖЕ СОЗДАНА импортом статистики: ftplist-скрипты
 *     шлют выгрузку в ups/object/10667430 с autoParent=165906636 и createParent=1, и ядро
 *     заводит недостающего родителя само (index.php, "Auto create parent") — но только со
 *     значением ISRC, без остальных реквизитов. Поэтому здесь запись НЕ создаём, а находим
 *     и дописываем ей недостающие реквизиты, включая ID записи трека из upsound (п.3).
 *
 * Списки logs/artists.txt и logs/tracks.txt — это перечни уже синхронизированных имён и ISRC:
 * с ними сверяемся перед обращением к API, а новые записи дописываем после успешной синхронизации.
 *
 * Таблицы артистов и таблицы ISRC объявлены уникальными, поэтому команда _m_new на существующем
 * значении ничего не создаёт, а возвращает ID существующей записи и ключ warning. Благодаря этому
 * повторный запуск после сбоя безопасен.
 *
 * Дописываем только ПУСТЫЕ реквизиты: заполненное значение не трогаем. Реквизиты Artist и
 * Album Title в ups — множественные ссылки (:MULTI:), а команда изменения заменяет весь набор
 * ссылок целиком, поэтому запись поверх непустого значения стёрла бы остальные ссылки.
 * Исключение — ID: он и есть цель синхронизации, его проставляем и при расхождении.
 */

# ############################## Конфигурация ##############################

# Пути и адрес можно переопределить до подключения файла (используется в тестах).
if (!defined('USYNC_BASE_URL'))     define('USYNC_BASE_URL', 'https://upsound.ideav.online');
if (!defined('USYNC_LOG_DIR'))      define('USYNC_LOG_DIR',      __DIR__ . '/logs');
if (!defined('USYNC_ARTISTS_FILE')) define('USYNC_ARTISTS_FILE', USYNC_LOG_DIR . '/artists.txt');
if (!defined('USYNC_TRACKS_FILE'))  define('USYNC_TRACKS_FILE',  USYNC_LOG_DIR . '/tracks.txt');
if (!defined('USYNC_TIMEOUT'))      define('USYNC_TIMEOUT', 30);

/**
 * Длина корректного ISRC — 12 символов (пример: RUA1D2574249).
 * В ups и в logs/tracks.txt есть обрезанные значения ('R', 'RU', 'RUA1D2378'…) — следы
 * посимвольного ввода, а не выгрузок: в самих выгрузках все ISRC по 12 символов.
 * Такие значения не принимаем: ни в upsound не создаём, ни в ups не дописываем.
 */
define('USYNC_ISRC_LENGTH', 12);

function usync_valid_isrc($isrc)
{
    return strlen(trim($isrc)) === USYNC_ISRC_LENGTH;
}

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
define('USYNC_UPS_ALBUM',            310); # Album Title — справочник альбомов, НЕ уникальный
define('USYNC_UPS_ISRC',       165906636); # ISRC
define('USYNC_UPS_ISRC_TITLE', 165912542); # Title, CHARS
define('USYNC_UPS_ISRC_ARTIST',165912539); # Artist, множественная ссылка на 308
define('USYNC_UPS_ISRC_ALBUM', 165912540); # Album Title, множественная ссылка на 310
define('USYNC_UPS_ISRC_UPC',   165912544); # upc, NUMBER
define('USYNC_UPS_ISRC_ID',    180202603); # ID, SHORT — сюда пишем ID трека из upsound

/**
 * Конфигурация: токены доступа к API и пароли FTP/SFTP.
 * Файл upsound_sync.config.php в .gitignore и в репозиторий не попадает,
 * образец со всеми ключами — upsound_sync.config.sample.php.
 */
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

/**
 * Значение из секции конфигурации ('ftp', 'sftp').
 * Порядок: переменная окружения USYNC_{СЕКЦИЯ}_{КЛЮЧ} (например USYNC_FTP_PASS),
 * затем upsound_sync.config.php, затем $default.
 */
function usync_secret($section, $key, $default = '')
{
    $env = getenv('USYNC_' . strtoupper($section) . '_' . strtoupper($key));
    if ($env !== false && $env !== '') {
        return $env;
    }
    $config = usync_config();
    if (isset($config[$section][$key]) && $config[$section][$key] !== '') {
        return $config[$section][$key];
    }
    return $default;
}

/**
 * Токен доступа к API конкретной БД.
 * Порядок: переменная окружения USYNC_TOKEN_UPSOUND / USYNC_TOKEN_UPS,
 * затем секция tokens в upsound_sync.config.php. Пустая строка — токен не задан.
 */
function usync_token($db)
{
    $env = getenv('USYNC_TOKEN_' . strtoupper($db));
    if ($env !== false && $env !== '') {
        return $env;
    }
    $config = usync_config();
    if (isset($config['tokens'][$db]) && $config['tokens'][$db] !== '') {
        return $config['tokens'][$db];
    }
    return '';
}

/**
 * Токен XSRF: команды _m_* без него отвечают 403. В конфигурации не хранится —
 * база отдаёт его по токену доступа, поэтому запрашиваем на лету и держим до конца работы
 * скрипта в $GLOBALS['usync_xsrf'] (тот же ключ позволяет подставить значение в тестах).
 */
function usync_xsrf($db)
{
    if (isset($GLOBALS['usync_xsrf'][$db]) && $GLOBALS['usync_xsrf'][$db] !== '') {
        return $GLOBALS['usync_xsrf'][$db];
    }
    $token = usync_token($db);
    if ($token === '') {
        return '';
    }
    $result = usync_decode(usync_http_get(USYNC_BASE_URL . "/$db/xsrf?JSON", $token));
    if (isset($result['error']) || !isset($result['_xsrf']) || $result['_xsrf'] === '') {
        return '';
    }
    $GLOBALS['usync_xsrf'][$db] = $result['_xsrf'];
    return $result['_xsrf'];
}

/** Понятное сообщение, если доступ к БД не настроен. */
function usync_no_token($db)
{
    return 'Не задан токен доступа к БД ' . $db
        . ' (USYNC_TOKEN_' . strtoupper($db) . ' или upsound_sync.config.php)';
}

/**
 * Режим проверки: изменяющие запросы (_m_new, _m_set) не отправляются, файлы списков
 * не дополняются. Чтение выполняется — иначе в логе не видно, что именно будет дописано.
 * Скрипты загрузки в этом режиме дополнительно не шлют импорт и не отмечают файл обработанным.
 *
 * Порядок: переменная окружения USYNC_DRY_RUN, затем ключ dry_run в upsound_sync.config.php
 * (для запуска по крону, где переменную окружения задать негде).
 */
function usync_dry_run()
{
    $env = getenv('USYNC_DRY_RUN');
    if ($env !== false && $env !== '') {
        return $env !== '0';
    }
    $config = usync_config();
    return !empty($config['dry_run']);
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
 * GET-запрос к API. Подменяется в тестах через $GLOBALS['usync_http_get_handler'].
 * Токен в строке запроса не работает — только заголовок X-Authorization или cookie.
 */
function usync_http_get($url, $token)
{
    if (isset($GLOBALS['usync_http_get_handler']) && is_callable($GLOBALS['usync_http_get_handler'])) {
        return call_user_func($GLOBALS['usync_http_get_handler'], $url, $token);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Authorization: $token"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, USYNC_TIMEOUT);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('body' => $body, 'error' => $error, 'code' => $code);
}

/** Разобрать ответ API: JSON либо array('error' => текст). */
function usync_decode($response)
{
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
 * Команда API. Возвращает разобранный JSON либо array('error' => текст).
 */
function usync_api($db, $cmd, $id, array $params)
{
    $token = usync_token($db);
    if ($token === '') {
        return array('error' => usync_no_token($db));
    }
    $xsrf = usync_xsrf($db);
    if ($xsrf === '') {
        return array('error' => "Не удалось получить _xsrf для БД $db");
    }
    $params['token'] = $token;
    $params['_xsrf'] = $xsrf;
    $url = USYNC_BASE_URL . "/$db/$cmd/$id?JSON";

    return usync_decode(usync_http_post($url, $params));
}

/**
 * Значение с апострофом или обратным слэшем в фильтр F_ подставлять нельзя: ядро кладёт
 * его в SQL без экранирования, и запрос падает с синтаксической ошибкой. Экранирование
 * на нашей стороне не помогает — проверены и \' и '' (живой API, 31.07.2026,
 * имя "Yan Pol' Musique"). Такие значения не ищем.
 */
function usync_searchable($value)
{
    return strpos($value, "'") === false && strpos($value, "\\") === false;
}

/**
 * Найти запись по значению первой колонки. Фильтр F_{таблица} сравнивает точно
 * (значение по префиксу ничего не находит — проверено на живом API).
 * Возвращает array('id' => int, 'reqs' => array) — id=0, если записи нет.
 * Для значений, которые нельзя искать, id=0 и признак not_searchable.
 */
function usync_find($db, $table, $value)
{
    $value = trim($value);
    if ($value === '') {
        return array('error' => 'Пустое значение записи');
    }
    if (!usync_searchable($value)) {
        return array('id' => 0, 'reqs' => array(), 'not_searchable' => true);
    }
    $token = usync_token($db);
    if ($token === '') {
        return array('error' => usync_no_token($db));
    }
    $url = USYNC_BASE_URL . "/$db/object/$table?JSON&LIMIT=2&F_$table=" . rawurlencode($value);

    $result = usync_decode(usync_http_get($url, $token));
    if (isset($result['error'])) {
        return $result;
    }
    if (empty($result['object']) || !is_array($result['object'])) {
        return array('id' => 0, 'reqs' => array());
    }
    $object = reset($result['object']);
    $id     = isset($object['id']) ? (int)$object['id'] : 0;
    $reqs   = isset($result['reqs'][$id]) && is_array($result['reqs'][$id]) ? $result['reqs'][$id] : array();

    return array('id' => $id, 'reqs' => $reqs);
}

/**
 * Текущее значение реквизита в ответе чтения. У ссылочных реквизитов значение приходит
 * дважды: ref_{id} — "{таблица}:{id записи}", {id} — отображаемое имя.
 */
function usync_req_value(array $reqs, $req_id)
{
    foreach (array("ref_$req_id", $req_id) as $key) {
        if (isset($reqs[$key]) && trim((string)$reqs[$key]) !== '') {
            return trim((string)$reqs[$key]);
        }
    }
    return '';
}

/**
 * Дописать реквизиты существующей записи.
 * $reqs — array('t165912542' => 'значение'). Пустые значения не передаём: команда
 * трактует пустое значение как удаление реквизита.
 */
function usync_update($db, $obj, array $reqs)
{
    $params = array();
    foreach ($reqs as $key => $value) {
        if ($value !== '' && $value !== null) {
            $params[$key] = $value;
        }
    }
    if (!count($params)) {
        return array('id' => (int)$obj, 'updated' => array());
    }
    if (usync_dry_run()) {
        return array('id' => (int)$obj, 'updated' => array_keys($params), 'dry_run' => true);
    }
    $result = usync_api($db, '_m_set', $obj, $params);
    if (isset($result['error'])) {
        return $result;
    }
    return array('id' => (int)$obj, 'updated' => array_keys($params));
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
        # Записи не создаём, но ID уже существующей достаём чтением — иначе в режиме
        # проверки не видно, какое значение будет проставлено в ups.
        $found = usync_find($db, $table, $value);
        if (isset($found['error'])) {
            return $found;
        }
        return array('id' => $found['id'], 'existed' => (bool)$found['id'], 'dry_run' => true);
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

/**
 * ID альбома в справочнике ups (310). Справочник НЕ уникальный, поэтому _m_new на
 * существующем названии завёл бы дубль — сначала ищем, создаём только отсутствующий.
 */
function usync_ups_album($name)
{
    $found = usync_find('ups', USYNC_UPS_ALBUM, $name);
    if (isset($found['error'])) {
        return $found;
    }
    if ($found['id']) {
        return array('id' => $found['id'], 'existed' => true);
    }
    if (!empty($found['not_searchable'])) {
        # Проверить, есть ли такой альбом, нечем, а справочник не уникален —
        # создание вслепую завело бы дубль. Оставляем реквизит незаполненным.
        return array('id' => 0, 'existed' => false, 'not_searchable' => true);
    }
    if (usync_dry_run()) {
        return array('id' => 0, 'existed' => false, 'dry_run' => true);
    }
    return usync_create('ups', USYNC_UPS_ALBUM, $name);
}

# ############################## Файлы списков ##############################

/**
 * Прочитать список в виде array(значение => true).
 * Файлы ведутся вручную, поэтому снимаем BOM, переносы CRLF и краевые пробелы.
 */
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
    $first = true;
    foreach ($lines as $line) {
        if ($first) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            $first = false;
        }
        $line = trim($line);
        if ($line !== '') {
            $list[$line] = true;
        }
    }
    return $list;
}

/**
 * Дописать значение в список.
 * Перенос строки берём такой же, какой уже в файле (примеры в issue #35 — CRLF),
 * и, если файл не заканчивается переносом, сначала добавляем его,
 * иначе новое значение приклеится к последней строке.
 */
function usync_append_list($file, $value)
{
    if (usync_dry_run()) {
        return true;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $eol    = "\r\n";
    $prefix = '';
    $size   = file_exists($file) ? filesize($file) : 0;
    if ($size > 0) {
        $h = @fopen($file, 'r');
        if ($h) {
            $head = fread($h, 4096);
            if (strpos($head, "\r\n") === false && strpos($head, "\n") !== false) {
                $eol = "\n";
            }
            fseek($h, -1, SEEK_END);
            if (fread($h, 1) !== "\n") {
                $prefix = $eol;
            }
            fclose($h);
        }
    }

    $h = @fopen($file, 'a');
    if (!$h) {
        return false;
    }
    fwrite($h, $prefix . $value . $eol);
    fclose($h);
    return true;
}

/**
 * Как записать состояние записи в лог. В режиме проверки ничего не создаётся,
 * поэтому «создан» там было бы неправдой.
 */
function usync_state(array $result)
{
    if (!empty($result['dry_run']) && empty($result['id'])) {
        return 'будет создан';
    }
    return !empty($result['existed']) ? 'уже был' : 'создан';
}

function usync_log($logger, $message)
{
    if (is_callable($logger)) {
        call_user_func($logger, "  $message");
    }
}

# ############################### Синхронизация ###############################

/**
 * Склейка соавторов одной строкой: "LEVU & Ксюша Фавия", "AMiD feat. T1MC",
 * "1996, D.masta, Смоки Мо". Это не артист, а перечисление — новых записей по таким
 * строкам не заводим. Именно так в ups набралось больше трёхсот записей-перечислений,
 * у которых нет и не может быть пары в upsound.
 */
function usync_is_collab($name)
{
    return (bool)preg_match('/&|,|\bfeat\b|\bft\b|\bvs\b/ui', $name);
}

/**
 * Склейка соавторов: не создаём, но если запись уже есть в ОБЕИХ базах — ссылку на неё
 * проставляем, чтобы не рвать то, что уже связано.
 *
 * Часть склеек лежит в ups с экранированной запятой ("Son\, Fire!"), а в выгрузке она
 * обычная. Найти их поиском нельзя: значение с обратным слэшем ядро в фильтр не пускает
 * (см. usync_searchable), да и само экранирование там своё. Такие склейки просто
 * пропускаются — по заданию заводить их всё равно не надо.
 */
function usync_link_collab($name, $logger = null)
{
    $upsound = usync_find('upsound', USYNC_UPSOUND_ARTIST, $name);
    if (isset($upsound['error'])) {
        usync_log($logger, "Артист '$name': upsound — " . $upsound['error']);
        return array('error' => $upsound['error']);
    }
    $ups = usync_find('ups', USYNC_UPS_ARTIST, $name);
    if (isset($ups['error'])) {
        usync_log($logger, "Артист '$name': ups — " . $ups['error']);
        return array('error' => $ups['error']);
    }
    if (!$upsound['id'] || !$ups['id']) {
        usync_log($logger, "Артист '$name': склейка соавторов — запись не заводим"
            . ($upsound['id'] || $ups['id'] ? ' (есть только в одной базе)' : ''));
        return array('collab' => true);
    }

    usync_log($logger, "Артист '$name': склейка соавторов, обе записи уже есть — "
        . 'upsound ID=' . $upsound['id'] . ', ups ID=' . $ups['id']);
    return array('upsound' => $upsound['id'], 'ups' => $ups['id']);
}

/**
 * Пункты 1 и 2 задания: артист в upsound, затем он же в ups с реквизитом ID.
 * Возвращает array('upsound' => id, 'ups' => id), array('collab' => true) для склейки
 * соавторов либо array('error' => текст).
 */
function usync_sync_artist($name, $logger = null)
{
    if (usync_is_collab($name)) {
        return usync_link_collab($name, $logger);
    }

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

    usync_log($logger, "Артист '$name': upsound ID=" . $upsound['id'] . ' (' . usync_state($upsound) . ')'
        . ', ups ID=' . $ups['id'] . ' (' . usync_state($ups) . ')');

    return array('upsound' => $upsound['id'], 'ups' => $ups['id']);
}

/**
 * Пункты 3 и 4 задания: трек создаётся в upsound (291), а в ups (165906636) запись уже
 * заведена импортом статистики — её находим и дописываем недостающие реквизиты,
 * включая ID записи из upsound.
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

    # Запись в ups создаёт импорт статистики (autoParent=165906636 + createParent=1),
    # причём только со значением ISRC. Здесь её ищем и дополняем.
    $ups = usync_find('ups', USYNC_UPS_ISRC, $isrc);
    if (isset($ups['error'])) {
        usync_log($logger, "Трек $isrc: ups — " . $ups['error']);
        return array('error' => $ups['error']);
    }
    if (!$ups['id']) {
        # Импорт не завёл родителя — повторим на следующем запуске, когда запись появится.
        $message = 'записи нет в ups: её создаёт импорт статистики';
        usync_log($logger, "Трек $isrc: $message");
        return array('error' => $message, 'missing' => true);
    }

    $reqs    = $ups['reqs'];
    $updates = array();
    $filled  = array();

    # Дописываем только пустые реквизиты: Artist и Album Title — множественные ссылки,
    # и запись поверх непустого значения заменила бы весь набор ссылок.
    if ($title !== '' && usync_req_value($reqs, USYNC_UPS_ISRC_TITLE) === '') {
        $updates['t' . USYNC_UPS_ISRC_TITLE] = $title;
        $filled[] = 'Title';
    }
    # Реквизит upc в ups числовой, поэтому нечисловые значения не передаём.
    if (preg_match('/^\d+$/', $upc) && usync_req_value($reqs, USYNC_UPS_ISRC_UPC) === '') {
        $updates['t' . USYNC_UPS_ISRC_UPC] = $upc;
        $filled[] = 'upc';
    }
    if ($artist_ids && $artist_ids['ups'] && usync_req_value($reqs, USYNC_UPS_ISRC_ARTIST) === '') {
        $updates['t' . USYNC_UPS_ISRC_ARTIST] = $artist_ids['ups'];
        $filled[] = 'Artist';
    }
    if ($album !== '' && usync_req_value($reqs, USYNC_UPS_ISRC_ALBUM) === '') {
        $album_ref = usync_ups_album($album);
        if (isset($album_ref['error'])) {
            usync_log($logger, "Трек $isrc: альбом '$album' — " . $album_ref['error']);
            return array('error' => $album_ref['error']);
        }
        if ($album_ref['id']) {
            $updates['t' . USYNC_UPS_ISRC_ALBUM] = $album_ref['id'];
            $filled[] = 'Album Title';
        }
        elseif (!empty($album_ref['not_searchable'])) {
            usync_log($logger, "Трек $isrc: альбом '$album' пропущен — апостроф в названии, поиск по нему ядро не выполняет");
        }
    }
    # ID из upsound — то, ради чего синхронизация и делается: проставляем и при расхождении.
    if ($upsound['id'] && usync_req_value($reqs, USYNC_UPS_ISRC_ID) !== (string)$upsound['id']) {
        $updates['t' . USYNC_UPS_ISRC_ID] = $upsound['id'];
        $filled[] = 'ID';
    }

    if (!count($updates)) {
        usync_log($logger, "Трек $isrc: upsound ID=" . $upsound['id']
            . ', ups ID=' . $ups['id'] . ' — реквизиты уже заполнены');
        return array('upsound' => $upsound['id'], 'ups' => $ups['id'], 'filled' => array());
    }

    $saved = usync_update('ups', $ups['id'], $updates);
    if (isset($saved['error'])) {
        usync_log($logger, "Трек $isrc: ups — " . $saved['error']);
        return array('error' => $saved['error']);
    }

    usync_log($logger, "Трек $isrc: upsound ID=" . $upsound['id']
        . ', ups ID=' . $ups['id'] . ', дописано: ' . implode(', ', $filled));
    return array('upsound' => $upsound['id'], 'ups' => $ups['id'], 'filled' => $filled);
}

/**
 * Точка входа. $tracks — array(ISRC => array(isrc, title, artist, album, upc)).
 * Возвращает статистику выполнения.
 */
function usync_sync(array $tracks, $logger = null)
{
    $stats = array(
        'artists_new'     => 0,
        'artists_failed'  => 0,
        'artists_collab'  => 0, # склейка соавторов — запись не заводим
        'tracks_synced'   => 0, # запись в ups дополнена (или уже была полной)
        'tracks_failed'   => 0,
        'tracks_missing'  => 0, # импорт статистики ещё не завёл запись в ups
        'tracks_known'    => 0,
        'tracks_invalid'  => 0, # ISRC не той длины — не принимаем
    );

    if (usync_dry_run()) {
        usync_log($logger, 'Синхронизация в режиме проверки: изменяющие запросы не отправляются');
    }

    # Отсеиваем некорректные ISRC до всего остального, чтобы по ним не заводились
    # ни артисты, ни записи треков.
    $rejected = array();
    foreach ($tracks as $isrc => $track) {
        if (!usync_valid_isrc($isrc)) {
            $rejected[] = $isrc;
            unset($tracks[$isrc]);
        }
    }
    if (count($rejected)) {
        $stats['tracks_invalid'] = count($rejected);
        $shown = array_slice($rejected, 0, 10);
        usync_log($logger, 'Синхронизация: отброшено ISRC неверной длины — ' . count($rejected)
            . ' (' . implode(', ', $shown) . (count($rejected) > count($shown) ? ', …' : '') . ')');
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
        if (isset($result['collab'])) {
            # Записи нет и заводить её не надо; треки этого «артиста» синхронизируем
            # без ссылки. В список не дописываем — вдруг склейку разложат по артистам.
            $artist_ids[$name] = null;
            $stats['artists_collab']++;
            continue;
        }
        if (isset($result['error'])) {
            $stats['artists_failed']++;
            continue;
        }
        $artist_ids[$name] = $result;
        $known_artists[$name] = true;
        usync_append_list(USYNC_ARTISTS_FILE, $name);
        $stats['artists_new']++;
    }

    # Пункты 3 и 4: треки, которых ещё нет в списке logs/tracks.txt
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
        # array_key_exists, а не isset: у склейки соавторов значение null, и isset()
        # заставлял бы искать её заново на каждом треке.
        if ($name !== '') {
            if (!array_key_exists($name, $artist_ids)) {
                $result = usync_sync_artist($name, $logger);
                if (isset($result['collab'])) {
                    $artist_ids[$name] = null;
                    $stats['artists_collab']++;
                }
                elseif (isset($result['error'])) {
                    usync_log($logger, "Трек $isrc пропущен: не удалось получить ID артиста '$name'");
                    $stats['tracks_failed']++;
                    continue;
                }
                else {
                    $artist_ids[$name] = $result;
                }
            }
            $ids = $artist_ids[$name];
        }

        $result = usync_sync_track($isrc, $track, $ids, $logger);
        if (isset($result['error'])) {
            # Ни при ошибке, ни при отсутствии записи в ups ISRC в список не дописываем —
            # тогда следующий запуск повторит трек.
            $stats[isset($result['missing']) ? 'tracks_missing' : 'tracks_failed']++;
            continue;
        }
        $known_tracks[$isrc] = true;
        usync_append_list(USYNC_TRACKS_FILE, $isrc);
        $stats['tracks_synced']++;
    }

    usync_log($logger, 'Синхронизация: артистов ' . (usync_dry_run() ? 'будет создано ' : 'создано ')
        . $stats['artists_new']
        . ', ошибок ' . $stats['artists_failed']
        . ', склеек пропущено ' . $stats['artists_collab']
        . '; треков ' . (usync_dry_run() ? 'будет дополнено ' : 'дополнено ') . $stats['tracks_synced']
        . ', ошибок ' . $stats['tracks_failed']
        . ', нет в ups ' . $stats['tracks_missing']
        . ', уже было ' . $stats['tracks_known']);

    return $stats;
}
