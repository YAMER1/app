<?php
// Защита и подключение ядра
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once("../user/checkLogin.php");
require_once("../premium/functions.php");

header('Content-Type: application/json; charset=utf-8');

// Считываем JSON-данные от Vue метода sendRequest
$data = json_decode(file_get_contents('php://input'), true) ?: [];

// === 1. ПОЛУЧАЕМ ВСЕ ПРЕМИУМ-ОПЦИИ ПОЛЬЗОВАТЕЛЯ ЗА ОДИН ЗАПРОС ===
// Используем $user["ID"], пришедший из checkLogin.php
$userData = \Bitrix\Main\UserTable::getList([
    "filter" => ["ID" => $user["ID"]],
    "select" => ["ID", "UF_PREM_OPTIONS"] 
])->fetch();

$options = [];
$savedMarkets = [];
$hasMainMarket = false;

if (!empty($userData["UF_PREM_OPTIONS"])) {
    $decoded = json_decode($userData["UF_PREM_OPTIONS"], true);
    if (is_array($decoded)) {
        $options = $decoded; // Сохраняем все старые ключи (theme, notifications и т.д.)
        
        if (isset($options["mainMarket"]) && is_array($options["mainMarket"])) {
            $savedMarkets = $options["mainMarket"];
            $hasMainMarket = true;
        }
    }
}


// Извлекаем значение для чекбокса "Топ постов" (по умолчанию 1 / true)
$topPostsValue = isset($options['topPosts']) ? (int)$options['topPosts'] : 1;

// Извлекаем интервал времени (по умолчанию круглосуточно)
$timeRange = isset($options["timeRange"]) ? $options["timeRange"] : [
    "start" => "00:00",
    "end" => "24:00"
];


// === 2. ВЫБИРАЕМ ЭЛЕМЕНТЫ МАРКЕТПЛЕЙСОВ И ФОРМИРУЕМ ИХ СТАТУС ===
$marketplacesList = [];

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
        $autoFillMarkets = [];

        foreach($arrMarketplaces as $key => $value) {
            // Твоя оригинальная очистка названия от лишних символов
            $cleanedName = trim(preg_replace('/^.*?\s/', '', $value["NAME"]));
            $marketId = $value["ID"];
            
            // Накапливаем структуру на случай, если ключа не было в базе
            $autoFillMarkets[$marketId] = true;

            // Определяем статус галочки (1 или 0)
            if ($hasMainMarket) {
                $checkedStatus = (isset($savedMarkets[$marketId]) && $savedMarkets[$marketId] == true) ? 1 : 0;
            } else {
                $checkedStatus = 1; // Дефолт, если настроек еще нет
            }

            $marketplacesList[] = [
                "ID" => $marketId,
                "NAME" => $cleanedName,
                "checked" => $checkedStatus
            ];
        }

        // === 3. АВТОЗАПОЛНЕНИЕ В БАЗЕ (ЕСЛИ У ПОЛЬЗОВАТЕЛЯ НЕТ КЛЮЧА mainMarket) ===
        if (!$hasMainMarket) {
            $cUser = new \CUser;
            $options["mainMarket"] = $autoFillMarkets;
            
            $cUser->Update($user["ID"], [
                "UF_PREM_OPTIONS" => json_encode($options, JSON_UNESCAPED_UNICODE)
            ]);
        }
    }
} else {
    // Если модуль не подключился, отдаем ошибку во Vue
    echo json_encode(["success" => false, "message" => "Ошибка подключения модуля инфоблоков"]);
    die();
}


// === 4. СБОРКА И ОТПРАВКА ЕДИНОГО ОТВЕТА ВО VUE ===
echo json_encode([
    "success" => true,
    "topPosts" => ($topPostsValue === 1), // Сразу преобразуем в boolean для Vue
    "timeRange" => $timeRange,
    "marketplaces" => $marketplacesList
], JSON_UNESCAPED_UNICODE);
die();
