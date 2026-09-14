<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 路由引擎 — GET/POST/ANY 路由注册、分组、参数提取、分发
 * @file app/Core/Router.php
 * @package app\Core
 */

namespace app\Core;

class Router
{
    protected $routes = [];
    protected $currentGroupPrefix = '';

    public function get($uri, $handler)
    {
        return $this->addRoute('GET', $uri, $handler);
    }

    public function post($uri, $handler)
    {
        return $this->addRoute('POST', $uri, $handler);
    }

    public function any($uri, $handler)
    {
        $this->addRoute('GET', $uri, $handler);
        $this->addRoute('POST', $uri, $handler);
        return $this;
    }

    public function group($prefix, $callback)
    {
        $previousPrefix = $this->currentGroupPrefix;
        $this->currentGroupPrefix = $prefix;
        $callback($this);
        $this->currentGroupPrefix = $previousPrefix;
    }

    protected function addRoute($method, $uri, $handler)
    {
        $uri = $this->currentGroupPrefix . '/' . trim($uri, '/');
        $uri = '/' . trim($uri, '/');
        $uri = $uri ?: '/';

        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $uri);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
        ];

        return $this;
    }

    public function dispatch()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // 剥离 BASE_PATH（二级目录支持）
        $base = \defined('BASE_PATH') ? \BASE_PATH : '';
        if ($base !== '' && \strpos($uri, $base) === 0) {
            $uri = \substr($uri, \strlen($base));
            if ($uri === '') $uri = '/';
        }

        // 支持查询参数 fallback: index.php?route=/forum
        // 限制：不允许通过 ?r= 访问后台路径，防止 URL 枚举
        if (($uri === '/' || $uri === '/index.php' || $uri === '') && !empty($_GET['r'])) {
            $r = $_GET['r'];
            // 格式校验：只允许字母数字下划线斜杠
            if (!\preg_match('/^[a-zA-Z0-9_\/-]+$/', $r)) {
                $r = '/';
            }
            // 不允许访问后台路径
            if (\strpos($r, 'admin') === 0 || \strpos($r, '/admin') === 0) {
                $r = '/';
            }
            $uri = '/' . \ltrim($r, '/');
        }

        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // 插件钩子：全局写操作拦截（封禁用户），在路由匹配前触发
        // 隔离：钩子异常不阻断后续正常路由（与 Controller::view 中 hook 的 try-catch 口径一致）
        try {
            \app\Helpers\Plugin::hook('route_before_dispatch');
        } catch (\Throwable $e) {
            \error_log('Plugin hook error (route_before_dispatch): ' . $e->getMessage());
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $handler = $route['handler'];

                if (is_array($handler)) {
                    list($class, $action) = $handler;
                    $controller = new $class();
                    return call_user_func_array([$controller, $action], $params);
                }

                if (is_callable($handler)) {
                    return call_user_func_array($handler, $params);
                }
            }
        }

        // 404
        http_response_code(404);
        if (file_exists(__DIR__ . '/../Views/errors/404.php')) {
            $view = new TemplateCompiler();
            $view->extend('main'); // 挂主布局，否则 404 页 section 内容无布局输出
            $view->display('errors/404');
        } else {
            echo '<h1>404 - Page Not Found</h1>';
        }
        exit;
    }
}
