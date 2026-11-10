<?php

/**
 * 后台保存前校验与提示。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Common;
use Typecho\Db;
use Typecho\Request;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait AdminTrait
{
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

        // 永久链接设置会改变路径第一段，保存前必须重新验证语言代码冲突。
        if (strpos($path, 'options-permalink') !== false) {
            self::guardPermalinkSettings($request);
            return;
        }

        $languages = self::languageMap();

        if (strpos($path, 'metas-category-edit') !== false || strpos($path, 'metas-tag-edit') !== false) {
            $do = (string)$request->get('do');
            if (!in_array($do, ['insert', 'update'], true)) {
                return;
            }

            $slug = self::normalizeTranslationSlug((string)$request->get('slug'));
            if ($slug === '') {
                $slug = self::normalizeTranslationSlug((string)$request->get('name'));
            }

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

        $routeName = strpos($path, 'contents-page-edit') !== false ? 'page' : 'post';
        $translationLang = self::requestedAdminLang();
        $slug = self::normalizeTranslationSlug((string)$request->get('slug'));

        if ($translationLang !== null) {
            // 译文模式下只校验译文缩略名，原文字段由 prepareWrite() 恢复。
            if ($slug !== '') {
                $message = self::contentTranslationSlugError(
                    $slug,
                    $translationLang,
                    (int)$request->get('cid'),
                    $routeName
                );
                if ($message !== null) {
                    self::rejectConflict($message);
                }
            }
            return;
        }

        // 文章目录来自分类缩略名，先检查本次提交的分类。
        if ($routeName === 'post') {
            $categoryIds = $request->get('category', []);
            if (is_array($categoryIds) && $categoryIds) {
                $db = Db::get();
                $rows = $db->fetchAll(
                    $db->select('slug')->from('table.metas')
                        ->where('type = ?', 'category')
                        ->where('mid IN ?', array_map('intval', $categoryIds))
                );
                foreach ($rows as $row) {
                    $categorySlug = self::normalizeTranslationSlug((string)$row['slug']);
                    if ($categorySlug !== '' && isset($languages[$categorySlug])) {
                        self::rejectSlug($categorySlug, _t('分类目录'));
                    }
                }
            }
        }

        if ($slug === '') {
            $slug = self::normalizeTranslationSlug((string)$request->get('title'));
        }

        if ($slug !== '' && isset($languages[$slug])) {
            // 页面缩略名始终保留；文章缩略名只在它确实占用路径第一段时阻止。
            if ($routeName === 'page' || self::routeFirstSegmentIsParam($routeName, 'slug')) {
                self::rejectSlug($slug, $routeName === 'page' ? _t('页面') : _t('文章'));
            }
        }
    }

    /**
     * 保存永久链接前，用新的路由表验证语言代码和译文缩略名冲突。
     *
     * @param Request $request
     */
    private static function guardPermalinkSettings(Request $request): void
    {
        $postPattern = $request->get('postPattern');
        if (!is_string($postPattern) || $postPattern === '') {
            return;
        }

        if ($postPattern === 'custom') {
            $customPattern = $request->get('customPattern');
            if (!is_string($customPattern) || trim($customPattern) === '') {
                return;
            }
            $postPattern = '/' . ltrim(self::encodePermalinkRule($customPattern), '/');
        }

        $pagePattern = $request->get('pagePattern');
        $categoryPattern = $request->get('categoryPattern');
        if (!is_string($pagePattern) || !is_string($categoryPattern)) {
            return;
        }

        $raw = Options::alloc()->routingTable;
        unset($raw[0]);
        if (!isset($raw['post'], $raw['page'], $raw['category'])) {
            return;
        }

        $raw['post']['url'] = $postPattern;
        $raw['page']['url'] = '/' . ltrim(self::encodePermalinkRule($pagePattern), '/');
        $raw['category']['url'] = '/' . ltrim(self::encodePermalinkRule($categoryPattern), '/');
        if (isset($raw['category_page'])) {
            $raw['category_page']['url'] = rtrim($raw['category']['url'], '/') . '/[page:digital]/';
        }

        try {
            $parsed = (new \Typecho\Router\Parser($raw))->parse();
        } catch (\Throwable $e) {
            return;
        }

        $languages = self::languageMap();
        $messages = array_merge(
            self::conflictMessageList(self::conflictingSlugs($languages, $parsed)),
            self::translationSlugConflicts($languages, $parsed)
        );

        if ($messages) {
            \Widget\Notice::alloc()->set(implode('<br />', $messages), 'error');
            Options::alloc()->response->goBack();
        }
    }

    /**
     * 与核心 Permalink 组件相同的规则编码。
     *
     * @param string $rule
     * @return string
     */
    private static function encodePermalinkRule(string $rule): string
    {
        return str_replace(
            ['{cid}', '{slug}', '{category}', '{directory}', '{year}', '{month}', '{day}', '{mid}'],
            [
                '[cid:digital]', '[slug]', '[category]', '[directory:split:0]',
                '[year:digital:4]', '[month:digital:2]', '[day:digital:2]', '[mid:digital]',
            ],
            $rule
        );
    }

    /**
     * @param array<string, array{slug:string,label:string}> $conflicts
     * @return array<int, string>
     */
    private static function conflictMessageList(array $conflicts): array
    {
        $messages = [];
        foreach ($conflicts as $code => $conflict) {
            $messages[] = sprintf(
                _t('语言代码 “%s” 与%s “%s” 冲突，请先修改该路径或语言代码。'),
                $code,
                $conflict['label'],
                $conflict['slug']
            );
        }

        return $messages;
    }

    /**
     * @param array<string, array{slug:string,label:string}> $conflicts
     * @return string
     */
    private static function conflictMessages(array $conflicts): string
    {
        return implode('<br />', self::conflictMessageList($conflicts));
    }

    /**
     * 提示译文缩略名冲突并回退。
     *
     * @param string $message
     */
    private static function rejectConflict(string $message): void
    {
        \Widget\Notice::alloc()->set($message, 'error');
        Options::alloc()->response->goBack();
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
        foreach (['detect', 'autoSwitcher', 'dropTables', 'pathMode', 'schemaVersion'] as $key) {
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
}
