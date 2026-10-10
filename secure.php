<?php
// --- БЛОК ЗАЩИТЫ: ДОСТУП ТОЛЬКО ЧЕРЕЗ CDN GCORE ИЛИ ЛОКАЛЬНЫЙ CRON ---

if (PHP_SAPI === 'cli') {
    return; 
}

// 1. Считываем текущий хост
$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

// 2. Проверяем, что запрос прошел через прокси CDN (Gcore или Cloudflare)
$isBehindCDN = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);

// 3. Проверяем секретный GET-параметр для основного домена
$hasValidPassword = (isset($_GET['password']) && $_GET['password'] === '54321Kon');

$devAccount = 0;
// Разрешаем локальный dev-аккаунт для отладки
if ($currentHost == "dev.megasborka.ru") {
    $devAccount = 1;
}

// ИСПРАВЛЕНО: Для megasborka.ru доступ открывается только при совпадении пароля
$isAllowedHost = (
    $currentHost === 'app.megasborka.ru' || 
    $currentHost === 'dev.megasborka.ru' && $hasValidPassword)
);

if (!$devAccount && (!$isAllowedHost || !$isBehindCDN)) {
    // Если зашли по прямому IP, без верифицированного CDN или не указали пароль для megasborka.ru — 403
    header('HTTP/1.0 403 Forbidden');
    echo "Access Denied.";
    exit;
}

// --- НАШ ХАК: Подменяем окружение для Битрикса строго в одинарных кавычках ---
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

// Считываем Origin, который прислал браузер (например, https://github.io)
$httpOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Формируем белый список доверенных источников Origin для CORS
$allowedOrigins = [
    'https://megasborka.ru',
    'https://megasborka.ru',
    'https://github.io'
];

// ИСПРАВЛЕНО: Динамически добавляем megasborka.ru в CORS только при наличии верного GET-пароля
if ($hasValidPassword) {
    $allowedOrigins[] = 'https://megasborka.ru';
}

// Если Origin находится в белом списке — отдаем индивидуальные CORS-заголовки
if (in_array($httpOrigin, $allowedOrigins)) {
    
    // Принудительно очищаем старые конфликтующие заголовки, если они были выставлены Битриксом ранее
    if (function_exists('header_remove')) {
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        header_remove('Access-Control-Allow-Methods');
        header_remove('Access-Control-Allow-Headers');
    }
    
    // Выставляем точечные, одобренные спецификацией браузеров заголовки CORS
    header("Access-Control-Allow-Origin: $httpOrigin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Telegram-Web-App-Init-Data");
}

// Если это предварительный запрос OPTIONS от Vue 3, мгновенно гасим его корректным ответом 200 (уже с CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit;
}

// --- УДАЛЕНИЕ ЗАПРЕТА ФРЕЙМОВ БИТРИКСА ДЛЯ РАБОТЫ В TG MINI APP ---
if (function_exists('header_remove')) {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
}
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors 'self' https://telegram.org https://*.telegram.org telegram:;");
// -------------------------------------------------------------------------------
