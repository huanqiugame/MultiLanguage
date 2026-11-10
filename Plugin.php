<?php

/**
 * Typecho 多语言支持
 *
 * 支持通过访客的语言偏好自动选择语言，并允许访客手动指定。后台文章/页面编辑页新增语言选择编辑功能。后台完整“多语言”面板可用于维护标签、分类等杂项译文。插件间兼容性良好。另提供相关API供主题等二次开发。详情请见 README.md。
 *
 * @package MultiLanguage
 * @author MultiLanguage Contributors
 * @version 0.1.0
 * @link https://typecho.org
 */
namespace TypechoPlugin\MultiLanguage;

use TypechoPlugin\MultiLanguage\Traits\SlugTrait;
use TypechoPlugin\MultiLanguage\Traits\RoutingTrait;
use TypechoPlugin\MultiLanguage\Traits\RepositoryTrait;
use TypechoPlugin\MultiLanguage\Traits\AdminTrait;
use TypechoPlugin\MultiLanguage\Traits\SchemaTrait;
use TypechoPlugin\MultiLanguage\Traits\ContentTrait;
use TypechoPlugin\MultiLanguage\Traits\ThemeTrait;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    use SlugTrait;
    use RoutingTrait;
    use RepositoryTrait;
    use AdminTrait;
    use SchemaTrait;
    use ContentTrait;
    use ThemeTrait;

    private const COOKIE_NAME = 'ml_lang';
    private const COOKIE_TTL = 86400 * 30;
    private const DEFAULT_LANG = 'zh-cn';
    private const PANEL_FILE = 'MultiLanguage/Manage.php';
    private const ACTION_NAME = 'ml-lang';
    private const PATH_MODE_ROOT = 'root';
    private const PATH_MODE_PREFIX = 'prefix';
    private const SCHEMA_VERSION = 4;

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

        // 路径设置与语言代码冲突时禁止启用，避免语言前缀抢占现有地址。
        $languageConflicts = self::conflictingSlugs(self::languageMap());
        if ($languageConflicts) {
            throw new PluginException(self::conflictMessages($languageConflicts));
        }

        self::installTables();
        self::installDefaultConfig();
        self::normalizeLanguageRows();

        $translationConflicts = self::translationSlugConflicts(self::languageMap());
        if ($translationConflicts) {
            throw new PluginException(implode('<br />', $translationConflicts));
        }

        self::ensureSchema();

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
        // 内部迁移版本：必须存在于表单中，否则 Typecho 渲染配置页时会找不到输入项。
        $config = self::getConfig();
        $form->addInput(new Hidden('schemaVersion', null, (string)($config->schemaVersion ?? '')));

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
     * 前台请求开始：解析语言、处理旧地址跳转并替换站点级文案。
     */
    public static function begin(): void
    {
        self::ensureSchema();
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


}
