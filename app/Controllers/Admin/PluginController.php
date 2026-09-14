<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 后台插件管理控制器 — 插件列表、启用/禁用、编辑配置
 * @file app/Controllers/Admin/PluginController.php
 * @package app\Controllers\Admin
 */

namespace app\Controllers\Admin;

use app\Helpers\Csrf;
use app\Helpers\Plugin;

class PluginController extends BaseController
{
    public function index()
    {
        Plugin::refresh();
        $plugins = Plugin::getPlugins();

        // 固定 5 类（后台分类 Tab），'all' 表示全部
        $categories = ['system', 'community', 'content', 'entertainment', 'default'];
        $cat = trim((string)($_GET['cat'] ?? 'all'));
        if ($cat !== 'all' && !in_array($cat, $categories, true)) {
            $cat = 'all';
        }

        // 分类计数（基于全量，供 Tab 角标显示）
        $catCounts = ['all' => count($plugins)];
        foreach ($categories as $c) {
            $catCounts[$c] = 0;
        }
        foreach ($plugins as $p) {
            $c = $p['category'] ?? 'default';
            if (!in_array($c, $categories, true)) {
                $c = 'default';
            }
            $catCounts[$c]++;
        }

        if ($cat !== 'all') {
            $plugins = array_filter($plugins, function ($p) use ($cat) {
                return ($p['category'] ?? 'default') === $cat;
            });
        }

        // 启用在前、禁用在后（稳定排序，同状态保持原顺序）
        uasort($plugins, function ($a, $b) {
            return !empty($b['activated']) <=> !empty($a['activated']);
        });

        // 搜索：按名称/描述内存过滤（在当前分类内过滤，插件量级小，无需查库）
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query !== '') {
            $q = mb_strtolower($query);
            $plugins = array_filter($plugins, function ($p) use ($q) {
                return mb_strpos(mb_strtolower((string)($p['name'] ?? '')), $q) !== false
                    || mb_strpos(mb_strtolower((string)($p['description'] ?? '')), $q) !== false;
            });
        }

        // 分页：每页 6 个（卡片 2 列 × 3 行）
        $perPage = 6;
        $total = count($plugins);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
        $plugins = array_slice($plugins, ($page - 1) * $perPage, $perPage, true);

        $this->view('admin/plugins', [
            'plugins' => $plugins,
            'query' => $query,
            'cat' => $cat,
            'categories' => $categories,
            'catCounts' => $catCounts,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'permissionEnforce' => \app\Helpers\Settings::get('permission_enforce', '0') === '1',
            '__nav_active' => 'plugins',
        ]);
    }

    public function edit(string $name)
    {
        Plugin::refresh();
        $plugin = Plugin::getPlugin($name);
        if (!$plugin) {
            $_SESSION['flash_error'] = \app\Helpers\I18n::get('admin.plugin_not_found');
            $this->redirect('/admin/plugins');
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie($_POST['csrf'] ?? '');

            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'author' => trim($_POST['author'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
            ];

            if (empty($data['name'])) {
                $error = \app\Helpers\I18n::get('admin.plugin_name_required');
            } elseif (Plugin::updateConfig($name, $data)) {
                $success = \app\Helpers\I18n::get('admin.plugin_config_updated');
                $plugin = Plugin::getPlugin($name);
            } else {
                $error = \app\Helpers\I18n::get('admin.plugin_save_failed');
            }
        }

        // 钩子文件列表（仅显示路径，不泄露源码）
        $hookFiles = [];
        if (!empty($plugin['hooks'])) {
            foreach ($plugin['hooks'] as $hookName => $hookPath) {
                $fullPath = $plugin['path'] . '/' . ltrim($hookPath, '/');
                if (file_exists($fullPath)) {
                    $hookFiles[$hookName] = [
                        'path' => $hookPath,
                        'content' => '', // 不读取源码
                    ];
                }
            }
        }

        $hasPluginClass = file_exists($plugin['path'] . '/Plugin.php');
        $pluginClassContent = ''; // 不读取源码

        $this->view('admin/plugin-edit', [
            'plugin' => $plugin,
            'pluginName' => $name,
            'error' => $error,
            'success' => $success,
            'hookFiles' => $hookFiles,
            'hasPluginClass' => $hasPluginClass,
            'pluginClassContent' => $pluginClassContent,
            '__nav_active' => 'plugins',
        ]);
    }

    public function activate()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name && Plugin::activate($name)) {
            $_SESSION['flash_msg'] = \app\Helpers\I18n::get('admin.plugin_enabled_msg');
        } else {
            $_SESSION['flash_error'] = \app\Helpers\I18n::get('admin.plugin_enable_failed');
        }
        $this->redirect($this->pluginListBackUrl());
    }

    public function deactivate()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name && Plugin::deactivate($name)) {
            $_SESSION['flash_msg'] = \app\Helpers\I18n::get('admin.plugin_disabled_msg');
        } else {
            $_SESSION['flash_error'] = \app\Helpers\I18n::get('admin.plugin_disable_failed');
        }
        $this->redirect($this->pluginListBackUrl());
    }

    public function uninstall()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name && Plugin::uninstall($name)) {
            $_SESSION['flash_msg'] = \app\Helpers\I18n::get('admin.plugin_uninstalled_msg');
        } else {
            $_SESSION['flash_error'] = \app\Helpers\I18n::get('admin.plugin_uninstall_failed');
        }
        $this->redirect($this->pluginListBackUrl());
    }

    /**
     * 插件权限强制开关（后台插件列表页）：
     * 置 1=强制模式（插件未声明权限即执行被拒绝）；置 0=警告模式（仅记录日志，默认）
     */
    public function togglePermissionEnforce()
    {
        Csrf::verifyOrDie($_POST['csrf'] ?? '');
        $enforce = ($_POST['enforce'] ?? '0') === '1' ? '1' : '0';
        \app\Helpers\Settings::update('permission_enforce', $enforce);
        $_SESSION['flash_msg'] = $enforce === '1'
            ? \app\Helpers\I18n::get('admin.plugin_enforce_on_msg')
            : \app\Helpers\I18n::get('admin.plugin_enforce_off_msg');
        $this->redirect('/admin/plugins');
    }

    /**
     * 插件列表回跳 URL：保留操作前的分类 Tab / 搜索词 / 页码（白名单 + 强转，防参数注入）
     */
    private function pluginListBackUrl(): string
    {
        $url = '/admin/plugins';
        $parts = [];

        $cat = trim((string)($_POST['cat'] ?? ''));
        if ($cat !== '' && $cat !== 'all') {
            $parts[] = 'cat=' . rawurlencode($cat);
        }

        $q = trim((string)($_POST['q'] ?? ''));
        if ($q !== '') {
            $parts[] = 'q=' . rawurlencode($q);
        }

        $page = (int)($_POST['page'] ?? 1);
        if ($page > 1) {
            $parts[] = 'page=' . $page;
        }

        return $parts ? $url . '?' . implode('&', $parts) : $url;
    }
}
