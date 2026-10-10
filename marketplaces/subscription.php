<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
require_once("../user/checkLogin.php");
require_once("../premium/functions.php");

$market = $data;

// 1. Проверяем входящие данные и авторизацию
if (!$market["id"]) {
    die(json_encode(["status" => "error", "message" => "ID маркетплейса не передан!"]));
}

if (empty($user["ID"])) {
    die(json_encode(["status" => "error", "message" => "Пользователь не авторизован"]));
}

// 3. Гарантируем наличие структуры массива (чтобы не было ошибок на пустых данных)
if (!isset($options["mainMarket"]) || !is_array($options["mainMarket"])) {
    $options["mainMarket"] = [];
}

// 4. Записываем чистый статус (1 или 0) для конкретного id маркетплейса
$options["mainMarket"][$market["id"]] = $market["isChecked"];

// 5. Динамически определяем действие на основе вычисленного
$actionText = ($market["isChecked"] == 1) ? "Подписка оформлена" : "Подписка отменена";
$actionType = ($market["isChecked"] == 1) ? "subscribed" : "unsubscribed";

// 6. Сохраняем данные через функцию (передаем корректный ID и сохраняем ответ)
$saveResult = saveUserPremOptions((int)$user["ID"], $options);

if ($saveResult === true) {
    echo json_encode([
        "status" => "success", 
        "message" => $actionText,
        "action" => $actionType, 
        "marketId" => $market["id"], 
        "data" => $options["mainMarket"] 
    ]);
} else {
    echo json_encode([
        "status" => "error", 
        "message" => $saveResult // Здесь вернется строка с ошибкой из Битрикса
    ]);
}
die();