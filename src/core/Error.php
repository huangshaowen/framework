<?php

namespace framework\core;

use framework\core\exception\ErrorException;
use framework\core\exception\Handle;
use framework\core\exception\ThrowableError;

class Error {

    /**
     * 注册异常处理
     * @access public
     * @return void
     */
    public static function register() {
        error_reporting(E_ALL);
        set_error_handler([__CLASS__, 'appError']);
        set_exception_handler([__CLASS__, 'appException']);
        register_shutdown_function([__CLASS__, 'appShutdown']);
    }

    /**
     * Exception Handler
     * @access public
     * @param  \Exception|\Throwable $e
     */
    public static function appException($e) {

        if (!$e instanceof \Exception) {
            $e = new ThrowableError($e);
        }

        if ($e instanceof ThrowableError) {
            /* 致命错误 */
            if (in_array($e->getSeverity(), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                
                self::getExceptionHandler()->report_error($e);
                
                return self::getExceptionHandler()->render_error($e);
            }
        }

        if ($e instanceof \Exception) {
            /* 普通异常 */
            self::getExceptionHandler()->report($e);

            if (PHP_SAPI == 'cli') {
                self::getExceptionHandler()->renderForCLI($e);
            } else {
                self::getExceptionHandler()->render($e)->send();
            }
        }
    }

    /**
     * Error Handler
     * @access public
     * @param  integer $errno   错误编号
     * @param  integer $errstr  详细错误信息
     * @param  string  $errfile 出错的文件
     * @param  integer $errline 出错行号
     * @throws ErrorException
     */
    public static function appError($errno, $errstr, $errfile = '', $errline = 0) {
        $exception = new ErrorException($errno, $errstr, $errfile, $errline);

        $errortype = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_DEPRECATED => 'Deprecated',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_USER_DEPRECATED => 'User Deprecated',
            E_STRICT => 'Runtime Notice',
            E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
        ];

        // 判断错误级别，决定是否退出。
        $errnostr = isset($errortype[$errno]) ? $errortype[$errno] : 'Unknonw';
        $s = "[{$errnostr}] : {$errstr} in File {$errfile}, Line: {$errline}";

        switch ($errno) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                // 抛出异常，记录到日志
                throw $exception;
                break;
            case E_WARNING:
                // 记录到日志
                Log::getInstance()->warning($s);
                break;
            case E_NOTICE:
                // 记录到日志
                Log::getInstance()->notice($s);
                break;
            default:
                Log::getInstance()->alert($s);
                break;
        }

        self::getExceptionHandler()->report($exception);
    }

    /**
     * Shutdown Handler
     * @access public
     */
    public static function appShutdown(): void {

        $last_error = error_get_last();
        if (in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new ErrorException($last_error['type'], $last_error['message'], $last_error['file'], $last_error['line']);
            self::appException($exception);
        }
    }

    /**
     * Get an instance of the exception handler.
     *
     * @access public
     * @return Handle
     */
    public static function getExceptionHandler() {
        static $handle;

        if (!$handle) {
            // 异常处理handle
            $handle = new \framework\core\exception\Handle();
        }

        return $handle;
    }

}
