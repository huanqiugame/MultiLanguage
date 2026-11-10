<?php

/**
 * 译文数据库读写。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait RepositoryTrait
{
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

        $slug = self::normalizeTranslationSlug($slug);
        $db = Db::get();
        $exists = $db->fetchRow(
            $db->select()->from('table.multilang_metas')
                ->where('mid = ?', $mid)->where('lang = ?', $lang)->limit(1)
        );

        if ($name === '' && $slug === '' && $description === '') {
            if ($exists) {
                $db->query($db->delete('table.multilang_metas')
                    ->where('mid = ?', $mid)->where('lang = ?', $lang));
            }
            return;
        }

        if ($slug !== '') {
            $slug = self::uniqueMetaTranslationSlug($slug, $lang, $mid);
        }

        $rows = [
            'name'        => $name,
            'slug'        => $slug === '' ? null : $slug,
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
        $slug = self::normalizeTranslationSlug($slug);
        if ($slug !== '') {
            $slug = self::uniqueContentTranslationSlug($slug, $lang, $cid);
        }
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
     * 避免并发或旧数据造成 (lang, slug) 重复，自动追加序号。
     *
     * @param string $slug
     * @param string $lang
     * @param int $cid
     * @return string
     */
    private static function uniqueContentTranslationSlug(string $slug, string $lang, int $cid): string
    {
        $db = Db::get();
        $base = $slug;
        $candidate = $slug;
        $index = 2;

        while (true) {
            try {
                $row = $db->fetchRow(
                    $db->select('cid')->from('table.multilang_contents')
                        ->where('lang = ?', strtolower($lang))
                        ->where('slug = ?', $candidate)
                        ->where('cid <> ?', $cid)
                        ->limit(1)
                );
            } catch (\Throwable $e) {
                return $candidate;
            }

            if (!$row) {
                return $candidate;
            }

            $candidate = $base . '-' . $index;
            $index++;
        }
    }

    /**
     * 避免分类/标签译文缩略名重复。
     *
     * @param string $slug
     * @param string $lang
     * @param int $mid
     * @return string
     */
    private static function uniqueMetaTranslationSlug(string $slug, string $lang, int $mid): string
    {
        $db = Db::get();
        $base = $slug;
        $candidate = $slug;
        $index = 2;

        while (true) {
            try {
                $row = $db->fetchRow(
                    $db->select('mid')->from('table.multilang_metas')
                        ->where('lang = ?', strtolower($lang))
                        ->where('slug = ?', $candidate)
                        ->where('mid <> ?', $mid)
                        ->limit(1)
                );
            } catch (\Throwable $e) {
                return $candidate;
            }

            if (!$row) {
                return $candidate;
            }

            $candidate = $base . '-' . $index;
            $index++;
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
