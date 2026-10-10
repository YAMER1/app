<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
/* Сохранение интервала времени отправки постов */
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once("../user/checkLogin.php");
require_once("../premium/functions.php");

// Устанавливаем заголовки для корректного ответа в JSON
header('Content-Type: application/json');

// 1. Получаем и декодируем JSON-поток от Vue
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Защита от двойного кодирования JSON-строки с фронтенда
if (is_string($data)) {
    $data = json_decode($data, true);
}

// Если данные совсем пустые или неверный формат
if (!is_array($data) || empty($data)) {
    die(json_encode([
        "status" => "error", 
        "message" => "Ошибка передачи данных: неверный формат JSON"
    ], JSON_UNESCAPED_UNICODE));
}

$action = isset($data["action"]) ? trim($data["action"]) : 'get';

// ==========================================
// СЦЕНАРИЙ 1: ПОЛУЧЕНИЕ АКТУАЛЬНОГО ВРЕМЕНИ
// ==========================================
if ($action === 'get') {
    $options = getUserPremOptions((int)$user["ID"]);
    
    // Вытаскиваем timeRange, если его нет — отдаем дефолт
    $timeRange = isset($options["timeRange"]) ? $options["timeRange"] : [
        "start" => "00:00",
        "end" => "24:00"
    ];

    echo json_encode([
        "status" => "success",
        "data" => $timeRange
    ], JSON_UNESCAPED_UNICODE);
}
if ($action === 'update') {
    $startTime = isset($data["startTime"]) ? trim($data["startTime"]) : '00:00';
    $endTime = isset($data["endTime"]) ? trim($data["endTime"]) : '24:00';

    // Строгое регулярное выражение: от 00:00 до 23:59 ИЛИ ровно 24:00
    $timePattern = '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/';
    if (!preg_match($timePattern, $startTime) || !preg_match($timePattern, $endTime)) {
        die(json_encode([
            "status" => "error", 
            "message" => "Некорректный формат времени. Ожидается ЧЧ:ММ (от 00:00 до 24:00)"
        ], JSON_UNESCAPED_UNICODE));
    }

    // Специфичное правило: если часы равны 24, то минуты могут быть только 00
    if (strpos($startTime, '24:') === 0 && $startTime !== '24:00') $startTime = '24:00';
    if (strpos($endTime, '24:') === 0 && $endTime !== '24:00') $endTime = '24:00';

    // Ограничение интервала: время "С" не может быть больше или равно времени "До"
    if ($startTime >= $endTime) {
        die(json_encode(["status" => "error", "message" => "Время начала не может быть больше или равно времени окончания"], JSON_UNESCAPED_UNICODE));
    }


    // 2. Записываем настройки интервала времени в отдельный изолированный ключ
    $options["timeRange"] = [
        "start" => $startTime,
        "end" => $endTime
    ];

    // 3. Сохраняем обновленный массив опций обратно в базу Битрикса
    $saveResult = saveUserPremOptions((int)$user["ID"], $options);

    if ($saveResult === true) {
        echo json_encode([
            "status" => "success", 
            "message" => "Интервал сохранен - ".$options["timeRange"]["start"]." - ".$options["timeRange"]["end"],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Ошибка сохранения в БД: " . $saveResult
        ], JSON_UNESCAPED_UNICODE);
    }
}
