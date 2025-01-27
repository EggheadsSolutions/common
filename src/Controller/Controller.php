<?php
declare(strict_types=1);

namespace ArtSkills\Controller;

use ArtSkills\Controller\Response\ApiResponse;
use ArtSkills\Error\InternalException;
use ArtSkills\Error\UserException;
use ArtSkills\Lib\Env;
use ArtSkills\ValueObject\ValueObject;
use Cake\Error\PHP7ErrorException;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\Routing\Router;
use ReflectionMethod;

/**
 * @SuppressWarnings(PHPMD.MethodProps)
 * @SuppressWarnings(PHPMD.MethodArgs)
 */
class Controller extends \Cake\Controller\Controller
{

    public const JSON_STATUS_OK = 'ok';
    public const JSON_STATUS_ERROR = 'error';

    protected const REQUEST_EXTENSION_HTML = 'html';
    protected const REQUEST_EXTENSION_JSON = 'json';
    protected const REQUEST_EXTENSION_DEFAULT = self::REQUEST_EXTENSION_HTML;

    /**
     * Задать редирект в случае ошибки
     *
     * @var null|string|array|Response
     */
    private $_errorRedirect = null; // @phpstan-ignore-line

    /**
     * Список экшнов, которые всегда должны возвращать джсон.
     * Автоматически вызывает для них _setIsJsonAction() в инициализации.
     *
     * @var string[]
     */
    protected array $_jsonResponseActions = [];

    /**
     * Формат ответа
     *
     * @var string
     */
    protected string $_responseExtension = self::REQUEST_EXTENSION_DEFAULT;

    /** @inheritdoc */
    public function invokeAction()
    {
        try {
            return parent::invokeAction();
        } catch (UserException $exception) {
            $exception->log();
            if ($this->_isJsonAction()) {
                if (!empty($this->_errorRedirect)) {
                    Log::error('Используется редирект в JSON ответе');
                }
                return $this->_sendJsonException($exception);
            }
            $this->Flash->error($exception->getUserMessage());
            $redirect = $this->_errorRedirect;
            if (is_string($redirect) || is_array($redirect)) {
                $redirect = $this->redirect($redirect);
            }
            return $redirect;
        }
    }

    /** @inheritdoc */
    public function isAction($action)
    {
        $isAction = parent::isAction($action);
        if ($isAction) {
            $methodName = (new ReflectionMethod($this, $action))->getName();
            if ($methodName !== $action) {
                // разный регистр букв
                $this->request = $this->request->withParam('action', $methodName);
                Router::pushRequest(Router::popRequest()->withParam('action', $methodName));
            }
        }
        return $isAction;
    }

    /** @inheritdoc */
    public function initialize()
    {
        parent::initialize();
        $currentAction = $this->request->getParam('action');
        foreach ($this->_jsonResponseActions as $action) {
            if ($action === $currentAction) {
                $this->_setIsJsonAction();
                break;
            }
        }
        if ($this->request->is(self::REQUEST_EXTENSION_JSON)) {
            $this->_setIsJsonAction();
        }
        $this->_responseExtension = $this->request->getParam('_ext', self::REQUEST_EXTENSION_DEFAULT);
    }

    /**
     * Задать редирект при обработке ошибок
     *
     * @param string|array|Response $redirect
     * @return void
     * @throws InternalException
     * @phpstan-ignore-next-line
     */
    protected function _setErrorRedirect($redirect)
    {
        if (empty($redirect)) {
            $this->_throwInternalError('Пустой параметр $redirect');
        }
        $this->_errorRedirect = $redirect;
    }

    /**
     * Задать, что при обработке ошибок редиректа нет
     *
     * @return void
     */
    protected function _setErrorNoRedirect()
    {
        $this->_errorRedirect = null;
    }

    /**
     * Бросить обычную пользовательскую ошибку
     *
     * @param string $message
     * @param bool|null|string|array|Response $redirect
     * @param bool $condition
     * @return void
     * @throws UserException
     * @phpstan-ignore-next-line
     */
    private function _throwUserErrorAnyResponse(string $message, $redirect, bool $condition)
    {
        if ($condition) {
            if ($redirect !== false) {
                $this->_errorRedirect = $redirect;
            }
            throw new UserException($message);
        }
    }

    /**
     * При выполнении условия бросить обычную пользовательскую ошибку, используя дефолтное поведение
     *
     * @param string $message
     * @param bool $condition
     * @return void
     * @throws UserException
     */
    protected function _throwUserError(string $message, bool $condition = true)
    {
        $this->_throwUserErrorAnyResponse($message, false, $condition);
    }

    /**
     * При выполнении условия бросить обычную пользовательскую ошибку и сделать редирект
     *
     * @param string $message
     * @param string|array|Response $redirect
     * @param bool $condition
     * @return void
     * @throws UserException
     * @throws InternalException
     * @phpstan-ignore-next-line
     */
    protected function _throwUserErrorRedirect(string $message, $redirect, bool $condition = true)
    {
        if (empty($redirect)) {
            $this->_throwInternalError('Пустой параметр $redirect');
        }
        $this->_throwUserErrorAnyResponse($message, $redirect, $condition);
    }

    /**
     * При выполнении условия бросить обычную пользовательскую ошибку и не делать редирект
     *
     * @param string $message
     * @param bool $condition
     * @return void
     * @throws UserException
     */
    protected function _throwUserErrorNoRedirect(string $message, bool $condition = true)
    {
        $this->_throwUserErrorAnyResponse($message, null, $condition);
    }

    /**
     * Бросить обычную внутреннюю ошибку
     *
     * @param string $message
     * @param ?array $addInfo Доп информация об ошибке для sentry (SentryLog::KEY_ADD_INFO)
     * @param string|string[]|null $scope Scope для логирования ошибки
     * @return void
     * @throws InternalException
     * @phpstan-ignore-next-line
     */
    protected function _throwInternalError(string $message, ?array $addInfo = null, $scope = null)
    {
        throw InternalException::instance($message)->setLogAddInfo($addInfo)->setLogScope($scope);
    }


    /**
     * Задать, что текущий экшн должен возвращать json
     *
     * @return void
     */
    protected function _setIsJsonAction()
    {
        if (!$this->_isJsonAction()) {
            $this->request = $this->request->withParam('_ext', self::REQUEST_EXTENSION_JSON);
            Router::pushRequest($this->request);
        }
    }

    /**
     * Узнать, должен ли текущий экшн должен возвращать json
     *
     * @return bool
     */
    protected function _isJsonAction(): bool
    {
        return ($this->request->getParam('_ext') === self::REQUEST_EXTENSION_JSON);
    }

    /**
     * Возвращает ответ без ошибки и прерывает выполнение
     *
     * @param array|ValueObject $jsonData
     * @return ?Response
     * @phpstan-ignore-next-line
     */
    protected function _sendJsonOk($jsonData = []): ?Response
    {
        if ($jsonData instanceof ValueObject) {
            if (!$jsonData instanceof ApiResponse) {
                Log::error(get_class($jsonData) . ' не наследует ApiResponse');
            }
            $jsonData = $jsonData->toArray();
        }

        return $this->_sendJsonResponse(['status' => self::JSON_STATUS_OK] + $jsonData);
    }

    /**
     * Возвращает ответ с ошибкой, сообщением, и прерывает выполнение
     *
     * @param string $message
     * @param array $jsonData Дополнительные параметры если нужны
     * @return ?Response
     * @internal
     * @phpstan-ignore-next-line
     */
    protected function _sendJsonError(string $message, array $jsonData = []): ?Response
    {
        return $this->_sendJsonResponse(['status' => self::JSON_STATUS_ERROR, 'message' => $message] + $jsonData);
    }

    /**
     * Вернуть json-ответ с ошибкой, сообщение берётся из $exception->getMessage().
     * Исключения PHPUnit прокидываются дальше
     *
     * @param \Exception $exception
     * @param array $jsonData
     * @return ?Response
     * @throws PHP7ErrorException
     * @internal
     * @phpstan-ignore-next-line
     */
    protected function _sendJsonException(\Exception $exception, array $jsonData = []): ?Response
    {
        Env::checkTestException($exception);
        if ($exception instanceof UserException) {
            $message = $exception->getUserMessage();
        } else {
            $message = $exception->getMessage();
        }
        return $this->_sendJsonError($message, $jsonData);
    }

    /**
     * Отправляем JSON/JSONP ответ клиенту
     *
     * @param array $jsonArray
     * @return null
     * @internal У нас стандартизированный JSON: _sendJsonOk и _sendJsonError
     * @phpstan-ignore-next-line
     */
    protected function _sendJsonResponse(array $jsonArray)
    {
        if (empty($jsonArray)) { // Дабы null не слать
            $jsonArray['status'] = self::JSON_STATUS_OK;
        }

        $jsonArray['_serialize'] = array_keys($jsonArray);
        $jsonArray['_jsonOptions'] = JSON_UNESCAPED_UNICODE;

        $this->set($jsonArray);
        $this->viewBuilder()->setClassName('Json');
        $jsonPResponse = !empty($this->request->getQuery('callback'));
        if ($jsonPResponse) {
            $this->response = $this->response->withType('application/x-javascript');
            $this->set('_jsonp', true);
        } else {
            $this->response = $this->response->withType('application/json');
        }
        return null;
    }

    /**
     * Отправка текстового ответа без использования view, поддержка режима тестирования
     *
     * @param string $text
     * @param string $contentType
     * @return ?Response
     */
    protected function _sendTextResponse(string $text, string $contentType = self::REQUEST_EXTENSION_DEFAULT): ?Response
    {
        $this->response = $this->response->withType($contentType);
        return $this->response->withStringBody($text);
    }
}
