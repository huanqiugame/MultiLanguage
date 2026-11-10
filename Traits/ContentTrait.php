<?php

/**
 * 内容/分类译文替换与保存。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Common;
use Typecho\Request;
use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait ContentTrait
{
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
        if (!empty($translation['slug'])) {
            // 译文缩略名用于生成当前语言的 permalink；路由层会再映射回原文查询。
            $row['slug'] = (string)$translation['slug'];
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
        // 供编辑页填写译文缩略名；prepareWrite() 会在保存前换回原文 slug。
        $row['slug'] = $translation && !empty($translation['slug']) ? (string)$translation['slug'] : '';
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
            'slug'  => self::normalizeTranslationSlug((string)($contents['slug'] ?? '')),
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
        if (!empty($translation['slug'])) {
            $row['slug'] = (string)$translation['slug'];
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
            $slug = self::normalizeTranslationSlug((string)($translation['slug'] ?? ''));

            if (isset(self::languageMap()[$lang]) && $lang !== self::defaultLang()) {
                if ($title === '' && $text === '') {
                    self::deleteContentTranslation($cid, $lang);
                } else {
                    self::upsertContentTranslation($cid, $lang, $title, $text, $slug);
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
            $slug = self::normalizeTranslationSlug((string)($data['slug'] ?? ''));

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
}
