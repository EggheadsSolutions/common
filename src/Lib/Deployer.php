<?php
declare(strict_types=1);

namespace ArtSkills\Lib;

use ArtSkills\Error\InternalException;
use ArtSkills\Log\Engine\SentryLog;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Cake\Log\Log;

/**
 * Деплойщик
 * обновляет текущий или вообще любой проект
 * [описание](https://github.com/ArtSkills/common/tree/master/src/Lib/Deployer.md)
 * @SuppressWarnings(PHPMD.MethodMix)
 */
class Deployer
{
    /**
     * Если не используется ротация и деплой идёт по живому
     * Заполнить либо это свойство, либо projectSymlink + rotateDeployFolders
     *
     * @var string
     */
    protected string $_singleRoot = '';

    /**
     * Список заранее созданных папок, между которыми переключается симлинк при деплое
     *
     * @var string[]
     */
    protected array $_rotateDeployFolders = [];

    /**
     * Симлинк, который будет переключаться, и через который внешний мир обращается к проекту
     *
     * @var string
     */
    protected string $_projectSymlink = '';

    /**
     * Название репозитория
     *
     * @var string
     */
    protected string $_repoName = '';

    /**
     * Файлы из этого списка при деплое будут скопированы
     * (Для файлов, находящихся в gitignore, которые не симлинкаются с целью возможности отката)
     * (локальные конфиги, например)
     *
     * @var string[]
     */
    protected array $_copyFileList = [];

    /**
     * Файл с версией
     * Можно указать полный путь, или неполный путь относительно корня
     * Главное, чтобы файл не лежал вне корня
     *
     * @var string
     */
    protected string $_versionFile = '';


    /**
     * Версия, инкрементируется при деплое, и записывается в файл
     * Либо null и ничего не происходит
     * Если задана константа CORE_VERSION, то берётся из ней
     *
     * @var int|null
     */
    protected ?int $_currentVersion = null;

    /**
     * Если вдруг кейковый проект не является корнем проекта
     *
     * @var string
     */
    protected string $_cakeSubPath = '';

    /**
     * Разворачивать ли миграции БД автоматически
     * Нужно указать явно
     *
     * @var null|bool
     */
    protected ?bool $_autoMigrate = null;

    /**
     * Можно ли в текущем окружении деплоить текущую конфигурацию
     * (Например, тест не может деплоить продакшн, и для конфигурации продакшна в тестовом окружении тут должен быть false)
     *
     * @var bool|null
     */
    protected ?bool $_isDeployEnv = null;


    /**
     * Ставить ли dev зависимости
     *
     * @var bool
     */
    protected bool $_composerRequireDev = false;

    /**
     * С какими опциями пускать композер
     * --no-interaction добавляется всегда автоматически
     *
     * @var string[]
     */
    protected array $_composerOptions = [
        '--optimize-autoloader',
    ];

    /**
     * Команда запуска композера
     *
     * @var ?string
     */
    protected ?string $_composerCommand = 'php composer.phar';

    /**
     * Домашняя папка, без неё композер не работает
     *
     * @var string
     */
    protected string $_composerHome = '/var/www';

    /**
     * Команда запуска финкса
     *
     * @var string
     */
    protected string $_phinxCommand = 'vendor/bin/phinx';

    /**
     * Куда писать результат
     *
     * @var string
     */
    protected string $_logScope = 'deployment';


    /**
     * Список команд, которые необходимы выполнить после деплоя
     *
     * @var string[]|null
     */
    protected ?array $_executeAfterDeploy = null;

    /**
     * вывод команд
     *
     * @var string[]
     */
    protected array $_output = [];

    /**
     * Объект работы с гитом
     *
     * @var Git|null
     */
    protected ?Git $_git = null;

    /**
     * Каталог, на который сейчас засимлинкан $_projectSymlink. Вычислимое поле, не трогать
     *
     * @var string
     */
    protected string $_currentRoot = '';

    /**
     * Из какой папки всё будет выполняться. Вычислимое поле, не трогать
     *
     * @var string
     */
    protected string $_runFrom = '';


    /**
     * конструктор
     *
     * @param array<string, bool|string|string[]|null> $config Конфиг. ключ => начение, клиючи - названия свойств этого класса без подчёркивания
     *                                                         описание в [доках](https://github.com/ArtSkills/common/tree/master/src/Lib/Deployer.md)
     * @throws InternalException
     */
    public function __construct(array $config = [])
    {
        $this->_applyConfig($config);
        $this->_normalizePaths();
        $this->_checkProperties();
        $this->_setValues();
    }

    /**
     * Инстанцировать объект
     * С настройками, взятыми из cake Configure
     * по ключу Deploy.$type
     *
     * @param string $type ключ
     * @return static
     * @throws InternalException
     */
    public static function createFromConfig(string $type): self
    {
        $configs = Configure::read('Deploy');
        if (empty($configs[$type])) {
            throw new InternalException("Не определён конфиг деплоя '$type'");
        }
        return new static($configs[$type]); // @phpstan-ignore-line
    }

    /**
     * Деплой с проверками того, что деплоится
     *
     * @param string $repo обновляемая репа
     * @param string $branch обновляемая ветка
     * @return bool
     * @throws \Exception
     */
    public function deploy(string $repo, string $branch): bool
    {
        $currentDir = getcwd();
        $this->_chdir($this->_runFrom);

        $success = false;
        try {
            $success = $this->_run($repo, $branch);
        } catch (\Exception $e) {
            SentryLog::logException($e, [
                'scope' => [$this->_logScope],
                SentryLog::KEY_ADD_INFO => $this->_output,
            ]);
        } finally {
            $this->_chdir($currentDir);
        }
        return $success;
    }

    /**
     * Деплой текущей ветки, без проверок
     *
     * @return bool
     * @throws \Exception
     */
    public function deployCurrentBranch(): bool
    {
        return $this->deploy($this->_repoName, $this->_git->getCurrentBranchName());
    }

    /**
     * Заменить папку проекта на симлинк
     * Может не сработать, если неправильные права
     * Обратите внимание, что у симлинка владельцем будет текущий пользователь
     *
     * @param string $absProjectPath полный путь до проекта
     * @param string $newFolderName новое название папки, относительный путь в том же каталоге, что и проект
     * @return void
     * @throws InternalException
     */
    public static function makeProjectSymlink(string $absProjectPath, string $newFolderName)
    {
        if (is_link($absProjectPath) || !is_dir($absProjectPath)) {
            throw new InternalException('Передан некорректный каталог проекта');
        }
        $newFolderFullName = dirname($absProjectPath) . DS . $newFolderName;
        if (file_exists($newFolderFullName)) {
            throw new InternalException('Такой каталог уже есть');
        }
        $newFolderFullName = escapeshellarg($newFolderFullName);
        $absProjectPath = escapeshellarg($absProjectPath);
        $commands = [
            "mv $absProjectPath $newFolderFullName",
            "ln -s $newFolderFullName $absProjectPath",
        ];
        [$success, $output, $resultCommand] = Shell::exec($commands);
        opcache_reset();
        if (!$success) {
            throw new InternalException("Ошибка. Команда: $resultCommand, Вывод: " . implode("\n", $output));
        }
    }

    /**
     * Заполнить свойства из конфига
     *
     * @param array<string, string|bool|string[]> $config
     * @return void
     */
    protected function _applyConfig(array $config)
    {
        $this->_isDeployEnv = Env::isProduction();
        if (defined('CORE_VERSION')) {
            $this->_currentVersion = CORE_VERSION;
        }
        if (defined('VERSION_FILE')) {
            $this->_versionFile = VERSION_FILE;
        }
        foreach ($config as $property => $value) {
            $property = '_' . $property;
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
    }

    /**
     * Делаем проверки заполненности свойств
     *
     * @return void
     * @throws InternalException
     */
    protected function _checkProperties()
    {
        if (!empty($this->_singleRoot)) {
            if (!empty($this->_rotateDeployFolders) || !empty($this->_projectSymlink)) {
                throw new InternalException('Заполнены конфликтующие свойства');
            }
        } else {
            if (count($this->_rotateDeployFolders) === 1) {
                throw new InternalException('В списке указана одна папка. Для деплоя в текущую папку явно задайте свойство _singleRoot');
            }
            if (count($this->_rotateDeployFolders) !== count(array_unique($this->_rotateDeployFolders))) {
                throw new InternalException('В списке папок есть дубли');
            }
            if (empty($this->_projectSymlink)) {
                throw new InternalException('Не указан главный симлинк');
            }
            if (!is_link($this->_projectSymlink)) {
                throw new InternalException("{$this->_projectSymlink} не является симлинком");
            }
            if (in_array($this->_projectSymlink, $this->_rotateDeployFolders)) {
                throw new InternalException('Главный симлинк задан в списке папок');
            }
        }

        if ($this->_autoMigrate === null) {
            throw new InternalException('Нужно явно указать параметр _autoMigrate');
        }
        if ($this->_autoMigrate && empty($this->_phinxCommand)) {
            throw new InternalException('Явно задан параметр миграции, но не задана команда');
        }

        if (empty($this->_repoName)) {
            throw new InternalException('Не указан репозиторий');
        }
    }

    /**
     * Сделать нужные преобразования над значениями
     *
     * @return void
     * @throws InternalException
     */
    protected function _setValues()
    {
        if (!empty($this->_singleRoot)) {
            $this->_currentRoot = $this->_singleRoot;
            $this->_rotateDeployFolders = [$this->_singleRoot];
        } else {
            $this->_currentRoot = $this->_cutTrailingDs(readlink($this->_projectSymlink));
            if (!in_array($this->_currentRoot, $this->_rotateDeployFolders)) {
                throw new InternalException('Каталог, на который сейчас ссылается симлинк, отсутствует в списке!');
            }
        }

        $this->_runFrom = $this->_getNextRoot();
        if (!empty($this->_cakeSubPath)) {
            $this->_runFrom = $this->_runFrom . DS . $this->_fullPathToRelative($this->_cakeSubPath);
        }

        $this->_git = new Git($this->_runFrom);
        if (empty($this->_git->getCurrentBranchName())) {
            throw new InternalException('Не проинициализировался гит');
        }

        $this->_versionFile = $this->_fullPathToRelative($this->_versionFile);
        foreach ($this->_copyFileList as &$path) {
            $path = $this->_fullPathToRelative($path);
        }

        if (!$this->_composerRequireDev) {
            $this->_composerOptions[] = '--no-dev';
        }
        $this->_composerOptions[] = '--no-interaction';
    }

    /**
     * Привести все пути к правильному формату
     *
     * @return void
     */
    protected function _normalizePaths()
    {
        // слеши в конце пути
        $folderProperties = [
            '_singleRoot',
            '_projectSymlink',
            '_cakeSubPath',
        ];
        foreach ($folderProperties as $property) {
            $this->$property = $this->_cutTrailingDs($this->$property);
        }
        foreach ($this->_rotateDeployFolders as &$folder) {
            $folder = $this->_cutTrailingDs($folder);
        }
        unset($folder);
    }

    /**
     * Убрать разделитель директорий с конца пути
     *
     * @param string $path
     * @return string
     */
    protected function _cutTrailingDs(string $path): string
    {
        return Strings::replaceIfEndsWith($path, DS);
    }

    /**
     * Сделать из полного пути относительный
     * Для файлов, лежащих в текущем корне
     *
     * @param string $fullPath
     * @return string
     * @throws InternalException
     */
    protected function _fullPathToRelative(string $fullPath): string
    {
        $toReplace = $this->_rotateDeployFolders;
        $toReplace[] = $this->_projectSymlink;
        foreach ($toReplace as &$path) {
            $path .= DS;
        }
        unset($path);
        $result = Strings::replaceIfStartsWith($fullPath, $toReplace);
        if (!empty($result) && ($result[0] === DS)) {
            throw new InternalException("Не могу получить относительный путь из {$fullPath}");
        }
        return $result;
    }


    /**
     * Деплой
     *
     * @param string $repo обновляемая репа
     * @param string $branch обновляемая ветка
     * @return bool
     * @throws InternalException
     */
    protected function _run(string $repo, string $branch): bool
    {
        if (!$this->_canDeploy($repo, $branch)) {
            $message = Time::now()->format('Y-m-d H:i:s') . ': Обновление не было запущено.';
            Log::info($message, ['scope' => [$this->_logScope]]);
            return false;
        }
        $nextRoot = $this->_getNextRoot();

        $timeStart = microtime(true);

        // мелочёвку сделаем сначала, чтобы после миграции максимально быстро переключить симлинк
        $this->_updateVersion();
        $this->_copyFiles();

        // первым идёт обновление репозитория, ибо там могли обновиться composer.lock и добавиться миграции
        $this->_updateRepo();
        $this->_updateComposer();
        $this->_migrateDb();

        AppCache::flushExcept();
        $this->_executeAfterDeploy();

        $this->_setProjectSymlink($nextRoot);

        $timeEnd = microtime(true);
        $this->_log($timeStart, $timeEnd);

        $this->_notifySuccess();
        opcache_reset();
        return true;
    }

    /**
     * Папка, на которую будем переключаться
     *
     * @return string
     */
    protected function _getNextRoot(): string
    {
        $currentFolderKey = array_search($this->_currentRoot, $this->_rotateDeployFolders);
        if ($currentFolderKey === (count($this->_rotateDeployFolders) - 1)) {
            $nextFolderKey = 0;
        } else {
            $nextFolderKey = $currentFolderKey + 1;
        }
        return $this->_rotateDeployFolders[$nextFolderKey];
    }

    /**
     * Переключить на указанную папку
     *
     * @param string $newActualRoot
     * @return void
     * @throws InternalException
     */
    protected function _setProjectSymlink(string $newActualRoot)
    {
        if (!empty($this->_singleRoot)) {
            // одна папка, деплой по живому
            return;
        }
        $newActualRoot = escapeshellarg($newActualRoot);
        $mainRoot = escapeshellarg($this->_projectSymlink);
        // ротация нескольких папок, переключаем симлинк
        // s - символьная, nf - чтобы ссылка на папку перезаписалась
        $this->_exec("ln -snf $newActualRoot $mainRoot", 'Не переключился симлинк');
    }

    /**
     * Скопировать файлы из списка
     *
     * @return void
     * @throws InternalException
     */
    protected function _copyFiles()
    {
        if (!empty($this->_singleRoot) || empty($this->_copyFileList)) {
            return;
        }
        foreach ($this->_copyFileList as $relativePath) {
            $oldPath = escapeshellarg($this->_currentRoot . DS . $relativePath);
            $newPath = escapeshellarg($this->_getNextRoot() . DS . $relativePath);
            $this->_exec("cp $oldPath $newPath", "Не удалось скопировать файл $relativePath");
        }
    }

    /**
     * Можно ли деплоить
     *
     * @param string $repo
     * @param string $branch
     * @return bool
     */
    protected function _canDeploy(string $repo, string $branch): bool
    {
        $currentBranch = $this->_git->getCurrentBranchName();
        return (
            ($repo === $this->_repoName)
            && !empty($currentBranch)
            && ($branch === $currentBranch)
            && $this->_isDeployEnv
        );
    }

    /**
     * Обновить репозиторий
     *
     * @return void
     * @throws InternalException
     */
    protected function _updateRepo()
    {
        $this->_addToOutput(["\n\nGit pull\n"]);
        [$success, $output] = $this->_git->pullCurrentBranch();
        $this->_addToOutput($output);
        $this->_checkSuccess($success, 'Не удалось спуллиться');
    }

    /**
     * обновить зависимости композера
     *
     * @return void
     * @throws InternalException
     */
    protected function _updateComposer()
    {
        $this->_addToOutput(["\n\nComposer\n"]);
        if (empty($this->_composerCommand)) {
            $this->_addToOutput(['not updating dependencies']);
            return;
        }
        if (!empty($this->_composerHome)) {
            $envData = 'HOME=' . $this->_composerHome;
            $this->_putEnv($envData);
        }
        $this->_exec(
            $this->_composerCommand . ' install ' . implode(' ', $this->_composerOptions),
            'Не удалось обновить композер'
        );
    }

    /**
     * Выполнить и вернуть результат
     *
     * @param string $command
     * @param string $failMessage
     * @return void
     * @throws InternalException
     */
    protected function _exec(string $command, string $failMessage)
    {
        [$success, $output] = Shell::execFromDir($this->_runFrom, $command);
        $this->_addToOutput([$command]);
        $this->_addToOutput($output);
        $this->_checkSuccess($success, $failMessage);
    }

    /**
     * Смена директории с добавлением записи в лог (и чтоб можно было мокать)
     * Можно было бы делать $this->_exec("cd $dir"), но это не работает =(
     *
     * @param string $dir
     * @return void
     */
    protected function _chdir(string $dir)
    {
        chdir($dir);
        $this->_addToOutput(["cd $dir"]);
    }

    /**
     * Задание переменной окружения с добавлением записи в лог (и чтоб можно было мокать)
     *
     * @param string $data
     * @return void
     */
    protected function _putEnv(string $data)
    {
        putenv($data);
        $this->_addToOutput(['putenv ' . $data]);
    }

    /**
     * выкидывает ошибку
     *
     * @param bool $condition
     * @param string $errorMessage
     * @return void
     * @throws InternalException
     */
    protected function _checkSuccess(bool $condition, string $errorMessage)
    {
        if (!$condition) {
            throw new InternalException($errorMessage);
        }
    }

    /**
     * Запоминаем вывод для дальнейшего лога
     *
     * @param string[] $output
     * @return void
     */
    protected function _addToOutput(array $output)
    {
        $this->_output = array_merge($this->_output, $output);
    }

    /**
     * Запустить миграции
     *
     * @return void
     * @throws InternalException
     */
    protected function _migrateDb()
    {
        $this->_addToOutput(["\n\nMigration\n"]);
        if ($this->_autoMigrate && !empty($this->_phinxCommand)) {
            $this->_exec(
                $this->_phinxCommand . ' migrate',
                'АЛЯРМ! Миграции не развернулись! Нужно проверить, что они не остановились на половине, и откатить!'
            );
            // я хотел здесь впилить автоматический откат миграций, но не получится =(

            // В одной миграции может быть несколько DDL запросов
            // Но в MySQL они не могут быть выполнены в одной транзакции, 1 DDL - 1 транзакция
            // Так что если миграция отвалится посередине, выполнившиеся запросы не откатятся
            // И их нужно откатывать руками
        } else {
            $this->_addToOutput(['migration was not run']);
        }
    }

    /**
     * Выполняем команды после деплоя
     */
    protected function _executeAfterDeploy(): void
    {
        if (!empty($this->_executeAfterDeploy)) {
            $this->_addToOutput(["\n\nExecute after deploy commands\n"]);
            foreach ($this->_executeAfterDeploy as $cmd) {
                $this->_exec($cmd, "Не запустилась команда \"$cmd\"");
            }
        }
    }

    /**
     * Обновить файл с версией
     *
     * @return void
     */
    protected function _updateVersion()
    {
        if (!empty($this->_versionFile) && ($this->_currentVersion !== null)) {
            $versionFilePath = $this->_getNextRoot() . DS . $this->_versionFile;
            file_put_contents($versionFilePath, ++$this->_currentVersion);
        }
    }

    /**
     * Записать результат в лог
     *
     * @param float $timeStart
     * @param float $timeEnd
     * @return void
     */
    protected function _log(float $timeStart, float $timeEnd)
    {
        $this->_output = array_merge([
            date('Y-m-d H:i:s', (int)$timeStart),
            'Finished in ' . round($timeEnd - $timeStart, 3) . ' seconds',
        ], $this->_output, ["\n\n"]);

        Log::info(implode("\n", $this->_output), [
            'scope' => [$this->_logScope],
        ]);

        $this->_output = [];
    }

    /**
     * Сообщить об успехе
     *
     * @return void
     */
    protected function _notifySuccess()
    {
        // не реализовано
    }

    /**
     * Откатиться к предыдущей версии
     *
     * @return void
     */
    public function rollback()
    {
        // не реализовано
    }
}
