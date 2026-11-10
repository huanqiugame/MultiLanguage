<?php

/**
 * 主题 API、SEO 与语言切换。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Common;
use Typecho\Cookie;
use Typecho\Db;
use Typecho\Request;
use Typecho\Router;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait ThemeTrait
{
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
}
