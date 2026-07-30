<?php
    logIt(" Check files");

    // Папка для файлов, полученных по FTP
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
        while (($line = fgetcsv($h_unique, 0, ';')) !== false) {
            if (!empty($line[0]) && $line[0] !== 'ISRC') {
                $existing_tracks[$line[0]] = true; // ISRC как ключ
            }
        }
        fclose($h_unique);
    }
    
    $hu = fopen(__DIR__.'/ftplist.txt', 'a+');
    $fstats = fstat($hu);
    fseek($hu, 0);
    if($fstats['size'] > 0)
        $uploaded = fread($hu, $fstats['size']);
    else
        $uploaded = "";
        
    $ftp_server = "ftp.maggregator.com";
    $conn_id = ftp_connect($ftp_server);
    $ftp_user_name = "upsound";
    $ftp_user_pass = "xxx";
    $login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);
    $contents = ftp_nlist($conn_id, '/');
    $i = $j = 0;
    
    // === НОВЫЙ БЛОК: Буфер для новых уникальных треков ===
    $new_unique_tracks = [];
    $unique_headers_saved = false;

    // === Все треки обработанных файлов для синхронизации с БД (issue #35) ===
    $all_tracks = [];

    foreach ($contents as $file){
        if(strpos($file, "report_5273") !== false){
            $i++;
            if(strpos($uploaded, $file) === false){
                logIt("  $file is new");
                $date = substr($file, 13, 4).substr($file, 18, 2).substr($file, 21, 2);
                $h = fopen('php://temp', 'r+');
                ftp_fget($conn_id, $h, $file, FTP_BINARY, 0);
                $fstats = fstat($h);
                fseek($h, 0);
                $contents_gz = gzdecode(fread($h, $fstats['size'])); 
                fclose($h);
                
                // СОХРАНЕНИЕ РАСПАКОВАННОГО ФАЙЛА (опционально)
                $local_file_txt = $ftp_dir . '/' . str_replace('.gz', '', $file) . '.txt';
                if (file_put_contents($local_file_txt, $contents_gz) !== false) {
                    logIt("  Распакованный .txt файл сохранен: ftp/" . basename($local_file_txt));
                }

                $lines = preg_split('/\n|\r\n?/', $contents_gz);
                $data = "";
                
                foreach($lines as $k => $v) {
                    if($k > 0 && strlen($v)){
                        $v = substr($v, 0, strposX($v, "\t", 2)) . substr($v, strposX($v, "\t", 3));
                        $tmp = explode("\t", $v);
                        
                        // === НОВЫЙ БЛОК: Извлечение данных для уникального файла ===
                        // Формат: ISRC;Title;;Artist;Album Title;upc;
                        $isrc    = isset($tmp[5]) ? trim($tmp[5]) : '';
                        $title   = isset($tmp[7]) ? trim($tmp[7]) : '';
                        $artist  = isset($tmp[4]) ? trim($tmp[4]) : '';
                        $album   = isset($tmp[3]) ? trim($tmp[3]) : '';
                        $upc     = isset($tmp[2]) ? trim($tmp[2]) : '';
                        
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

                        // Оригинальная логика для API
                        $data .= $tmp[5].";$date;".$tmp[7].";".$tmp[4].";".$tmp[3].";\r\n";
                    }
                }
                
                $data = "DATA\r\n$data";
                
                // Отправка на API (оригинальная логика)
                $ch = curl_init();
                $h = fopen(__DIR__.'/ftplist.tmp', 'w');
                fwrite($h, $data);
                fclose($h);
                $cFile = curl_file_create(__DIR__.'/ftplist.tmp','text/plain','bki_file');
                $post = array('import'=>'1'
                            ,'autoParent'=>'165906636'
                            ,'createParent' => '1'
                            ,'bki_file'=>$cFile
                            ,'_xsrf'=>'TCAFK2135340y'
                            ,'token'=>'TCAFK2135340y');
        
                curl_setopt($ch, CURLOPT_POST,true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_URL,"https://upsound.ideav.online/ups/object/10667430?JSON&noorder");
                $result=curl_exec($ch);
                echo print_r($result);
                echo curl_error($ch);
                curl_close($ch);
                
                fwrite($hu, "$file\r\n");
                logIt("  $file imported");
                $j++;
            }
        }
    }
    
    // === НОВЫЙ БЛОК: Сохраняем уникальные треки в отдельный файл ===
    if (!empty($new_unique_tracks)) {
        $file_exists = file_exists($unique_file);
        $h_unique = fopen($unique_file, 'a'); // Открываем на дозапись
        
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
        logIt("  Новых уникальных треков не найдено");
    }
    
    // === Синхронизация артистов и треков с БД upsound и ups (issue #35) ===
    require_once __DIR__ . '/upsound_sync.php';
    usync_sync($all_tracks, 'logIt');

    logIt(" $i files found, $j imported");
    fclose($hu);
    ftp_close($conn_id);
    
    function logIt($text){
        $h = fopen(__DIR__.'/ftplist.log', 'a+');
        fwrite($h, date("d/m/Y H:i:s")."$text\r\n");
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