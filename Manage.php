<?php

/**
 * 多语言插件后台面板：语言管理、分类/标签、站点信息和译文概览。
 *
 * @package MultiLanguage
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include_once 'common.php';

/** @var \Widget\Options $options */
/** @var \Widget\User $user */
/** @var \Widget\Security $security */
/** @var \Typecho\Request $request */
/** @var \Typecho\Response $response */

$user->pass('administrator');

$pluginClass = '\TypechoPlugin\MultiLanguage\Plugin';
$languages = $pluginClass::languageMap();
$defaultLang = $pluginClass::defaultLang();
$editableLanguages = $languages;
unset($editableLanguages[$defaultLang]);

$tab = (string)$request->get('tab', 'meta');
if (!in_array($tab, ['meta', 'content', 'site', 'languages'], true)) {
    $tab = 'meta';
}

$lang = (string)$request->get('lang', '');
if (!isset($editableLanguages[$lang])) {
    $lang = $editableLanguages ? (string)array_key_first($editableLanguages) : $defaultLang;
}

$panelUrl = 'extending.php?panel=' . urlencode('MultiLanguage/Manage.php');
$csrfToken = $security->getToken('MultiLanguage/Manage.php');
$db = \Typecho\Db::get();
$pathMode = $pluginClass::pathMode();
$routeWarnings = $pluginClass::routeFirstSegmentWarnings();

/** 处理提交 */
if ($request->isPost()) {
    if (!hash_equals($csrfToken, (string)$request->get('_ml'))) {
        $response->goBack();
    }

    if ($tab === 'languages') {
        $rows = $request->get('langs', []);
        $new = $request->get('new', []);
        $delete = $request->get('delete', []);
        $newDefault = strtolower(trim((string)$request->get('default')));
        $errors = [];
        $result = [];

        if (is_array($rows)) {
            foreach ($rows as $code => $data) {
                $code = strtolower(trim((string)$code));
                $name = trim((string)($data['name'] ?? ''));
                if (is_array($delete) && in_array($code, $delete, true)) {
                    continue;
                }
                if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $code)) {
                    $errors[] = sprintf(_t('语言代码 “%s” 格式不正确'), $code);
                    continue;
                }
                if ($name === '') {
                    $errors[] = sprintf(_t('语言 “%s” 的名称不能为空'), $code);
                    continue;
                }
                $result[$code] = $name;
            }
        }

        if (is_array($new)) {
            $newCode = strtolower(trim((string)($new['code'] ?? '')));
            $newName = trim((string)($new['name'] ?? ''));
            if ($newCode !== '' || $newName !== '') {
                if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $newCode)) {
                    $errors[] = _t('新增语言的代码格式不正确');
                } elseif (isset($languages[$newCode])) {
                    $errors[] = sprintf(_t('语言代码 “%s” 已存在'), $newCode);
                } elseif ($newName === '') {
                    $errors[] = _t('新增语言的名称不能为空');
                } else {
                    $result[$newCode] = $newName;
                }
            }
        }

        if (is_array($delete)) {
            foreach ($delete as $code) {
                $code = trim((string)$code);
                if ($code === $defaultLang) {
                    $errors[] = _t('默认语言不能删除');
                    continue;
                }
                $count = 0;
                foreach (['multilang_contents', 'multilang_metas', 'multilang_options', 'multilang_fields'] as $table) {
                    $row = $db->fetchRow(
                        $db->select(['COUNT(*)' => 'num'])->from('table.' . $table)->where('lang = ?', $code)
                    );
                    $count += (int)($row['num'] ?? 0);
                }
                if ($count > 0) {
                    $errors[] = sprintf(_t('语言 “%s” 还有 %d 条译文，不能直接删除'), $code, $count);
                }
            }
        }

        if (empty($result)) {
            $errors[] = _t('至少需要保留一种语言');
        }
        if (!isset($result[$newDefault])) {
            $errors[] = _t('默认语言必须存在于语言列表中');
        }

        foreach ($pluginClass::conflictingSlugs($result) as $code => $conflict) {
            $errors[] = sprintf(
                _t('语言代码 “%s” 与%s “%s” 冲突，请先修改该路径或语言代码。'),
                $code,
                $conflict['label'],
                $conflict['slug']
            );
        }
        foreach ($pluginClass::translationSlugConflicts($result) as $message) {
            $errors[] = $message;
        }

        if ($errors) {
            \Widget\Notice::alloc()->set(implode('<br>', $errors), 'error');
        } else {
            if ($pluginClass::saveLanguages($result, $newDefault)) {
                \Widget\Notice::alloc()->set(_t('语言设置已经保存'), 'success');
            } else {
                \Widget\Notice::alloc()->set(_t('已有译文时不能直接切换默认语言；请先迁移或清空译文。'), 'error');
            }
        }

        $response->redirect($options->adminUrl($panelUrl . '&tab=languages', true));
    }

    if ($tab === 'meta' && $lang !== $defaultLang) {
        $rows = $request->get('meta', []);
        $metaErrors = [];
        $seenSlugs = [];
        if (is_array($rows)) {
            foreach ($rows as $mid => $data) {
                if (!is_array($data)) {
                    continue;
                }

                $type = (string)($data['type'] ?? '');
                if (!in_array($type, ['category', 'tag'], true)) {
                    $typeRow = $db->fetchRow(
                        $db->select('type')->from('table.metas')->where('mid = ?', (int)$mid)->limit(1)
                    );
                    $type = (string)($typeRow['type'] ?? '');
                }

                $slug = trim((string)($data['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $normalized = $pluginClass::normalizeTranslationSlug($slug);
                if (isset($seenSlugs[$normalized])) {
                    $metaErrors[$mid] = sprintf(
                        _t('译文缩略名 “%s” 在本次提交中重复。'),
                        $normalized
                    );
                    continue;
                }
                $seenSlugs[$normalized] = true;

                $message = $pluginClass::metaTranslationSlugError($slug, $lang, (int)$mid, $type);
                if ($message !== null) {
                    $metaErrors[$mid] = $message;
                }
            }
        }

        if ($metaErrors) {
            \Widget\Notice::alloc()->set(implode('<br />', $metaErrors), 'error');
            $response->redirect($options->adminUrl(
                $panelUrl . '&tab=meta&lang=' . rawurlencode($lang),
                true
            ));
        }

        if (is_array($rows)) {
            foreach ($rows as $mid => $data) {
                if (!is_array($data)) {
                    continue;
                }
                $pluginClass::saveMetaTranslation(
                    (int)$mid,
                    $lang,
                    trim((string)($data['name'] ?? '')),
                    trim((string)($data['slug'] ?? '')),
                    trim((string)($data['description'] ?? ''))
                );
            }
        }
    } elseif ($tab === 'site' && $lang !== $defaultLang) {
        $values = $request->get('site', []);
        $pluginClass::saveOptionTranslations($lang, is_array($values) ? $values : []);
    }

    $response->redirect($options->adminUrl(
        $panelUrl . '&tab=' . rawurlencode($tab)
            . ($tab === 'meta' || $tab === 'site' ? '&lang=' . rawurlencode($lang) : ''),
        true
    ));
}

$contents = [];
$metas = [];
$siteValues = [];
$originalSite = [
    'title'       => (string)$options->title,
    'description' => (string)$options->description,
    'keywords'    => (string)$options->keywords,
];

if ($tab === 'content') {
    $rows = $db->fetchAll(
        $db->select('cid', 'title', 'slug', 'type', 'status')
            ->from('table.contents')
            ->where('type = ? OR type = ?', 'post', 'page')
            ->order('cid', \Typecho\Db::SORT_DESC)
    );

    foreach ($rows as $row) {
        $row['translations'] = $pluginClass::contentTranslations((int)$row['cid']);
        $contents[] = $row;
    }
} elseif ($tab === 'meta') {
    $rows = $db->fetchAll(
        $db->select('mid', 'name', 'slug', 'type', 'description')
            ->from('table.metas')
            ->where('type = ? OR type = ?', 'category', 'tag')
            ->order('type', \Typecho\Db::SORT_ASC)
            ->order('mid', \Typecho\Db::SORT_ASC)
    );

    foreach ($rows as $row) {
        $row['translation'] = $lang !== $defaultLang
            ? $pluginClass::metaTranslation((int)$row['mid'], $lang)
            : null;
        $metas[] = $row;
    }
} elseif ($tab === 'site') {
    $siteValues = $lang !== $defaultLang ? $pluginClass::optionTranslations($lang) : [];
}

$formAction = $options->adminUrl(
    $panelUrl . '&tab=' . rawurlencode($tab)
        . ($tab === 'meta' || $tab === 'site' ? '&lang=' . rawurlencode($lang) : ''),
    true
);

include 'header.php';
include 'menu.php';
?>
<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="typecho-page-main">
            <ul class="typecho-option-tabs clearfix">
                <li class="<?php echo $tab === 'meta' ? 'current' : ''; ?>">
                    <a href="<?php echo $options->adminUrl($panelUrl . '&tab=meta&lang=' . rawurlencode($lang), true); ?>">
                        <?php _e('分类 / 标签'); ?>
                    </a>
                </li>
                <li class="<?php echo $tab === 'content' ? 'current' : ''; ?>">
                    <a href="<?php echo $options->adminUrl($panelUrl . '&tab=content', true); ?>">
                        <?php _e('文章 / 页面'); ?>
                    </a>
                </li>
                <li class="<?php echo $tab === 'site' ? 'current' : ''; ?>">
                    <a href="<?php echo $options->adminUrl($panelUrl . '&tab=site&lang=' . rawurlencode($lang), true); ?>">
                        <?php _e('站点信息'); ?>
                    </a>
                </li>
                <li class="<?php echo $tab === 'languages' ? 'current' : ''; ?>">
                    <a href="<?php echo $options->adminUrl($panelUrl . '&tab=languages', true); ?>">
                        <?php _e('语言'); ?>
                    </a>
                </li>
            </ul>

            <?php if (($tab === 'meta' || $tab === 'site') && $editableLanguages): ?>
                <div class="typecho-list-operate clearfix" style="margin:12px 0;">
                    <form method="get" action="<?php echo $options->adminUrl('extending.php', true); ?>">
                        <input type="hidden" name="panel" value="MultiLanguage/Manage.php" />
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>" />
                        <label for="ml-lang"><?php _e('编辑语言'); ?></label>
                        <select id="ml-lang" name="lang" onchange="this.form.submit()">
                            <?php foreach ($editableLanguages as $code => $label): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $code === $lang ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label . ' (' . $code . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn"><?php _e('切换'); ?></button></noscript>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'languages'): ?>
                <p class="description">
                    <?php echo sprintf(
                        _t('当前路径模式：%s。默认语言：%s。'),
                        $pathMode === 'prefix' ? _t('全前缀（所有语言都带前缀）') : _t('根路径（默认语言不带前缀）'),
                        htmlspecialchars($defaultLang, ENT_QUOTES, 'UTF-8')
                    ); ?>
                    <br />
                    <?php _e('语言代码会占用路径第一段；分类、页面、文章等缩略名与语言代码冲突时会被阻止。'); ?>
                    <br />
                    <?php _e('sitemap：'); ?>
                    <a href="<?php echo htmlspecialchars($pluginClass::siteFileUrl('sitemap-index.xml'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">sitemap-index.xml</a>
                    ·
                    <a href="<?php echo htmlspecialchars($pluginClass::siteFileUrl('sitemap-default.xml'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">sitemap-default.xml</a>
                    ·
                    <a href="<?php echo htmlspecialchars($pluginClass::siteFileUrl('sitemap-multilang.xml'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">sitemap-multilang.xml</a>
                </p>
                <?php if ($routeWarnings): ?>
                    <p class="message notice">
                        <?php echo implode('<br />', array_map(
                            function (string $warning): string {
                                return htmlspecialchars($warning, ENT_QUOTES, 'UTF-8');
                            },
                            $routeWarnings
                        )); ?>
                    </p>
                <?php endif; ?>
                <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_ml" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                    <table class="typecho-list-table">
                        <thead>
                        <tr>
                            <th><?php _e('语言代码'); ?></th>
                            <th><?php _e('显示名称'); ?></th>
                            <th><?php _e('默认语言'); ?></th>
                            <th><?php _e('删除'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($languages as $code => $name): ?>
                            <tr>
                                <td><input class="w-100 mono" type="text" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" readonly /></td>
                                <td><input class="w-100 text" type="text" name="langs[<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>][name]" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" /></td>
                                <td><input type="radio" name="default" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === $defaultLang ? 'checked' : ''; ?> /></td>
                                <td><input type="checkbox" name="delete[]" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === $defaultLang ? 'disabled' : ''; ?> /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input class="w-100 mono" type="text" name="new[code]" placeholder="ja" /></td>
                            <td><input class="w-100 text" type="text" name="new[name]" placeholder="日本語" /></td>
                            <td colspan="2" class="description"><?php _e('新增语言：填写代码和名称后保存'); ?></td>
                        </tr>
                        </tbody>
                    </table>
                    <p><button type="submit" class="btn primary"><?php _e('保存语言'); ?></button></p>
                </form>
            <?php elseif ($tab === 'meta'): ?>
                <?php if (!$editableLanguages): ?>
                    <p class="message notice"><?php _e('当前只有默认语言。请先在“语言”中添加其他语言。'); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_ml" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                    <table class="typecho-list-table">
                        <thead>
                        <tr>
                            <th><?php _e('类型'); ?></th>
                            <th><?php _e('原文名称'); ?></th>
                            <th><?php _e('原文描述'); ?></th>
                            <?php if ($lang !== $defaultLang): ?>
                                <th><?php _e('译文名称'); ?></th>
                                <th><?php _e('译文描述'); ?></th>
                                <th><?php _e('译文缩略名'); ?></th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($metas as $meta): ?>
                            <?php $tr = $meta['translation'] ?? []; ?>
                            <tr>
                                <td>
                                    <?php echo $meta['type'] === 'category' ? _t('分类') : _t('标签'); ?>
                                    <?php if ($lang !== $defaultLang): ?>
                                        <input type="hidden"
                                               name="meta[<?php echo (int)$meta['mid']; ?>][type]"
                                               value="<?php echo htmlspecialchars((string)$meta['type'], ENT_QUOTES, 'UTF-8'); ?>" />
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)$meta['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$meta['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($lang !== $defaultLang): ?>
                                    <td>
                                        <input class="w-100 text" type="text"
                                               name="meta[<?php echo (int)$meta['mid']; ?>][name]"
                                               value="<?php echo htmlspecialchars($tr['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                                    </td>
                                    <td>
                                        <input class="w-100 text" type="text"
                                               name="meta[<?php echo (int)$meta['mid']; ?>][description]"
                                               value="<?php echo htmlspecialchars($tr['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                                    </td>
                                    <td>
                                        <input class="w-100 mono" type="text"
                                               name="meta[<?php echo (int)$meta['mid']; ?>][slug]"
                                               value="<?php echo htmlspecialchars($tr['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($lang !== $defaultLang): ?>
                        <p><button type="submit" class="btn primary"><?php _e('保存分类/标签译文'); ?></button></p>
                    <?php else: ?>
                        <p class="description"><?php _e('默认语言是原文，请到 Typecho 原生的分类/标签管理页面修改。'); ?></p>
                    <?php endif; ?>
                </form>
            <?php elseif ($tab === 'content'): ?>
                <table class="typecho-list-table">
                    <thead>
                    <tr>
                        <th><?php _e('ID'); ?></th>
                        <th><?php _e('原文标题'); ?></th>
                        <th><?php _e('类型'); ?></th>
                        <th><?php _e('已有译文'); ?></th>
                        <th><?php _e('编辑'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contents as $content): ?>
                        <?php
                        $editFile = $content['type'] === 'page' ? 'write-page.php' : 'write-post.php';
                        $translated = array_keys($content['translations']);
                        ?>
                        <tr>
                            <td><?php echo (int)$content['cid']; ?></td>
                            <td><?php echo $content['title']; ?></td>
                            <td><?php echo $content['type'] === 'page' ? _t('页面') : _t('文章'); ?></td>
                            <td><?php echo $translated ? htmlspecialchars(implode(', ', $translated), ENT_QUOTES, 'UTF-8') : _t('无'); ?></td>
                            <td>
                                <a href="<?php echo $options->adminUrl($editFile . '?cid=' . (int)$content['cid'], true); ?>">
                                    <?php _e('原文'); ?>
                                </a>
                                <?php foreach ($editableLanguages as $code => $name): ?>
                                    · <a href="<?php echo $options->adminUrl($editFile . '?cid=' . (int)$content['cid'] . '&ml_lang=' . rawurlencode($code), true); ?>">
                                        <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_ml" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                    <table class="typecho-list-table">
                        <thead>
                        <tr>
                            <th><?php _e('项目'); ?></th>
                            <th><?php _e('原文'); ?></th>
                            <?php if ($lang !== $defaultLang): ?><th><?php _e('译文'); ?></th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (['title' => _t('站点标题'), 'description' => _t('站点描述'), 'keywords' => _t('关键词')] as $key => $label): ?>
                            <tr>
                                <th style="width:140px;"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></th>
                                <td><?php echo htmlspecialchars($originalSite[$key], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($lang !== $defaultLang): ?>
                                    <td><input class="w-100 text" type="text" name="site[<?php echo $key; ?>]"
                                               value="<?php echo htmlspecialchars($siteValues[$key] ?? '', ENT_QUOTES, 'UTF-8'); ?>" /></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($lang !== $defaultLang): ?>
                        <p><button type="submit" class="btn primary"><?php _e('保存站点译文'); ?></button></p>
                    <?php else: ?>
                        <p class="description"><?php _e('默认语言是原文，请到 Typecho 原生的“设置 → 基本”页面修改。'); ?></p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
