<?php

/**
 * Typecho 多语言支持（可行性验证原型）
 *
 * 原始语言保存在 Typecho 自带的 contents / metas / fields / options 表中，
 * 其他语言保存在本插件创建的 ml_* 表中。前台通过语言前缀（/en/...）或
 * Cookie 选择语言，后台在文章/页面编辑页和“多语言”面板维护译文。
 *
 * @package MultiLanguage
 * @author OpenCode Prototype
 * @version 0.2.0
 * @link https://typecho.org
 */
namespace TypechoPlugin\MultiLanguage;

use Typecho\Common;
use Typecho\Config;
use Typecho\Cookie;
use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Request;
use Typecho\Router;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    private const COOKIE_NAME = 'ml_lang';
    private const COOKIE_TTL = 86400 * 30;
    private const DEFAULT_LANG = 'zh-cn';
    private const PANEL_FILE = 'MultiLanguage/Manage.php';
    private const ACTION_NAME = 'ml-lang';
    private const PATH_MODE_ROOT = 'root';
    private const PATH_MODE_PREFIX = 'prefix';

    /** 历史版本注册过、现在由原生路由直接处理的路径。 */
    private const OBSOLETE_ROUTES = ['ml_feedback', 'ml_comment_page'];

    /** @var bool 当前请求是否已经调整过 options->index */
    private static bool $indexAdjusted = false;

    /** @var bool 当前请求是否已经注册过 Route 组件钩子 */
    private static bool $routeHooksRegistered = false;

    /** @var string|null 当前请求的语言 */
    private static ?string $currentLang = null;

    /** @var array<string, string>|null 配置中的语言 code => label */
    private static ?array $languages = null;

    /**
     * 插件自身注册的路由，也是升级时自动补全和禁用时清理的依据。
     *
     * @return array<string, array{url:string,widget:class-string,action:string,after:string}>
     */
    private static function routeDefinitions(): array
    {
        return [
            'ml_lang' => [
                'url'    => '/[lang:alpha]',
                'widget' => Route::class,
                'action' => 'render',
                'after'  => 'index',
            ],
            'ml_do' => [
                'url'    => '/[lang:alpha]/action/[action:alpha]',
                'widget' => '\Widget\Action',
                'action' => 'action',
                'after'  => 'ml_lang',
            ],
            'ml_feed' => [
                'url'    => '/[lang:alpha]/feed[feed:string:0]',
                'widget' => '\Widget\Feed',
                'action' => 'render',
                'after'  => 'ml_do',
            ],
            'ml_lang_path' => [
                'url'    => '/[lang:alpha]/[path:string]',
                'widget' => Route::class,
                'action' => 'render',
                'after'  => 'ml_feed',
            ],
            'multilang_sitemap' => [
                'url'    => '/sitemap-multilang.xml',
                'widget' => Sitemap::class,
                'action' => 'generate',
                'after'  => 'index',
            ],
            'multilang_sitemap_default' => [
                'url'    => '/sitemap-default.xml',
                'widget' => Sitemap::class,
                'action' => 'generateDefault',
                'after'  => 'multilang_sitemap',
            ],
            'multilang_sitemap_index' => [
                'url'    => '/sitemap-index.xml',
                'widget' => SitemapIndex::class,
                'action' => 'generate',
                'after'  => 'multilang_sitemap_default',
            ],
        ];
    }

    /**
     * 启用插件：建表、注册钩子、增加后台面板。
     *
     * @return string
     * @throws PluginException
     */
    public static function activate(): string
    {
        if (basename(dirname(__FILE__)) !== 'MultiLanguage') {
            throw new PluginException(_t('插件目录名必须为 MultiLanguage'));
        }

        self::installTables();
        self::installDefaultConfig();
        self::normalizeLanguageRows();

        // 前台入口：在路由分发前解析语言，并覆盖站点标题/描述。
        TypechoPlugin::factory('index.php')->begin = [__CLASS__, 'begin'];

        // 内容与分类/标签的译文替换。
        // filter_9 的权重为 -9，保证在 PartiallyPassword 等默认权重插件之前处理译文。
        TypechoPlugin::factory('Widget_Abstract_Contents')->filter_9 = [__CLASS__, 'filterContent'];
        TypechoPlugin::factory('Widget_Abstract_Metas')->filter = [__CLASS__, 'filterMeta'];

        // SEO：在 <head> 中补充 canonical、hreflang、og:locale；渲染前修正 archiveUrl。
        // Route 子类的组件名与 Widget_Archive 不同，会在 begin 中运行时补注册。
        TypechoPlugin::factory('Widget_Archive')->header = [__CLASS__, 'seoHeader'];
        TypechoPlugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'beforeRender'];
        TypechoPlugin::factory('Widget_Archive')->footer = [__CLASS__, 'renderSwitcher'];

        // 文章/页面保存与删除。
        foreach (['Widget_Contents_Post_Edit', 'Widget_Contents_Page_Edit'] as $handle) {
            TypechoPlugin::factory($handle)->write = [__CLASS__, 'prepareWrite'];
            TypechoPlugin::factory($handle)->finishPublish = [__CLASS__, 'saveTranslations'];
            TypechoPlugin::factory($handle)->finishSave = [__CLASS__, 'saveTranslations'];
            TypechoPlugin::factory($handle)->finishDelete = [__CLASS__, 'deleteContentTranslations'];
        }

        // 文章/页面编辑页：语言切换条放在标题附近（由 write-js 移动到 .title 后）。
        TypechoPlugin::factory('admin/write-post.php')->content = [__CLASS__, 'renderLanguageSelector'];
        TypechoPlugin::factory('admin/write-page.php')->content = [__CLASS__, 'renderLanguageSelector'];
        TypechoPlugin::factory('admin/write-js.php')->write = [__CLASS__, 'renderLanguageBarJs'];

        // 语言前缀路由与多语言 sitemap。
        self::installRoutes();
        Helper::addAction(self::ACTION_NAME, SwitchAction::class);

        // 后台“多语言”菜单。
        $panelIndex = Helper::addMenu(_t('多语言'));
        Helper::addPanel(
            $panelIndex,
            self::PANEL_FILE,
            _t('翻译管理'),
            _t('分类、标签与站点信息翻译'),
            'administrator'
        );

        return _t('多语言插件已启用，数据表已创建');
    }

    /**
     * 禁用插件：移除菜单；默认保留数据表，可在设置中改为删除。
     *
     * @return string
     */
    public static function deactivate(): string
    {
        $config = self::getConfig();
        if (!empty($config->dropTables)) {
            self::dropTables();
        }

        $panelTable = Options::alloc()->panelTable;
        foreach ($panelTable['child'] ?? [] as $index => $children) {
            foreach ($children as $child) {
                if (($child[2] ?? '') === 'extending.php?panel=' . urlencode(self::PANEL_FILE)) {
                    Helper::removePanel((int)$index, self::PANEL_FILE);
                }
            }
        }
        Helper::removeMenu(_t('多语言'));
        foreach (array_merge(array_keys(self::routeDefinitions()), self::OBSOLETE_ROUTES) as $name) {
            Helper::removeRoute($name);
        }
        Helper::removeAction(self::ACTION_NAME);

        return _t('多语言插件已禁用，译文数据表已保留');
    }

    /**
     * 插件设置。
     *
     * @param Form $form
     */
    public static function config(Form $form): void
    {
        $languages = new Text(
            'languages',
            null,
            'zh-cn:中文,en:English',
            _t('语言列表（兼容字段）'),
            _t('建议在“多语言 → 语言”面板中增删语言；这里保留“代码:名称,代码:名称”格式用于兼容旧配置。')
        );
        $form->addInput($languages);

        $default = new Text('default', null, self::DEFAULT_LANG, _t('默认语言（兼容字段）'), _t('默认语言是原文所在语言；建议在“多语言 → 语言”面板中设置。'));
        $form->addInput($default);

        $pathMode = new Radio(
            'pathMode',
            [
                self::PATH_MODE_ROOT   => _t('根路径：默认语言不带前缀'),
                self::PATH_MODE_PREFIX => _t('全前缀：所有语言都带前缀'),
            ],
            self::PATH_MODE_ROOT,
            _t('语言路径模式'),
            _t('根路径模式下 / 是默认语言，/en/ 是其他语言；全前缀模式下 /zh-cn/ 和 /en/ 都带前缀，旧根地址 301 到默认语言前缀。')
        );
        $form->addInput($pathMode);

        $detect = new Radio(
            'detect',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('自动检测'),
            _t('访客未手动选择语言时，根据 Accept-Language 请求头匹配站点语言')
        );
        $form->addInput($detect);

        $autoSwitcher = new Radio(
            'autoSwitcher',
            ['0' => _t('关闭'), '1' => _t('开启')],
            '0',
            _t('自动输出切换条'),
            _t('在主题 footer 中自动输出语言切换链接；也可以在主题中调用 \\TypechoPlugin\\MultiLanguage\\Plugin::switcher()')
        );
        $form->addInput($autoSwitcher);

        $dropTables = new Radio(
            'dropTables',
            ['0' => _t('保留'), '1' => _t('删除')],
            '0',
            _t('禁用时删除数据表'),
            _t('默认保留译文，避免误删数据')
        );
        $form->addInput($dropTables);
    }

    /**
     * 个人用户设置。
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 后台保存分类、标签、文章、页面时，阻止缩略名占用语言前缀。
     *
     * Typecho 的表单没有插件钩子，因此在路由分发前检查 /action/* 的 POST，
     * 冲突时回退并提示。
     */
    private static function guardReservedSlugs(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $request = Request::getInstance();
        if (!$request->isPost()) {
            return;
        }

        $path = (string)$request->getPathInfo();
        $languages = self::languageMap();

        if (strpos($path, 'metas-category-edit') !== false || strpos($path, 'metas-tag-edit') !== false) {
            $do = (string)$request->get('do');
            if (!in_array($do, ['insert', 'update'], true)) {
                return;
            }

            $slug = (string)$request->get('slug');
            if ($slug === '') {
                $slug = (string)$request->get('name');
            }
            $slug = strtolower(Common::slugName($slug));

            if ($slug !== '' && isset($languages[$slug])) {
                self::rejectSlug($slug, strpos($path, 'metas-category-edit') !== false ? _t('分类') : _t('标签'));
            }
            return;
        }

        if (
            strpos($path, 'contents-post-edit') === false
            && strpos($path, 'contents-page-edit') === false
        ) {
            return;
        }

        $do = (string)$request->get('do');
        if (!in_array($do, ['publish', 'save'], true)) {
            return;
        }

        // 文章目录来自分类缩略名，先检查本次提交的分类。
        if (strpos($path, 'contents-post-edit') !== false) {
            $categoryIds = $request->get('category', []);
            if (is_array($categoryIds) && $categoryIds) {
                $db = Db::get();
                $rows = $db->fetchAll(
                    $db->select('slug')->from('table.metas')
                        ->where('type = ?', 'category')
                        ->where('mid IN ?', array_map('intval', $categoryIds))
                );
                foreach ($rows as $row) {
                    $slug = strtolower((string)$row['slug']);
                    if ($slug !== '' && isset($languages[$slug])) {
                        self::rejectSlug($slug, _t('分类目录'));
                    }
                }
            }
        }

        $routeName = strpos($path, 'contents-page-edit') !== false ? 'page' : 'post';
        $slug = (string)$request->get('slug');
        if ($slug === '') {
            $slug = (string)$request->get('title');
        }
        $slug = strtolower(Common::slugName($slug));

        if ($slug !== '' && isset($languages[$slug])) {
            // 页面缩略名始终保留；文章缩略名只在它确实占用路径第一段时阻止。
            if ($routeName === 'page' || self::routeFirstSegmentIsParam($routeName, 'slug')) {
                self::rejectSlug($slug, $routeName === 'page' ? _t('页面') : _t('文章'));
            }
        }
    }

    /**
     * 提示并回退。
     *
     * @param string $slug
     * @param string $kind
     */
    private static function rejectSlug(string $slug, string $kind): void
    {
        \Widget\Notice::alloc()->set(
            sprintf(_t('%s缩略名 “%s” 与语言代码冲突，请换一个缩略名或语言代码。'), $kind, $slug),
            'error'
        );
        Options::alloc()->response->goBack();
    }

    /**
     * 前台请求开始：解析语言、处理旧地址跳转并替换站点级文案。
     */
    public static function begin(): void
    {
        self::ensureRoutes();
        self::ensureAction();
        self::registerRouteHooks();

        if (self::isAdminRequest()) {
            return;
        }

        self::guardReservedSlugs();

        if (self::isSystemPath()) {
            return;
        }

        self::redirectLegacyUrl();

        $lang = self::currentLang();

        // 只有路径前缀请求才改 options->index。
        // 根路径模式下的默认语言不加前缀，避免触发核心 permalink 跳转。
        if (self::pathLanguage() !== null) {
            self::applyIndexPrefix($lang);
        }

        // Cookie/Accept-Language 会影响未带前缀的同一 URL，提醒缓存层按语言区分。
        \Typecho\Response::getInstance()
            ->setHeader('Vary', 'Accept-Language, Cookie')
            ->setHeader('Content-Language', $lang);

        if ($lang === self::defaultLang()) {
            return;
        }

        $rows = self::fetchOptionTranslations($lang);
        if (empty($rows)) {
            return;
        }

        $options = Options::alloc();
        foreach (['title', 'description', 'keywords'] as $name) {
            if (isset($rows[$name]) && $rows[$name] !== '') {
                $options->{$name} = $rows[$name];
            }
        }
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

        $path = self::stripLanguagePrefix('/' . ltrim($path, '/'));
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

    private static function routeFirstSegmentIsParam(string $routeName, string $param): bool
    {
        $route = self::parsedRoutes()[$routeName] ?? null;

        return is_array($route) && self::routeLeadingParam($route) === $param;
    }

    /**
     * 渲染前修正 archiveUrl，让核心 canonical/og:url 指向当前语言 URL。
     *
     * @param mixed $archive
     */
    public static function beforeRender($archive): void
    {
        if (self::isAdminRequest() || !is_object($archive) || !method_exists($archive, 'setArchiveUrl')) {
            return;
        }

        $archive->setArchiveUrl(self::currentPageUrl());
    }

    /**
     * SEO 头部：canonical、hreflang、og:locale、搜索/404 noindex。
     *
     * @param string $header 已输出的核心 header
     * @param mixed $archive
     */
    public static function seoHeader($header, $archive = null): void
    {
        if (self::isAdminRequest() || !is_object($archive)) {
            return;
        }

        $type = method_exists($archive, 'getArchiveType') ? (string)$archive->getArchiveType() : '';
        $isSearch = $type === 'search';
        $is404 = $type === '404';
        $isSingle = method_exists($archive, 'is') && $archive->is('single');
        $current = self::currentLang();
        $default = self::defaultLang();
        $tags = [];

        if ($isSearch || $is404) {
            $tags[] = '<meta name="robots" content="noindex,follow" />';
        }

        // single 的核心 canonical 已经输出，但 archiveUrl 已在 beforeRender 修正；
        // 其他页面由插件补 self-canonical。
        if (!$isSingle && !$isSearch && !$is404) {
            $tags[] = '<link rel="canonical" href="' . self::escape(self::currentPageUrl()) . '" />';
        }

        if (!$isSearch && !$is404) {
            foreach (self::languageMap() as $code => $name) {
                $tags[] = '<link rel="alternate" hreflang="' . self::escape($code) . '"'
                    . ' href="' . self::escape(self::pageUrl($code)) . '" />';
            }
            $tags[] = '<link rel="alternate" hreflang="x-default"'
                . ' href="' . self::escape(self::pageUrl($default)) . '" />';

            $tags[] = '<meta property="og:locale" content="' . self::escape(str_replace('-', '_', $current)) . '" />';
            foreach (array_keys(self::languageMap()) as $code) {
                if ($code !== $current) {
                    $tags[] = '<meta property="og:locale:alternate" content="'
                        . self::escape(str_replace('-', '_', $code)) . '" />';
                }
            }
        }

        if ($tags) {
            echo implode("\n", $tags) . "\n";
        }
    }

    /**
     * 当前页面带当前语言的 URL。
     */
    private static function currentPageUrl(): string
    {
        return self::pageUrl(self::currentLang());
    }

    /**
     * HTML 转义快捷方法。
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 内容过滤器：前台替换标题/正文；后台只对文章/页面 Edit widget 合并译文。
     *
     * @param array $row
     * @param mixed $widget
     * @return array
     */
    public static function filterContent(array $row, $widget = null): array
    {
        if (self::isEditWidget($widget)) {
            return self::isAdminRequest() ? self::filterAdminContent($row, $widget) : $row;
        }

        if (self::isAdminRequest()) {
            return $row;
        }

        if (!self::isTranslatableRequest() || empty($row['cid'])) {
            return $row;
        }

        $lang = self::currentLang();
        if ($lang === self::defaultLang()) {
            return $row;
        }

        $translation = self::fetchContentTranslation((int)$row['cid'], $lang);
        if (!$translation) {
            return $row;
        }

        if (!empty($translation['title'])) {
            $row['title'] = $translation['title'];
        }
        if (isset($translation['text']) && $translation['text'] !== '') {
            // 译文自身的前缀决定 Markdown/HTML，不再按原文猜格式。
            $row['text'] = (string)$translation['text'];
        }

        return $row;
    }

    /**
     * 后台编辑页过滤器：只在 Post/Page Edit widget 上把译文合并进 row，
     * 让原生编辑器、EditorMD、SMEditor 等继续操作同一个 #text。
     *
     * @param array $row
     * @param mixed $widget
     * @return array
     */
    private static function filterAdminContent(array $row, $widget = null): array
    {
        if (!self::isEditWidget($widget)) {
            return $row;
        }

        $lang = self::requestedAdminLang();
        if ($lang === null) {
            return $row;
        }

        $cid = self::rowSourceCid($row);
        if ($cid <= 0) {
            return $row;
        }

        $translation = self::fetchContentTranslation($cid, $lang);

        if ($translation) {
            $title = $translation['title'] ?: ($row['title'] ?? '');
            $text = (string)$translation['text'];
        } else {
            // 尚无译文时先以原文为骨架，方便在同一编辑器里翻译。
            $title = $row['title'] ?? '';
            $text = (string)($row['text'] ?? '');
        }

        // 格式完全跟随译文自身；没有译文时跟随原文，不再提供额外的格式下拉。
        $text = self::normalizeTextFormat(
            $text,
            $translation ? self::textIsMarkdown((string)$translation['text']) : self::textIsMarkdown((string)($row['text'] ?? ''))
        );

        $row['title'] = $title;
        $row['text'] = $text;
        // slug 暂不用于译文路由，保持原文 slug，避免 core applySlug 改掉原文 URL。
        return $row;
    }

    /**
     * 保存前的 write 过滤器：捕获译文，把 $contents 换回原文，交给 core 正常保存。
     *
     * @param array $contents
     * @param mixed $edit
     * @return array
     */
    public static function prepareWrite(array $contents, $edit): array
    {
        $lang = self::requestedAdminLang();
        if ($lang === null) {
            return $contents;
        }

        $cid = (int)($edit->cid ?? 0);
        if ($cid <= 0) {
            return $contents;
        }

        $db = Db::get();
        $original = $db->fetchRow(
            $db->select()->from('table.contents')->where('cid = ?', $cid)->limit(1)
        );
        if (!$original) {
            return $contents;
        }

        $sourceMarkdown = self::textIsMarkdown((string)($original['text'] ?? ''));
        $translatedText = (string)($contents['text'] ?? '');
        $existing = self::fetchContentTranslation($cid, $lang);
        $desiredMarkdown = $existing
            ? self::textIsMarkdown((string)$existing['text'])
            : $sourceMarkdown;
        $translatedText = self::normalizeTextFormat($translatedText, $desiredMarkdown);

        $contents['_ml'] = [
            'cid'   => $cid,
            'lang'  => $lang,
            'title' => (string)($contents['title'] ?? ''),
            'text'  => $translatedText,
        ];

        // 恢复原文的语言无关字段，避免在译文模式下误改原文。
        $contents['title'] = $original['title'];
        $contents['text'] = $original['text'];
        $contents['created'] = (int)$original['created'];
        $contents['slug'] = $original['slug'];
        $contents['password'] = $original['password'];
        $contents['allowComment'] = $original['allowComment'];
        $contents['allowPing'] = $original['allowPing'];
        $contents['allowFeed'] = $original['allowFeed'];
        $contents['visibility'] = !empty($original['password']) ? 'password' : $original['status'];

        // 分类/标签属于共享关系，译文模式不改变它们。
        $contents['category'] = array_column(
            $db->fetchAll(
                $db->select('table.metas.mid')
                    ->from('table.metas')
                    ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                    ->where('table.relationships.cid = ?', $cid)
                    ->where('table.metas.type = ?', 'category')
            ),
            'mid'
        );
        $contents['tags'] = implode(',', array_column(
            $db->fetchAll(
                $db->select('table.metas.name')
                    ->from('table.metas')
                    ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                    ->where('table.relationships.cid = ?', $cid)
                    ->where('table.metas.type = ?', 'tag')
            ),
            'name'
        ));

        if ($edit instanceof \Widget\Contents\Page\Edit) {
            $contents['template'] = $original['template'];
            $contents['order'] = (int)$original['order'];
            $contents['parent'] = (int)$original['parent'];
        }

        return $contents;
    }

    /**
     * 分类/标签过滤器：用当前语言的译文替换名称和描述。
     *
     * @param array $row
     * @param mixed $widget
     * @return array
     */
    public static function filterMeta(array $row, $widget = null): array
    {
        if (self::isAdminRequest() || !self::isTranslatableRequest() || empty($row['mid'])) {
            return $row;
        }

        $lang = self::currentLang();
        if ($lang === self::defaultLang()) {
            return $row;
        }

        $translation = self::fetchMetaTranslation((int)$row['mid'], $lang);
        if (!$translation) {
            return $row;
        }

        if (!empty($translation['name'])) {
            $row['name'] = $translation['name'];
        }
        if (isset($translation['description']) && $translation['description'] !== '') {
            $row['description'] = $translation['description'];
        }

        return $row;
    }

    /**
     * 保存文章/页面译文。挂载在 finishPublish 与 finishSave 上。
     *
     * @param array $contents
     * @param mixed $edit
     */
    public static function saveTranslations(array $contents, $edit): void
    {
        $cid = (int)($edit->cid ?? 0);
        if ($cid <= 0) {
            return;
        }

        // 新编辑器模式：prepareWrite 已经把译文放进 _ml。
        $translation = $contents['_ml'] ?? null;
        if (is_array($translation)) {
            $lang = (string)($translation['lang'] ?? '');
            $title = trim((string)($translation['title'] ?? ''));
            $text = (string)($translation['text'] ?? '');

            if (isset(self::languageMap()[$lang]) && $lang !== self::defaultLang()) {
                if ($title === '' && $text === '') {
                    self::deleteContentTranslation($cid, $lang);
                } else {
                    self::upsertContentTranslation($cid, $lang, $title, $text, '');
                }
            }
            return;
        }

        // 兼容旧版底部 textarea 提交格式。
        $input = Request::getInstance()->get('ml', []);
        if (!is_array($input)) {
            return;
        }

        $languages = self::languageMap();
        $default = self::defaultLang();

        foreach ($input as $lang => $data) {
            if (!is_string($lang) || !isset($languages[$lang]) || $lang === $default || !is_array($data)) {
                continue;
            }

            $title = trim((string)($data['title'] ?? ''));
            $text = (string)($data['text'] ?? '');
            $slug = trim((string)($data['slug'] ?? ''));

            if ($title === '' && $text === '') {
                self::deleteContentTranslation($cid, $lang);
                continue;
            }

            self::upsertContentTranslation($cid, $lang, $title, $text, $slug);
        }
    }

    /**
     * 删除内容时清理所有译文。
     *
     * @param mixed $content
     * @param mixed $edit
     */
    public static function deleteContentTranslations($content, $edit = null): void
    {
        $cid = 0;
        if (is_int($content)) {
            $cid = $content;
        } elseif (is_array($content)) {
            $cid = (int)($content['cid'] ?? 0);
        } elseif (is_object($content)) {
            $cid = (int)($content->cid ?? 0);
        }

        if ($cid > 0) {
            $db = Db::get();
            $db->query($db->delete('table.multilang_contents')->where('cid = ?', $cid));
        }
    }

    /**
     * 在文章/页面编辑页输出译文编辑区。
     *
     * @param mixed $content
     */
    public static function renderLanguageSelector($content): void
    {
        $cid = (int)($content->cid ?? 0);
        $languages = self::languageMap();
        $default = self::defaultLang();

        if (count($languages) <= 1) {
            return;
        }

        $currentLang = self::requestedAdminLang();
        $current = $currentLang ?? $default;
        $isPage = $content instanceof \Widget\Contents\Page\Edit;
        $editFile = $isPage ? 'write-page.php' : 'write-post.php';
        $baseUrl = Options::alloc()->adminUrl($editFile, true);

        $buildUrl = function (string $lang) use ($baseUrl, $cid): string {
            $query = ['cid' => $cid];
            if ($lang !== self::defaultLang()) {
                $query['ml_lang'] = $lang;
            }
            return $baseUrl . '?' . http_build_query($query);
        };

        echo '<div id="ml-language-bar" class="ml-language-bar" style="margin:0 0 8px;">';

        if ($cid <= 0) {
            echo '<span class="description">' . _t('保存后可添加译文') . '</span>';
            echo '</div>';
            return;
        }

        echo '<select id="ml-lang-select" onchange="location.href=this.value" style="min-width:180px;">';
        echo '<option value="' . htmlspecialchars($buildUrl($default), ENT_QUOTES, 'UTF-8') . '"'
            . ($current === $default ? ' selected' : '') . '>'
            . htmlspecialchars(_t('默认语言（原文）') . ' — ' . $languages[$default], ENT_QUOTES, 'UTF-8')
            . '</option>';

        foreach ($languages as $code => $label) {
            if ($code === $default) {
                continue;
            }
            echo '<option value="' . htmlspecialchars($buildUrl($code), ENT_QUOTES, 'UTF-8') . '"'
                . ($current === $code ? ' selected' : '') . '>'
                . htmlspecialchars($label . ' (' . $code . ')', ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        echo '</select>';

        if ($current !== $default) {
            $translation = self::fetchContentTranslation($cid, $current);
            echo '<input type="hidden" name="ml_lang" value="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '" />';
            if (!$translation) {
                echo ' <span class="description">' . _t('尚无译文') . '</span>';
            }
            echo '<style>.typecho-post-area .url-slug{display:none;}</style>';
        }

        echo '</div>';
    }

    /**
     * 把语言切换条移动到标题上方。
     */
    public static function renderLanguageBarJs(): void
    {
        echo '<script>jQuery(function($){var bar=$("#ml-language-bar");if(bar.length){var title=$(".typecho-post-area .title").first();if(title.length){bar.insertBefore(title);}else{bar.insertBefore("#text");}}});</script>';
    }

    /**
     * 兼容旧调用名。
     *
     * @param mixed $content
     */
    public static function renderContentEditor($content): void
    {
        self::renderLanguageSelector($content);
    }

    /**
     * 输出前台语言切换条；由主题调用或 autoSwitcher 设置自动调用。
     *
     * @param mixed $archive
     */
    public static function renderSwitcher($archive = null): void
    {
        $config = self::getConfig();
        if (empty($config->autoSwitcher)) {
            return;
        }

        self::switcher();
    }

    /**
     * 可直接在主题中调用的语言切换条。
     */
    public static function switcher(): void
    {
        echo self::switcherHtml();
    }

    /**
     * 主题 API：返回语言切换链接数组，便于完全自定义。
     *
     * url 会先写 Cookie 再跳转；pageUrl 是干净的语言页面地址。
     *
     * @return array<int, array{code:string,name:string,url:string,pageUrl:string,current:bool,default:bool}>
     */
    public static function switcherLinks(): array
    {
        $current = self::currentLang();
        $default = self::defaultLang();
        $links = [];

        foreach (self::languageMap() as $code => $name) {
            $links[] = [
                'code'    => $code,
                'name'    => $name,
                'url'     => self::switchUrl($code),
                'pageUrl' => self::pageUrl($code),
                'current' => $code === $current,
                'default' => $code === $default,
            ];
        }

        return $links;
    }

    /**
     * 主题 API：返回默认样式的切换器 HTML。
     *
     * @param array $options class、currentClass、separator 可选
     */
    public static function switcherHtml(array $options = []): string
    {
        $links = self::switcherLinks();
        if (count($links) <= 1) {
            return '';
        }

        $class = (string)($options['class'] ?? 'ml-switcher');
        $currentClass = (string)($options['currentClass'] ?? 'ml-current');
        $separator = (string)($options['separator'] ?? ' ');

        $html = '<div class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($links as $index => $link) {
            if ($index > 0) {
                $html .= $separator;
            }
            $classes = $link['current'] ? $class . '-current ' . $currentClass : '';
            $html .= '<a class="' . htmlspecialchars(trim($classes), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-lang="' . htmlspecialchars($link['code'], ENT_QUOTES, 'UTF-8') . '"'
                . ' href="' . htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($link['name'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 主题 API：当前页面在指定语言下的干净 URL，无 Cookie/跳转副作用。
     */
    public static function pageUrl(?string $lang = null): string
    {
        $lang = strtolower($lang ?? self::currentLang());

        return self::urlForLang(Request::getInstance()->getRequestUrl(), $lang);
    }

    /**
     * 主题 API：切换语言的入口 URL。
     *
     * 访问后会写入 Cookie，再 302 到 pageUrl() 对应的干净地址。
     */
    public static function switchUrl(?string $lang = null): string
    {
        $lang = strtolower($lang ?? self::currentLang());
        $pageUrl = self::pageUrl($lang);
        $parts = parse_url($pageUrl);
        $redirect = (string)($parts['path'] ?? '/');

        if (isset($parts['query'])) {
            $redirect .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $redirect .= '#' . $parts['fragment'];
        }

        $actionUrl = Router::url('do', ['action' => self::ACTION_NAME], Options::alloc()->index);

        return $actionUrl . '?' . http_build_query([
            'lang'     => $lang,
            'redirect' => $redirect,
        ]);
    }

    /**
     * 主题 API：当前页面切换到指定语言的 URL（会先写 Cookie）。
     */
    public static function languageUrl(string $lang): string
    {
        return self::switchUrl($lang);
    }

    /**
     * 主题 API：所有语言的当前页切换 URL。
     *
     * @return array<string, string>
     */
    public static function languageUrls(): array
    {
        $result = [];
        foreach (self::languageMap() as $code => $label) {
            $result[$code] = self::switchUrl($code);
        }
        return $result;
    }

    /**
     * 写入语言偏好 Cookie；只允许配置中存在的语言。
     */
    public static function rememberLanguage(string $lang): void
    {
        $lang = strtolower(trim($lang));
        if (isset(self::languageMap()[$lang])) {
            Cookie::set(self::COOKIE_NAME, $lang, time() + self::COOKIE_TTL);
        }
    }

    /**
     * 主题 API：当前语言的 HTML lang 属性值。
     */
    public static function htmlLang(): string
    {
        return self::currentLang();
    }

    /**
     * 主题 API：语言显示名称。
     */
    public static function languageName(?string $lang = null): string
    {
        $lang = strtolower($lang ?? self::currentLang());
        $languages = self::languageMap();

        return $languages[$lang] ?? $lang;
    }

    /**
     * 主题 API：某内容是否存在指定语言译文。
     */
    public static function hasTranslation(int $cid, string $lang): bool
    {
        return self::fetchContentTranslation($cid, strtolower($lang)) !== null;
    }

    /**
     * 主题 API：获取指定语言译文，返回 title/text/slug 等字段。
     */
    public static function translation(int $cid, string $lang): ?array
    {
        return self::fetchContentTranslation($cid, strtolower($lang));
    }

    /**
     * 主题 API：获取站点级译文；语言为空时使用当前语言。
     */
    public static function option(string $name, ?string $lang = null): ?string
    {
        $lang = strtolower($lang ?? self::currentLang());
        if ($lang === self::defaultLang()) {
            $value = Options::alloc()->{$name};
            return $value === null ? null : (string)$value;
        }

        $rows = self::fetchOptionTranslations($lang);
        return isset($rows[$name]) ? (string)$rows[$name] : null;
    }

    /**
     * 当前语言代码。
     */
    public static function currentLang(): string
    {
        if (self::$currentLang === null) {
            self::$currentLang = self::resolveLanguage();
        }

        return self::$currentLang;
    }

    /**
     * 路由解析出前缀语言后强制覆盖当前语言。
     */
    public static function forceLanguage(string $lang): void
    {
        self::$currentLang = strtolower($lang);
    }

    /**
     * 当前语言是否为默认语言。
     */
    public static function isDefaultLang(): bool
    {
        return self::currentLang() === self::defaultLang();
    }

    /**
     * 语言 code => label 映射。
     *
     * @return array<string, string>
     */
    public static function languageMap(): array
    {
        if (self::$languages !== null) {
            return self::$languages;
        }

        $config = self::getConfig();
        $languages = [];

        foreach (explode(',', (string)$config->languages) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            [$code, $label] = array_pad(explode(':', $item, 2), 2, null);
            $code = strtolower(trim($code));
            if ($code === '') {
                continue;
            }
            $languages[$code] = trim((string)$label) ?: $code;
        }

        $default = self::defaultLang();
        if (!isset($languages[$default])) {
            $languages = [$default => $default] + $languages;
        }

        self::$languages = $languages;
        return $languages;
    }

    /**
     * 默认语言代码。
     */
    public static function defaultLang(): string
    {
        $config = self::getConfig();
        $default = trim((string)$config->default);

        return $default !== '' ? strtolower($default) : self::DEFAULT_LANG;
    }

    /**
     * 供后台面板读取一条内容译文。
     *
     * @param int $cid
     * @param string $lang
     * @return array|null
     */
    public static function contentTranslation(int $cid, string $lang): ?array
    {
        return self::fetchContentTranslation($cid, strtolower($lang));
    }

    /**
     * 供后台面板读取一条分类/标签译文。
     *
     * @param int $mid
     * @param string $lang
     * @return array|null
     */
    public static function metaTranslation(int $mid, string $lang): ?array
    {
        return self::fetchMetaTranslation($mid, strtolower($lang));
    }

    /**
     * 后台面板保存分类/标签译文。
     *
     * @param int $mid
     * @param string $lang
     * @param string $name
     * @param string $slug
     * @param string $description
     */
    public static function saveMetaTranslation(
        int $mid,
        string $lang,
        string $name,
        string $slug,
        string $description
    ): void {
        $lang = strtolower($lang);
        $languages = self::languageMap();
        if ($mid <= 0 || !isset($languages[$lang]) || $lang === self::defaultLang()) {
            return;
        }

        $db = Db::get();
        $exists = $db->fetchRow(
            $db->select()->from('table.multilang_metas')
                ->where('mid = ?', $mid)->where('lang = ?', $lang)->limit(1)
        );

        if ($name === '' && $description === '') {
            if ($exists) {
                $db->query($db->delete('table.multilang_metas')
                    ->where('mid = ?', $mid)->where('lang = ?', $lang));
            }
            return;
        }

        $rows = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
        ];

        if ($exists) {
            $db->query($db->update('table.multilang_metas')->rows($rows)
                ->where('mid = ?', $mid)->where('lang = ?', $lang));
        } else {
            $rows['mid'] = $mid;
            $rows['lang'] = $lang;
            $db->query($db->insert('table.multilang_metas')->rows($rows));
        }
    }

    /**
     * 后台面板保存站点级译文（标题、描述、关键词）。
     *
     * @param string $lang
     * @param array $values
     */
    public static function saveOptionTranslations(string $lang, array $values): void
    {
        $lang = strtolower($lang);
        $languages = self::languageMap();
        if (!isset($languages[$lang]) || $lang === self::defaultLang()) {
            return;
        }

        $db = Db::get();
        foreach (['title', 'description', 'keywords'] as $name) {
            $value = trim((string)($values[$name] ?? ''));
            $exists = $db->fetchRow(
                $db->select()->from('table.multilang_options')
                    ->where('name = ?', $name)->where('lang = ?', $lang)->limit(1)
            );

            if ($value === '') {
                if ($exists) {
                    $db->query($db->delete('table.multilang_options')
                        ->where('name = ?', $name)->where('lang = ?', $lang));
                }
            } elseif ($exists) {
                $db->query($db->update('table.multilang_options')->rows(['value' => $value])
                    ->where('name = ?', $name)->where('lang = ?', $lang));
            } else {
                $db->query($db->insert('table.multilang_options')->rows([
                    'name'  => $name,
                    'lang'  => $lang,
                    'value' => $value,
                ]));
            }
        }
    }

    /**
     * 找出与现有路径第一段冲突的语言代码。
     *
     * 覆盖：静态路由首段、分类目录、文章/页面/标签缩略名，以及所有
     * 可能把某个参数放在路径第一段的内置路由。
     *
     * @param array<string, string> $languages
     * @return array<string, array{slug:string,label:string}> code => conflict
     */
    public static function conflictingSlugs(array $languages): array
    {
        $reserved = self::reservedFirstSegments();
        $conflicts = [];

        foreach ($languages as $code => $name) {
            $code = strtolower(trim((string)$code));
            if ($code !== '' && isset($reserved[$code])) {
                $conflicts[$code] = $reserved[$code];
            }
        }

        return $conflicts;
    }

    /**
     * 兼容旧调用名，返回 code => slug。
     *
     * @param array<string, string> $languages
     * @return array<string, string>
     */
    public static function conflictingCategorySlugs(array $languages): array
    {
        $conflicts = [];
        foreach (self::conflictingSlugs($languages) as $code => $conflict) {
            $conflicts[$code] = $conflict['slug'];
        }

        return $conflicts;
    }

    /**
     * 动态首段的自定义路由提示；无法替用户判断数据，只提示。
     *
     * @return array<int, string>
     */
    public static function routeFirstSegmentWarnings(): array
    {
        $knownParams = [
            'slug', 'directory', 'year', 'month', 'day', 'page', 'cid',
            'uid', 'keywords', 'permalink', 'commentPage', 'action', 'type', 'feed',
        ];
        $warnings = [];

        foreach (self::parsedRoutes() as $name => $route) {
            if (self::isOwnRoute($name)) {
                continue;
            }

            $param = self::routeLeadingParam($route);
            if ($param !== null && !in_array($param, $knownParams, true)) {
                $warnings[] = sprintf(
                    _t('路由 “%s” 以动态段 “%s” 开头；若该段可能等于语言代码，请自行确保不冲突。'),
                    $name,
                    $param
                );
            }
        }

        return $warnings;
    }

    /**
     * 当前站点的路径第一段保留值。
     *
     * @return array<string, array{slug:string,label:string}>
     */
    private static function reservedFirstSegments(): array
    {
        $reserved = [];

        foreach (self::parsedRoutes() as $name => $route) {
            if (self::isOwnRoute($name)) {
                continue;
            }

            $first = self::staticFirstSegment($route);
            if ($first !== '') {
                $reserved[$first] ??= ['slug' => $first, 'label' => _t('路由路径')];
            }

            // 页面缩略名不依赖当前 permalink 规则，一律保留，避免以后改规则时冲突。
            if ($name === 'page') {
                self::addReservedSlugs($reserved, self::contentSlugs('page'), _t('页面缩略名'));
            }

            $param = self::routeLeadingParam($route);
            if ($param === null) {
                continue;
            }

            if ($name === 'post' && $param === 'directory') {
                self::addReservedSlugs($reserved, self::categorySlugs(true), _t('分类目录'));
            } elseif ($name === 'post' && $param === 'slug') {
                self::addReservedSlugs($reserved, self::contentSlugs('post'), _t('文章缩略名'));
            } elseif ($name === 'category' && $param === 'slug') {
                self::addReservedSlugs($reserved, self::categorySlugs(false), _t('分类缩略名'));
            } elseif ($name === 'tag' && $param === 'slug') {
                self::addReservedSlugs($reserved, self::tagSlugs(), _t('标签缩略名'));
            }
        }

        return $reserved;
    }

    /**
     * @param array<string, array{slug:string,label:string}> $reserved
     * @param array<int, string> $slugs
     */
    private static function addReservedSlugs(array &$reserved, array $slugs, string $label): void
    {
        foreach ($slugs as $slug) {
            $slug = strtolower((string)$slug);
            if ($slug !== '') {
                $reserved[$slug] ??= ['slug' => $slug, 'label' => $label];
            }
        }
    }

    /**
     * 路由 URL 中第一段是否是纯静态文本。
     */
    private static function staticFirstSegment(array $route): string
    {
        $url = ltrim((string)($route['url'] ?? ''), '/');
        if ($url === '' || $url[0] === '[') {
            return '';
        }

        $segment = explode('/', $url)[0];
        $segment = (string)preg_replace('/\[.*$/', '', $segment);

        return strtolower(trim($segment));
    }

    /**
     * @return array<int, string>
     */
    private static function categorySlugs(bool $rootOnly): array
    {
        $db = Db::get();
        $select = $db->select('slug')->from('table.metas')->where('type = ?', 'category');
        if ($rootOnly) {
            $select->where('parent = ?', 0);
        }

        return array_column($db->fetchAll($select), 'slug');
    }

    /**
     * @param string $type post|page
     * @return array<int, string>
     */
    private static function contentSlugs(string $type): array
    {
        $db = Db::get();

        return array_column(
            $db->fetchAll(
                $db->select('slug')->from('table.contents')->where('type = ?', $type)
            ),
            'slug'
        );
    }

    /**
     * @return array<int, string>
     */
    private static function tagSlugs(): array
    {
        $db = Db::get();

        return array_column(
            $db->fetchAll(
                $db->select('slug')->from('table.metas')->where('type = ?', 'tag')
            ),
            'slug'
        );
    }

    /**
     * 后台面板保存语言列表与默认语言。
     *
     * @param array<string, string> $languages code => name
     * @param string $default
     */
    public static function saveLanguages(array $languages, string $default): bool
    {
        $normalized = [];
        foreach ($languages as $code => $name) {
            $normalized[strtolower(trim((string)$code))] = trim((string)$name);
        }
        $languages = $normalized;
        $default = strtolower(trim($default));

        if (empty($languages) || !isset($languages[$default])) {
            return false;
        }

        $oldDefault = self::defaultLang();
        if ($default !== $oldDefault) {
            $db = Db::get();
            $count = 0;
            foreach (['multilang_contents', 'multilang_metas', 'multilang_options', 'multilang_fields'] as $table) {
                $row = $db->fetchRow(
                    $db->select(['COUNT(*)' => 'num'])->from('table.' . $table)
                );
                $count += (int)($row['num'] ?? 0);
            }
            if ($count > 0) {
                return false;
            }
        }

        $config = self::getConfig();
        $data = self::defaultConfig();
        foreach (['detect', 'autoSwitcher', 'dropTables', 'pathMode'] as $key) {
            $value = $config->{$key} ?? null;
            if ($value !== null) {
                $data[$key] = (string)$value;
            }
        }

        $items = [];
        foreach ($languages as $code => $name) {
            $items[] = $code . ':' . $name;
        }
        $data['languages'] = implode(',', $items);
        $data['default'] = $default;

        \Widget\Plugins\Edit::configPlugin('MultiLanguage', $data);
        self::$languages = null;
        self::$currentLang = null;

        return true;
    }

    /**
     * 读取站点级译文，返回 name => value。
     *
     * @param string $lang
     * @return array<string, string>
     */
    public static function optionTranslations(string $lang): array
    {
        return self::fetchOptionTranslations(strtolower($lang));
    }

    /**
     * 读取一条内容的所有语言译文。
     *
     * @param int $cid
     * @return array<string, array>
     */
    public static function contentTranslations(int $cid): array
    {
        return self::fetchContentTranslations($cid);
    }

    /**
     * 后台编辑页显式请求的语言；默认语言返回 null。
     */
    private static function requestedAdminLang(): ?string
    {
        $lang = Request::getInstance()->get('ml_lang');
        if (!is_string($lang)) {
            return null;
        }
        $lang = strtolower(trim($lang));
        if (!isset(self::languageMap()[$lang]) || $lang === self::defaultLang()) {
            return null;
        }

        return $lang;
    }

    /**
     * 是否文章/页面 Edit widget。
     */
    private static function isEditWidget($widget): bool
    {
        return $widget instanceof \Widget\Contents\Post\Edit
            || $widget instanceof \Widget\Contents\Page\Edit;
    }

    /**
     * 取 row 对应原文的 cid；revision 使用 parent。
     */
    private static function rowSourceCid(array $row): int
    {
        if (($row['type'] ?? '') === 'revision') {
            return (int)($row['parent'] ?? 0);
        }

        return (int)($row['cid'] ?? 0);
    }

    /**
     * 文本是否带 Typecho 的 Markdown 前缀。
     */
    private static function textIsMarkdown(string $text): bool
    {
        return strpos($text, '<!--markdown-->') === 0;
    }

    /**
     * 按目标格式补/去 Markdown 前缀。
     */
    private static function normalizeTextFormat(string $text, bool $markdown): string
    {
        if ($text === '') {
            return $text;
        }

        if ($markdown) {
            return self::textIsMarkdown($text) ? $text : '<!--markdown-->' . $text;
        }

        return self::textIsMarkdown($text) ? substr($text, 15) : $text;
    }

    /**
     * 解析当前请求语言。
     *
     * 优先级：路径前缀 > Cookie > Accept-Language > 默认语言。
     * 语言切换只通过 SwitchAction 写入 Cookie，不再使用 ?lang 参数。
     */
    private static function resolveLanguage(): string
    {
        $languages = self::languageMap();
        $config = self::getConfig();

        $pathLang = self::pathLanguage();
        if ($pathLang !== null) {
            return $pathLang[0];
        }

        $cookieLang = Cookie::get(self::COOKIE_NAME);
        if (is_string($cookieLang)) {
            $cookieLang = strtolower(trim($cookieLang));
            if (isset($languages[$cookieLang])) {
                return $cookieLang;
            }
        }

        if (!empty($config->detect)) {
            $detected = self::detectAcceptLanguage($languages);
            if ($detected !== null) {
                return $detected;
            }
        }

        $default = self::defaultLang();
        return isset($languages[$default]) ? $default : (string)array_key_first($languages);
    }

    /**
     * 从 Accept-Language 中匹配站点支持的语言。
     *
     * @param array<string, string> $languages
     * @return string|null
     */
    private static function detectAcceptLanguage(array $languages): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') {
            return null;
        }

        $lookup = [];
        foreach ($languages as $code => $label) {
            $lookup[strtolower($code)] = $code;
        }

        $candidates = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0] ?? ''));
            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $piece) {
                $piece = trim($piece);
                if (strpos($piece, 'q=') === 0) {
                    $quality = (float)substr($piece, 2);
                }
            }

            $candidates[] = [$tag, $quality, $position];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a[1] === $b[1]) {
                return $a[2] <=> $b[2];
            }
            return $a[1] < $b[1] ? 1 : -1;
        });

        foreach ($candidates as [$tag]) {
            if (isset($lookup[$tag])) {
                return $lookup[$tag];
            }

            $primary = explode('-', $tag)[0];
            if (isset($lookup[$primary])) {
                return $lookup[$primary];
            }

            foreach ($lookup as $code => $original) {
                if (explode('-', $code)[0] === $primary) {
                    return $original;
                }
            }
        }

        return null;
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

    /**
     * 读取插件配置；配置不存在时返回默认值。
     */
    private static function getConfig(): Config
    {
        try {
            return Options::alloc()->plugin('MultiLanguage');
        } catch (\Throwable $e) {
            return new Config(self::defaultConfig());
        }
    }

    /**
     * 默认配置。
     *
     * @return array<string, string>
     */
    private static function defaultConfig(): array
    {
        return [
            'languages'    => 'zh-cn:中文,en:English',
            'default'      => self::DEFAULT_LANG,
            'detect'       => '1',
            'autoSwitcher' => '0',
            'dropTables'   => '0',
            'pathMode'     => self::PATH_MODE_ROOT,
        ];
    }

    /**
     * 激活时写入默认配置，避免后台首次打开设置页时出现空值。
     */
    private static function installDefaultConfig(): void
    {
        $db = Db::get();
        $exists = $db->fetchRow(
            $db->select()->from('table.options')->where('name = ?', 'plugin:MultiLanguage')->limit(1)
        );

        if (!$exists) {
            $db->query($db->insert('table.options')->rows([
                'name'  => 'plugin:MultiLanguage',
                'value' => json_encode(self::defaultConfig()),
                'user'  => 0,
            ]));
        }
    }

    /**
     * 把历史译文中的语言代码统一为小写。
     */
    private static function normalizeLanguageRows(): void
    {
        $db = Db::get();
        foreach (['multilang_contents', 'multilang_metas', 'multilang_options', 'multilang_fields'] as $table) {
            $db->query(
                $db->update('table.' . $table)->expression('lang', 'LOWER(lang)', false)
            );
        }
    }

    /**
     * 按数据库类型创建译文表。
     *
     * @throws PluginException
     */
    private static function installTables(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();

        if (stripos($adapter, 'Mysql') !== false) {
            self::installMysqlTables($db, $prefix);
        } elseif (stripos($adapter, 'Pgsql') !== false) {
            self::installPgsqlTables($db, $prefix);
        } elseif (stripos($adapter, 'SQLite') !== false) {
            self::installSqliteTables($db, $prefix);
        } else {
            throw new PluginException(_t('当前数据库适配器 %s 暂不支持', $adapter));
        }
    }

    private static function installMysqlTables(Db $db, string $prefix): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}multilang_contents` (
                `cid` int(10) NOT NULL,
                `lang` varchar(16) NOT NULL,
                `title` varchar(150) DEFAULT NULL,
                `slug` varchar(150) DEFAULT NULL,
                `text` longtext,
                `created` int(10) DEFAULT 0,
                `modified` int(10) DEFAULT 0,
                PRIMARY KEY (`cid`, `lang`),
                KEY `{$prefix}ml_contents_lang_slug` (`lang`, `slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$prefix}multilang_metas` (
                `mid` int(10) NOT NULL,
                `lang` varchar(16) NOT NULL,
                `name` varchar(150) DEFAULT NULL,
                `slug` varchar(150) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`mid`, `lang`),
                KEY `{$prefix}ml_metas_lang_slug` (`lang`, `slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$prefix}multilang_fields` (
                `cid` int(10) NOT NULL,
                `lang` varchar(16) NOT NULL,
                `name` varchar(150) NOT NULL,
                `str_value` longtext,
                PRIMARY KEY (`cid`, `lang`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$prefix}multilang_options` (
                `name` varchar(64) NOT NULL,
                `lang` varchar(16) NOT NULL,
                `value` longtext,
                PRIMARY KEY (`name`, `lang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($statements as $sql) {
            $db->query($sql, Db::WRITE);
        }
    }

    private static function installSqliteTables(Db $db, string $prefix): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_contents\" (
                \"cid\" INTEGER NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"title\" varchar(150) DEFAULT NULL,
                \"slug\" varchar(150) DEFAULT NULL,
                \"text\" TEXT,
                \"created\" INTEGER DEFAULT 0,
                \"modified\" INTEGER DEFAULT 0,
                PRIMARY KEY (\"cid\", \"lang\")
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}ml_contents_lang_slug\"
                ON \"{$prefix}multilang_contents\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_metas\" (
                \"mid\" INTEGER NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150) DEFAULT NULL,
                \"slug\" varchar(150) DEFAULT NULL,
                \"description\" varchar(255) DEFAULT NULL,
                PRIMARY KEY (\"mid\", \"lang\")
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}ml_metas_lang_slug\"
                ON \"{$prefix}multilang_metas\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_fields\" (
                \"cid\" INTEGER NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150) NOT NULL,
                \"str_value\" TEXT,
                PRIMARY KEY (\"cid\", \"lang\", \"name\")
            )",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_options\" (
                \"name\" varchar(64) NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"value\" TEXT,
                PRIMARY KEY (\"name\", \"lang\")
            )",
        ];

        foreach ($statements as $sql) {
            $db->query($sql, Db::WRITE);
        }
    }

    private static function installPgsqlTables(Db $db, string $prefix): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_contents\" (
                \"cid\" integer NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"title\" varchar(150),
                \"slug\" varchar(150),
                \"text\" text,
                \"created\" integer DEFAULT 0,
                \"modified\" integer DEFAULT 0,
                PRIMARY KEY (\"cid\", \"lang\")
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}ml_contents_lang_slug\"
                ON \"{$prefix}multilang_contents\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_metas\" (
                \"mid\" integer NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150),
                \"slug\" varchar(150),
                \"description\" varchar(255),
                PRIMARY KEY (\"mid\", \"lang\")
            )",
            "CREATE INDEX IF NOT EXISTS \"{$prefix}ml_metas_lang_slug\"
                ON \"{$prefix}multilang_metas\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_fields\" (
                \"cid\" integer NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150) NOT NULL,
                \"str_value\" text,
                PRIMARY KEY (\"cid\", \"lang\", \"name\")
            )",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_options\" (
                \"name\" varchar(64) NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"value\" text,
                PRIMARY KEY (\"name\", \"lang\")
            )",
        ];

        foreach ($statements as $sql) {
            $db->query($sql, Db::WRITE);
        }
    }

    /**
     * 删除译文表。
     */
    private static function dropTables(): void
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();
        $quote = stripos($adapter, 'Mysql') !== false ? '`' : '"';

        foreach (['multilang_fields', 'multilang_options', 'multilang_metas', 'multilang_contents'] as $table) {
            $db->query(
                "DROP TABLE IF EXISTS {$quote}{$prefix}{$table}{$quote}",
                Db::WRITE
            );
        }
    }

    /**
     * 读取内容译文。
     */
    private static function fetchContentTranslation(int $cid, string $lang): ?array
    {
        $lang = strtolower($lang);
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.multilang_contents')
                ->where('cid = ?', $cid)->where('lang = ?', $lang)->limit(1)
        );

        return $row ?: null;
    }

    /**
     * 读取一条内容的所有译文，返回 lang => row。
     *
     * @return array<string, array>
     */
    private static function fetchContentTranslations(int $cid): array
    {
        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select()->from('table.multilang_contents')->where('cid = ?', $cid)
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['lang']] = $row;
        }
        return $result;
    }

    /**
     * 写入或更新内容译文。
     */
    private static function upsertContentTranslation(
        int $cid,
        string $lang,
        string $title,
        string $text,
        string $slug
    ): void {
        $db = Db::get();
        $exists = self::fetchContentTranslation($cid, $lang);
        $rows = [
            'title'    => $title === '' ? null : htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'slug'     => $slug === '' ? null : $slug,
            'text'     => $text,
            'modified' => time(),
        ];

        if ($exists) {
            $db->query($db->update('table.multilang_contents')->rows($rows)
                ->where('cid = ?', $cid)->where('lang = ?', $lang));
        } else {
            $rows['cid'] = $cid;
            $rows['lang'] = $lang;
            $rows['created'] = time();
            $db->query($db->insert('table.multilang_contents')->rows($rows));
        }
    }

    /**
     * 删除一条内容译文。
     */
    private static function deleteContentTranslation(int $cid, string $lang): void
    {
        $db = Db::get();
        $db->query($db->delete('table.multilang_contents')
            ->where('cid = ?', $cid)->where('lang = ?', $lang));
    }

    /**
     * 读取分类/标签译文。
     */
    private static function fetchMetaTranslation(int $mid, string $lang): ?array
    {
        $lang = strtolower($lang);
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.multilang_metas')
                ->where('mid = ?', $mid)->where('lang = ?', $lang)->limit(1)
        );

        return $row ?: null;
    }

    /**
     * 读取站点级译文，返回 name => value。
     *
     * @return array<string, string>
     */
    private static function fetchOptionTranslations(string $lang): array
    {
        $lang = strtolower($lang);
        $db = Db::get();
        $rows = $db->fetchAll(
            $db->select()->from('table.multilang_options')->where('lang = ?', $lang)
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['name']] = $row['value'];
        }
        return $result;
    }
}
