<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
/* Подписка пользователя на разделы */
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

// Устанавливаем заголовки для корректного ответа в JSON
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true) ?: [];

if (empty($data)) {
    die(json_encode([
        "status" => "error", 
        "message" => "Ошибка передачи данных"
    ]));
}

// Защита: проверяем, что все входные параметры переданы
if (empty($data["userId"])) {
    // Превращаем массив в строку
    $dataString = json_encode($data, JSON_UNESCAPED_UNICODE); 
    die(json_encode([
        "status" => "error", 
        "message" => "Ошибка: id пользователя пуст - " . $dataString
    ], JSON_UNESCAPED_UNICODE));
}

$userLogin = "tg" . (int)$data["userId"];
$user = Bitrix\Main\UserTable::getList([
    "filter" => ["=LOGIN" => $userLogin],
    "select" => ["ID"]
])->fetch();

if(empty($user["ID"])){
    die(json_encode(["status" => "error", "message" => "Пользователь с логином {$userLogin} не найден"]));
}