<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
if(!isset($_GET["password"]) || ($_GET["password"]!="543231Kon")){
    die();
}

// Официальный URL свежего Telegram SDK
$telegramUrl = 'https://telegram.org/js/telegram-web-app.js';

// Пути для сохранения
$savePath = __DIR__ . '/telegram-web-app.js';
$tempPath = $savePath . '.tmp';

// Инициализируем cURL для скачивания
$ch = curl_init($telegramUrl);
$fp = fopen($tempPath, 'w+');

// ИСПРАВЛЕНО: Заменили ошибочный $chDel на правильный рабочий дескриптор $ch
curl_setopt($ch, CURLOPT_INTERFACE, "206.223.247.136");
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

// Проверяем результат
if ($success && $httpCode === 200 && file_exists($tempPath) && filesize($tempPath) > 100) {
    // Атомарно заменяем старый файл новым
    rename($tempPath, $savePath);
    
    // ИСПРАВЛЕНО: Явно выставляем безопасные и правильные права 644 на скачанный файл JS
    chmod($savePath, 0644);
    
    echo "Success: telegram-web-app.js updated at " . date('Y-m-d H:i:s');
} else {
    // Удаляем временный файл в случае ошибки
    if (file_exists($tempPath)) {
        unlink($tempPath);
    }
    http_response_code(500);
    echo "Error: Failed to update Telegram JS. HTTP Code: {$httpCode}";
}
