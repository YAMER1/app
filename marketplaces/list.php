<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
require_once("../user/checkLogin.php");

// 1. Получаем сохраненные подписки текущего пользователя из UF_PREM_OPTIONS
// Использовали $user["ID"], который пришел из checkLogin.php
$userData = \Bitrix\Main\UserTable::getList([
    "filter" => ["ID" => $user["ID"]],
    "select" => ["ID", "UF_PREM_OPTIONS"] 
])->fetch();

$savedMarkets = [];
$hasMainMarket = false; // Флаг проверяет наличие конкретно ключа mainMarket
$options = [];          // Сюда сохраняем ВСЕ существующие ключи из базы для слияния

if (!empty($userData["UF_PREM_OPTIONS"])) {
    $decoded = json_decode($userData["UF_PREM_OPTIONS"], true);
    if (is_array($decoded)) {
        $options = $decoded; // Сохраняем абсолютно все старые ключи (theme, notifications и т.д.)
        
        if (isset($options["mainMarket"]) && is_array($options["mainMarket"])) {
            $savedMarkets = $options["mainMarket"];
            $hasMainMarket = true; // Ключ mainMarket уже существует, ничего дополнять не нужно
        }
    }
}

// 2. Выбираем элементы маркетплейсов
header('Content-Type: application/json; charset=utf-8');
if (\Bitrix\Main\Loader::includeModule('iblock')) {
    
    $arrMarketplaces = \Bitrix\Iblock\ElementTable::getList([
        "filter" => [
            "IBLOCK_SECTION_ID" => 49,
            "ACTIVE" => "Y",
        ],
        "select" => ["ID", "NAME"], 
        "order" => ["SORT" => "ASC"]
    ])->fetchAll();

    if (!empty($arrMarketplaces)) {
        
        // Переменная для формирования структуры автоматического заполнения (если ключа mainMarket нет)
        $autoFillMarkets = [];

        // 3. Обрабатываем массив маркетплейсов для Vue
        foreach($arrMarketplaces as $key => $value) {
            $arrMarketplaces[$key]["NAME"] = trim(preg_replace('/^.*?\s/', '', $value["NAME"]));
            $marketId = $value["ID"];
            
            // Накапливаем структуру "ID => true" на случай, если ключа не было в базе
            $autoFillMarkets[$marketId] = true;

            if ($hasMainMarket) {
                // Если настройки маркетплейсов в базе есть — проверяем сохраненный статус
                $arrMarketplaces[$key]["checked"] = (isset($savedMarkets[$marketId]) && $savedMarkets[$marketId] == true) ? 1 : 0;
            } else {
                // Если настроек в базе не было — ставим по умолчанию 1 (так как мы их сейчас включим в БД)
                $arrMarketplaces[$key]["checked"] = 1;
            }
        }

        // 4. Если у пользователя вообще нет настроек маркетплейсов, добавляем их без затирания остальных данных
        if (!$hasMainMarket) {
            // Переименовали переменную объекта в $cUser, чтобы не затереть массив $user с данными авторизации
            $cUser = new \CUser;
            
            // Дописываем/подсаживаем ключ mainMarket в общий массив опций $options, сохраняя старые ключи
            $options["mainMarket"] = $autoFillMarkets;
            
            $updatedJson = json_encode($options, JSON_UNESCAPED_UNICODE);

            $cUser->Update($user["ID"], [
                "UF_PREM_OPTIONS" => $updatedJson
            ]);
        }

        echo json_encode($arrMarketplaces, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => "Ошибка, список маркетплейсов пуст"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Ошибка модуля"]);
}
die();