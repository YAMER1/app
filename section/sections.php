<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
/* Список разделов, лист */
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

// Получаем содержимое POST-запроса
$data = json_decode(file_get_contents('php://input'), true);

$filter = $data["filter"] ?? []; // чтобы был массив даже если ничего не пришло
$select = $data["select"] ?? []; // то же самое

$arFilter =  ["=IBLOCK_ID" => 2, "=ACTIVE" => "Y", "DEPTH_LEVEL"=>$filter["DEPTH_LEVEL"] ?? 1];

if(!empty($filter["IBLOCK_SECTION_ID"])){
    $arFilter["IBLOCK_SECTION_ID"] = $filter["IBLOCK_SECTION_ID"];
}

$sectionsArr = \Bitrix\Iblock\Model\Section::compileEntityByIblock(2)::getList([
    "filter" => [
        $arFilter
    ],
    "select" => $select,
    "order" => ["NAME" => "ASC"],
])->fetchAll();


/* Получаем оба поля пользователя для проверки подписи на разделы */
$userData = Bitrix\Main\UserTable::getList([
    "filter" => ["=LOGIN" => "tg".$data["userId"]],
    "select" => ["ID","UF_MAINSECTION","UF_SUBSECTION"]
])->fetch();

// 1. Получаем чистые строки (удаляем пробелы по краям, если они null — заменяем на '')
$mainStr = trim($userData["UF_MAINSECTION"] ?? '');
$subStr  = trim($userData["UF_SUBSECTION"] ?? '');

// 2. Превращаем каждую непустую строку в массив
$mainArray = $mainStr !== '' ? array_map('trim', explode(',', $mainStr)) : [];
$subArray  = $subStr  !== '' ? array_map('trim', explode(',', $subStr))  : [];
$allSections = array_merge($mainArray, $subArray);
/* Получаем оба поля пользователя для проверки подписи на разделы */


if(!empty($sectionsArr)){
    $movedSection = null; // Переменная для хранения 135-го раздела

    foreach($sectionsArr as $key => $section){
        /*
        if($section["ID"] == 124){
            unset($sectionsArr[$key]);
            continue; // Переходим к следующему элементу массива
        } */
    
        // Существующая логика checked
        $sectionsArr[$key]["checked"] = (!empty($allSections) && in_array($section["ID"], $allSections));
        
        // НАЧАЛО ИЗМЕНЕНИЙ: Ищем раздел 135
        if ($section["ID"] == 135) {
            $movedSection = $sectionsArr[$key]; // Запоминаем измененный элемент (уже с флагом checked)
            unset($sectionsArr[$key]);          // Удаляем его из текущей позиции
        }
    }
   
    // Если раздел 135 был найден, добавляем его в самый конец
    if ($movedSection !== null) {
        $sectionsArr[] = $movedSection;
        $sectionsArr = array_values($sectionsArr); // Сбрасываем ключи, чтобы JSON остался массивом [ ... ]
    }
   
    echo json_encode($sectionsArr, JSON_UNESCAPED_UNICODE);
}