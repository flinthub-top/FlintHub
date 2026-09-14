<?php
/**
 * FlintHub — 版块强制 Tag 插件 前台控制器
 * 提供版块强制配置 JSON 接口：发帖页未预选分类时，script.js 监听分类下拉变化后
 * 动态拉取所选版块的强制 Tag / 标题前缀配置（懒加载，未选分类不发请求）
 * @file plugins/forum_required_tag/FrontController.php
 * @package Plugin\ForumRequiredTag
 * @version 1.0.0
 */

namespace Plugin\ForumRequiredTag;

use app\Core\Controller;

class FrontController extends Controller
{
    /**
     * GET /api/forum-required-tag/config?forum_id=N
     * 返回该版块的强制配置 JSON（与 layout_head_end 直出的 #frt-config 数据结构一致）
     */
    public function config()
    {
        // 发帖页为登录态页面，配置接口同样要求登录（未登录 fetch 失败时前端静默不渲染）
        $this->requireLogin();

        $forumId = (int)($_GET['forum_id'] ?? 0);
        if ($forumId <= 0) {
            $this->json([
                'forum_id' => 0,
                'tags' => [],
                'prefixes' => [],
                'required_tag' => false,
                'required_prefix' => false,
                'i18n' => [
                    'hint_tags' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_tags'),
                    'hint_prefix' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_prefix'),
                ],
            ]);
            return;
        }

        $tags = \Plugin\ForumRequiredTag\Plugin::getForumTags($forumId);
        $prefixes = \Plugin\ForumRequiredTag\Plugin::getForumPrefixes($forumId);

        $this->json([
            'forum_id' => $forumId,
            'tags' => \array_column($tags, 'tag_name'),
            'prefixes' => \array_column($prefixes, 'prefix'),
            'required_tag' => !empty($tags),
            'required_prefix' => !empty($prefixes),
            'i18n' => [
                'hint_tags' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_tags'),
                'hint_prefix' => \app\Helpers\I18n::get('plugin.forum_required_tag.hint_prefix'),
            ],
        ]);
    }
}
