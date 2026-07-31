<?php
/**
 * Образец конфигурации: токены доступа к API и пароли FTP/SFTP.
 *
 * Скопировать рядом под именем upsound_sync.config.php и заполнить — тот файл
 * перечислен в .gitignore и в репозиторий не попадает. Этот образец секретов не содержит.
 *
 * Любое значение можно вместо файла задать переменной окружения — её имя указано
 * в комментарии; переменная окружения имеет приоритет над файлом.
 *
 * _xsrf здесь не хранится: база отдаёт его по токену доступа, и upsound_sync.php
 * запрашивает его на лету (GET /{db}/xsrf?JSON) один раз за запуск.
 */

return array(

    // Токены доступа к API обеих баз. Сейчас это сервисный пользователь `ftp` (роль `ftp`).
    // Чей токен и что он может — GET /{db}/xsrf?JSON вернёт user и role.
    'tokens' => array(
        'upsound' => '',   // USYNC_TOKEN_UPSOUND
        'ups'     => '',   // USYNC_TOKEN_UPS
    ),

    // FTP, откуда ftplist2025.php забирает report_5273_*.gz
    'ftp' => array(
        'host' => 'ftp.maggregator.com',   // USYNC_FTP_HOST
        'user' => 'upsound',               // USYNC_FTP_USER
        'pass' => '',                      // USYNC_FTP_PASS
    ),

    // SFTP, откуда ftplist25.php забирает upsound_daily/spd_upsound_*
    'sftp' => array(
        'host' => '195.46.167.154',        // USYNC_SFTP_HOST
        'user' => 'UpSound25',             // USYNC_SFTP_USER
        'pass' => '',                      // USYNC_SFTP_PASS
    ),
);
