<?php

/**
 * 多语言 sitemap 索引。
 *
 * 访问地址：/sitemap-index.xml
 *
 * 索引只引用本插件生成的两个文件：
 * - /sitemap-default.xml：默认语言
 * - /sitemap-multilang.xml：其他语言
 *
 * 不修改第三方 Sitemap 插件；如果仍在用它的 /sitemap.xml，可单独提交。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage;

use Widget\Base\Contents;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class SitemapIndex extends Contents
{
    /**
     * 输出 sitemapindex。
     */
    public function generate(): void
    {
        $xml = '<?xml version="1.0" encoding="' . $this->options->charset . '"?>' . PHP_EOL;
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach (['sitemap-default.xml', 'sitemap-multilang.xml'] as $file) {
            $xml .= '  <sitemap>' . PHP_EOL;
            $xml .= '    <loc>' . $this->escape(Plugin::siteFileUrl($file)) . '</loc>' . PHP_EOL;
            $xml .= '  </sitemap>' . PHP_EOL;
        }

        $xml .= '</sitemapindex>';

        $this->response->throwContent($xml, 'text/xml');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
