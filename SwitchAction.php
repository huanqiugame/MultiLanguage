<?php

/**
 * 语言切换入口。
 *
 * 访问地址：/action/ml-lang?lang=en&redirect=/current/path
 *
 * 只负责三件事：校验语言、写入 Cookie、跳回干净的目标 URL。
 *
 * @package MultiLanguage
 */

namespace TypechoPlugin\MultiLanguage;

use Typecho\Request;
use Typecho\Widget;
use Typecho\Widget\Exception as WidgetException;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class SwitchAction extends Widget implements ActionInterface
{
    /**
     * 切换语言：写 Cookie 后 302 到目标页面。
     *
     * @throws WidgetException
     */
    public function action()
    {
        $request = Request::getInstance();
        $lang = strtolower(trim((string)$request->get('lang')));

        if (!isset(Plugin::languageMap()[$lang])) {
            throw new WidgetException(_t('语言不存在'), 404);
        }

        Plugin::rememberLanguage($lang);

        $redirect = $this->localRedirect((string)$request->get('redirect'));
        $target = $redirect ?? Plugin::urlForLang((string)Options::alloc()->siteUrl, $lang);

        $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->redirect($target, false);
    }

    /**
     * 只接受站内相对路径，避免开放重定向。
     *
     * @param string $redirect
     * @return string|null
     */
    private function localRedirect(string $redirect): ?string
    {
        $redirect = trim($redirect);
        if ($redirect === '' || $redirect[0] !== '/') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $redirect)) {
            return null;
        }

        $decoded = rawurldecode($redirect);
        if ($decoded === '' || $decoded[0] !== '/' || strpos($decoded, '//') === 0) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $decoded)) {
            return null;
        }

        $parts = parse_url($redirect);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $redirect;
    }
}
