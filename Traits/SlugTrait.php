<?php

/**
 * 译文 slug 解析、目录与冲突检查。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Common;
use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait SlugTrait
{
    /**
     * 归一化译文缩略名。译文地址统一使用小写，避免大小写造成重复路由。
     *
     * @param string $slug
     * @return string
     */
    public static function normalizeTranslationSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return '';
        }

        return Common::slugName($slug);
    }

    /**
     * 把地址里的译文缩略名映射回原文缩略名，保证核心查询能命中原文。
     *
     * @param string $lang
     * @param string $routeName
     * @param array<string, string> $params
     * @param array $route
     * @return array<string, string>
     */
    public static function resolveRouteParams(
        string $lang,
        string $routeName,
        array $params,
        array $route
    ): array {
        $lang = strtolower($lang);
        if ($lang === self::defaultLang()) {
            return $params;
        }

        $hasSlug = isset($params['slug']) && is_string($params['slug']) && $params['slug'] !== '';
        $hasDirectory = isset($params['directory']) && is_string($params['directory']) && $params['directory'] !== '';

        if ($routeName === 'post' || $routeName === 'page') {
            if ($routeName === 'post' && !$hasSlug) {
                return $params;
            }

            if ($hasSlug) {
                $candidate = $params['slug'];
            } elseif ($routeName === 'page' && $hasDirectory) {
                $candidate = self::lastPathSegment($params['directory']);
            } else {
                return $params;
            }

            $translation = self::fetchContentTranslationBySlug($lang, $candidate, $routeName);
            if (!$translation) {
                return $params;
            }

            $cid = (int)$translation['cid'];
            if (isset($params['cid']) && (int)$params['cid'] !== $cid) {
                return $params;
            }

            $sourceSlug = (string)($translation['source_slug'] ?? '');
            if ($sourceSlug === '') {
                return $params;
            }

            if ($hasSlug) {
                $params['slug'] = $sourceSlug;
            }

            // 独立页面可能使用多级 directory，必须整体换回原目录才能命中并生成 canonical。
            if ($routeName === 'page' && $hasDirectory) {
                $params['directory'] = implode('/', self::sourcePageDirectory($cid));
            }

            return $params;
        }

        if ($routeName === 'category' || $routeName === 'tag') {
            if (!$hasSlug) {
                return $params;
            }

            $translation = self::fetchMetaTranslationBySlug($lang, $params['slug'], $routeName);
            if (!$translation) {
                return $params;
            }

            if (isset($params['mid']) && (int)$params['mid'] !== (int)$translation['mid']) {
                return $params;
            }

            $sourceSlug = (string)($translation['source_slug'] ?? '');
            if ($sourceSlug !== '') {
                $params['slug'] = $sourceSlug;
            }
        }

        return $params;
    }

    /**
     * 多级页面的译文目录，按父级链逐级替换缩略名。
     *
     * @param int $cid
     * @param string $lang
     * @return array<int, string>
     */
    public static function translatedPageDirectory(int $cid, string $lang): array
    {
        $directory = self::sourcePageDirectory($cid);
        if (strtolower($lang) === self::defaultLang()) {
            return $directory;
        }

        foreach ($directory as $index => $slug) {
            $translation = self::fetchContentTranslationBySourceSlug($lang, $slug, 'page');
            if ($translation && !empty($translation['slug'])) {
                $directory[$index] = (string)$translation['slug'];
            }
        }

        return $directory;
    }

    /**
     * 分类目录是多级结构，逐段替换为译文缩略名。
     *
     * @param array<int, string> $slugs
     * @param string $lang
     * @return array<int, string>
     */
    public static function translateMetaDirectory(array $slugs, string $lang): array
    {
        if (strtolower($lang) === self::defaultLang()) {
            return $slugs;
        }

        foreach ($slugs as $index => $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $translation = self::fetchMetaTranslationBySourceSlug($lang, $slug);
            if ($translation && !empty($translation['slug'])) {
                $slugs[$index] = (string)$translation['slug'];
            }
        }

        return $slugs;
    }

    /**
     * 原文页面的完整目录（含自身）。
     *
     * @param int $cid
     * @return array<int, string>
     */
    private static function sourcePageDirectory(int $cid): array
    {
        $directory = [];
        $seen = [];
        $db = Db::get();

        while ($cid > 0 && !isset($seen[$cid])) {
            $seen[$cid] = true;
            try {
                $row = $db->fetchRow(
                    $db->select('cid', 'slug', 'parent')
                        ->from('table.contents')
                        ->where('cid = ?', $cid)
                        ->where('type = ?', 'page')
                        ->limit(1)
                );
            } catch (\Throwable $e) {
                break;
            }

            if (!$row) {
                break;
            }

            array_unshift($directory, (string)$row['slug']);
            $cid = (int)$row['parent'];
        }

        return $directory;
    }

    /**
     * 按译文缩略名查找内容。
     *
     * @param string $lang
     * @param string $slug
     * @param string $type
     * @return array|null
     */
    private static function fetchContentTranslationBySlug(string $lang, string $slug, string $type): ?array
    {
        $db = Db::get();

        try {
            $row = $db->fetchRow(
                $db->select(
                    'table.multilang_contents.cid',
                    'table.multilang_contents.slug',
                    ['table.contents.slug' => 'source_slug'],
                    ['table.contents.type' => 'source_type']
                )
                    ->from('table.multilang_contents')
                    ->join('table.contents', 'table.contents.cid = table.multilang_contents.cid')
                    ->where('table.multilang_contents.lang = ?', strtolower($lang))
                    ->where('table.multilang_contents.slug = ?', $slug)
                    ->where('table.contents.type = ?', $type)
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * 按原文缩略名查找同语言译文。
     *
     * @param string $lang
     * @param string $slug
     * @param string $type
     * @return array|null
     */
    private static function fetchContentTranslationBySourceSlug(string $lang, string $slug, string $type): ?array
    {
        $db = Db::get();

        try {
            $row = $db->fetchRow(
                $db->select(
                    'table.multilang_contents.cid',
                    'table.multilang_contents.slug',
                    ['table.contents.slug' => 'source_slug']
                )
                    ->from('table.multilang_contents')
                    ->join('table.contents', 'table.contents.cid = table.multilang_contents.cid')
                    ->where('table.multilang_contents.lang = ?', strtolower($lang))
                    ->where('table.contents.slug = ?', $slug)
                    ->where('table.contents.type = ?', $type)
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * 按译文缩略名查找分类/标签。
     *
     * @param string $lang
     * @param string $slug
     * @param string $type
     * @return array|null
     */
    private static function fetchMetaTranslationBySlug(string $lang, string $slug, string $type): ?array
    {
        $db = Db::get();

        try {
            $row = $db->fetchRow(
                $db->select(
                    'table.multilang_metas.mid',
                    'table.multilang_metas.slug',
                    ['table.metas.slug' => 'source_slug']
                )
                    ->from('table.multilang_metas')
                    ->join('table.metas', 'table.metas.mid = table.multilang_metas.mid')
                    ->where('table.multilang_metas.lang = ?', strtolower($lang))
                    ->where('table.multilang_metas.slug = ?', $slug)
                    ->where('table.metas.type = ?', $type)
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * 按原文缩略名查找分类/标签译文。
     *
     * @param string $lang
     * @param string $slug
     * @return array|null
     */
    private static function fetchMetaTranslationBySourceSlug(string $lang, string $slug): ?array
    {
        $db = Db::get();

        try {
            $row = $db->fetchRow(
                $db->select('table.multilang_metas.mid', 'table.multilang_metas.slug')
                    ->from('table.multilang_metas')
                    ->join('table.metas', 'table.metas.mid = table.multilang_metas.mid')
                    ->where('table.multilang_metas.lang = ?', strtolower($lang))
                    ->where('table.metas.slug = ?', $slug)
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * 内容译文缩略名冲突检查。
     *
     * @param string $slug
     * @param string $lang
     * @param int $excludeCid
     * @param string $type post|page
     * @param array|null $routes
     * @return string|null
     */
    public static function contentTranslationSlugError(
        string $slug,
        string $lang,
        int $excludeCid,
        string $type,
        ?array $routes = null
    ): ?string {
        $slug = self::normalizeTranslationSlug($slug);
        if ($slug === '') {
            return null;
        }

        $lang = strtolower($lang);
        $languages = self::languageMap();
        if (isset($languages[$slug])) {
            return sprintf(_t('译文缩略名 “%s” 与语言代码冲突，请换一个缩略名。'), $slug);
        }

        $db = Db::get();
        try {
            $sameTranslation = $db->fetchRow(
                $db->select('cid')->from('table.multilang_contents')
                    ->where('lang = ?', $lang)
                    ->where('slug = ?', $slug)
                    ->where('cid <> ?', $excludeCid)
                    ->limit(1)
            );
            if ($sameTranslation) {
                return sprintf(_t('译文缩略名 “%s” 已被其他内容译文使用。'), $slug);
            }

            $sameSource = $db->fetchRow(
                $db->select('cid', 'type')->from('table.contents')
                    ->where('slug = ?', $slug)
                    ->where('cid <> ?', $excludeCid)
                    ->limit(1)
            );
            if ($sameSource) {
                return sprintf(
                    _t('译文缩略名 “%s” 与%s缩略名冲突。'),
                    $slug,
                    ($sameSource['type'] ?? '') === 'page' ? _t('页面') : _t('文章')
                );
            }
        } catch (\Throwable $e) {
            return null;
        }

        try {
            $categorySlugs = self::categorySlugs(true);
        } catch (\Throwable $e) {
            $categorySlugs = [];
        }

        foreach ($categorySlugs as $categorySlug) {
            if (strtolower(trim((string)$categorySlug)) === $slug) {
                return sprintf(_t('译文缩略名 “%s” 与分类目录冲突。'), $slug);
            }
        }

        if (self::slugOccupiesFirstSegment($type, $routes) && isset(self::staticReservedSegments($routes)[$slug])) {
            return sprintf(_t('译文缩略名 “%s” 与路由路径冲突。'), $slug);
        }

        return null;
    }

    /**
     * 分类/标签译文缩略名冲突检查。
     *
     * @param string $slug
     * @param string $lang
     * @param int $excludeMid
     * @param string $type category|tag
     * @param array|null $routes
     * @return string|null
     */
    public static function metaTranslationSlugError(
        string $slug,
        string $lang,
        int $excludeMid,
        string $type,
        ?array $routes = null
    ): ?string {
        $slug = self::normalizeTranslationSlug($slug);
        if ($slug === '') {
            return null;
        }

        $lang = strtolower($lang);
        $languages = self::languageMap();
        if (isset($languages[$slug])) {
            return sprintf(_t('译文缩略名 “%s” 与语言代码冲突，请换一个缩略名。'), $slug);
        }

        $db = Db::get();
        try {
            $sameTranslation = $db->fetchRow(
                $db->select('mid')->from('table.multilang_metas')
                    ->where('lang = ?', $lang)
                    ->where('slug = ?', $slug)
                    ->where('mid <> ?', $excludeMid)
                    ->limit(1)
            );
            if ($sameTranslation) {
                return sprintf(_t('译文缩略名 “%s” 已被其他分类/标签译文使用。'), $slug);
            }

            $sameSource = $db->fetchRow(
                $db->select('mid', 'type')->from('table.metas')
                    ->where('slug = ?', $slug)
                    ->where('mid <> ?', $excludeMid)
                    ->limit(1)
            );
            if ($sameSource) {
                return sprintf(
                    _t('译文缩略名 “%s” 与%s缩略名冲突。'),
                    $slug,
                    ($sameSource['type'] ?? '') === 'category' ? _t('分类') : _t('标签')
                );
            }
        } catch (\Throwable $e) {
            return null;
        }

        if (self::routeFirstSegmentIsParam($type, 'slug', $routes) && isset(self::staticReservedSegments($routes)[$slug])) {
            return sprintf(_t('译文缩略名 “%s” 与路由路径冲突。'), $slug);
        }

        return null;
    }

    /**
     * 检查现有译文缩略名在指定路由表下是否仍安全。
     *
     * @param array<string, string> $languages
     * @param array|null $routes
     * @return array<int, string>
     */
    public static function translationSlugConflicts(array $languages, ?array $routes = null): array
    {
        $messages = [];
        $seen = [];
        $db = Db::get();

        try {
            $contents = $db->fetchAll(
                $db->select(
                    'table.multilang_contents.cid',
                    'table.multilang_contents.lang',
                    'table.multilang_contents.slug',
                    ['table.contents.type' => 'type']
                )
                    ->from('table.multilang_contents')
                    ->join('table.contents', 'table.contents.cid = table.multilang_contents.cid')
                    ->where('table.multilang_contents.slug <> ?', '')
            );
        } catch (\Throwable $e) {
            $contents = [];
        }

        foreach ($contents as $row) {
            if (!isset($languages[$row['lang']])) {
                continue;
            }
            $message = self::contentTranslationSlugError(
                (string)$row['slug'],
                (string)$row['lang'],
                (int)$row['cid'],
                (string)$row['type'],
                $routes
            );
            if ($message !== null && !isset($seen[$message])) {
                $seen[$message] = true;
                $messages[] = $message;
            }
        }

        try {
            $metas = $db->fetchAll(
                $db->select(
                    'table.multilang_metas.mid',
                    'table.multilang_metas.lang',
                    'table.multilang_metas.slug',
                    ['table.metas.type' => 'type']
                )
                    ->from('table.multilang_metas')
                    ->join('table.metas', 'table.metas.mid = table.multilang_metas.mid')
                    ->where('table.multilang_metas.slug <> ?', '')
            );
        } catch (\Throwable $e) {
            $metas = [];
        }

        foreach ($metas as $row) {
            if (!isset($languages[$row['lang']])) {
                continue;
            }
            $message = self::metaTranslationSlugError(
                (string)$row['slug'],
                (string)$row['lang'],
                (int)$row['mid'],
                (string)$row['type'],
                $routes
            );
            if ($message !== null && !isset($seen[$message])) {
                $seen[$message] = true;
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * 指定类型的缩略名是否会占用路径第一段。
     *
     * @param string $type
     * @param array|null $routes
     * @return bool
     */
    private static function slugOccupiesFirstSegment(string $type, ?array $routes = null): bool
    {
        if ($type === 'page') {
            return true;
        }

        return self::routeFirstSegmentIsParam($type, 'slug', $routes);
    }

    /**
     * 路由中纯静态的第一段。
     *
     * @param array|null $routes
     * @return array<string, bool>
     */
    private static function staticReservedSegments(?array $routes = null): array
    {
        $reserved = [];
        foreach ($routes ?? self::parsedRoutes() as $name => $route) {
            if (self::isOwnRoute((string)$name)) {
                continue;
            }
            $first = self::staticFirstSegment($route);
            if ($first !== '') {
                $reserved[$first] = true;
            }
        }

        return $reserved;
    }

    /**
     * 找出与现有路径第一段冲突的语言代码。
     *
     * 覆盖：静态路由首段、分类目录、文章/页面/标签缩略名，以及所有
     * 可能把某个参数放在路径第一段的内置路由。
     *
     * @param array<string, string> $languages
     * @param array|null $routes
     * @return array<string, array{slug:string,label:string}> code => conflict
     */
    public static function conflictingSlugs(array $languages, ?array $routes = null): array
    {
        $reserved = self::reservedFirstSegments($routes);
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
     * @param array|null $routes
     * @return array<string, array{slug:string,label:string}>
     */
    private static function reservedFirstSegments(?array $routes = null): array
    {
        $reserved = [];

        foreach ($routes ?? self::parsedRoutes() as $name => $route) {
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
}
