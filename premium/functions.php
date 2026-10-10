<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
function getUserPremOptions(int $userId): array 
{
    if ($userId <= 0) return [];

    // Получаем текущие данные пользователя из базы
    $userData = \Bitrix\Main\UserTable::getList([
        "filter" => ["ID" => $userId],
        "select" => ["ID", "UF_PREM_OPTIONS"]
    ])->fetch();

    if (!$userData || empty($userData["UF_PREM_OPTIONS"])) {
        return [];
    }

    // Разбираем существующий JSON
    $options = json_decode($userData["UF_PREM_OPTIONS"], true);
    
    return is_array($options) ? $options : [];
}

function saveUserPremOptions(int $userId, array $newOptions) 
{
    if ($userId <= 0) return 'Неверный ID пользователя';

    // 1. Получаем текущие (старые) опции из базы данных
    $currentOptions = getUserPremOptions($userId);

    // 2. Объединяем старые опции с новыми изменениями, которые пришли
    // array_merge_recursive или просто по ключам:
    foreach ($newOptions as $key => $value) {
        if (is_array($value) && isset($currentOptions[$key]) && is_array($currentOptions[$key])) {
            // Если внутри ключа (например, mainMarket) тоже массив, объединяем его элементы
            foreach ($value as $subKey => $subValue) {
                $currentOptions[$key][$subKey] = $subValue;
            }
        } else {
            // Для простых ключей или если раньше ключа не было — просто перезаписываем
            $currentOptions[$key] = $value;
        }
    }

    // 3. Кодируем в JSON уже объединенный массив
    $jsonString = json_encode($currentOptions, JSON_UNESCAPED_UNICODE);

    // 4. Обновляем поле пользователя с помощью CUser
    $cUser = new \CUser;
    $isUpdated = $cUser->Update($userId, [
        "UF_PREM_OPTIONS" => $jsonString
    ]);

    // Возвращаем true, если всё ок, иначе текст ошибки Битрикса
    return $isUpdated ? true : $cUser->LAST_ERROR;
}