# MultiLanguage（可行性验证原型）

为 Typecho 1.3 增加多语言内容。默认语言仍存放在 Typecho 原生表中，其他语言存放在插件自建的 `multilang_*` 表中，避免修改 Typecho 核心表结构。

## 已实现的验证点

- 激活时按 MySQL / SQLite / Pgsql 分支建表。
- 前台语言解析：`/en/...` 语言前缀 > Cookie > `Accept-Language` > 默认语言。
- **语言切换**：主题切换链接指向 `/action/ml-lang`，先写入 `ml_lang` Cookie，再 302 到干净的页面 URL；不再使用 `?lang=` 作为语言选择入口。
- **语言路径模式**：
  - `root`（默认）：默认语言留在根路径（`/...`），其他语言使用 `/en/...`。
  - `prefix`：所有语言都带前缀（`/zh-cn/...`、`/en/...`），旧根地址 301 到默认语言前缀。
  - 语言标识只占用路径第一段，剩余路径交回 Typecho 原生路由，主题逻辑完全复用。
- 文章/页面标题、正文按当前语言替换；分类/标签名称、描述按当前语言替换。
- 站点标题、描述、关键词可按语言覆盖。
- **SEO**：Widget_Archive:header 注入 self-canonical、`hreflang`、`x-default`、`og:locale`；搜索/404 输出 `noindex,follow`；`beforeRender` 修正 `archiveUrl`；语言前缀 URL 会做 canonical 跳转校验。
- **编辑器复用**：文章/页面编辑页标题上方选择语言，服务端把对应译文载入原生 `#text`，因此 EditorMD、SMEditor 等仍操作同一个编辑器；保存时 `write` 过滤器把原文换回核心表，译文单独落库。
- **Markdown**：译文沿用 Typecho 的 `<!--markdown-->` 前缀，格式跟随原文/现有译文，不再额外选择格式。
- 后台“多语言”面板：
  - 语言管理（增删语言、设置默认语言，不再手填 `代码:名称` 字符串）
  - 分类/标签译文
  - 站点信息译文
  - 文章/页面译文概览
- 默认语言在分类/标签、站点信息页以“原文”只读信息展示，不再出现被禁用的空输入框。
- 禁用时默认保留译文表，可在插件设置中改为删除。

## 数据表

表名均带 Typecho 配置的前缀（示例前缀为 `typecho_`）。

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
CREATE INDEX typecho_ml_contents_lang_slug ON typecho_multilang_contents (lang, slug);

CREATE TABLE typecho_multilang_metas (
  mid         INTEGER NOT NULL,
  lang        VARCHAR(16) NOT NULL,
  name        VARCHAR(150),
  slug        VARCHAR(150),
  description VARCHAR(255),
  PRIMARY KEY (mid, lang)
);
CREATE INDEX typecho_ml_metas_lang_slug ON typecho_multilang_metas (lang, slug);

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

- `multilang_contents.text` 自身用 `<!--markdown-->` 前缀表达格式，和 Typecho 原生内容一致。
- `slug` 目前只保存、不参与路由；MVP 不启用译文 slug。
- `multilang_fields` 为自定义字段/SEO 元数据预留，当前未接入前台替换。
- `multilang_options` 当前接入 `title`、`description`、`keywords`。

## 主题 API

```php
use TypechoPlugin\MultiLanguage\Plugin;

Plugin::currentLang();          // 当前语言代码
Plugin::defaultLang();          // 默认语言
Plugin::isDefaultLang();        // 是否默认语言
Plugin::htmlLang();             // 主题 <html lang="..."> 用
Plugin::languageMap();          // code => name
Plugin::languageName('en');     // 显示名称
Plugin::pageUrl('en');          // 当前页面的英文干净 URL（无副作用，SEO 用）
Plugin::switchUrl('en');        // 切换入口：写 Cookie 后 302 到 pageUrl
Plugin::languageUrl('en');      // 切换链接（= switchUrl）
Plugin::languageUrls();         // 所有语言的切换链接
Plugin::switcherLinks();        // [{code,name,url,pageUrl,current,default}, ...]
Plugin::switcherHtml([...]);    // 返回 HTML，可自定义 class/separator
Plugin::switcher();             // 直接 echo，兼容旧用法
Plugin::pathMode();             // root | prefix
Plugin::urlForLang($url, 'en'); // 给任意 URL 加语言前缀
Plugin::hasTranslation($cid, 'en');
Plugin::translation($cid, 'en');
Plugin::metaTranslation($mid, 'en');
Plugin::option('title', 'en');  // 站点级译文
```

主题可以完全不用默认切换器，例如：

```php
foreach (Plugin::switcherLinks() as $link) {
    echo '<a href="' . htmlspecialchars($link['url']) . '">' . $link['name'] . '</a>';
}
```

## 编辑器与 Markdown

- 编辑页 URL 增加 `ml_lang=en` 后，服务端只对 `Widget\Contents\Post\Edit` / `Page\Edit` 合并译文，不影响后台列表和预览。
- 语言切换条通过 `admin/write-post.php` / `admin/write-page.php` 的 `content` 钩子输出，再由 `admin/write-js.php` 的 `write` 钩子移动到标题上方，不放在侧边栏。
- 原生编辑器、EditorMD、SMEditor 仍操作 `#text`，因此切换语言 = 重新加载编辑页，不需要复制第二个编辑器实例。
- 保存时 `write` 过滤器：
  - 捕获提交的译文；
  - 把 `$contents` 恢复为原文；
  - 由 `finishPublish` / `finishSave` 写入译文表。
- 译文格式完全跟随现有译文/原文的 `<!--markdown-->` 前缀，**不再提供额外格式下拉**：
  - 原文是 Markdown，新建译文默认也是 Markdown；
  - 已有译文按它自己存储的前缀判断；
  - 需要把译文改成另一种格式时，应通过编辑器自身能力或后续格式迁移处理。
- 共享字段（分类、标签、状态、密码、创建时间、模板、排序、父页面）在译文模式下按原文恢复，避免误改。

## 多语言 sitemap

不修改第三方 Sitemap 插件，新增三个文件：

```text
/sitemap-default.xml     默认语言
/sitemap-multilang.xml   其他语言
/sitemap-index.xml       索引，引用上面两个
```

- 每条 URL 带 `xhtml:link` 的 `hreflang` 和 `x-default`。
- 使用路径式 URL：根路径模式下默认语言无前缀，其他语言 `/en/...`；全前缀模式下默认语言也带 `/zh-cn/` 前缀。
- 语言对应关系保持一致：同一篇内容的各语言 URL 互为 alternate。
- 索引文件只引用本插件生成的两个 sitemap，不修改 `usr/plugins/Sitemap/Generator.php`。
- 如果继续使用第三方 `/sitemap.xml`，可以把它和 `/sitemap-multilang.xml` 分别提交；全前缀模式下第三方文件里的根路径 URL 会 301，建议改用 `/sitemap-index.xml`。

## 根路径 / 语言前缀

配置项：

```text
pathMode = root | prefix
```

- `root`（默认）：`/` 和 `/post/...` 是默认语言；`/en/` 和 `/en/post/...` 是其他语言。
  - 点击“中文”等默认语言切换链接时，会先访问 `/action/ml-lang` 写入 Cookie，再 302 回根路径。
  - `/zh-cn/...` 这类默认语言前缀会 301 回根路径。
- `prefix`：默认语言也使用 `/zh-cn/...`。
  - 旧根地址 301 到默认语言前缀。
  - 系统路径（`/action/...`、`/feed/...`、`/sitemap-*.xml`）不强制加语言前缀。

语言切换：

- 切换链接统一指向 `/action/ml-lang?lang=en&redirect=/current/path`。
- action 会校验语言、写入 `ml_lang` Cookie、校验 `redirect` 只能是站内相对路径，然后 302 到干净 URL。
- 不再支持 `?lang=xx` 作为语言选择入口；语言只由路径前缀和 Cookie 决定。

实现方式：

- 新增 `Route` 组件，路由 `/[lang]/...` 只占路径第一段，匹配后把剩余路径重新交给原生路由表。
- 新增 `/[lang]/action/...`、`/[lang]/feed...` 前缀路由，保证 `options->index` 加前缀后生成的系统链接仍然可用。
- 评论和评论分页继续使用 Typecho 原生路由：`[permalink]/comment`、`[permalink]/comment-page-N`。原生路由能直接接收带语言前缀的 permalink，**不要为它们再注册额外前缀路由**，否则失败的 `Router::match` 会把全局 matched 状态置为 true，导致后续评论请求报 `Path not found`。
- 通知 Typecho 的 `options->index` 带当前语言前缀，因此文章/分类/标签 permalink、分页和 feed 会自动保持语言。
- `checkPermalink` 保持开启，错误目录或旧 slug 会 301 到当前语言的正确 URL。

## slug 冲突检查

语言代码会占用路径第一段，因此保存语言、分类、标签、页面、文章时都会检查：

- 所有路由的静态首段（如 `blog`、`category`、`feed`）。
- 现有分类目录、分类/标签缩略名。
- **页面缩略名一律保留**（避免以后修改 permalink 规则时冲突）。
- 文章缩略名只在当前 permalink 规则确实把 slug 放在第一段时阻止；默认规则的第一段是分类目录。
- 自定义路由如果以动态段开头，后台会显示提示；插件无法推断该段的实际数据，需要站点自行避免。

冲突时后台保存会被阻止并给出提示，不会写入冲突数据。

## SEO 现状与下一步

已实现：

- 路径式 canonical：默认语言无前缀，非默认语言 `/en/...`（全前缀模式下默认语言也带前缀）。
- `hreflang` + `x-default`
- `og:locale` / `og:locale:alternate`
- 搜索/404 `noindex,follow`
- 单篇内容的核心 canonical 通过 `archiveUrl` 修正
- 路径式 sitemap：`/sitemap-default.xml`、`/sitemap-multilang.xml`、`/sitemap-index.xml`

建议下一步：

1. **译文 slug**：给 `(lang, slug)` 加唯一索引，请求时把译文 slug 映射回原文 slug/cid；页面还要处理多级 `directory`。
2. **多语言评论分页与附件 URL**：目前主要保证文章、页面、分类、标签、日期归档、分页和 feed。
3. **`<html lang>` 与主题硬编码文案**：必须在主题中调用 `Plugin::htmlLang()`；插件无法修改主题硬编码的 `<html lang="zh-cn">`。
4. **自动检测策略**：生产环境建议关闭 `detect`，避免同一 URL 因 Cookie/Accept-Language 返回不同语言内容。

## 当前限制

- URL 仍使用原始 slug；译文 slug 尚未接入路由。
- 路径式 URL 需要开启 Typecho 的 URL 重写（或服务器支持 PATH_INFO）；未开启重写时建议先留在查询参数方案。
- 搜索仍只搜默认语言；ExSearch 有自己的索引，译文不可搜。
- 评论、附件、RSS 的语言细节未完整处理；评论提交和 feed 已恢复可用，但附件在各语言下仍需逐项验证。
- 自定义字段翻译表已建，但前台未接入。
- 分类/标签删除时不会自动清理译文。
- 列表页逐条查询译文，数据量大时需要预加载或 JOIN。
- MySQL/Pgsql 建表语句未实际运行验证。
- 草稿/修订版（revision）在译文模式下的保存路径需要进一步测试。
- 旧译文如果没有 `<!--markdown-->` 前缀，前台会按 HTML/autoP 处理；升级时需要迁移旧数据。
- `EditorMD` / `SMEditor` 当前安装的旧版本会从外部调用 Typecho 1.3 已变为 protected 的 `markdown()` / `autoP()`，在 1.3 下本身就可能报错，需要等插件更新；本插件的原生编辑器复用方案不依赖它们。
- `ExSearch` 需要单独扩展索引/API 才能搜索译文；核心搜索和 ExSearch 目前都只覆盖原文。
- `<html lang="zh-cn">` 仍是主题硬编码；主题需改成 `Plugin::htmlLang()`。
