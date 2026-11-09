<?php

/**
 * 多语言 sitemap。
 *
 * 访问地址：
 * - /sitemap-default.xml    默认语言
 * - /sitemap-multilang.xml  其他语言
 *
 * 两个文件都带 xhtml:link hreflang；索引见 SitemapIndex。
 *
 * 不修改第三方 Sitemap 插件；默认语言文件改名，避免与它的 /sitemap.xml 冲突。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage;

use Widget\Base\Contents;
use Widget\Contents\Page\Rows as PageRows;
use Widget\Contents\Post\Recent;
use Widget\Metas\Category\Rows as CategoryRows;
use Widget\Metas\Tag\Cloud;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Sitemap extends Contents
{
    /**
     * 非默认语言 sitemap。
     */
    public function generate(): void
    {
        $this->output(false);
    }

    /**
     * 默认语言 sitemap；prefix 模式下 URL 会自动带默认语言前缀。
     */
    public function generateDefault(): void
    {
        $this->output(true);
    }

    /**
     * @param bool $defaultOnly true 输出默认语言，false 输出其他语言
     */
    private function output(bool $defaultOnly): void
    {
        $default = Plugin::defaultLang();
        $languages = Plugin::languageMap();
        $targets = $defaultOnly
            ? [$default => $languages[$default]]
            : array_diff_key($languages, [$default => null]);

        $contentTranslations = $this->loadContentTranslations();
        $metaTranslations = $this->loadMetaTranslations();

        $xml = '<?xml version="1.0" encoding="' . $this->options->charset . '"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . PHP_EOL;

        // 首页：各语言都存在
        foreach ($targets as $lang => $name) {
            $xml .= $this->url(
                Plugin::urlForLang((string)$this->options->siteUrl, $lang),
                null,
                'daily',
                '1.0',
                $this->homeAlternates($languages)
            );
        }

        // 文章
        $postsCount = $this->size($this->select()
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.created < ?', $this->options->time)
            ->where('table.contents.type = ?', 'post'));

        if ($postsCount > 0) {
            $posts = Recent::alloc(['pageSize' => $postsCount]);
            while ($posts->next()) {
                $cid = (int)$posts->cid;
                $translations = $contentTranslations[$cid] ?? [];
                $xml .= $this->contentUrls(
                    (string)$posts->permalink,
                    $translations,
                    $targets,
                    $languages,
                    (int)$posts->modified,
                    'weekly',
                    '0.8'
                );
            }
        }

        // 独立页面
        $pages = PageRows::alloc();
        while ($pages->next()) {
            $cid = (int)$pages->cid;
            $translations = $contentTranslations[$cid] ?? [];
            $xml .= $this->contentUrls(
                (string)$pages->permalink,
                $translations,
                $targets,
                $languages,
                (int)$pages->modified,
                'monthly',
                '0.5'
            );
        }

        // 分类
        $categories = CategoryRows::alloc();
        while ($categories->next()) {
            $mid = (int)$categories->mid;
            $translations = $metaTranslations[$mid] ?? [];
            $xml .= $this->metaUrls(
                (string)$categories->permalink,
                $translations,
                $targets,
                $languages,
                'weekly',
                '0.6'
            );
        }

        // 标签
        $tags = Cloud::alloc();
        while ($tags->next()) {
            $mid = (int)$tags->mid;
            $translations = $metaTranslations[$mid] ?? [];
            $xml .= $this->metaUrls(
                (string)$tags->permalink,
                $translations,
                $targets,
                $languages,
                'weekly',
                '0.4'
            );
        }

        $xml .= '</urlset>';

        $this->response->throwContent($xml, 'text/xml');
    }

    /**
     * @param array<string, int> $translations
     * @param array<string, string> $targets
     * @param array<string, string> $languages
     */
    private function contentUrls(
        string $permalink,
        array $translations,
        array $targets,
        array $languages,
        int $modified,
        string $freq,
        string $priority
    ): string {
        $xml = '';
        foreach ($targets as $lang => $name) {
            if ($lang !== Plugin::defaultLang() && !isset($translations[$lang])) {
                continue;
            }

            $xml .= $this->url(
                Plugin::urlForLang($permalink, $lang),
                $modified,
                $freq,
                $priority,
                $this->alternates($permalink, $translations, $languages)
            );
        }

        return $xml;
    }

    /**
     * @param array<string, int> $translations
     * @param array<string, string> $targets
     * @param array<string, string> $languages
     */
    private function metaUrls(
        string $permalink,
        array $translations,
        array $targets,
        array $languages,
        string $freq,
        string $priority
    ): string {
        $xml = '';
        foreach ($targets as $lang => $name) {
            if ($lang !== Plugin::defaultLang() && !isset($translations[$lang])) {
                continue;
            }

            $xml .= $this->url(
                Plugin::urlForLang($permalink, $lang),
                null,
                $freq,
                $priority,
                $this->alternates($permalink, $translations, $languages)
            );
        }

        return $xml;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function loadContentTranslations(): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('cid', 'lang', 'modified')->from('table.multilang_contents')
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['cid']][(string)$row['lang']] = (int)$row['modified'];
        }
        return $result;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function loadMetaTranslations(): array
    {
        // multilang_metas 目前没有 modified 字段，用 0 表示未知。
        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'lang')->from('table.multilang_metas')
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['mid']][(string)$row['lang']] = 0;
        }
        return $result;
    }

    /**
     * @param array<string, string> $languages
     * @return array<string, string>
     */
    private function homeAlternates(array $languages): array
    {
        $urls = [];
        foreach ($languages as $lang => $name) {
            $urls[$lang] = Plugin::urlForLang((string)$this->options->siteUrl, $lang);
        }
        return $urls;
    }

    /**
     * @param array<string, int> $translations
     * @param array<string, string> $languages
     * @return array<string, string>
     */
    private function alternates(string $permalink, array $translations, array $languages): array
    {
        $urls = [Plugin::defaultLang() => Plugin::urlForLang($permalink, Plugin::defaultLang())];
        foreach (array_keys($translations) as $lang) {
            if (isset($languages[$lang]) && $lang !== Plugin::defaultLang()) {
                $urls[$lang] = Plugin::urlForLang($permalink, $lang);
            }
        }
        return $urls;
    }

    /**
     * @param string $url
     * @param int|null $lastmod
     * @param string $freq
     * @param string $priority
     * @param array<string, string> $alternates
     */
    private function url(
        string $url,
        ?int $lastmod,
        string $freq,
        string $priority,
        array $alternates
    ): string {
        $xml = '  <url>' . PHP_EOL;
        $xml .= '    <loc>' . $this->escape($url) . '</loc>' . PHP_EOL;
        if ($lastmod) {
            $xml .= '    <lastmod>' . date('c', $lastmod) . '</lastmod>' . PHP_EOL;
        }
        $xml .= '    <changefreq>' . $freq . '</changefreq>' . PHP_EOL;
        $xml .= '    <priority>' . $priority . '</priority>' . PHP_EOL;
        foreach ($alternates as $lang => $alternate) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->escape($lang)
                . '" href="' . $this->escape($alternate) . '" />' . PHP_EOL;
        }
        $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
            . $this->escape($alternates[Plugin::defaultLang()] ?? $url) . '" />' . PHP_EOL;
        $xml .= '  </url>' . PHP_EOL;

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
