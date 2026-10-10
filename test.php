<?
require_once($_SERVER["DOCUMENT_ROOT"] . "/app/secure.php");
use Bitrix\Main\Loader;
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
global $APPLICATION;
$APPLICATION->ShowHead();
CJSCore::Init();
Loader::includeModule('iblock');

echo "Проверка страницы<br>";