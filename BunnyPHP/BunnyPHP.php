<?php
/**
 * Created by PhpStorm.
 * User: IvanLu
 * Date: 2018/7/28
 * Time: 17:42
 */

namespace BunnyPHP;

use ReflectionClass;
use ReflectionException;

class BunnyPHP
{
    const BUNNY_VERSION = '2.2.6';
    const MODE_NORMAL = 0;
    const MODE_API = 1;
    const MODE_AJAX = 2;
    const MODE_CLI = 3;

    /**
     * @var $config Config
     */
    protected $config;
    protected $mode = BunnyPHP::MODE_NORMAL;
    protected $apps = [];

    private static $app;
    private static $storage;
    private static $cache;
    private static $request;
    private static $logger;

    private $variable = [];
    private $container = [];

    public function __construct($m = BunnyPHP::MODE_NORMAL)
    {
        $this->mode = $m;
        BunnyPHP::$app = $this;
    }

    public function run()
    {
        spl_autoload_register([$this, 'loadClass']);
        $this->setReporting();
        $this->removeMagicQuotes();
        $this->unregisterGlobals();
        $this->loadConfig();
        $this->route();
    }

    private function route()
    {
        $appName = '';
        if ($this->mode == BunnyPHP::MODE_CLI) {
            $cli_arg = array_slice($_SERVER['argv'], 1);
            if (in_array($cli_arg[0], array_keys($this->apps))) {
                $appName = $cli_arg[0];
                array_shift($cli_arg);
            }
            $controllerName = !empty($cli_arg[0]) ? ucfirst($cli_arg[0]) : $this->config->get('controller', 'Index');
            $actionName = !empty($cli_arg[1]) ? $cli_arg[1] : 'index';
            $param = array_slice($cli_arg, 2);
        } else {
            $controllerName = !empty($_GET['mod']) ? ucfirst($_GET['mod']) : $this->config->get('controller', 'Index');
            $actionName = !empty($_GET['action']) ? $_GET['action'] : 'index';
            $request_url = $_SERVER['REQUEST_URI'];
            $position = strpos($request_url, '?');
            $request_url = ($position === false) ? $request_url : substr($request_url, 0, $position);
            $request_url = trim($request_url, '/');
            $param = [];
            if ($request_url && !in_array(strtolower($request_url), ['index.php', 'api.php', 'ajax.php'])) {
                $url_array = explode('/', $request_url);
                $url_array = array_filter($url_array);
                if (strtolower($url_array[0]) == "api" || strtolower($url_array[0]) == "api.php") {
                    array_shift($url_array);
                    $this->mode = BunnyPHP::MODE_API;
                } elseif (strtolower($url_array[0]) == "ajax" || strtolower($url_array[0]) == "ajax.php") {
                    array_shift($url_array);
                    $this->mode = BunnyPHP::MODE_AJAX;
                } elseif (strtolower($url_array[0]) == "index.php") {
                    array_shift($url_array);
                }
                if (in_array($url_array[0], array_keys($this->apps))) {
                    $appName = $url_array[0];
                    array_shift($url_array);
                }
                $controllerName = $url_array ? ucfirst($url_array[0]) : $controllerName;
                array_shift($url_array);
                $actionName = $url_array ? $url_array[0] : $actionName;
                array_shift($url_array);
                $param = $url_array ? $url_array : [];
            }
        }
        $prefix = $this->config->get('namespace', '');
        if (!empty($appName)) {
            $appConf = $this->apps[$appName];
            $prefix = isset($appConf['namespace']) ? $appConf['namespace'] : '';
            if (isset($appConf['path'])) define('SUB_APP_PATH', $appConf['path']);
        }
        if ($prefix) {
            $controllerPrefix = $prefix . '\\Controller\\';
        } else {
            $controllerPrefix = '';
        }
        $controller = $controllerPrefix . $controllerName . 'Controller';
        if (!class_exists($controller)) {
            if (!class_exists('OtherController')) {
                View::error(['ret' => '-2', 'status' => 'mod does not exist', 'tp_error_msg' => "模块{$controller}不存在"], $this->mode);
            } else {
                $controller = 'OtherController';
            }
        }
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtolower($_SERVER['REQUEST_METHOD']) : 'cli';
        $actionFunc = 'ac_' . $actionName . '_' . $request_method;
        if (method_exists($controller, $actionFunc)) {
            $dispatch = new $controller($controllerName, $actionName, $this->mode);
            $this->callAction($controller, $dispatch, $actionFunc, $param);
        } elseif (method_exists($controller, 'ac_' . $actionName)) {
            $dispatch = new $controller($controllerName, $actionName, $this->mode);
            $this->callAction($controller, $dispatch, 'ac_' . $actionName, $param);
        } elseif (method_exists($controller, 'other')) {
            $dispatch = new $controller($controllerName, $actionName, $this->mode);
            $this->callAction($controller, $dispatch, 'other', $param);
        } else {
            View::error(['ret' => '-3', 'status' => 'action does not exist', 'tp_error_msg' => "Action {$actionName}不存在"], $this->mode);
        }
    }

    private function callAction($controller, $dispatch, $action, $pathParam = [])
    {
        try {
            $class = new ReflectionClass($controller);
            $method = $class->getMethod($action);
            $pathValue = [];
            $assignValue = [];
            if ($docComment = $method->getDocComment()) {
                list($flag, $pathValue, $assignValue) = $this->processAnnotation($docComment, $pathParam);
                if ($flag == Filter::STOP) return;
            }
            call_user_func_array([$dispatch, 'assignAll'], [$assignValue]);
            if ($method->getNumberOfParameters() > 0) {
                $params = $method->getParameters();
                $value = [];
                foreach ($params as $param) {
                    $type = '' . $param->getType();
                    $name = '' . $param->getName();
                    if ($param->isOptional()) {
                        $defVal = $param->getDefaultValue();
                    } else {
                        $defVal = '';
                    }
                    if (!empty($type)) {
                        if ($type == 'array') {
                            $value[] = $pathParam;
                        } elseif ($type == 'string') {
                            $p = isset($pathValue[$name]) ? $pathValue[$name] : $defVal;
                            $p = isset($_REQUEST[$name]) ? $_REQUEST[$name] : $p;
                            $value[] = $p;
                        } elseif ($type == 'int') {
                            $p = isset($pathValue[$name]) ? intval($pathValue[$name]) : $defVal;
                            $p = isset($_REQUEST[$name]) ? intval($_REQUEST[$name]) : $p;
                            $value[] = $p;
                        } elseif ($type == 'float') {
                            $p = isset($pathValue[$name]) ? floatval($pathValue[$name]) : $defVal;
                            $p = isset($_REQUEST[$name]) ? floatval($_REQUEST[$name]) : $p;
                            $value[] = $p;
                        } else {
                            if (!isset($this->container[$type])) {
                                $this->container[$type] = new $type();
                            }
                            $value[] = $this->container[$type];
                        }
                    } else {
                        $p = isset($pathValue[$name]) ? $pathValue[$name] : $defVal;
                        $p = isset($_REQUEST[$name]) ? $_REQUEST[$name] : $p;
                        $value[] = $p;
                    }
                }
                call_user_func_array([$dispatch, $action], $value);
            } else {
                call_user_func_array([$dispatch, $action], [$pathParam]);
            }
        } catch (ReflectionException $e) {
            call_user_func_array([$dispatch, $action], [$pathParam]);
        }
    }

    private function processAnnotation($docComment, $pathParam = [])
    {
        /**
         * @var $filter Filter
         */
        $pattern = "#(@[a-zA-Z]+\s*[a-zA-Z0-9, ()_].*)#";
        $pathValue = [];
        $assignValue = [];
        $prefix = $this->config->get('namespace', '');
        if ($prefix) {
            $filterPrefix = $prefix . '\\Controller\\';
        } else {
            $filterPrefix = '';
        }
        if (preg_match_all($pattern, $docComment, $matches, PREG_PATTERN_ORDER)) {
            foreach ($matches[1] as $decorate) {
                if (strpos($decorate, '@filter') === 0) {
                    $filterInfo = explode(' ', trim($decorate));
                    array_filter($filterInfo);
                    array_shift($filterInfo);
                    $filterName = $filterPrefix . trim(ucfirst(array_shift($filterInfo))) . 'Filter';
                    $filter = new $filterName($this->mode);
                    $result = $filter->doFilter($filterInfo);
                    if ($result == Filter::STOP) {
                        return [Filter::STOP];
                    }
                    $assignValue = array_merge($assignValue, $filter->getVariable());
                } elseif (strpos($decorate, '@param') === 0) {
                    $patName = '/\$([\w]+)\s*/';
                    $patPath = '/path\(([0-9])(,(.*))?\)/';
                    if (preg_match($patName, $decorate, $matName)) {
                        if (preg_match($patPath, $decorate, $matPath)) {
                            if (isset($pathParam[intval($matPath[1])])) {
                                $pathValue[trim($matName[1])] = $pathParam[intval($matPath[1])];
                            } else {
                                if (isset($matPath[3])) {
                                    $pathValue[trim($matName[1])] = $matPath[3];
                                }
                            }
                        }
                    }
                }
            }
            return [Filter::NEXT, $pathValue, $assignValue];
        }
        return [Filter::NEXT];
    }

    public function get($key)
    {
        return isset($this->variable[$key]) ? $this->variable[$key] : null;
    }

    public function set($key, $value)
    {
        $this->variable[$key] = $value;
    }

    public static function app(): BunnyPHP
    {
        return self::$app;
    }

    public static function getStorage(): Storage
    {
        return self::$storage;
    }

    public static function getCache(): Cache
    {
        return self::$cache;
    }

    public static function getRequest(): Request
    {
        return self::$request;
    }

    public static function getLogger(): Logger
    {
        return self::$logger;
    }

    private function setReporting()
    {
        if (APP_DEBUG === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', 'On');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', 'Off');
            ini_set('log_errors', 'On');
        }
    }

    private function stripSlashesDeep($value)
    {
        $value = is_array($value) ? array_map([$this, 'stripSlashesDeep'], $value) : stripslashes($value);
        return $value;
    }

    private function removeMagicQuotes()
    {
        if (get_magic_quotes_gpc()) {
            $_GET = isset($_GET) ? $this->stripSlashesDeep($_GET) : '';
            $_POST = isset($_POST) ? $this->stripSlashesDeep($_POST) : '';
            $_COOKIE = isset($_COOKIE) ? $this->stripSlashesDeep($_COOKIE) : '';
            $_SESSION = isset($_SESSION) ? $this->stripSlashesDeep($_SESSION) : '';
        }
    }

    private function unregisterGlobals()
    {
        if (ini_get('register_globals')) {
            $array = array('_SESSION', '_POST', '_GET', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES');
            foreach ($array as $value) {
                foreach ($GLOBALS[$value] as $key => $var) {
                    if ($var === $GLOBALS[$key]) {
                        unset($GLOBALS[$key]);
                    }
                }
            }
        }
    }

    private function loadConfig()
    {
        $this->config = Config::load('config');
        define("TP_SITE_NAME", $this->config->get('site_name', 'BunnyPHP'));
        define("TP_SITE_URL", $this->config->get('site_url', 'localhost'));
        define("TP_SITE_REWRITE", $this->config->get('site_rewrite', true));

        if ($this->config->has('db')) {
            define('DB_TYPE', $this->config->get(['db', 'type'], 'mysql'));
            define('DB_HOST', $this->config->get(['db', 'host'], 'localhost'));
            define('DB_PORT', $this->config->get(['db', 'port'], '3306'));
            define('DB_NAME', $this->config->get(['db', 'database']));
            define('DB_USER', $this->config->get(['db', 'username']));
            define('DB_PASS', $this->config->get(['db', 'password']));
            define('DB_PREFIX', $this->config->get(['db', 'prefix']));
        }

        $storageName = 'BunnyPHP\\FileStorage';
        if ($this->config->has('storage')) {
            $storageName = ucfirst(($this->config->get(['storage', 'name'], 'BunnyPHP\\File')) . 'Storage');
        }
        BunnyPHP::$storage = new $storageName($this->config->get('storage', []));

        $cacheName = 'BunnyPHP\\FileCache';
        if ($this->config->has('cache')) {
            $cacheName = ucfirst(($this->config->get(['cache', 'name'], 'BunnyPHP\\File')) . 'Cache');
        }
        BunnyPHP::$cache = new $cacheName($this->config->get('cache', []));

        $loggerName = 'BunnyPHP\\FileLogger';
        if ($this->config->has('logger')) {
            $loggerName = ucfirst(($this->config->get(['logger', 'name'], 'BunnyPHP\\File')) . 'Logger');
        }
        BunnyPHP::$logger = new $loggerName($this->config->get('logger', []));

        if ($this->config->has('apps')) {
            $this->apps = $this->config->get('apps', []);
        }
        BunnyPHP::$request = new Request();
    }

    private static function getClassType($class)
    {
        $i = strlen($class) - 1;
        while ($i >= 0 && ($class[$i] < 'A' || $class[$i] > 'Z')) {
            $i--;
        }
        return substr($class, $i);
    }

    private static function loadClass($class)
    {
        $classA = explode('\\', $class);
        $class = array_pop($classA);
        $frameworkFile = __DIR__ . '/' . $class . '.php';
        $classType = strtolower(self::getClassType($class));
        $classFile = APP_PATH . 'app' . '/' . $classType . '/' . $class . '.php';
        if (defined('SUB_APP_PATH')) {
            $subClassFile = APP_PATH . 'app' . '/' . SUB_APP_PATH . '/' . $classType . '/' . $class . '.php';
            if (file_exists($subClassFile)) {
                include $subClassFile;
            }
        } else {
            if (file_exists($frameworkFile)) {
                include $frameworkFile;
            } elseif (file_exists($classFile)) {
                include $classFile;
            }
        }
    }
}
