<?php
declare(strict_types=1);

namespace ArtSkills\Test\TestCase\Lib\DeployerTest;

use ArtSkills\Lib\Shell;
use ArtSkills\Lib\Deployer;
use ArtSkills\Lib\Git;
use ArtSkills\Lib\Strings;
use ArtSkills\Log\Engine\SentryLog;
use ArtSkills\TestSuite\AppTestCase;
use Eggheads\Mocks\MethodMocker;
use Cake\Log\Log;

/**
 * @covers \ArtSkills\Lib\Deployer
 * Тест создания объекта и метода деплой
 */
class DeployTest extends AppTestCase
{
    const VERSION_FILE_PATH = LocalDeployer::DIR_NEXT . DS . LocalDeployer::VERSION_FILE;
    const VERSION_FILE_PATH_CURRENT = LocalDeployer::DIR_CURRENT . DS . LocalDeployer::VERSION_FILE;
    const COPY_FILE_FROM = LocalDeployer::DIR_CURRENT . DS . LocalDeployer::COPY_FILE;
    const COPY_FILE_TO = LocalDeployer::DIR_NEXT . DS . LocalDeployer::COPY_FILE;

    /**
     * История команд
     *
     * @var string[]
     */
    private array $_executeHistory = [];

    /**
     * текущая папка
     *
     * @var string
     */
    private string $_currentDir = '';

    /**
     * тестовое значение репы
     *
     * @var string
     */
    private string $_repo = '';

    /**
     * текущая ветка
     *
     * @var string
     */
    private string $_branch = '';

    /**
     * ожидаемый следующий корень для нормальных тестов
     *
     * @var string
     */
    private string $_nextRoot = '';

    /**
     * ожидаемая подпапка для нормальных тестов
     *
     * @var string
     */
    private string $_nextRootSub = '';


    /** @inheritdoc */
    public function setUp()
    {
        parent::setUp();
        $this->_executeHistory = [];
        $this->_cleanFiles();
        symlink(LocalDeployer::DIR_CURRENT, LocalDeployer::SYMLINK);

        $this->_currentDir = getcwd();
        $this->_repo = LocalDeployer::REPO_NAME;
        $this->_branch = Git::getInstance()->getCurrentBranchName();
        $this->_nextRoot = LocalDeployer::DIR_NEXT;
        $this->_nextRootSub = $this->_nextRoot . DS . LocalDeployer::CAKE_SUB_PATH;
    }

    /** @inheritdoc */
    public function tearDown()
    {
        $this->_cleanFiles();
        parent::tearDown();
    }

    /** удалить ненужные файлы */
    private function _cleanFiles(): void
    {
        $toClean = [
            self::VERSION_FILE_PATH,
            self::VERSION_FILE_PATH_CURRENT,
            self::COPY_FILE_TO,
            LocalDeployer::SYMLINK,
        ];
        foreach ($toClean as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Обычный деплой с ротацией
     * Смотрим выполняемые команды
     */
    public function testDeploy(): void
    {
        $mainRoot = LocalDeployer::SYMLINK;

        $this->_mockExec(6);
        $this->_mockOther();

        $deployer = new LocalDeployer();
        $res = $deployer->deployCurrentBranch();
        self::assertTrue($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            'putenv HOME=/var/www',
            "php composer.phar install --optimize-autoloader --no-dev --no-interaction 2>&1",
            'vendor/bin/phinx migrate 2>&1',
            "ln -snf '{$this->_nextRoot}' '$mainRoot' 2>&1",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
        $this->assertFileEqualsString((string)(LocalDeployer::VERSION + 1), self::VERSION_FILE_PATH);
        self::assertFileEquals(self::COPY_FILE_FROM, self::COPY_FILE_TO);
    }

    /** Деплой в текущую папку */
    public function testDeploySingleRoot(): void
    {
        $singleRoot = LocalDeployer::DIR_CURRENT;
        $rootSub = $singleRoot . DS . LocalDeployer::CAKE_SUB_PATH;

        $this->_mockExec(5);
        $this->_mockOther();

        $deployer = new LocalDeployer([
            'singleRoot' => $singleRoot,
            'projectSymlink' => '',
            'rotateDeployFolders' => [],
            'versionFile' => $singleRoot . DS . LocalDeployer::VERSION_FILE,
            'cakeSubPath' => $rootSub,
            'executeAfterDeploy' => ['ls -la'],
        ]);
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertTrue($res);

        $expectedCommandList = [
            "cd '$rootSub' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd $rootSub",
            'git pull 2>&1',
            'putenv HOME=/var/www',
            "php composer.phar install --optimize-autoloader --no-dev --no-interaction 2>&1",
            'vendor/bin/phinx migrate 2>&1',
            'ls -la 2>&1',
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
        $this->assertFileEqualsString((string)(LocalDeployer::VERSION + 1), self::VERSION_FILE_PATH_CURRENT);
    }

    /** Что если не удалось спуллиться */
    public function testPullFail(): void
    {
        $this->_mockExec(3, '/^git pull 2>&1$/');
        $this->_mockOther(2, 0, 0, 1);
        $this->_expectException('Не удалось спуллиться');

        $deployer = new LocalDeployer();
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertFalse($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /** Что если не обновился композер */
    public function testComposerFail(): void
    {
        $this->_mockExec(4, '/^php composer.phar install/');
        $this->_mockOther(2, 1, 0, 1);
        $this->_expectException('Не удалось обновить композер');

        $deployer = new LocalDeployer();
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertFalse($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            'putenv HOME=/var/www',
            "php composer.phar install --optimize-autoloader --no-dev --no-interaction 2>&1",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /** Что если не развернулись миграции */
    public function testMigrateFail(): void
    {
        $this->_mockExec(5, '/^vendor\/bin\/phinx migrate 2>&1$/');
        $this->_mockOther(2, 1, 0, 1);
        $this->_expectException('Миграции не развернулись');

        $deployer = new LocalDeployer();
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertFalse($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            'putenv HOME=/var/www',
            "php composer.phar install --optimize-autoloader --no-dev --no-interaction 2>&1",
            'vendor/bin/phinx migrate 2>&1',
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /**
     * Мокаем выполнение консольных команд
     *
     * @param int $expectTimes
     * @param string|bool $failPattern для каких команд возвращать неудачу
     * @throws \Exception
     */
    private function _mockExec(int $expectTimes, $failPattern = false): void
    {
        MethodMocker::mock(Shell::class, '_exec')
            ->expectCall($expectTimes)
            ->willReturnAction(
                function ($args) use ($failPattern) {
                    $command = $args[0];
                    $this->_executeHistory[] = $command;
                    if (preg_match('/^(cd [^&]+(&1)?\s+&&\s\()?git (branch( -[ar])?|for-each-ref.*|rev-parse.*)/', $command)
                        || Strings::startsWith($command, 'cp ')
                    ) {
                        exec($args[0], $output, $returnCode);
                        return [$returnCode === 0, $output];
                    } elseif ($failPattern && preg_match($failPattern, $command)) {
                        return [false, []];
                    } else {
                        return [true, []];
                    }
                }
            );
    }

    /**
     * Мокаем выполнение ещё всякой мелочи
     *
     * @param int $expectTimesChdir
     * @param int $expectTimesPutenv
     * @param int $expectTimesLog
     * @param int $expectFileWrite
     * @throws \Exception
     */
    private function _mockOther(
        int $expectTimesChdir = 2,
        int $expectTimesPutenv = 1,
        int $expectTimesLog = 1,
        int $expectFileWrite = 1
    ): void {
        MethodMocker::sniff(Deployer::class, '_chdir')
            ->expectCall($expectTimesChdir)
            ->willReturnAction(function ($args) {
                $this->_executeHistory[] = 'cd ' . $args[0];
            });

        MethodMocker::mock(Deployer::class, '_putEnv')
            ->expectCall($expectTimesPutenv)
            ->willReturnAction(function ($args) {
                $this->_executeHistory[] = 'putenv ' . $args[0];
            });

        MethodMocker::mock(Log::class, 'info')->expectCall($expectTimesLog);

        MethodMocker::sniff(Deployer::class, '_updateVersion')->expectCall($expectFileWrite);
    }

    /**
     * Ожидаем, что в тесте вылетит исключение с заданным сообщением
     *
     * @param string $message
     */
    private function _expectException(string $message): void
    {
        MethodMocker::mock(SentryLog::class, 'logException')
            ->singleCall()
            ->willReturnAction(function ($args) use ($message) {
                /** @var \Exception $exception */
                $exception = $args[0];
                self::assertContains($message, $exception->getMessage());
            });
    }


    /**
     * Проверяем, что ничего не произошло
     *
     * @param string $repo
     * @param string $branch
     * @param array $config
     * @phpstan-ignore-next-line
     * @SuppressWarnings(PHPMD.MethodArgs)
     */
    private function _testNothingHappens(string $repo, $branch, $config = []): void
    {
        $this->_mockExec(1);
        $this->_mockOther(2, 0, 1, 0);

        $deployer = new LocalDeployer($config);
        $res = $deployer->deploy($repo, $branch);
        self::assertFalse($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /** не та репа */
    public function testWrongRepo(): void
    {
        $this->_testNothingHappens('badRepoName', $this->_branch);
    }

    /** не та ветка */
    public function testWrongBranch(): void
    {
        $this->_testNothingHappens($this->_repo, 'branchNameThatWillNeverEverExist');
    }

    /** не то окружение */
    public function testWrongEnv(): void
    {
        $this->_testNothingHappens($this->_repo, $this->_branch, ['isDeployEnv' => false]);
    }

    /** не мигрируем по-умолчанию (например, в тестовом окружении) и не разворачиваем композер */
    public function testNoAutoMigrateNoComposer(): void
    {
        $mainRoot = LocalDeployer::SYMLINK;

        $this->_mockExec(4);
        $this->_mockOther(2, 0, 1, 1);

        $deployer = new LocalDeployer([
            'autoMigrate' => false,
            'composerCommand' => null,
        ]);
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertTrue($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            "ln -snf '{$this->_nextRoot}' '$mainRoot' 2>&1",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /** не перечислены зависимости и не надо копировать файлы */
    public function testNoCopy(): void
    {
        $mainRoot = LocalDeployer::SYMLINK;

        $this->_mockExec(5);
        $this->_mockOther();

        $deployer = new LocalDeployer([
            'copyFileList' => [],
        ]);
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertTrue($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            'git pull 2>&1',
            'putenv HOME=/var/www',
            'php composer.phar install --optimize-autoloader --no-dev --no-interaction 2>&1',
            'vendor/bin/phinx migrate 2>&1',
            "ln -snf '{$this->_nextRoot}' '$mainRoot' 2>&1",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
    }

    /** не указан composer home, файл версии и опции композера */
    public function testNoHomeNoVersionNoOptions(): void
    {
        $mainRoot = LocalDeployer::SYMLINK;

        $this->_mockExec(6);
        $this->_mockOther(2, 0, 1, 1);

        $deployer = new LocalDeployer([
            'versionFile' => '',
            'composerHome' => '',
            'composerOptions' => [],
            'composerRequireDev' => true,
        ]);
        $res = $deployer->deploy($this->_repo, $this->_branch);
        self::assertTrue($res);

        $expectedCommandList = [
            "cd '{$this->_nextRootSub}' 2>&1 && (git rev-parse --abbrev-ref HEAD 2>&1)",
            "cd {$this->_nextRootSub}",
            "cp '" . self::COPY_FILE_FROM . "' '" . self::COPY_FILE_TO . "' 2>&1",
            'git pull 2>&1',
            "php composer.phar install --no-interaction 2>&1",
            'vendor/bin/phinx migrate 2>&1',
            "ln -snf '{$this->_nextRoot}' '$mainRoot' 2>&1",
            "cd {$this->_currentDir}",
        ];
        self::assertEquals($expectedCommandList, $this->_executeHistory);
        self::assertFileNotExists(self::VERSION_FILE_PATH);
    }


    /**
     * конфликт конфига
     */
    public function testConfigConflict(): void
    {
        $this->expectExceptionMessage("Заполнены конфликтующие свойства");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'singleRoot' => ROOT,
        ]);
    }

    /**
     * не указан automigrate
     */
    public function testUnsetAutoMigrate(): void
    {
        $this->expectExceptionMessage("Нужно явно указать параметр _autoMigrate");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'autoMigrate' => null,
        ]);
    }

    /**
     * Явно задан параметр миграции, но не задана команда
     */
    public function testBadAutoMigrate(): void
    {
        $this->expectExceptionMessage("Явно задан параметр миграции, но не задана команда");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'autoMigrate' => true,
            'phinxCommand' => '',
        ]);
    }

    /**
     * не указан главный симлинк
     */
    public function testNoMainRoot(): void
    {
        $this->expectExceptionMessage("Не указан главный симлинк");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'projectSymlink' => '',
        ]);
    }

    /**
     * главный симлинк в списке
     */
    public function testSymlinkInList(): void
    {
        $this->expectExceptionMessage("Главный симлинк задан в списке папок");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'rotateDeployFolders' => [LocalDeployer::SYMLINK, '/var/www/common-1'],
        ]);
    }

    /**
     * то же самое, но путь оканчивается на слеш
     */
    public function testSymlinkInListDs(): void
    {
        $this->expectExceptionMessage("Главный симлинк задан в списке папок");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'rotateDeployFolders' => [LocalDeployer::SYMLINK . DS, '/var/www/common-1'],
        ]);
    }


    /**
     * не проинициализировался гит
     */
    public function testGitInitFail(): void
    {
        $this->expectExceptionMessage("Не проинициализировался гит");
        $this->expectException(\Exception::class);
        MethodMocker::mock(Git::class, 'getCurrentBranchName')->willReturnValue('');
        new LocalDeployer();
    }

    /**
     * не указан репозиторий
     */
    public function testNoRepo(): void
    {
        $this->expectExceptionMessage("Не указан репозиторий");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'repoName' => '',
        ]);
    }

    /**
     * только одна папка в списке
     */
    public function testOneFolderInList(): void
    {
        $this->expectExceptionMessage("явно задайте свойство _singleRoot");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'rotateDeployFolders' => [ROOT],
        ]);
    }

    /**
     * дубли в списке папок
     */
    public function testFolderDuplicates(): void
    {
        $this->expectExceptionMessage("В списке папок есть дубли");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'rotateDeployFolders' => [ROOT, ROOT],
        ]);
    }

    /**
     * плохой файл версий
     */
    public function testBadVersionFile(): void
    {
        $this->expectExceptionMessage("Не могу получить относительный путь из");
        $this->expectException(\Exception::class);
        new LocalDeployer([
            'versionFile' => '/var/www/common/asdfgh',
        ]);
    }
}
