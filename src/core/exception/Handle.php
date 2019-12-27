<?php

namespace framework\core\exception;

use framework\core\Exception;
use framework\core\Request;
use framework\core\Response;
use framework\core\Config;

class Handle {

    protected $render;
    protected $ignoreReport = [
        '\\framework\\core\\exception\\HttpException',
    ];

    public function setRender($render) {
        $this->render = $render;
    }

    /**
     * Report or log an exception.
     * @param Exception $exception
     */
    public function report(Exception $exception) {

        if ($this->isIgnoreReport($exception)) {
            return;
        }

        // 收集异常数据
        $data = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'message' => $this->getMessage($exception),
            'code' => $this->getCode($exception),
        ];
        $log = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";

        $log .= "\r\n" . $exception->getTraceAsString();

        switch ($data['code']) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                // 记录到日志
                \framework\core\Log::getInstance()->error($log);
                break;
            case E_WARNING:
                // 记录到日志
                \framework\core\Log::getInstance()->warning($log);
                break;
            case E_NOTICE:
                // 记录到日志
                \framework\core\Log::getInstance()->notice($log);
                break;
            default:
                \framework\core\Log::getInstance()->info($log);
                break;
        }
    }

    public function report_error(ThrowableError $e) {

        // 收集异常数据
        $data = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage(),
            'code' => $e->getSeverity(),
        ];
        $log = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";

        $log .= "\r\n" . $e->getTraceAsString();

        switch ($data['code']) {
            case E_ERROR:
            case E_PARSE:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                // 记录到日志
                \framework\core\Log::getInstance()->error($log);
                break;
            case E_WARNING:
                // 记录到日志
                \framework\core\Log::getInstance()->warning($log);
                break;
            case E_NOTICE:
                // 记录到日志
                \framework\core\Log::getInstance()->notice($log);
                break;
            default:
                \framework\core\Log::getInstance()->info($log);
                break;
        }
    }

    protected function isIgnoreReport(Exception $exception) {
        foreach ($this->ignoreReport as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param  \Exception $e
     * @return Response
     */
    public function render(Exception $e) {
        if ($this->render && $this->render instanceof \Closure) {
            $result = call_user_func_array($this->render, [$e]);

            if ($result) {
                return $result;
            }
        }

        return $this->show_exception($e);
    }

    public function render_error(ThrowableError $e) {
        if ($this->render && $this->render instanceof \Closure) {
            $result = call_user_func_array($this->render, [$e]);

            if ($result) {
                return $result;
            }
        }

        $data = [
            'code' => $e->getCode(),
            'filepath' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage(),
        ];

        /* 隐藏目录 */
        $data['filepath'] = str_replace($_SERVER['DOCUMENT_ROOT'], '', $data['filepath']);

        if (Request::getInstance()->isAjax() == true) {
            $json = json_encode(['ret' => $data['code'], 'data' => null, 'msg' => $data['message']]);
            return Response::getInstance()->clear()->contentType("application/json")->write($json)->send();
        }

        //保留一层
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        /* 模板展示Html */
        ob_start();
        extract($data);
        $templates_path = Config::getInstance()->get('error_views_path');
        if (empty($templates_path)) {
            $templates_path = __DIR__ . '/../../tpl/';
        }
        $file = $templates_path . 'error_php.tpl.php';
        include($file);

        // 获取并清空缓存
        $content = ob_get_clean();
        return Response::getInstance()->status(200)->write($content)->send();
    }

    public function renderForCLI(Exception $e) {
        
    }

    /**
     * @access protected
     * @param  Exception $exception
     * @return Response
     */
    protected function show_exception(Exception $exception) {

        // 收集异常数据
        $data = [
            'name' => get_class($exception),
            'filepath' => $exception->getFile(),
            'line' => $exception->getLine(),
            'message' => $this->getMessage($exception),
            'code' => $this->getCode($exception),
        ];

        if (Request::getInstance()->isAjax() == true) {
            $json = json_encode(['ret' => $data['code'], 'data' => null, 'msg' => $data['message']]);
            return Response::getInstance()->clear()->contentType("application/json")->write($json);
        }

        //保留一层
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        /* 隐藏目录 */
        $data['filepath'] = str_replace($_SERVER['DOCUMENT_ROOT'], '', $data['filepath']);

        /* 模板展示Html */
        ob_start();
        extract($data);
        $templates_path = Config::getInstance()->get('error_views_path');
        if (empty($templates_path)) {
            $templates_path = __DIR__ . '/../../tpl/';
        }
        $file = $templates_path . 'error_php.tpl.php';
        include($file);

        // 获取并清空缓存
        $content = ob_get_clean();
        return Response::getInstance()->status(200)->write($content);
    }

    /**
     * 获取错误编码
     * ErrorException则使用错误级别作为错误编码
     * @access protected
     * @param  \Exception $exception
     * @return integer                错误编码
     */
    protected function getCode(Exception $exception) {
        $code = $exception->getCode();

        if (!$code && $exception instanceof ErrorException) {
            $code = $exception->getSeverity();
        }

        return $code;
    }

    /**
     * 获取错误信息
     * ErrorException则使用错误级别作为错误编码
     * @access protected
     * @param  \Exception $exception
     * @return string                错误信息
     */
    protected function getMessage(Exception $exception) {
        return $exception->getMessage();
    }

    /**
     * 获取出错文件内容
     * 获取错误的前9行和后9行
     * @access protected
     * @param  \Exception $exception
     * @return array                 错误文件内容
     */
    protected function getSourceCode(Exception $exception) {
        // 读取前9行和后9行
        $line = $exception->getLine();
        $first = ($line - 9 > 0) ? $line - 9 : 1;

        try {
            $contents = file($exception->getFile());
            $source = [
                'first' => $first,
                'source' => array_slice($contents, $first - 1, 19),
            ];
        } catch (Exception $e) {
            $source = [];
        }

        return $source;
    }

    /**
     * 获取异常扩展信息
     * 用于非调试模式html返回类型显示
     * @access protected
     * @param  \Exception $exception
     * @return array                 异常类定义的扩展数据
     */
    protected function getExtendData(Exception $exception) {
        $data = [];

        if ($exception instanceof \framework\core\Exception) {
            $data = $exception->getData();
        }

        return $data;
    }

    /**
     * 获取常量列表
     * @access private
     * @return array 常量列表
     */
    private static function getConst() {
        $const = get_defined_constants(true);

        return isset($const['user']) ? $const['user'] : [];
    }

}
