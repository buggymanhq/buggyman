<?php
/**
 * @project Buggyman
 */

namespace Buggyman;

use Exception;

class Buggyman
{

    /**
     * @var string
     */
    protected static $token;

    /**
     * @var array
     */
    protected static $metaFromServer
        = array(
            'REMOTE_ADDR',
            'SERVER_NAME',
            'HTTP_HOST',
            'HTTP_REFERER',
            'REQUEST_URI',
            'HTTP_USER_AGENT'
        );

    /**
     * @var array
     */
    protected static $customMeta = array();

    /**
     * @var int
     */
    protected static $errorLevel = null;

    /**
     * @var array
     */
    protected static $storage = array();

    /**
     * @var string
     */
    protected static $root;

    /**
     * @var Buggyman
     */
    protected static $instance;

    /**
     * Init functions
     */
    public static function init()
    {
        if (!self::$instance) {
            self::$instance = new static();
            set_exception_handler(array(__CLASS__, 'reportException'));
            set_error_handler(array(__CLASS__, 'reportError'), self::getErrorLevel());
            register_shutdown_function(array(__CLASS__, 'shutdown'));
        }
    }

    /**
     * Report exception to buggyman
     *
     * @param Exception $exception
     */
    public static function reportException(Exception $exception)
    {
        static::$storage[] = self::exceptionToArray($exception);
    }

    /**
     * @param int $code
     * @param string $title
     * @param string $file
     * @param int $line
     */
    public static function reportError($code, $title, $file, $line)
    {
        $data = array();
        $data['message'] = $title;
        $data['code'] = $code;
        $data['line'] = $line;
        $data['file'] = $file;
        $data['exception'] = self::codeToException($code);
        $data['stack'] = self::traceToString(self::trace());
        $data['meta'] = self::getMeta();

        static::$storage[] = $data;
    }

    /**
     * @param $reports
     */
    public static function sendReport($reports)
    {
        $data = json_encode($reports);

        $curl = curl_init('http://api.buggyman.io/v1/report?token=' . self::getToken());
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        curl_exec($curl);
    }

    /**
     *
     * @param Exception $exception
     *
     * @return array
     */
    public static function exceptionToArray(Exception $exception)
    {
        $data = array();
        $data['message'] = $exception->getMessage();
        $data['code'] = $exception->getCode();
        $data['file'] = $exception->getFile();
        $data['line'] = $exception->getLine();
        $data['stack'] = $exception->getTraceAsString();
        $data['exception'] = get_class($exception);
        $data['meta'] = self::getMeta();
        $data['root'] = self::getRoot();

        if ($exception->getPrevious()) {
            $data['previous'] = static::exceptionToArray($exception->getPrevious());
        }

        return $data;
    }


    /**
     * @param int $skip
     *
     * @return array
     */
    public static function trace($skip = 2)
    {
        $trace = debug_backtrace();
        while ($skip--) {
            array_shift($trace);
        }

        return $trace;
    }

    /**
     * @param array $trace
     *
     * @return string
     */
    public static function traceToString($trace)
    {
        if (!is_array($trace)) {
            return '';
        }

        $result = array();
        foreach ($trace as $index => $line) {
            if (is_array($line['args'])) {
                $params = static::paramsToString($line['args']);
            } else {
                $params = '';
            }

            if (isset($line['file'])) {
                $result[] = sprintf(
                    "#%d %s:%d - %s(%s)", $index, $line['file'], $line['line'], $line['function'], $params
                );
            } else {
                $result[] = sprintf("#%d - %s(%s)", $index, $line['function'], $params);
            }
        }

        return implode(PHP_EOL, $result);
    }

    /**
     * @param array $params
     *
     * @return string
     */
    protected static function paramsToString(array $params)
    {
        $params = array_map(array(__CLASS__, 'paramToString'), $params);
        return implode(', ', $params);
    }

    /**
     * @param mixed $param
     *
     * @return string
     */
    protected static function paramToString($param)
    {
        if (is_string($param)) {
            return '"' . $param . '"';
        }

        if (is_numeric($param)) {
            return $param;
        }

        if (is_null($param)) {
            return 'NULL';
        }

        if (is_bool($param)) {
            return $param ? 'TRUE' : 'FALSE';
        }

        if (is_resource($param)) {
            return '#resource';
        }

        if (is_callable($param)) {
            if (is_string($param)) {
                return $param;
            }

            if (is_array($param)) {
                if (is_string($param[0])) {
                    return $param[0] . '::' . $param[1];
                } else {
                    return get_class($param[0]) . '->' . $param[1];
                }
            }

            return 'Closure';
        }

        if (is_array($param)) {
            return 'Array(' . count($param) . ')';
        }

        if (is_object($param)) {
            return get_class($param);
        }

        return 'unknown';
    }

    /**
     * @param int $code
     *
     * @return string
     */
    protected static function codeToException($code)
    {
        $codes = array(
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_NOTICE => 'E_NOTICE',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        );

        if (isset($codes[$code])) {
            return $codes[$code];
        }

        return 'Unknown';
    }

    /**
     * @return array
     */
    protected static function getMeta()
    {
        $meta = array();
        foreach (self::getMetaFromServer() as $key) {
            if (isset($_SERVER[$key])) {
                $meta[$key] = $_SERVER[$key];
            }
        }

        $meta = array_merge($meta, self::getCustomMeta());

        return $meta;
    }

    /**
     * @param string $meta
     */
    public static function addMetaFromServer($meta)
    {
        self::$metaFromServer[] = $meta;
    }

    /**
     * @param array $metaFromServer
     */
    public static function setMetaFromServer($metaFromServer)
    {
        self::$metaFromServer = $metaFromServer;
    }

    /**
     * @return array
     */
    public static function getMetaFromServer()
    {
        return self::$metaFromServer;
    }

    /**
     * @param array $customMeta
     */
    public static function setCustomMeta($customMeta)
    {
        self::$customMeta = $customMeta;
    }

    /**
     * @return array
     */
    public static function getCustomMeta()
    {
        return self::$customMeta;
    }

    /**
     * @param string $key
     * @param string $value
     */
    public static function addCustomMeta($key, $value)
    {
        self::$customMeta[$key] = $value;
    }

    /**
     * @param int $errorLevel
     */
    public static function setErrorLevel($errorLevel)
    {
        self::$errorLevel = $errorLevel;
    }

    /**
     * @return int
     */
    public static function getErrorLevel()
    {
        if (null === self::$errorLevel) {
            self::$errorLevel = E_ALL | E_STRICT;
        }
        return self::$errorLevel;
    }

    /**
     * Set value of Token
     *
     * @param string $token
     */
    public static function setToken($token)
    {
        self::$token = $token;
    }

    /**
     * Return value of Token
     *
     * @return string
     */
    public static function getToken()
    {
        return self::$token;
    }

    /**
     * Set value of Root
     *
     * @param string $root
     */
    public static function setRoot($root)
    {
        self::$root = $root;
    }

    /**
     * Return value of Root
     *
     * @return string
     */
    public static function getRoot()
    {
        return self::$root;
    }

    /**
     * Check error
     */
    public static function shutdown()
    {
        $error = error_get_last();

        if (!is_null($error)) {
            if ($error['type'] == E_ERROR) {
                self::reportError($error['type'], $error['message'], $error['file'], $error['line']);
            }
        }

        if (count(self::$storage)) {
            self::sendReport(self::$storage);
        }
    }

    public function __destruct()
    {
        if (count(self::$storage)) {
            self::sendReport(self::$storage);
        }
    }

}