<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
// registration.php
$messageLog = "";
$data = json_decode(file_get_contents("php://input"), true) ?: [];

try {
    if (isset($data['userId'])) {
        //$userId = $_POST['userId'];
        // Обработка, сохранение, ответ

        
        Loader::includeModule('main');

        // Получаем ID из POST
        $tgId = trim($data['userId']);

        if (!$tgId) {
            die('ID пользователя не передан');
        }

        $login = "tg" . $tgId; // Формируем логин: tg123456
        $password = randString(12); // Генерируем пароль

        // 1. Проверяем, нет ли уже пользователя с таким логином
        $existingUser = UserTable::getList([
            'filter' => ['=LOGIN' => $login],
            'select' => ['ID','ACTIVE']
        ])->fetch();

        if ($existingUser) {
            if($existingUser["ACTIVE"]=="N"){
                $cUser  = new CUser;
                $fields = ["ACTIVE" => "Y"]; // Меняем флаг активности на "Да"
                $cUser->Update($existingUser["ID"], $fields);
            }
        } else {
            // 2. Регистрируем нового пользователя
            $user = new \CUser;
            $fields = [
                "ACTIVE"            => "Y",
                "LOGIN"             => $login,
                "EMAIL"             => $login."@ya.ru",
                "LID"               => "s1",
                "ACTIVE"            => "Y",
                "GROUP_ID"          => [6], // Группы (например, Зарегистрированные)
                "PASSWORD"          => $password,
                "CONFIRM_PASSWORD"  => $password,
            ];

            $newUserId = $user->Add($fields);
            if (intval($newUserId) > 0) {
                $messageLog.="Пользователь успешно создан! ID: " . $newUserId."\n";
                $messageLog.="Данные для авторизации на сайте:\n";
                $messageLog.="Логин ".$login."\n";
                $messageLog.="Пароль ".$password."\n";
            } else {
                $messageLog.="Ошибка: " . $user->LAST_ERROR."\n";
            }
        }
        if (CModule::IncludeModule("iblock")) {
            $verifyUser = \Bitrix\Iblock\Elements\ElementVerifySubscriptionTable::getList([
                "filter" => [
                    "IBLOCK_ID" => 17,
                    "NAME" => "%/".$tgId
                ],
                "select" => [
                    "ID"
                ]
            ])->fetch();
            if(!empty($verifyUser)){
                \CIBlockElement::SetPropertyValuesEx($verifyUser["ID"], 17, ["FAST_CHECK" => "Y"]);
            }
        }

        if(!empty($messageLog)){
            send_to_telegram("-1004428467234/21108", $messageLog, null);
        }

    } else {
        //$messageLog.="Данные не получены.\n";
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        "message" => trim($messageLog)
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $ex) {
    // Формируем детальный текст ошибки
    $errorDetails = "❌ <b>Критическая ошибка в боте!</b>\n\n" .
                    "<b>Сообщение:</b> " . $ex->getMessage() . "\n" .
                    "<b>Файл:</b> " . $ex->getFile() . "\n" .
                    "<b>Строка:</b> " . $ex->getLine() . "\n\n" .
                    "<b>Предыдущий лог выполнения:</b>\n" . $messageLog;

    // Отправляем структурированное сообщение об ошибке разработчику/в чат логов
    // Внимание: chat_id должен быть чистым ID (например, -1004428467234), без слешей и user_id
    send_to_telegram("-1004428467234/16", $errorDetails, null);
}