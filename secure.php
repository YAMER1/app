<?php
// --- БЛОК ЗАЩИТЫ: ДОСТУП ТОЛЬКО ЧЕРЕЗ CDN GCORE ИЛИ ЛОКАЛЬНЫЙ CRON ---

if (PHP_SAPI === 'cli') {
    return; 
}

// 1. Считываем текущий хост
$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

// 2. Проверяем, что запрос прошел через прокси CDN (Gcore или Cloudflare)
$isBehindCDN = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);

$devAccount = 0;
if ($currentHost == "dev.megasborka.ru") {
    $devAccount = 1;
}

// ИСПРАВЛЕНО: Теперь разрешенными хостами считаются И app.megasborka.ru, И маскировочный megasborka.ru
$isAllowedHost = ($currentHost === 'app.megasborka.ru' || $currentHost === 'megasborka.ru');

if (!$devAccount && (!$isAllowedHost || !$isBehindCDN)) {
    // Если зашли по IP или без верифицированного CDN — отдаем 403 ошибку
    header('HTTP/1.0 403 Forbidden');
    echo "Access Denied.";
    exit;
}

// --- НАШ ХАК: Подменяем окружение для Битрикса строго в одинарных кавычках ---
// Если зашли под app. или если Gcore уже прислал маскировку — форсируем параметры для s1
if ($currentHost == "app.megasborka.ru" || $currentHost == "megasborka.ru") {
    $_SERVER['HTTP_HOST'] = 'megasborka.ru';
    $_SERVER['SERVER_NAME'] = 'megasborka.ru';

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
    if (isset($_SERVER['HTTPS'])) {
        unset($_SERVER['HTTPS']);
    }
}
// ----------------------------------------------------------------------------

// --- ПУЛЕНЕПРОБИВАЕМЫЙ CORS: УБИРАЕМ КОНФЛИКТЫ МНОЖЕСТВЕННЫХ ЗАГОЛОВКОВ ---

// Считываем Origin, который прислал браузер (например, https://megasborka.ru)
$httpOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Разрешаем отвечать ТОЛЬКО если запрос прилетел с нашего поддомена или основного домена
if ($httpOrigin === 'https://app.megasborka.ru' || $httpOrigin === 'https://megasborka.ru') {
    
    // Принудительно очищаем старые конфликтующие заголовки, если они были выставлены Битриксом ранее
    if (function_exists('header_remove')) {
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
    }
    
    // Выставляем один чистый, точечный заголовок CORS
    header("Access-Control-Allow-Origin: $httpOrigin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}

// Если это предварительный запрос OPTIONS от Vue 3, мгновенно гасим его корректным ответом 200
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit;
}

// --- УДАЛЕНИЕ ЗАПРЕТА ФРЕЙМОВ БИТРИКСА ---
if (function_exists('header_remove')) {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
}
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors 'self' https://telegram.org https://*.telegram.org telegram:;");
// -------------------------------------------------------------------------------