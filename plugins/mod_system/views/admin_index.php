<?php
/**
 * 社区治理插件 — 后台管理（单入口 + 顶部 Tab：审核封禁 / 封禁配置）
 * 侧栏仅一个入口「社区管理」，页内用 Tab（Alpine 无刷新切换）在两个子页之间切换。
 * 首次激活哪个面板由控制器下发的 $tab 决定；落地页默认「封禁配置」。
 * @file plugins/mod_system/views/admin_index.php
 * @package Plugin\ModSystem
 */
use app\Helpers\I18n as _T;

$I        = function (string $k, array $p = []) { return _T::get($k, $p); };
$active   = (string)$this->getData('__nav_active', 'mod_system_reports');
$tab      = (string)$this->getData('tab', 'config');
$total    = (int)$this->getData('total', 0);
$siteName = $this->getData('siteName');
// 控制器已把 $tab 限定为 reports|config，此处再兜底保证为合法 JS 字面量
$initTab  = $tab === 'reports' ? 'reports' : 'config';
?>
<?php $this->section('title'); ?><?php echo $I('plugin.mod_system.admin_menu'); ?> - <?php echo $this->e($siteName); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php $cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: 1; ?>
<link rel="stylesheet" href="<?php echo $this->url('/plugins/mod_system/assets/style.css'); ?>?v=<?php echo $cssVer; ?>">

<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => $active]); ?>
    <main class="admin-content" x-data="{ tab: '<?php echo $initTab; ?>' }">
        <div class="admin-header">
            <h1><?php echo $this->icon('shield', 16); ?> <?php echo $I('plugin.mod_system.admin_menu'); ?></h1>
        </div>

        <div class="mod-admin-tabs" role="tablist">
            <button type="button" class="mod-admin-tab" role="tab"
                    :class="{ 'is-active': tab === 'reports' }"
                    :aria-selected="tab === 'reports' ? 'true' : 'false'"
                    @click="tab = 'reports'">
                <?php echo $this->icon('shield', 14); ?> <?php echo $I('plugin.mod_system.nav'); ?>
                <span class="mod-tab-count"><?php echo $total; ?></span>
            </button>
            <button type="button" class="mod-admin-tab" role="tab"
                    :class="{ 'is-active': tab === 'config' }"
                    :aria-selected="tab === 'config' ? 'true' : 'false'"
                    @click="tab = 'config'">
                <?php echo $this->icon('settings', 14); ?> <?php echo $I('plugin.mod_system.admin_config'); ?>
            </button>
        </div>

        <!-- 两个面板同时渲染（无刷新切换需都存在于 DOM）；x-cloak 按需加在「非默认激活」面板上，
             保证 Alpine 未挂载或加载受阻时，默认激活面板仍可见不空白 -->
        <div x-show="tab === 'reports'"<?php echo $initTab === 'config' ? ' x-cloak' : ''; ?>>
            <?php $this->include('plugins/mod_system/_reports_panel'); ?>
        </div>
        <div x-show="tab === 'config'"<?php echo $initTab === 'reports' ? ' x-cloak' : ''; ?>>
            <?php $this->include('plugins/mod_system/_config_panel'); ?>
        </div>
    </main>
</div>
<?php $this->endSection(); ?>