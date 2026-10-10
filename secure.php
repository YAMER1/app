<?php
// === АБСОЛЮТНЫЙ CORS ПЕРЕХВАТЧИК ДЛЯ BITRIXVM ===

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

// 3. Если источник в белом списке — выдаем заголовки при любом типе запроса
if (in_array($httpOrigin, $allowedOrigins)) {
    
    // Принудительно вычищаем любые заголовки, которые BitrixVM или Apache пытались навязать
    if (function_exists('header_remove')) {
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        header_remove('Access-Control-Allow-Methods');
        header_remove('Access-Control-Allow-Headers');
    }
    
    // Выставляем жесткие, точечные CORS-заголовки
    header("Access-Control-Allow-Origin: $httpOrigin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Telegram-Web-App-Init-Data");
    
    // ЕСЛИ ЭТО ПРЕДЗАПРОС OPTIONS — МГНОВЕННО УБИВАЕМ ЕГО ТУТ ЖЕ
    if ($requestMethod === 'OPTIONS') {
        header("HTTP/1.1 200 OK");
        exit;
    }
}

// Резервный перехват OPTIONS (если браузер прислал OPTIONS вообще без заголовка Origin)
if ($requestMethod === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit;
}

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
header("Content-Security-Policy: frame-ancestors 'self' https://telegram.org https://*.telegram.org telegram:;");
