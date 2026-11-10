<?php

/**
 * 建表、升级与配置初始化。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage\Traits;

use Typecho\Config;
use Typecho\Db;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

trait SchemaTrait
{
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

        self::ensureTranslationIndexes($db, $prefix, $adapter);
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
                UNIQUE KEY `{$prefix}ml_contents_lang_slug_unique` (`lang`, `slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `{$prefix}multilang_metas` (
                `mid` int(10) NOT NULL,
                `lang` varchar(16) NOT NULL,
                `name` varchar(150) DEFAULT NULL,
                `slug` varchar(150) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`mid`, `lang`),
                UNIQUE KEY `{$prefix}ml_metas_lang_slug_unique` (`lang`, `slug`)
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
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}ml_contents_lang_slug_unique\"
                ON \"{$prefix}multilang_contents\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_metas\" (
                \"mid\" INTEGER NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150) DEFAULT NULL,
                \"slug\" varchar(150) DEFAULT NULL,
                \"description\" varchar(255) DEFAULT NULL,
                PRIMARY KEY (\"mid\", \"lang\")
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}ml_metas_lang_slug_unique\"
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
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}ml_contents_lang_slug_unique\"
                ON \"{$prefix}multilang_contents\" (\"lang\", \"slug\")",

            "CREATE TABLE IF NOT EXISTS \"{$prefix}multilang_metas\" (
                \"mid\" integer NOT NULL,
                \"lang\" varchar(16) NOT NULL,
                \"name\" varchar(150),
                \"slug\" varchar(150),
                \"description\" varchar(255),
                PRIMARY KEY (\"mid\", \"lang\")
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS \"{$prefix}ml_metas_lang_slug_unique\"
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
     * 升级旧安装：确保 (lang, slug) 唯一索引存在。
     */
    private static function ensureSchema(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }
        $checked = true;

        $config = self::getConfig();
        if ((string)($config->schemaVersion ?? '') === (string)self::SCHEMA_VERSION) {
            return;
        }

        $db = Db::get();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();
        if (!self::ensureTranslationIndexes($db, $prefix, $adapter)) {
            return;
        }

        $data = self::defaultConfig();
        foreach (
            ['languages', 'default', 'detect', 'autoSwitcher', 'dropTables', 'pathMode', 'schemaVersion'] as $key
        ) {
            $value = $config->{$key} ?? null;
            if ($value !== null) {
                $data[$key] = (string)$value;
            }
        }
        $data['schemaVersion'] = (string)self::SCHEMA_VERSION;

        try {
            \Widget\Plugins\Edit::configPlugin('MultiLanguage', $data);
        } catch (\Throwable $e) {
            // 配置写入失败时下次请求会重试迁移，不影响前台请求。
        }
    }

    /**
     * 清理旧数据中的重复译文缩略名，再建立唯一索引。
     *
     * @param Db $db
     * @param string $prefix
     * @param string $adapter
     * @return bool
     */
    private static function ensureTranslationIndexes(Db $db, string $prefix, string $adapter): bool
    {
        self::dedupeTranslationSlugs($db, 'multilang_contents', 'cid');
        self::dedupeTranslationSlugs($db, 'multilang_metas', 'mid');

        $indexes = [
            ["{$prefix}multilang_contents", "{$prefix}ml_contents_lang_slug_unique"],
            ["{$prefix}multilang_metas", "{$prefix}ml_metas_lang_slug_unique"],
        ];

        $ok = true;
        foreach ($indexes as [$table, $index]) {
            if (stripos($adapter, 'Mysql') !== false) {
                try {
                    $db->query(
                        "CREATE UNIQUE INDEX `{$index}` ON `{$table}` (`lang`, `slug`)",
                        Db::WRITE
                    );
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if (
                        stripos($message, 'Duplicate key name') === false
                        && stripos($message, 'already exists') === false
                    ) {
                        $ok = false;
                    }
                }
            } else {
                try {
                    $db->query(
                        "CREATE UNIQUE INDEX IF NOT EXISTS \"{$index}\""
                        . " ON \"{$table}\" (\"lang\", \"slug\")",
                        Db::WRITE
                    );
                } catch (\Throwable $e) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /**
     * 重复缩略名会保留最早的一条，其余清空以回退到原文地址。
     *
     * @param Db $db
     * @param string $table
     * @param string $idColumn
     */
    private static function dedupeTranslationSlugs(Db $db, string $table, string $idColumn): void
    {
        try {
            // 空字符串会占用唯一索引，统一转换为 NULL。
            $db->query(
                $db->update('table.' . $table)->rows(['slug' => null])->where('slug = ?', '')
            );
        } catch (\Throwable $e) {
            // 忽略：旧数据可能没有该列或表尚未创建。
        }

        try {
            $rows = $db->fetchAll(
                $db->select($idColumn, 'lang', 'slug')
                    ->from('table.' . $table)
                    ->where('slug <> ?', '')
            );
        } catch (\Throwable $e) {
            return;
        }

        $seen = [];
        foreach ($rows as $row) {
            $slug = trim((string)$row['slug']);
            if ($slug === '') {
                continue;
            }

            $key = strtolower((string)$row['lang']) . "\0" . strtolower($slug);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                continue;
            }

            try {
                $db->query(
                    $db->update('table.' . $table)
                        ->rows(['slug' => null])
                        ->where($idColumn . ' = ?', (int)$row[$idColumn])
                        ->where('lang = ?', $row['lang'])
                );
            } catch (\Throwable $e) {
                // 清理失败时保留数据；唯一索引创建会失败，下次再重试。
            }
        }

        // 统一大小写，避免旧数据出现只有大小写不同的地址。
        try {
            $rows = $db->fetchAll(
                $db->select($idColumn, 'lang', 'slug')
                    ->from('table.' . $table)
                    ->where('slug <> ?', '')
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $slug = trim((string)$row['slug']);
            if ($slug === '') {
                continue;
            }

            $normalized = strtolower($slug);
            if ($normalized === $slug) {
                continue;
            }

            try {
                $db->query(
                    $db->update('table.' . $table)
                        ->rows(['slug' => $normalized])
                        ->where($idColumn . ' = ?', (int)$row[$idColumn])
                        ->where('lang = ?', $row['lang'])
                );
            } catch (\Throwable $e) {
                // 与已有小写缩略名冲突时保留原值，交由冲突检查提示。
            }
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
}
