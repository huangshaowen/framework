<?php

namespace framework\core;

/**
 * 异常、错误
 */
class ErrorOrException {

    /**
     * 致命错误处理
     */
    public static function FatalError() {
        $last_error = error_get_last();
        if (isset($last_error) && ($last_error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING))) {
            self::ErrorHandle($last_error['type'], $last_error['message'], $last_error['file'], $last_error['line']);
        }
    }

    /**
     * 错误处理
     * @param type $errno
     * @param type $errstr
     * @param type $errfile
     * @param type $errline
     * @return boolean
     */
    public static function ErrorHandle($errno, $errstr, $errfile, $errline) {

        $errfile = str_replace($_SERVER['DOCUMENT_ROOT'], '', $errfile);

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

        switch ($errno) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                // 抛出异常，记录到日志
                $errnostr = isset($errortype[$errno]) ? $errortype[$errno] : 'Unknonw';
                $s = "[{$errnostr}] : {$errstr} in File {$errfile}, Line: {$errline}";
                Log::getInstance()->emerg($s);

                $msg = "500 Internal Server Error {$errno} {$s}";

                if ((Request::getInstance()->isAjax() == true) || Request::getInstance()->isCli() == true) {
                    $json = ['ret' => 500, 'data' => null, 'msg' => $msg];
                    Response::getInstance()->json($json);
                } else {
                    /* Web　显示 */
                    self::show_php_error($msg, $errfile, $errline);
                }


                break;
            case E_WARNING:
                // 记录到日志
                $errnostr = isset($errortype[$errno]) ? $errortype[$errno] : 'Unknonw';
                $s = "[{$errnostr}] : {$errstr} in File {$errfile}, Line: {$errline}";
                Log::getInstance()->warning($s);
                break;
            case E_NOTICE:
                // 记录到日志
                $errnostr = isset($errortype[$errno]) ? $errortype[$errno] : 'Unknonw';
                $s = "[{$errnostr}] : {$errstr} in File {$errfile}, Line: {$errline}";
                Log::getInstance()->notice($s);
                break;
            default:
                break;
        }
        return false;
    }

    /**
     * 应用异常处理
     * @param type $exception
     */
    public static function AppException($exception) {
        $errfile = str_replace($_SERVER['DOCUMENT_ROOT'], '', $exception->getFile());

        /* 记录日志 */
        $msg = $exception->getMessage() . ' File: ' . $errfile . ' [' . $exception->getLine() . ']';
        Log::getInstance()->emerg($msg);

        if ((Request::getInstance()->isAjax() == true) || Request::getInstance()->isCli() == true) {
            $json = ['ret' => $exception->getCode(), 'data' => null, 'msg' => $exception->getMessage()];
            Response::getInstance()->json($json);
        } else {
            /* Web　显示 */
            self::show_exception($exception);
        }
    }

    /**
     * 404 Error Handler
     */
    public static function show_404() {

        $heading = '404 Page Not Found';
        $message = 'The page you requested was not found.';

        self::show_error($heading, $message, 'error_404', 404);
        exit(4);
    }

    /**
     * 一般错误提示信息
     *
     * Takes an error message as input (either as a string or an array)
     * and displays it using the specified template.
     *
     * @param	string		$heading	Page heading
     * @param	string|string[]	$message	Error message
     * @param	string		$template	Template name
     * @param 	int		$status_code	(default: 500)
     *
     * @return	string	Error page output
     */
    public static function show_error($heading, $message, $template = 'error_general', $status_code = 500) {

        if ((Request::getInstance()->isAjax() == true) || Request::getInstance()->isCli() == true) {
            $json = [
                'ret' => $status_code,
                'data' => null,
                'msg' => $message
            ];
            Response::getInstance()->json($json);
            return;
        }

        $templates_path = Config::getInstance()->get('error_views_path');

        if (empty($templates_path)) {
            $templates_path = __DIR__ . '/../tpl/';
        }

        if (Request::getInstance()->isCli() == true) {
            $message = "\t" . (is_array($message) ? implode("\n\t", $message) : $message);
        } else {
            Response::getInstance()->clear()->status($status_code);
            $message = '<p>' . (is_array($message) ? implode('</p><p>', $message) : $message) . '</p>';
        }


        View::getInstance()->assign('heading', $heading);
        View::getInstance()->assign('message', $message);

        $buffer = View::getInstance()->fetch($template . '.tpl.php', $templates_path);

        Response::getInstance()->write($buffer)->send();
        // 中止执行  避免出错后继续执行
        exit();
    }

    /**
     * 展示异常
     * @param type $exception
     * @return type
     */
    public static function show_exception($exception) {
        $templates_path = Config::getInstance()->get('error_views_path');

        if (empty($templates_path)) {
            $templates_path = __DIR__ . '/../tpl/';
        }

        $message = $exception->getMessage();

        if (empty($message)) {
            $message = '(null)';
        }

        View::getInstance()->assign('message', $message);
        View::getInstance()->assign('exception', $exception);

        $buffer = View::getInstance()->fetch('error_exception.tpl.php', $templates_path);

        Response::getInstance()->write($buffer)->send();
        // 中止执行  避免出错后继续执行
        exit();
    }

    /**
     * php 错误 web 展示
     */
    public static function show_php_error($message, $filepath, $line) {
        $templates_path = Config::getInstance()->get('error_views_path');

        if (empty($templates_path)) {
            $templates_path = __DIR__ . '/../tpl/';
        }

        View::getInstance()->assign('message', $message);
        View::getInstance()->assign('filepath', $filepath);
        View::getInstance()->assign('line', $line);

        $buffer = View::getInstance()->fetch('error_php.tpl.php', $templates_path);

        Response::getInstance()->write($buffer)->send();
        // 中止执行  避免出错后继续执行
        exit();
    }

}
