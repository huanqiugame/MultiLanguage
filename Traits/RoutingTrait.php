<?php

/**
 * 语言路由、路径解析与跨语言 URL 生成。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Request;
use Typecho\Router;
use Utils\Helper;
use Widget\Options;
use TypechoPlugin\MultiLanguage\Route;
use TypechoPlugin\MultiLanguage\SwitchAction;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait RoutingTrait
{
    /**
     * @param string $path
     * @return string
     */
    private static function lastPathSegment(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $segment = end($segments);

        return is_string($segment) ? $segment : '';
    }

    /**
     * 确保本插件路由存在；升级插件但未重新启用时也能自动补全。
     */
    private static function ensureRoutes(): void
    {
        static $checked = false;

        if ($checked || PHP_SAPI === 'cli') {
            return;
        }
        $checked = true;

        $parsed = Options::alloc()->routingTable[0] ?? [];
        $missing = false;
        foreach (array_keys(self::routeDefinitions()) as $name) {
            if (!isset($parsed[$name])) {
                $missing = true;
                break;
            }
        }

        $obsolete = false;
        foreach (self::OBSOLETE_ROUTES as $name) {
            if (isset($parsed[$name])) {
                $obsolete = true;
                break;
            }
        }

        if (!$missing && !$obsolete) {
            return;
        }

        self::installRoutes();

        $options = Options::alloc();
        $raw = $options->routingTable;
        if (isset($raw[0])) {
            unset($raw[0]);
        }

        $parsed = (new \Typecho\Router\Parser($raw))->parse();
        $table = array_merge([$parsed], $raw);
        $options->routingTable = $table;
        Router::setRoutes($table);
    }

    /**
     * 把缺失的路由写回数据库。
     */
    private static function installRoutes(): void
    {
        foreach (self::OBSOLETE_ROUTES as $name) {
            $current = Options::alloc()->routingTable;
            $existing = $current[0] ?? $current;
            if (isset($existing[$name])) {
                Helper::removeRoute($name);
            }
        }

        foreach (self::routeDefinitions() as $name => $route) {
            $current = Options::alloc()->routingTable;
            $existing = $current[0] ?? $current;
            if (isset($existing[$name])) {
                continue;
            }

            $after = $route['after'];
            if (!isset($existing[$after])) {
                $after = 'index';
            }

            Helper::addRoute($name, $route['url'], $route['widget'], $route['action'], $after);
        }
    }

    /**
     * 确保语言切换 action 已注册；升级插件但未重新启用时自动补全。
     */
    private static function ensureAction(): void
    {
        static $checked = false;

        if ($checked || PHP_SAPI === 'cli') {
            return;
        }
        $checked = true;

        $actionTable = Options::alloc()->actionTable ?? [];
        if (isset($actionTable[self::ACTION_NAME])) {
            return;
        }

        Helper::addAction(self::ACTION_NAME, SwitchAction::class);
    }

    /**
     * Route 子类的插件组件名与 Widget_Archive 不同，运行时补注册一次。
     *
     * 早期版本把 Route 钩子写进过 plugins 配置，会重复输出 SEO 标签；
     * 这里先清掉历史记录，再统一由运行时注册一份。
     */
    private static function registerRouteHooks(): void
    {
        if (self::$routeHooksRegistered) {
            return;
        }
        self::$routeHooksRegistered = true;

        self::cleanupRouteHooks();

        $methods = [
            'header'       => 'seoHeader',
            'beforeRender' => 'beforeRender',
            'footer'       => 'renderSwitcher',
        ];

        foreach ($methods as $component => $method) {
            TypechoPlugin::factory(Route::class)->{$component} = [__CLASS__, $method];
        }
    }

    /**
     * 清除旧版本写入 plugins 配置的 Route 钩子。
     */
    private static function cleanupRouteHooks(): void
    {
        $options = Options::alloc();
        $plugins = $options->plugins;
        $prefix = Common::nativeClassName(Route::class) . ':';
        $changed = false;

        foreach (array_keys($plugins['handles'] ?? []) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($plugins['handles'][$key]);
                $changed = true;
            }
        }

        foreach ($plugins['activated'] ?? [] as $name => $plugin) {
            foreach (array_keys($plugin['handles'] ?? []) as $key) {
                if (strpos($key, $prefix) === 0) {
                    unset($plugins['activated'][$name]['handles'][$key]);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            Helper::setOption('plugins', $plugins);
            TypechoPlugin::init($plugins);
        }
    }

    /**
     * 不需要参与语言前缀/跳转的系统路径。
     */
    private static function isSystemPath(): bool
    {
        $path = (string)Request::getInstance()->getPathInfo();

        return preg_match('#(?:^|/)action/#', $path) === 1
            || preg_match('#(?:^|/)sitemap[^/]*\.xml$#', $path) === 1;
    }

    /**
     * 语言路径模式。
     */
    public static function pathMode(): string
    {
        $mode = (string)self::getConfig()->pathMode;

        return $mode === self::PATH_MODE_PREFIX ? self::PATH_MODE_PREFIX : self::PATH_MODE_ROOT;
    }

    /**
     * 指定语言是否需要带路径前缀。
     */
    public static function shouldPrefixLang(string $lang): bool
    {
        return self::pathMode() === self::PATH_MODE_PREFIX || $lang !== self::defaultLang();
    }

    /**
     * 当前请求路径中的语言代码。
     *
     * @return array{0:string,1:array<int,string>}|null
     */
    private static function pathLanguage(): ?array
    {
        $path = (string)Request::getInstance()->getPathInfo();
        $segments = explode('/', trim($path, '/'));
        $first = strtolower((string)($segments[0] ?? ''));

        if ($first === '' || !isset(self::languageMap()[$first])) {
            return null;
        }

        return [$first, $segments];
    }

    /**
     * 处理旧地址：
     * - 根路径模式：/zh-cn/... 301 到 /...
     * - 全前缀模式：/... 301 到 /zh-cn/...
     */
    private static function redirectLegacyUrl(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $request = Request::getInstance();
        $pathLang = self::pathLanguage();

        if ($pathLang !== null) {
            if (self::pathMode() === self::PATH_MODE_ROOT && $pathLang[0] === self::defaultLang()) {
                Options::alloc()->response->redirect(
                    self::urlForLang($request->getRequestUrl(), self::defaultLang()),
                    true
                );
            }
            return;
        }

        if (self::pathMode() !== self::PATH_MODE_PREFIX) {
            return;
        }

        if (!self::pathMatchesLocalizableRoute((string)$request->getPathInfo())) {
            return;
        }

        Options::alloc()->response->redirect(
            self::urlForLang($request->getRequestUrl(), self::defaultLang()),
            true
        );
    }

    /**
     * 当前路径是否属于需要语言前缀的内容路由。
     */
    private static function pathMatchesLocalizableRoute(string $path): bool
    {
        foreach (self::parsedRoutes() as $name => $route) {
            if (self::isOwnRoute($name) || empty($route['regx'])) {
                continue;
            }

            if (preg_match($route['regx'], $path)) {
                $widget = (string)($route['widget'] ?? '');
                return $widget !== '' && is_a($widget, \Widget\Archive::class, true);
            }
        }

        return false;
    }

    /**
     * 让 Router::url 生成的语言前缀 URL 立即生效。
     */
    private static function applyIndexPrefix(string $lang): void
    {
        if (self::$indexAdjusted) {
            return;
        }
        self::$indexAdjusted = true;

        if (!self::shouldPrefixLang($lang)) {
            return;
        }

        $options = Options::alloc();
        $index = rtrim((string)$options->index, '/');
        $indexPath = (string)(parse_url($index, PHP_URL_PATH) ?? '');

        if ($indexPath !== '' && self::stripLanguagePrefix($indexPath) !== $indexPath) {
            return;
        }

        $options->index = $index . '/' . $lang;
    }

    /**
     * 给 URL 加上指定语言的前缀，并清理旧的 lang 查询参数。
     */
    public static function urlForLang(string $url, string $lang): string
    {
        $lang = strtolower($lang);
        $parts = parse_url($url);
        if ($parts === false) {
            $parts = [];
        }

        $sitePath = rtrim((string)(parse_url((string)Options::alloc()->siteUrl, PHP_URL_PATH) ?? ''), '/');
        $path = (string)($parts['path'] ?? '/');

        if ($sitePath !== '' && strpos($path, $sitePath) === 0) {
            $path = substr($path, strlen($sitePath));
        }

        $path = '/' . ltrim($path, '/');
        $fromLang = self::defaultLang();
        if (preg_match('#^/([^/]+)(/.*)?$#', $path, $matches)) {
            $candidate = strtolower($matches[1]);
            if (isset(self::languageMap()[$candidate])) {
                $fromLang = $candidate;
                $path = $matches[2] ?? '/';
            }
        }

        if ($fromLang !== $lang) {
            $localized = self::localizePath(rawurldecode($path), $fromLang, $lang);
            if ($localized !== null) {
                $path = $localized;
            }
        }

        $prefix = self::shouldPrefixLang($lang) ? '/' . $lang : '';
        $parts['path'] = self::normalizePath($sitePath . $prefix . $path);

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['lang']);
        $parts['query'] = http_build_query($query);
        if ($parts['query'] === '') {
            unset($parts['query']);
        }

        return Common::buildUrl($parts);
    }

    /**
     * 把一条站内路径转换成目标语言的等价路径。
     *
     * 只处理文章、页面、分类和标签；其他路径返回 null 由调用方自行加前缀。
     *
     * @param string $path 已去掉语言前缀的路径
     * @param string $fromLang
     * @param string $toLang
     * @return string|null
     */
    private static function localizePath(string $path, string $fromLang, string $toLang): ?string
    {
        $match = self::matchContentPath($path);
        if ($match === null) {
            return null;
        }

        $routeName = $match['name'];
        $params = $match['params'];
        if (!in_array($routeName, ['post', 'page', 'category', 'tag'], true)) {
            return null;
        }

        if ($routeName === 'post' || $routeName === 'page') {
            $cid = 0;
            $sourceSlug = '';
            $hasSlug = isset($params['slug']) && $params['slug'] !== '';
            $hasDirectory = isset($params['directory']) && $params['directory'] !== '';

            if ($routeName === 'post' && isset($params['cid']) && (int)$params['cid'] > 0) {
                $content = self::fetchContentByCid((int)$params['cid'], 'post');
                if ($content === null) {
                    return null;
                }
                $cid = (int)$content['cid'];
                $sourceSlug = (string)$content['slug'];
            } else {
                if ($hasSlug) {
                    $candidate = $params['slug'];
                } elseif ($routeName === 'page' && $hasDirectory) {
                    $candidate = self::lastPathSegment($params['directory']);
                } else {
                    return null;
                }

                $translation = $fromLang !== self::defaultLang()
                    ? self::fetchContentTranslationBySlug($fromLang, $candidate, $routeName)
                    : null;

                if ($translation) {
                    $cid = (int)$translation['cid'];
                    $sourceSlug = (string)($translation['source_slug'] ?? '');
                } else {
                    $content = self::fetchContentBySlug($candidate, $routeName);
                    if ($content === null) {
                        return null;
                    }
                    $cid = (int)$content['cid'];
                    $sourceSlug = (string)$content['slug'];
                }
            }

            if ($sourceSlug === '') {
                return null;
            }

            $targetSlug = $sourceSlug;
            if ($toLang !== self::defaultLang()) {
                $translation = self::fetchContentTranslation($cid, $toLang);
                if ($translation && !empty($translation['slug'])) {
                    $targetSlug = (string)$translation['slug'];
                }
            }

            if ($hasSlug) {
                $params['slug'] = urlencode($targetSlug);
            }

            if ($routeName === 'page' && $hasDirectory) {
                $params['directory'] = implode('/', array_map(
                    'urlencode',
                    self::translatedPageDirectory($cid, $toLang)
                ));
            }

            if ($routeName === 'post') {
                if (isset($params['category']) && $params['category'] !== '') {
                    $categoryPath = self::translatedCategoryDirectory($cid, $toLang);
                    if ($categoryPath) {
                        $params['category'] = urlencode((string)end($categoryPath));
                    }
                }
                if (isset($params['directory']) && $params['directory'] !== '') {
                    $categoryPath = self::translatedCategoryDirectory($cid, $toLang);
                    if ($categoryPath) {
                        $params['directory'] = implode('/', array_map('urlencode', $categoryPath));
                    }
                }
            }
        } else {
            if (!isset($params['slug']) || $params['slug'] === '') {
                return null;
            }

            $translation = $fromLang !== self::defaultLang()
                ? self::fetchMetaTranslationBySlug($fromLang, $params['slug'], $routeName)
                : null;

            if ($translation) {
                $mid = (int)$translation['mid'];
                $sourceSlug = (string)($translation['source_slug'] ?? '');
            } else {
                $meta = self::fetchMetaBySlug($params['slug'], $routeName);
                if ($meta === null) {
                    return null;
                }
                $mid = (int)$meta['mid'];
                $sourceSlug = (string)$meta['slug'];
            }

            if ($sourceSlug === '') {
                return null;
            }

            $targetSlug = $sourceSlug;
            if ($toLang !== self::defaultLang()) {
                $translation = self::fetchMetaTranslation($mid, $toLang);
                if ($translation && !empty($translation['slug'])) {
                    $targetSlug = (string)$translation['slug'];
                }
            }

            $params['slug'] = urlencode($targetSlug);
        }

        $url = Router::url($routeName, $params, '');
        if ($url === '#' || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        return is_array($parts) ? (string)($parts['path'] ?? null) : null;
    }

    /**
     * 匹配站内内容路由。
     *
     * @param string $path
     * @return array{name:string,params:array<string,string>,route:array}|null
     */
    private static function matchContentPath(string $path): ?array
    {
        foreach (self::parsedRoutes() as $name => $route) {
            if (self::isOwnRoute((string)$name) || empty($route['regx']) || empty($route['params'])) {
                continue;
            }

            if (preg_match($route['regx'], $path, $matches)) {
                array_shift($matches);
                $params = [];
                foreach ($route['params'] as $index => $param) {
                    $params[$param] = (string)($matches[$index] ?? '');
                }

                return [
                    'name'   => (string)$name,
                    'params' => $params,
                    'route'  => $route,
                ];
            }
        }

        return null;
    }

    /**
     * @param int $cid
     * @param string $type
     * @return array|null
     */
    private static function fetchContentByCid(int $cid, string $type): ?array
    {
        try {
            $row = Db::get()->fetchRow(
                Db::get()->select('cid', 'slug', 'type')->from('table.contents')
                    ->where('cid = ?', $cid)->where('type = ?', $type)->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * @param string $slug
     * @param string $type
     * @return array|null
     */
    private static function fetchContentBySlug(string $slug, string $type): ?array
    {
        try {
            $row = Db::get()->fetchRow(
                Db::get()->select('cid', 'slug', 'type')->from('table.contents')
                    ->where('slug = ?', $slug)->where('type = ?', $type)->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * @param string $slug
     * @param string $type
     * @return array|null
     */
    private static function fetchMetaBySlug(string $slug, string $type): ?array
    {
        try {
            $row = Db::get()->fetchRow(
                Db::get()->select('mid', 'slug', 'type')->from('table.metas')
                    ->where('slug = ?', $slug)->where('type = ?', $type)->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * 文章所属分类的完整目录（含自身）。
     *
     * @param int $cid
     * @param string $lang
     * @return array<int, string>
     */
    private static function translatedCategoryDirectory(int $cid, string $lang): array
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('table.metas.mid', 'table.metas.slug', 'table.metas.parent')
                    ->from('table.metas')
                    ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                    ->where('table.relationships.cid = ?', $cid)
                    ->where('table.metas.type = ?', 'category')
                    ->order('table.metas.mid', Db::SORT_ASC)
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (!$row) {
            return [];
        }

        $slugs = [];
        $seen = [];
        $mid = (int)$row['mid'];
        while ($mid > 0 && !isset($seen[$mid])) {
            $seen[$mid] = true;
            try {
                $meta = $db->fetchRow(
                    $db->select('mid', 'slug', 'parent')->from('table.metas')
                        ->where('mid = ?', $mid)->where('type = ?', 'category')->limit(1)
                );
            } catch (\Throwable $e) {
                break;
            }
            if (!$meta) {
                break;
            }
            array_unshift($slugs, (string)$meta['slug']);
            $mid = (int)$meta['parent'];
        }

        return self::translateMetaDirectory($slugs, $lang);
    }

    /**
     * 站点根目录下的静态文件地址，例如 sitemap-index.xml。
     */
    public static function siteFileUrl(string $file): string
    {
        return rtrim((string)Options::alloc()->siteUrl, '/') . '/' . ltrim($file, '/');
    }

    /**
     * 去掉路径第一段语言代码。
     */
    private static function stripLanguagePrefix(string $path): string
    {
        if (preg_match('#^/([^/]+)(/.*)?$#', $path, $matches)) {
            if (isset(self::languageMap()[strtolower($matches[1])])) {
                return $matches[2] ?? '/';
            }
        }

        return $path === '' ? '/' : $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = (string)preg_replace('#/+#', '/', $path);

        return $path === '' || $path[0] !== '/' ? '/' . $path : $path;
    }

    /**
     * @return array<string, array>
     */
    private static function parsedRoutes(): array
    {
        return Options::alloc()->routingTable[0] ?? [];
    }

    /**
     * 是否是本插件注册的路由。
     */
    private static function isOwnRoute(string $name): bool
    {
        return strpos($name, 'ml_') === 0 || strpos($name, 'multilang_') === 0;
    }

    /**
     * 路由第一段是否正好由某个参数占满（例如 /[slug]，而不是 /[slug].html）。
     */
    private static function routeLeadingParam(array $route): ?string
    {
        $format = ltrim((string)($route['format'] ?? ''), '/');
        if ($format === '') {
            return null;
        }

        $first = explode('/', $format)[0];
        if ($first !== '%s') {
            return null;
        }

        return $route['params'][0] ?? null;
    }

    private static function routeFirstSegmentIsParam(string $routeName, string $param, ?array $routes = null): bool
    {
        $route = ($routes ?? self::parsedRoutes())[$routeName] ?? null;

        return is_array($route) && self::routeLeadingParam($route) === $param;
    }

    /**
     * 当前是否后台请求。
     */
    private static function isAdminRequest(): bool
    {
        return defined('__TYPECHO_ADMIN__') && __TYPECHO_ADMIN__;
    }

    /**
     * 是否处于需要替换译文的前台渲染场景。
     */
    private static function isTranslatableRequest(): bool
    {
        return !self::isAdminRequest();
    }
}
