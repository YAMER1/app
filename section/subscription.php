<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
require_once("../user/checkLogin.php");

if(empty($data["sectionId"])){
    die(json_encode(["status" => "error", "message" => "Ошибка, нету id раздела"]));
}

if ($data["sectionType"] == "mainSection") {
    $select = "UF_MAINSECTION";
} else {
    $select = "UF_SUBSECTION";
}

// Формируем логин точно так же, как у вас в фильтре
$userData = Bitrix\Main\UserTable::getList([
    "filter" => ["ID" => $user["ID"]],
    "select" => ["ID", $select]
])->fetch();

$currentValue = trim($userData[$select] ?? '');

// Текущие подписки пользователя (массив строк/чисел)
$userDataSections = $currentValue !== '' ? array_map('trim', explode(',', $currentValue)) : [];

// --- НОВЫЙ БЛОК ОБРАБОТКИ ВХОДЯЩИХ ID ---
// Превращаем $data["sectionId"] в массив, даже если пришло одиночное значение
$inputSections = is_string($data["sectionId"]) ? explode(',', $data["sectionId"]) : [$data["sectionId"]];
// Очищаем от пробелов и удаляем пустые элементы
$inputSections = array_filter(array_map('trim', $inputSections), 'strlen');

$isModified = false; // Флаг: были ли изменения в массиве
$addedCount = 0;
$removedCount = 0;

// Перебираем все входящие ID секций
foreach ($inputSections as $sectionId) {
    // Ищем, есть ли уже этот раздел в массиве текущих подписок
    $elementIndex = array_search($sectionId, $userDataSections);

    if ($data["isChecked"]==true) {
        // Сценарий 1: Галочку ПОСТАВИЛИ. Добавляем, если его еще нет
        if ($elementIndex === false) {
            $userDataSections[] = $sectionId;
            $isModified = true;
            $addedCount++;
        }
    } else {
        // Сценарий 2: Галочку СНЯЛИ. Удаляем, если он там есть
        if ($elementIndex !== false) {
            unset($userDataSections[$elementIndex]);
            $isModified = true;
            $removedCount++;
        }
    }
}

// Формируем текст сообщения в зависимости от того, что произошло
if ($data["isChecked"]==true) {
    $message = $addedCount > 0 ? "Подписка оформлена" : "Вы уже подписаны";
} else {
    $message = $removedCount > 0 ? "Подписка отменена" : "Вы не были подписаны";
}

// Если массив изменился — собираем строку и сохраняем в базу через CUser
if ($isModified) {
    // Удаляем возможные дубликаты, переиндексируем массив после unset и собираем в строку
    $userDataSections = array_unique(array_values($userDataSections));
    $newSectionsString = implode(',', $userDataSections);

    $cUser = new CUser;
    if ($cUser->Update($userData['ID'], [$select => $newSectionsString])) {
        echo json_encode(["status" => "success", "message" => $message]);
    } else {
        echo json_encode(["status" => "error", "message" => $cUser->LAST_ERROR]);
    }
} else {
    // Если изменений не было, просто отдаем JSON-ответ
    echo json_encode(["status" => "success", "message" => $message]);
}
