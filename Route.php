<?php

/**
 * 语言前缀路由：把 /en/... 解析回 Typecho 原生路由。
 *
 * 语言标识只占用路径第一段。匹配成功后把剩余路径交给原生路由表，
 * 再把路由参数写回当前请求，因此主题、归档、评论等逻辑完全复用。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage;

use Typecho\Config;
use Typecho\Router;
use Typecho\Widget\Exception as WidgetException;
use Widget\Archive;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Route extends Archive
{
    /**
     * 插件自己的路由名称前缀，内部匹配时必须跳过，避免递归。
     */
    private const OWN_ROUTE_PREFIXES = ['ml_', 'multilang_'];

    /**
     * @param Config $parameter
     * @throws WidgetException
     */
    protected function initParameter(Config $parameter)
    {
        parent::initParameter($parameter);

        $routeName = (string)(Router::$current ?? '');
        $lang = $this->request->get('lang');
        $lang = is_string($lang) ? strtolower(trim($lang)) : '';

        if (!isset(Plugin::languageMap()[$lang])) {
            // 交给 Router::dispatch 继续尝试后面的原生路由。
            throw new WidgetException('Language not found', 404);
        }

        Plugin::forceLanguage($lang);

        $innerPath = '/';
        if ($routeName === 'ml_lang_path') {
            $path = $this->request->get('path');
            if (is_string($path) && trim($path, '/') !== '') {
                $innerPath = '/' . trim($path, '/');
            }
        }

        $match = self::matchInnerPath($innerPath);
        if ($match === null || !is_a($match['widget'], Archive::class, true)) {
            // 非内容组件由 ml_do / ml_feed 等专用前缀路由处理。
            throw new WidgetException('Path not found', 404);
        }

        foreach ($match['params'] as $name => $value) {
            $this->request->setParam($name, $value);
        }

        // 父类已按 ml_lang 记录当前归档名；这里改成真正的原生路由名，
        // 让 Archive 使用正确的 handle。checkPermalink 保持开启，
        // 这样 /en/archives/1-start 会 301 到真正的 /en/default/1-start。
        $parameter->type = $match['name'];
    }

    /**
     * 在原始路由表中匹配去掉语言前缀后的路径。
     *
     * @param string $path
     * @return array{name:string,widget:string,params:array<string,string>}|null
     */
    private static function matchInnerPath(string $path): ?array
    {
        $routingTable = Options::alloc()->routingTable[0] ?? [];

        foreach ($routingTable as $name => $route) {
            if (self::isOwnRoute((string)$name) || empty($route['regx'])) {
                continue;
            }

            if (preg_match($route['regx'], $path, $matches)) {
                array_shift($matches);
                $params = [];
                foreach ($route['params'] ?? [] as $index => $param) {
                    $params[$param] = (string)($matches[$index] ?? '');
                }

                return [
                    'name'   => (string)$name,
                    'widget' => (string)($route['widget'] ?? ''),
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    private static function isOwnRoute(string $name): bool
    {
        foreach (self::OWN_ROUTE_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
