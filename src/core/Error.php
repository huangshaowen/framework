<?php

namespace framework\core;

use framework\core\exception\ErrorException;
use framework\core\exception\Handle;
use Throwable;

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
    public static function appException(Throwable $e) {
        /* 普通异常 */
        self::getExceptionHandler()->report($e);

        if (PHP_SAPI == 'cli') {
            self::getExceptionHandler()->renderForCLI($e);
        } else {
            self::getExceptionHandler()->render($e);
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

        self::getExceptionHandler()->report($exception);
    }

    /**
     * Shutdown Handler
     * @access public
     */
    public static function appShutdown(): void {

        if (!is_null($last_error = error_get_last()) && self::isFatal($last_error['type'])) {
            $exception = new ErrorException($last_error['type'], $last_error['message'], $last_error['file'], $last_error['line']);
            self::appException($exception);
        }
    }

    /**
     * 确定错误类型是否致命
     *
     * @access protected
     * @param int $type
     * @return bool
     */
    public static function isFatal(int $type): bool {
        return in_array($type, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE]);
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
