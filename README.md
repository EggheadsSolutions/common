# Установка
* В `bootstrap.php` под строкой `require CORE_PATH . 'config' . DS . 'bootstrap.php';` дописываем следующую: 
```php
require ROOT . DS . 'vendor' . DS . 'artskills' . DS . 'common' . DS . 'src' . DS . 'config' . DS . 'bootstrap.php';
```
* Наследуем `AppController` от [ArtSkills\Controller\Controller](src/Controller/Controller.php)
* Наследуем `AppTable` от [ArtSkills\ORM\Table](src/ORM/Table.php)

# Что тут сделано

## Основные фичи
* Куча [дополнительных инструментов тестирования](src/TestSuite).
* Построитель классов Table и Entity на основе структуры базы (перенести сюда доки).
* Логирование ошибок в [Sentry](src/Log/Engine).
* Полезные фичи ORM (классы Table, Entity, Query) (написать доки).
* [Helper для работы со скриптами и стилями](src/View/Helper/AssetHelper.md)).
* [Деплойщик](src/Lib/Deployer.md) проектов

## Мелочь
* [Формирование](src/config/phinx.php) конфига для phinx на основе кейковского конфига подключения
* В [контроллере](src/Controller/Controller.php) - методы для стандартных json ответов
* Правильная обработка вставки NULL значений в поля типа [JSON](src/Database/Type/JsonType.php)
* [zip/unzip](src/Filesystem/File.php)
* Очистка [папок](src/Filesystem/Folder.php) по времени создания, отложенное создание папки и ещё пара мелочей
* Незначительные изменения [Http/Client](src/Http/Client.php)
* Из окружения разработчика все [емейлы](src/Mailer/Email.php) шлются на тестовый ящик; из юнит-тестов - [сохраняются](src/Mailer/Transport/TestEmailTransport.php) с возможностью достать их и проверить; и ещё пара мелочей
* [Ограничения](src/Phinx/Db/Table.php) для миграций - для полей таблиц обязательно указывать комментарии и значения по-умолчанию (либо явно указывать, что по-умолчанию значений нет)
* Трейты для [одиночек](src/Traits/Singleton.php) и [полностью статичных классов-библиотек](src/Traits/Library.php)
* [Функции](src/config/functions.php) для удобного формирования в запросах вложенных ассоциаций и полных названий полей
* Формирование конфига [кеша](src/Lib/AppCache.php)
* Некоторые удобные функции для работы с [массивами](src/Lib/Arrays.php) и [строками](src/Lib/Strings.php)
* Удобные [чтение](src/Lib/CsvReader.php) и [запись](src/Lib/CsvWriter.php) в csv
* Немного более [удобная](src/Lib/DB.php) работа с Connection
* Определение [окружения](src/Lib/Env.php) и автодополнение для чтения из Configure
* Класс для работы с [git](src/Lib/Git.php) и [чистка](src/Lib/GitBranchTrim.php) устаревших веток
* Однострочный вызов [http](src/Lib/Http.php) запросов и получение результата в нужном формате
* [Русская граматика](src/Lib/RusGram.php): правильное склонение слов с числитильными; даты
* [Транслит](src/Lib/Translit.php)
* Построитель [Url](src/Lib/Url.php). Основная фишка - использование текущего домена по всему коду (у всех разработчиков и на продакшне текущий домен разный)
* [Объект](src/Lib/ValueObject.php) для сообщений между классами да и вообще для любых целей (использование объектов вместо ассоциативных массивов ради автодополнения)
* Функции для удобного запуска [команд в шелле](src/Lib/Shell.php) (Удобные возвращаемые значения, ошибки по-умолчанию попадают в вывод, возможность запускать в фоновом режиме, запуск из конкретной папки)

 
 
 ## Доработать
* Описать регламент работы с common (как оформлять доки, как оформлять код)
* Выпилить использование Git как одиночки и без указания папки (сначала в проектах, потом в common)
* В деплое сделать функцию возврата к предыдущей версии
  * По большей части всё просто - переключить симлинк на предыдущую папку и очистить кеш
  * Но до этого нужно откатить миграции. Сравнить миграции в текущей папке и в предыдущей, и из текущей откатить разницу
  * И нужно проверять, что в предыдущей папке сейчас более старая версия. (Если сделано более одного отката, то можно сделать круг.) Проверка по currentVersion
* Об успешном деплое тоже можно сделать оповещение (чтоб не гадать, когда он завершился)
* Можно в деплое добавить проверку, что сейчас ничего не деплоится/откатывается, чтобы нельзя было запустить одновременно  

## Подумать
* Можно ли как-нибудь вынести общий js код