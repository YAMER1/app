<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
/* Проверка, что пользователь подписался на канал и бота */

// Защита для запуска через консоль Крона
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__, 2); 
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
use Bitrix\Main\Type\DateTime;

try  {
    // НАСТРОЙКИ ТЕЛЕГРАМА
    define('BOT_TOKEN', '6816172103:AAE3Y6-kCv6RoLYIYATyQXrnp1W2isXaido');
    define('REQUIRED_CHANNEL_ID', -1003716728224); // Обязательный спонсорский канал

    $messageLog = "";

    // Точка отсчета времени (текущее время минус 3 минуты)
    $timeLimit = new DateTime();
    $timeLimit->add('-3 minutes');

    // Выбираем НЕВЕРИФИЦИРОВАННЫЕ элементы
    $userArr = \Bitrix\Iblock\Elements\ElementVerifySubscriptionTable::getList([
        "filter" => [
            "IBLOCK_ID" => 17,
            // ДОБАВЛЕНО: Логика ИЛИ (Условие по времени ИЛИ досрочный флаг)
            [
                "LOGIC" => "OR",
                // 1. Время вступления меньше или равно лимиту (прошло 3 минуты)
                ["<=DATE_JOIN.VALUE" => $timeLimit->format('d.m.Y H:i:s')],
                
                // 2. Или пользователь нажал кнопку на сайте (быстрая проверка)
                ["=FAST_CHECK.VALUE" => "Y"]
            ]
        ],
        "select" => [
            "ID", 
            "CHAT_ID_VALUE" => "CHAT_ID.VALUE", 
            "USER_ID_VALUE" => "USER_ID.VALUE", 
            "DATE_JOIN_VALUE" => "DATE_JOIN.VALUE",
            "FAST_CHECK_VALUE" => "FAST_CHECK.VALUE"
        ]
    ])->fetchAll();
    if(!empty($userArr)){
        /**
         * Функция запроса к Telegram API для проверки подписки
         */
        function checkTelegramSubscription($userId) {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatMember"; 
            $params = ['chat_id' => "-1003716728224", 'user_id' => $userId]; // t.me/megasborka_chat

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
                    return in_array($status, ['member', 'administrator', 'creator']);
                }
            }
            return false;
        }

        function kickUserFromGroup($chatId, $userId) {
            if (!empty($chatId) && !empty($userId)) {
                
                // 1. УДАЛЯЕМ ВСЕ СООБЩЕНИЯ И КОММЕНТАРИИ ЭТОГО ПОЛЬЗОВАТЕЛЯ ИЗ ЧАТА
                // Этот метод сотрет даже те сообщения, вебхуки от которых задержались!
                $urlDeleteAll = "https://api.telegram.org/bot" . BOT_TOKEN . "/deleteUserChatMessages";
                $deleteParams = [
                    'chat_id'    => $chatId,
                    'user_id'    => $userId,
                    'revoke'     => true // Принудительный отзыв для всех участников
                ];

                $chDel = curl_init($urlDeleteAll);
                curl_setopt($chDel, CURLOPT_INTERFACE, "206.223.247.136");
                curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chDel, CURLOPT_POST, true);
                curl_setopt($chDel, CURLOPT_POSTFIELDS, $deleteParams);
                curl_setopt($chDel, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($chDel);
                curl_close($chDel);

                // Маленькая пауза в 1 секунду, чтобы Telegram успел применить очистку
                sleep(1);

                // 2. Выполняем стандартный БАН
                $urlBan = "https://api.telegram.org/bot" . BOT_TOKEN . "/banChatMember";
                $ch = curl_init($urlBan);
                curl_setopt($ch, CURLOPT_INTERFACE, "206.223.247.136");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chatId, 'user_id' => $userId, 'revoke_messages' => true]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($ch);
                curl_close($ch);
                
                // Пауза перед разбаном
                sleep(2); 

                // 3. Выполняем РАЗБАН (возвращаем дефолтные права)
                $urlUnban = "https://api.telegram.org/bot" . BOT_TOKEN . "/unbanChatMember";
                $chUnban = curl_init($urlUnban);
                curl_setopt($chUnban, CURLOPT_INTERFACE, "206.223.247.136");
                curl_setopt($chUnban, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chUnban, CURLOPT_POST, true);
                curl_setopt($chUnban, CURLOPT_POSTFIELDS, ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
                curl_setopt($chUnban, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($chUnban);
                curl_close($chUnban);
            }
        }
        function banUserFromGroup($chatId, $userId) {
            if (!empty($chatId) && !empty($userId)) {
                global $messageLog;

                // Официальный метод Telegram Bot API
                $urlBan = "https://api.telegram.org/bot" . BOT_TOKEN . "/banChatMember";
                
                $banParams = [
                    'chat_id'         => $chatId, // ID супергруппы (обязательно с -100)
                    'user_id'         => $userId, // ID нарушителя
                    'revoke_messages' => true     // Флаг полного удаления всей истории сообщений в чате!
                ];

                $ch = curl_init($urlBan);
                curl_setopt($ch, CURLOPT_INTERFACE, "206.223.247.136");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $banParams);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                curl_close($ch);
                
                $messageLog .= "[Telegram API Пермабан]: Чат: {$chatId} | Юзер: {$userId} | Ответ: " . $response . "\n";
                
                return $response;
            }
        }


        foreach ($userArr as $user) {
            $chatId = $user['CHAT_ID_VALUE'];
            $userId = $user['USER_ID_VALUE'];
            $elementId = $user['ID'];

            // Превращаем вашу строку "02.09.2026 08:13:52" из DATE_JOIN_VALUE в объект времени Битрикса
            $userDateTime = new DateTime($user['DATE_JOIN_VALUE'], 'd.m.Y H:i:s');

            // ПРОВЕРЯЕМ: ПРОШЛО ЛИ 3 МИНУТЫ?
            // Если время вступления пользователя МЕНЬШЕ или РАВНО $timeLimit, значит 3 минуты точно прошло!
            if ((!empty($user["FAST_CHECK_VALUE"])) || ($userDateTime->getTimestamp() <= $timeLimit->getTimestamp())) {
                require_once($_SERVER["DOCUMENT_ROOT"]."/local/php_interface/classes/transfer/send_to_telegram.php");
                $messageLog .= "<a href='https://t.me/c/" . substr($chatId, 4) . "'>Идентификатор чата - ".$chatId."</a>";
                $messageLog .= "Проверка пользователя - ".$userId."<br>";
                
                // 1. Проверяем подписку в Telegram API
                $isSubscribed = checkTelegramSubscription($userId);

                // 2. Ищем запись аккаунта в Битриксе
                $dbUser = \Bitrix\Main\UserTable::getList([
                    "filter" => [
                        "ACTIVE" => "Y",
                        "=LOGIN" => "tg".$userId
                    ],
                    "select" => ["ID"] // Оставляем только ID, поле UF_VERIFIED здесь больше не нужно
                ])->fetch();

                // Переменная-флаг успешности прохождения абсолютно ВСЕХ условий
                $accessGranted = false;

                if (empty($dbUser)) {
                    // Ситуация: Нет регистрации на сайте / в боте
                    if ($isSubscribed) {
                        $messageLog .= "Ошибка: пользователь вступил в супергруппу, но не авторизовался в боте<br>";
                    }
                } else {
                    // Ситуация: Пользователь есть на сайте
                    if (!$isSubscribed) {
                        $messageLog .= "Ошибка: пользователь авторизовался в боте, но не вступил в супергруппу<br>";
                        
                        $errorText = "❌ <b>Проверка не пройдена!</b>\n\n" .
                            "Вы подписались на бота, но не вступили в супергруппу!\n\n" .
                            "❗ <a href='https://t.me/c/3716728224'>Вступить в супергруппу</a>\n\n" .
                            "👉 <a href='https://t.me/c/" . substr($chatId, 4) . "'>Вступить обратно в группу</a>";
                            
                        send_to_telegram($userId, $errorText, null);
                    } else {
                        // Оба условия выполнены на 100%
                        $accessGranted = true;
                    }
                }

                // 3. Вынесение вердикта
                if ($accessGranted) {
                    $messageLog .= "Пользователь успешно верифицирован.\n";

                    // Проставляем постоянную верификацию в профиль
                    $cUser = new \CUser;
                    $cUser->Update($dbUser["ID"], ["UF_VERIFIED" => "Y"]);
                    
                    // Удаляем временный элемент контроля из ИБ 17
                    \CIBlockElement::Delete($elementId);

                    $successText = "✅ <b>Проверка успешно пройдена!</b>\n\n" .
                        "Спасибо, что выполнили все условия. Теперь вам доступны все функции нашего сообщества без ограничений. 🙌\n\n" .
                        "👉 Полный список каналов доступен в описании бота -> <b>Сообщество</b>\n" .
                        "👉 <a href='https://t.me/c/" . substr($chatId, 4) . "'>Вернуться обратно в группу</a>";

                    send_to_telegram($userId, $successText, null);

                    // Вычёркиваем из быстрого файла новичков
                    $pendingFile = __DIR__ . '/pending_users.txt';
                    if (file_exists($pendingFile)) {
                        $content = file_get_contents($pendingFile);
                        $content = str_replace("[" . $userId . "]\n", "", $content);
                        file_put_contents($pendingFile, $content, LOCK_EX);
                    }
                } else {

                    // Наказание: проверяем количество попыток в ИБ 18
                    $kickedUser = \Bitrix\Iblock\Elements\ElementCheckSubscriptionAgainTable::getList([
                        "filter" => [
                            "IBLOCK_ID" => 18,
                            "=NAME" => "tg".$userId, // Ищем по уникальному имени аккаунта
                        ],
                        "select" => [
                            "ID",
                            "ATTEMPT_COUNT_VALUE" => "ATTEMPT_COUNT.VALUE"
                        ]
                    ])->fetch();

                    // Определяем текущее количество попыток нарушителя
                    $currentAttempts = !empty($kickedUser) ? intval($kickedUser["ATTEMPT_COUNT_VALUE"]) : 0;

                    if ($currentAttempts < 2) {
                        // Попыток меньше 2 — увеличиваем счетчик и делаем обычный КИК (бан + моментальный разбан)
                        $newAttemptsCount = $currentAttempts + 1;

                        if ($currentAttempts === 0) {
                            // Самый первый косяк — создаем новую запись в ИБ 18
                            $el18 = new \CIBlockElement;
                            $newKickedElId = $el18->Add([ // ИСПРАВЛЕНО: НЕ перезаписываем $userId
                                "IBLOCK_ID" => 18,
                                "NAME"      => "tg".$userId,
                                "ACTIVE"    => "Y",
                                "PROPERTY_VALUES" => [
                                    "ATTEMPT_COUNT" => $newAttemptsCount
                                ]
                            ]);
                        } else {
                            // Повторный косяк — просто обновляем счетчик в существующем элементе ИБ 18
                            \CIBlockElement::SetPropertyValuesEx($kickedUser["ID"], 18, ["ATTEMPT_COUNT" => $newAttemptsCount]);
                        }

                        // Выполняем обычный кик (с задержкой 5 секунд и разбаном, как писали ранее)
                        kickUserFromGroup($chatId, $userId);

                        // Удаляем запись из ИБ 17, чтобы Крон завершил текущую задачу проверки
                        \CIBlockElement::Delete($elementId); 
                        $messageLog .= "Пользователь {$userId} не выполнил условия (Попытка {$newAttemptsCount}/3) и был исключен.\n";

                        if (empty($dbUser)) {
                            $messageLog .= "Авторизация в боте НЕТУ<br>";
                        } else {
                            $messageLog .= "Авторизация в боте ЕСТЬ<br>";
                        }
                    
                        if (!$isSubscribed) {
                            $messageLog .= "Подписка на супергруппу НЕТУ<br>";
                        } else {
                            $messageLog .= "Подписка на супергруппу ЕСТЬ<br>";
                        }

                        // Очищаем из текстового файла новичков
                        $pendingFile = __DIR__ . '/pending_users.txt';
                        if (file_exists($pendingFile)) {
                            $content = file_get_contents($pendingFile);
                            $content = str_replace("[" . $userId . "]\n", "", $content);
                            file_put_contents($pendingFile, $content, LOCK_EX);
                        }
                    } else {
                        \CIBlockElement::SetPropertyValuesEx($kickedUser["ID"], 18, ["ATTEMPT_COUNT" => 3]);
                        $messageLog .= "Пользователь {$userId} исчерпал все 3 попытки верификации и забанен НАВСЕГДА.\n";
                        // Попыток 3 или больше — ЖЕСТКИЙ ПЕРМАНЕНТНЫЙ БАН (без последующего разбана)
                        banUserFromGroup($chatId, $userId);

                        // Удаляем его из ИБ 17 и файла pending, чтобы закрыть текущую задачу проверки
                        \CIBlockElement::Delete($elementId); 

                        $pendingFile = __DIR__ . '/pending_users.txt';
                        if (file_exists($pendingFile)) {
                            $content = file_get_contents($pendingFile);
                            $content = str_replace("[" . $userId . "]\n", "", $content);
                            file_put_contents($pendingFile, $content, LOCK_EX);
                        }

                        // =========================================================================
                        // АВТОМАТИЧЕСКАЯ ОЧИСТКА ИБ 18: Удаляем тех, кто в бане больше месяца
                        // =========================================================================
                        // 1. Вычисляем дату ровно 1 месяц назад от текущего момента
                        $oldBanLimit = new \Bitrix\Main\Type\DateTime();
                        $oldBanLimit->add('-1 month');

                        // 2. ИСПРАВЛЕНО: Превращаем объект даты в правильную строку формата Битрикса (d.m.Y H:i:s)
                        $formattedBanLimit = $oldBanLimit->format('d.m.Y H:i:s');

                        // 3. Ищем записи в ИБ 18, которые были созданы РАНЬШЕ этой даты
                        $oldBans = \Bitrix\Iblock\Elements\ElementCheckSubscriptionAgainTable::getList([
                            "filter" => [
                                "IBLOCK_ID" => 18,
                                "<=DATE_CREATE" => $formattedBanLimit // Передаем строго строку!
                            ],
                            "select" => ["ID"]
                        ])->fetchAll();

                        // 4. Удаляем старые записи из базы в цикле
                        if (!empty($oldBans)) {
                            foreach ($oldBans as $oldBan) {
                                \CIBlockElement::Delete($oldBan["ID"]);
                            }
                            $messageLog .= "[Очистка ИБ 18]: Удалено устаревших записей бана: " . count($oldBans) . "\n";
                        }
                    }
                }
            }
        }
    }

    if(!empty($messageLog)){
        send_to_telegram("-1004428467234/19543", $messageLog, null);
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