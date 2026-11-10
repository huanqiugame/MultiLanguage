# MultiLanguage

为 Typecho 1.3 添加完整的多语言支持：文章、独立页面、分类、标签和站点信息都能为每种语言单独编辑，访客通过语言地址或切换链接浏览对应语言。

> [!WARNING]
>
> 此插件仍处于早期开发阶段，欢迎测试、提交issue和PR。应用于正式生产环境时应当小心。

## 核心功能

- **内容全量翻译**：文章、独立页面、分类、标签的标题、正文、名称和描述均可按语言单独设置。
- **站点信息翻译**：站点标题、描述和关键词支持多语言。
- **语言地址**：英语使用 `/en/`，默认语言使用 `/`或可选自定义语言前缀，其他语言以此类推。
- **语言切换**：默认跟随浏览器语言，内置语言切换按钮模板，支持记住语言选择。
- **后台统一管理**：语言增删、默认语言、译文编辑和译文概览都可在后台完成。
- **编辑器无感切换**：在文章/页面编辑页可直接切换编辑不同语言文本。
- **SEO**：自动输出多语言 SEO 标记，并提供多语言 sitemap。
- **主题接口**：提供语言判断、地址生成和切换链接接口，主题可完全自定义展示。

## 安装

1. 将插件目录上传到 `usr/plugins/MultiLanguage`，目录名必须保持为 `MultiLanguage`。
2. 登录后台，在“控制台 → 插件”中启用“多语言”。
3. 打开“多语言 → 语言”，添加需要的语言并设置默认语言。
4. 在插件设置中开启“自动输出切换条”，或在主题中手动编写语言切换器。

启用后插件会自动创建所需数据表。

## 使用

### 添加语言和翻译内容

- 在“多语言 → 语言”中新增语言，例如 `en`、`ja`，并设置默认语言。
- 在“多语言 → 分类 / 标签”和“多语言 → 站点信息”中填写对应语言的译文。
- 翻译文章或页面：打开编辑页，在标题上方的语言下拉框中选择目标语言，编辑并保存。原文和译文会分别保存。
- 译文编辑页的“网址缩略名”可以单独填写；留空时该译文沿用原文地址。填写后会做冲突检查并用于当前语言的链接。
- 在“多语言 → 文章 / 页面”中可以查看哪些内容已有译文。

### 语言地址

以默认语言为中文（zh-cn）、添加英文（en）为例：

- `/`、`/archives/1`：中文（默认语言）
- `/en/`、`/en/archives/1`：English
- 访问 `/zh-cn/...` 会跳回不带前缀的中文地址。

如果希望所有语言都带前缀（`/zh-cn/...`、`/en/...`），在插件设置中把“语言路径模式”改为“全前缀”。

### 语言切换

主题中的切换链接由插件生成。访客点击后会记住其选择，并跳转到当前页面的对应语言版本。

## 技术性

### 语言与切换接口

```php
use TypechoPlugin\MultiLanguage\Plugin;

Plugin::currentLang();          // 当前语言代码
Plugin::defaultLang();          // 默认语言
Plugin::isDefaultLang();        // 当前是否为默认语言
Plugin::htmlLang();             // <html lang="..."> 使用
Plugin::languageMap();          // 所有语言：code => name
Plugin::languageName('en');     // 语言显示名称
Plugin::pageUrl('en');          // 当前页面在英文下的地址（无副作用）
Plugin::switchUrl('en');        // 英文切换入口，会先记住选择再跳转
Plugin::languageUrl('en');      // 同 switchUrl
Plugin::languageUrls();         // 所有语言的切换链接
Plugin::switcherLinks();        // 切换链接数据，便于完全自定义
Plugin::switcherHtml([...]);    // 返回默认切换器 HTML
Plugin::switcher();             // 直接输出默认切换器
Plugin::urlForLang($url, 'en'); // 给任意地址加上语言前缀
Plugin::hasTranslation($cid, 'en');
Plugin::translation($cid, 'en');
Plugin::metaTranslation($mid, 'en');
Plugin::option('title', 'en');  // 指定语言的站点标题
```

### 路由说明

- 语言代码只占用地址第一段，剩余路径交回 Typecho 原生路由，归档、分页、评论和 feed 保持原有行为。
- 译文缩略名会用于当前语言的地址；访问原文缩略名会 301 到译文地址，切换语言和 hreflang 也会映射到目标语言的缩略名。
- 插件会按当前语言调整 Typecho 生成的地址，文章、分类、标签、分页和 feed 的链接会自动带上语言前缀。
- 评论和评论分页继续使用 Typecho 原生路由，插件不会为它们重复注册前缀路由。
- `/action/...`、`/feed...`、`/sitemap-*.xml` 等系统地址不受语言前缀影响。
- 开启“自动检测”时，插件会输出 `Vary: Accept-Language, Cookie` 和 `Content-Language`，方便缓存层按语言区分。

## 站点配置

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| 语言路径模式 | 根路径 | 默认语言不带前缀；改为“全前缀”后所有语言都带前缀 |
| 自动检测 | 开启 | 访客未选择语言时按浏览器语言推荐；使用缓存时建议关闭 |
| 自动输出切换条 | 关闭 | 在主题 footer 自动输出切换器；也可以由主题手动调用 |
| 禁用时删除数据表 | 保留 | 默认保留译文，避免误删；确认不需要时可改为删除 |

### 缩略名冲突

语言代码会占用地址第一段，因此分类目录、分类、标签、页面和文章的缩略名不能与语言代码相同。保存时插件会自动检查并阻止冲突；启用插件和保存后台永久链接设置时也会先用当前路径规则做一次完整校验。

译文可以单独填写缩略名。保存时会检查语言代码、路由路径、分类目录、其他内容/译文缩略名，并通过 `(lang, slug)` 唯一索引避免重复。没有填写译文缩略名的内容继续沿用原文地址。

### SEO 与 sitemap

插件会自动补充当前语言对应的 canonical、`hreflang`、`x-default` 和 `og:locale`，搜索页与 404 页输出 `noindex,follow`。

多语言 sitemap 提供三个地址，不修改第三方 Sitemap 插件：

| 地址 | 内容 |
| --- | --- |
| `/sitemap-default.xml` | 默认语言内容 |
| `/sitemap-multilang.xml` | 其他语言中已有译文的内容 |
| `/sitemap-index.xml` | 引用上面两个 sitemap 的索引 |

如果继续使用第三方 `/sitemap.xml`，可以将它与 `/sitemap-multilang.xml` 分别提交；全前缀模式下建议改用 `/sitemap-index.xml`。

### 数据结构

译文保存在插件自建的表中，表名带 Typecho 的数据库前缀。禁用插件时默认保留这些数据。

| 表 | 用途 |
| --- | --- |
| `multilang_contents` | 文章/页面译文，`text` 使用与 Typecho 相同的 `<!--markdown-->` 格式前缀 |
| `multilang_metas` | 分类/标签译文 |
| `multilang_options` | 站点级译文，目前包括标题、描述、关键词 |
| `multilang_fields` | 为自定义字段预留，当前未接入前台替换 |

<details>
<summary>建表语句</summary>

```sql
CREATE TABLE typecho_multilang_contents (
  cid      INTEGER NOT NULL,
  lang     VARCHAR(16) NOT NULL,
  title    VARCHAR(150),
  slug     VARCHAR(150),
  text     TEXT,
  created  INTEGER DEFAULT 0,
  modified INTEGER DEFAULT 0,
  PRIMARY KEY (cid, lang)
);
CREATE UNIQUE INDEX typecho_ml_contents_lang_slug_unique ON typecho_multilang_contents (lang, slug);

CREATE TABLE typecho_multilang_metas (
  mid         INTEGER NOT NULL,
  lang        VARCHAR(16) NOT NULL,
  name        VARCHAR(150),
  slug        VARCHAR(150),
  description VARCHAR(255),
  PRIMARY KEY (mid, lang)
);
CREATE UNIQUE INDEX typecho_ml_metas_lang_slug_unique ON typecho_multilang_metas (lang, slug);

CREATE TABLE typecho_multilang_fields (
  cid       INTEGER NOT NULL,
  lang      VARCHAR(16) NOT NULL,
  name      VARCHAR(150) NOT NULL,
  str_value TEXT,
  PRIMARY KEY (cid, lang, name)
);

CREATE TABLE typecho_multilang_options (
  name  VARCHAR(64) NOT NULL,
  lang  VARCHAR(16) NOT NULL,
  value TEXT,
  PRIMARY KEY (name, lang)
);
```

</details>

## 兼容性与已知限制

- 译文缩略名已接入路由；未填写译文缩略名的内容沿用原文地址。
- 搜索目前只搜索默认语言；ExSearch 插件需要单独扩展才能搜索译文。
- 评论、附件和 RSS 已基本可用，附件在各语言下的地址仍需逐项验证。
- 自定义字段翻译表已建立，前台尚未接入。
- 删除分类或标签时不会自动清理对应译文。
- 数据量较大时，列表页逐条读取译文可能变慢。
- MySQL 和 PostgreSQL 的建表语句尚未在所有版本上实际验证。

## 目录结构

| 文件 | 说明 |
| --- | --- |
| `Plugin.php` | 插件入口：启停、建表、语言解析、内容过滤、编辑器集成、路由与 action 注册 |
| `Route.php` | 语言前缀路由，把 `/[lang]/...` 解析回 Typecho 原生路由 |
| `SwitchAction.php` | 语言切换 action，负责校验语言、记住选择和跳转 |
| `Manage.php` | 后台“多语言”面板 |
| `Sitemap.php` | 多语言 sitemap 生成 |
| `SitemapIndex.php` | sitemap 索引 |
| `TODO.md` | 后续开发计划 |

## 开发

插件为纯 PHP 实现，无 Composer 依赖。修改后可运行语法检查：

```bash
for file in *.php; do php -l "$file"; done
```
