<?php
// === АБСОЛЮТНЫЙ CORS ПЕРЕХВАТЧИК С ЛОГИРОВАНИЕМ ДЛЯ BITRIXVM ===

// 1. Считываем Origin и метод
$httpOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';

// 2. Белый список доменов (строго без опечаток и лишних слэшей)
$allowedOrigins = [
    'https://app.megasborka.ru',
    'https://megasborka.ru',
    'https://dev.megasborka.ru',
    'https://yamer1.github.io'
];

$corsStatus = 'NOT_TRIGGERED';

// 3. Если источник в белом списке — выдаем заголовки при любом типе запроса
if (in_array($httpOrigin, $allowedOrigins)) {
    $corsStatus = 'CORS_SUCCESS';
    
    // Принудительно вычищаем любые заголовки, которые BitrixVM или Apache пытались навязать
    if (function_exists('header_remove')) {
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        header_remove('Access-Control-Allow-Methods');
        header_remove('Access-Control-Allow-Headers');
    }
    
    // Выставляем жесткие, точечные CORS-заголовки
    header("Access-Control-Allow-Origin: $httpOrigin");
    header("Access-Control-Allow-Credentials: false");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: content-type, content-type, Content-Type, Authorization, X-Requested-With, X-Telegram-Web-App-Init-Data, x-telegram-init-data");

    
    // Записываем лог ПЕРЕД завершением OPTIONS-запроса
    //writeCorsLog($corsStatus, "Браузер прислал разрешенный Origin. Метод: $requestMethod");

    // ЕСЛИ ЭТО ПРЕДЗАПРОС OPTIONS — МГНОВЕННО УБИВАЕМ ЕГО ТУТ ЖЕ
    if ($requestMethod === 'OPTIONS') {
        header("HTTP/1.1 200 OK");
        exit;
    }
} else {
    // Если Origin прилетел, но его нет в массиве доверенных доменов
    if (!empty($httpOrigin)) {
        $corsStatus = 'CORS_FAILED';
        //writeCorsLog($corsStatus, "Внимание! Origin '$httpOrigin' отсутствует в белом списке!");
    }
}

// Резервный перехват OPTIONS (если браузер прислал OPTIONS вообще без заголовка Origin)
if ($requestMethod === 'OPTIONS') {
    $corsStatus = 'OPTIONS_FALLBACK';
    //writeCorsLog($corsStatus, "Внимание! Запрос OPTIONS прилетел без заголовка Origin.");
    header("HTTP/1.1 200 OK");
    exit;
}

// Записываем лог для успешных обычных запросов (GET/POST)
if ($corsStatus === 'CORS_SUCCESS') {
    //writeCorsLog($corsStatus, "Успешная обработка основного запроса. Метод: $requestMethod");
}

/*
function writeCorsLog($status, $messageText = '') {
    $logFile = __DIR__ . '/cors_debug_log.txt';
    
    $logData = [
        'date' => date('Y-m-d H:i:s'),
        'cors_status' => $status,
        'message' => $messageText,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'UNKNOWN',
        'script_path' => isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__,
        'http_host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'NONE',
        'http_origin' => isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : 'NONE',
        'http_referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'NONE',
        'remote_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'NONE',
    ];

    if (function_exists('getallheaders')) {
        $logData['headers'] = getallheaders();
    }

    $logEntry = "==================================================\n";
    $logEntry .= json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $logEntry .= "==================================================\n\n";

    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}
*/

// =========================================================================
// ДАЛЬШЕ ИДЕТ ВАШ ИЗНАЧАЛЬНЫЙ КОД СКРИПТА / БЛОК ЗАЩИТЫ ПО IP / ЯДРО БИТРИКСА
// =========================================================================

if (PHP_SAPI === 'cli') {
    return; 
}

$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isBehindCDN = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);

$devAccount = ($currentHost == "dev.megasborka.ru") ? 1 : 0;
$isAllowedHost = ($currentHost === 'app.megasborka.ru' || $currentHost === 'megasborka.ru' || $currentHost === 'dev.megasborka.ru');

if (!$devAccount && (!$isAllowedHost || !$isBehindCDN)) {
    header('HTTP/1.0 403 Forbidden');
    echo "Access Denied.";
    exit;
}

if ($currentHost == "app.megasborka.ru" || $currentHost == "megasborka.ru") {
    $_SERVER['HTTP_HOST'] = 'megasborka.ru';
    $_SERVER['SERVER_NAME'] = 'megasborka.ru';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $_SERVER['HTTPS'] = 'on';
}

if (function_exists('header_remove')) {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
}
header("X-Frame-Options: ALLOWALL");

// ИСПРАВЛЕНО: Явно разрешаем fetch-подключения (connect-src) для всех наших тестовых и рабочих доменов
header("Content-Security-Policy: frame-ancestors 'self' https://telegram.org https://*.telegram.org telegram:; connect-src 'self' https://app.megasborka.ru https://dev.megasborka.ru https://megasborka.ru https://yamer1.github.io;");
