<?php
logIt(" Check files");

// Папка для файлов, полученных по SFTP
$ftp_dir = __DIR__ . '/ftp';
if (!is_dir($ftp_dir)) {
    mkdir($ftp_dir, 0775, true);
}

// === НОВЫЙ БЛОК: Файл для уникальных треков ===
$unique_file = __DIR__ . '/unique_tracks_' . date('Y-m-d') . '.csv';
$existing_tracks = [];

// Загружаем уже сохранённые ISRC для проверки уникальности
if (file_exists($unique_file)) {
    $h_unique = fopen($unique_file, 'r');
    if ($h_unique) {
        while (($line = fgetcsv($h_unique, 0, ';')) !== false) {
            if (!empty($line[0]) && $line[0] !== 'ISRC') {
                $existing_tracks[$line[0]] = true; // ISRC как ключ
            }
        }
        fclose($h_unique);
    }
}

$hu = fopen(__DIR__.'/ftplist25.txt', 'a+');
$fstats = fstat($hu);
fseek($hu, 0);
if($fstats['size'] > 0)
    $uploaded = fread($hu, $fstats['size']);
else
    $uploaded = "";
logIt("   uploaded ".$fstats['size']);

$ftp_server = "195.46.167.154";
$conn_id = ssh2_connect($ftp_server);
logIt("  ssh2_connect $conn_id");
$username = "UpSound25";
$password = "xxx";
if (!ssh2_auth_password($conn_id, $username, $password)) {
    logIt("  ssh2_auth failed");
    die('Не удалось аутентифицироваться');
}

$sftp = ssh2_sftp($conn_id);
$sftp_fd = intval($sftp);
$handle = opendir("ssh2.sftp://$sftp_fd/upsound_daily");

logIt("  dir open");
$i = $j = 0;

// === НОВЫЙ БЛОК: Буфер для новых уникальных треков ===
$new_unique_tracks = [];

// === Все треки обработанных файлов для синхронизации с БД (issue #35) ===
$all_tracks = [];

while (false != ($file = readdir($handle))){
    if(strpos($file, "spd_upsound_") !== false){
        $i++;
        if(strpos($uploaded, $file) === false){
            logIt("  $file is new");
            $date = substr($file, 12, 4).substr($file, 17, 2).substr($file, 20, 2);
            
            // ИСПРАВЛЕНИЕ: Правильный способ чтения файла через SFTP
            $remote_file = "ssh2.sftp://$sftp_fd/upsound_daily/$file";
            
            // Способ 1: Чтение через file_get_contents (проще всего)
            $contents = file_get_contents($remote_file);
            if ($contents === false) {
                logIt("  ERROR: Не удалось прочитать файл $file");
                continue;
            }
            
            logIt("  Файл $file прочитан, размер: ".strlen($contents)." байт");
            
            // СОХРАНЕНИЕ ОРИГИНАЛЬНОГО ФАЙЛА В ПАПКУ ftp
            $local_file_path = $ftp_dir . '/' . $file;
            if (file_put_contents($local_file_path, $contents) !== false) {
                logIt("  Оригинальный файл сохранен: ftp/$file");
            } else {
                logIt("  ERROR: Не удалось сохранить файл ftp/$file");
            }
            
            // Обработка содержимого
            $lines = preg_split('/\n|\r\n?/', $contents);
            $data = "";
            foreach($lines as $k => $v) {
                if($k > 0 && strlen(trim($v))) {
                    $parts = explode("\t", $v); 
                    if (count($parts) >= 9) { // Проверяем, что достаточно частей
                        $data .= trim($parts[6]).";$date;".trim($parts[8]).";".trim($parts[5]).";".trim($parts[4]).";\r\n";
                        
                        // === НОВЫЙ БЛОК: Извлечение данных для уникального файла ===
                        // Формат: ISRC;Title;;Artist;Album Title;upc;
                        $isrc   = isset($parts[6]) ? trim($parts[6]) : '';
                        $title  = isset($parts[8]) ? trim($parts[8]) : '';
                        $artist = isset($parts[5]) ? trim($parts[5]) : '';
                        $album  = isset($parts[4]) ? trim($parts[4]) : '';
                        $upc    = isset($parts[3]) ? trim($parts[3]) : ''; // UPC обычно в 4-й колонке
                        
                        // Проверяем уникальность по ISRC
                        if (!empty($isrc) && !isset($existing_tracks[$isrc]) && !isset($new_unique_tracks[$isrc])) {
                            $new_unique_tracks[$isrc] = [
                                'isrc' => $isrc,
                                'title' => $title,
                                'artist' => $artist,
                                'album' => $album,
                                'upc' => $upc
                            ];
                        }

                        // === Треки для синхронизации с БД (issue #35) ===
                        // Собираем независимо от дневного файла unique_tracks_*.csv:
                        // сверка идёт со списками logs/artists.txt и logs/tracks.txt
                        if (!empty($isrc) && !isset($all_tracks[$isrc])) {
                            $all_tracks[$isrc] = [
                                'isrc' => $isrc,
                                'title' => $title,
                                'artist' => $artist,
                                'album' => $album,
                                'upc' => $upc
                            ];
                        }
                    }
                }
            }
            
            if (empty($data)) {
                logIt("  WARNING: Нет данных для импорта в файле $file");
                fwrite($hu, "$file\r\n"); // Все равно отмечаем как обработанный
                continue;
            }
            
            $data = "DATA\r\n$data";
            
            // Отправка через CURL
            $ch = curl_init();
            
            $tmp_file = __DIR__.'/ftplist25.tmp';
            $h = fopen($tmp_file, 'w');
            fwrite($h, $data);
            fclose($h);
            
            $cFile = curl_file_create($tmp_file, 'text/plain', 'bki_file');
            $post = array(
                'import' => '1',
                'autoParent' => '165906636',
                'createParent' => '1',
                'bki_file' => $cFile,
                '_xsrf' => 'TCAFK2135340y',
                'token' => 'TCAFK2135340y'
            );
            
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, "https://upsound.ideav.online/ups/object/10667430?JSON&noorder");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Добавлено для HTTPS
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Добавлено для HTTPS
            
            $result = curl_exec($ch);
            $curl_error = curl_error($ch);
            
            if ($curl_error) {
                logIt("  CURL Error: $curl_error");
            }
            
            curl_close($ch);
            
            // Удаляем временный файл
            @unlink($tmp_file);
            
            fwrite($hu, "$file\r\n");
            logIt("  $file imported" . ($curl_error ? " with errors" : ""));
            $j++;
            
            echo ("$file imported<br>\n");
        }
    }
}

// === НОВЫЙ БЛОК: Сохраняем уникальные треки в отдельный файл ===
if (!empty($new_unique_tracks)) {
    $file_exists = file_exists($unique_file);
    $h_unique = fopen($unique_file, 'a'); // Открываем на дозапись
    
    if ($h_unique) {
        // Если файл новый — добавляем заголовок
        if (!$file_exists) {
            fputcsv($h_unique, ['ISRC', 'Title', '', 'Artist', 'Album Title', 'upc'], ';');
        }
        
        // Записываем новые треки
        foreach ($new_unique_tracks as $track) {
            fputcsv($h_unique, [
                $track['isrc'],
                $track['title'],
                '', // пустое поле по требованию формата
                $track['artist'],
                $track['album'],
                $track['upc']
            ], ';');
        }
        
        fclose($h_unique);
        logIt("  Добавлено " . count($new_unique_tracks) . " уникальных треков в " . basename($unique_file));
        
        // Выводим для отладки
        echo "\n--- Уникальные треки сохранены в: " . $unique_file . " ---\n";
        foreach ($new_unique_tracks as $track) {
            echo $track['isrc'] . " | " . $track['title'] . " | " . $track['artist'] . "\n";
        }
    } else {
        logIt("  ERROR: Не удалось открыть файл для записи уникальных треков");
    }
} else {
    logIt("  Новых уникальных треков не найдено");
}

// === Синхронизация артистов и треков с БД upsound и ups (issue #35) ===
require_once __DIR__ . '/upsound_sync.php';
usync_sync($all_tracks, 'logIt');

logIt(" $i files found, $j imported");
fclose($hu);

// Закрываем SFTP соединение
if ($conn_id) {
    ssh2_exec($conn_id, 'exit');
}

function logIt($text){
    $h = fopen(__DIR__.'/ftplist25.log', 'a+');
    fwrite($h, date("d/m/Y H:i:s")." $text\r\n");
    fclose($h);
}

function strposX($haystack, $needle, $number = 0)
{
    return strpos($haystack, $needle,
        $number > 1 ?
        strposX($haystack, $needle, $number - 1) + strlen($needle) : 0
    );
}
?>