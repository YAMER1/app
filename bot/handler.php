<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
// https://www.youtube.com/watch?v=7cqXHwBqAJQ
// Убедитесь, что webhook настроен правильно

// https://api.telegram.org/bot6816172103:AAE3Y6-kCv6RoLYIYATyQXrnp1W2isXaido/setWebhook?url=https://app.megasborka.ru/app/bot/handler.php&drop_pending_updates=true&allowed_updates=%5B%22message%22%2C%22chat_member%22%5D

// Проверка хука {"ok":true,"result":{"url":"https://app.megasborka.ru/app/bot/handler.php","has_custom_certificate":false,"pending_update_count":0,"max_connections":40,"ip_address":"206.223.247.136","allowed_updates":["message","chat_member"]}}

$messageLog = ""; // Логи

// Получаем сырые данные от Telegram
$input = file_get_contents('php://input');

$decodedInput = json_decode($input, true);
if ($decodedInput) {
    $cleanJson = json_encode($decodedInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    $cleanJson = $input; 
}

$update = $decodedInput;

// Токен вашего бота (взят из вашего скрипта)
define('BOT_TOKEN', '6816172103:AAE3Y6-kCv6RoLYIYATyQXrnp1W2isXaido');

// =========================================================================
// НАСТРОЙКА ГРУПП ДЛЯ ПРОВЕРКИ
// Формат: "ID группы, куда вступают" => "ID группы, на который ОБЯЗАНЫ подписаться"
// Замените цифры ниже на реальные ID ваших групп (обязательно с -100)
// =========================================================================
define('REQUIRED_CHANNEL_ID', -1003716728224);  // супергруппа по маркетплейсам
$protectedChannels = [
    -1003614650013, // pcdatshop chat
    -1002257629034, // megasborku комплектующие ПК
    -1004319718917, // megasborka_all
    -1002280262747, // megasborka_smart
    -1003716728224  // супергруппа по всем каналам сразу
];
/**
 * Функция проверки подписки на определенную группу
 */
try {
    function checkSubscription($userId, $channelId) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatMember";
        $params = [
            'chat_id' => $channelId,
            'user_id' => $userId
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_INTERFACE, "206.223.247.136");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);        
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['ok']) && $data['ok'] === true) {
                $status = $data['result']['status'];
                // Если статус пользователя один из этих, то он подписан
                return in_array($status, ['member', 'administrator', 'creator']);
            }
        }
        return false;
    }

    require_once($_SERVER["DOCUMENT_ROOT"]."/local/php_interface/classes/transfer/send_to_telegram.php");

    function saveUserForCheckSubscription($chatId, $userId) {
        global $messageLog;

        require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
        
        // 1. Ищем пользователя в UserTable
        $dbUser = \Bitrix\Main\UserTable::getList([
            "filter" => [
                "ACTIVE" => "Y",
                "=LOGIN" => "tg".$userId
            ],
            "select" => ["ID", "UF_VERIFIED"]
        ])->fetch();

        $elementName = $chatId . "/" . $userId;

        // Если пользователь зарегистрирован и УЖЕ полностью верифицирован
        if (!empty($dbUser) && $dbUser["UF_VERIFIED"] == "Y") {
            $messageLog .= "[Битрикс] Пользователь " . $userId . " уже верифицирован на сайте. Проверка пропущена.\n";
            send_to_telegram($userId, "Вы авторизованы, повторная проверка на робота не нужна! 👍", null);
            return false; 
        }

        if (!CModule::IncludeModule("iblock")) {
            $messageLog .= "[Ошибка Битрикс] Модуль инфоблоков не установлен.\n";
            return false;
        }

        // Определяем дефолтные свойства. 
        // КРИТИЧЕСКИЙ МОМЕНТ: Если пользователь ЕСТЬ в базе сайта (dbUser найден), но не верифицирован,
        // мы ОДНОВРЕМЕННО со входом включаем ему FAST_CHECK => Y, так как подписка на бота уже выполнена!
        $isUserRegisteredOnSite = !empty($dbUser);
        
        $properties = [
            "CHAT_ID"    => $chatId,
            "USER_ID"    => $userId,
            "DATE_JOIN"  => date("d.m.Y H:i:s"),
            "FAST_CHECK" => $isUserRegisteredOnSite ? "Y" : "N" 
        ];

        $el = new CIBlockElement;
        $userId = $el->Add([
            "IBLOCK_ID" => 17,
            "NAME"      => $elementName,
            "ACTIVE"    => "Y",
            "PROPERTY_VALUES" => $properties
        ]);

        if ($userId) {
            $messageLog .= "[Битрикс] Создан элемент проверки (ID: " . $userId . "). FAST_CHECK => " . ($isUserRegisteredOnSite ? "Y" : "N") . "\n";
            return $userId;
        }

        $messageLog .= "[Ошибка Битрикс] Ошибка добавления: " . $el->LAST_ERROR . "\n";
        return false;
    }





    // =========================================================================
    // ЛОГИКА 1: ПРОВЕРКА ВСТУПЛЕНИЯ В КАНАЛЫ (Новый блок защиты)
    // =========================================================================
    if (isset($update['chat_member'])) {
        $chatMemberUpdate = $update['chat_member'];
        
        $chatId = $chatMemberUpdate['chat']['id']; // Канал, куда зашли
        $userId = $chatMemberUpdate['new_chat_member']['user']['id']; // Кто зашел
        $oldStatus = $chatMemberUpdate['old_chat_member']['status'];
        $newStatus = $chatMemberUpdate['new_chat_member']['status'];

        //$wasNotMember = in_array($oldStatus, ['left', 'kicked']); убрано для того, чтобы была возможность дебажить и пуст выполняет все условия заново!
        $isNowMember = ($newStatus === 'member');

        // Если это событие добавления нового человека и эта группа есть в списке отслеживаемых
        if (in_array($chatId, $protectedChannels) /*&& $wasNotMember*/ && $isNowMember) {
            $messageLog .= "В группе - ".$chatId." новый подписчик ".$userId."<br>";
            
            $messageLog .= "Ожидание подписки на группу<br>";

            
            require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
            if(saveUserForCheckSubscription($chatId,$userId)){ // Сохраняем пользователя в БД для проверки подписки
                file_put_contents(__DIR__ . '/pending_users.txt', "[" . $userId . "]\n", FILE_APPEND | LOCK_EX);
                $messageLog .= "Пользователь успешно сохранён<br>";
            } else {
                $messageLog .= "Ошибка при сохранении пользователя<br>";
            }

        } else {
            if($userId == 8514733653 || $userId == 661648698){ // 8514733653 katrina и 661648698 алекс
                // Развернутое логирование причин, почему условие не выполнено:
                $messageLog .= "⚠️ <b>Событие пользователя - ".$userId." пропущено. Причины:</b><br>";
                
                // 1. Проверяем группу
                if (!in_array($chatId, $protectedChannels)) {
                    $reasonFound = 1;
                    $messageLog .= "— Группа с ID <code>" . $chatId . "</code> отсутствует в списке отслеживаемых (\$protectedChannels).<br>";
                }
                
                /*// 2. Проверяем прошлый статус
                if (!$wasNotMember) {
                    $messageLog .= "— Пользователь " . $userId . " уже находился в группе ранее (прошлый статус: <i>" . $oldStatus . "</i>).<br>";
                } */
                
                // 3. Проверяем новый статус
                if (!$isNowMember) {
                    $reasonFound = 1;
                    $messageLog .= "— Это событие не является подпиской (новый статус пользователя: <i>" . $newStatus . "</i>).<br>";
                }

                if(empty($reasonFound)){
                    $messageLog .= "— Это событие не является подпиской (новый статус пользователя: <i>" . $newStatus . "</i>).<br>";
                }
            }
        }
    }


    // =========================================================================
    // ЛОГИКА 2: ОБРАБОТКА ОБЫЧНЫХ СООБЩЕНИЙ И MINI APP (Ваш старый блок)
    // =========================================================================
    if (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $userId = $update['message']['from']['id'];
        $messageId = $update['message']['message_id']; // Уникальный ID сообщения для удаления
        $text = isset($update['message']['text']) ? $update['message']['text'] : '';

        $messageLog .= "Новое сообщение, ID чата - <a href='https://t.me/c/" . substr($chatId, 4) . "'>" . $chatId . "</a><br>";
        // =========================================================================
        // ЛОГИКА АНТИСПАМА: Если пишут в ГРУППУ (ID отрицательный, начинается с -100)
        // =========================================================================

        if($chatId < 0){
            if(in_array($chatId, $protectedChannels)){
                // Быстрая проверка: если файла нет или в нем нет ID этого пользователя,
                // значит, это старый проверенный участник — ИГНОРИРУЕМ и не трогаем Битрикс!
                $pendingFile = __DIR__ . '/pending_users.txt';
                $pendingContent = file_exists($pendingFile) ? file_get_contents($pendingFile) : '';
                
                // Ищем ID пользователя в списке новичков (например, окружив границами строки)
                if (strpos($pendingContent, "[" . $userId . "]") !== false) {
                    
                    // ТОЛЬКО ДЛЯ НОВИЧКОВ подключаем Битрикс и проверяем базу!
                    require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
                    
                    $pendingUser = \Bitrix\Iblock\Elements\ElementVerifySubscriptionTable::getList([
                        "filter" => [
                            "IBLOCK_ID" => 17,
                            "USER_ID.VALUE" => $userId,
                            "CHAT_ID.VALUE" => $chatId
                        ],
                        "select" => ["ID"]
                    ])->fetch();

                    if (!empty($pendingUser)) {
                        $messageLog .= "Удаляем сообщение<br>";
                        
                        $urlDelete = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteMessage";
                        
                        // ИСПРАВЛЕНО: Передаем параметры, чтобы Telegram знал, что удалять
                        $chDel = curl_init($urlDelete);
                        curl_setopt($chDel, CURLOPT_INTERFACE, "206.223.247.136");
                        curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($chDel, CURLOPT_POST, true);
                        curl_setopt($chDel, CURLOPT_POSTFIELDS, [
                            'chat_id' => $chatId,
                            'message_id' => $messageId
                        ]);
                        curl_setopt($chDel, CURLOPT_SSL_VERIFYPEER, false);
                        curl_exec($chDel);
                        curl_close($chDel);
                    }
                } else {
                    $messageLog .= "Пользователь не найден в списке новичков<br>";
                }
            } else {
                $messageLog .= "Чат или канал не защищён от спама<br>";
            }
        } else {
            $menuKeyboard = [
                "inline_keyboard" => [
                    [
                        [
                            "text" => "Канал по комплектующим ПК",
                            "url" => "https://t.me/pcdatshop" 
                        ]
                    ]
                ]
            ];

            $message = "";

            if ($text == "/start") {
                $message = "Привет!\nЭто приложение для создания подборки товаров с маркетплейсов!\n\nНажми Создать подборку";
            } else {
                // Если это личка бота (chatId > 0), то на любой текст пишем "Меню"
                if ($chatId > 0) {
                    $message = "Меню";
                }
            }
            
            if (!empty($message)) {
                send_to_telegram($chatId, $message, $menuKeyboard); 
            }

        }
        
    }

    if(!empty($messageLog)){
        send_to_telegram("-1004428467234/17778", $messageLog, null);
    }
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