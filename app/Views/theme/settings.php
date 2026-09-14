<?php
/**
 * 主题设置视图 — 主题/配色切换（点击即切换）
 * @file app/Views/theme/settings.php
 */
$availableThemes = $this->getData('availableThemes', []);
$currentTheme = $this->getData('currentTheme', '');
$success = $this->getData('success', '');
$hideRightSidebar = (int)$this->getData('hideRightSidebar', 0);
$nightAuto = (int)$this->getData('nightAuto', 0);
$listExcerpt = (int)$this->getData('listExcerpt', 1);
$listExcerptLen = (int)$this->getData('listExcerptLen', 80);
$homeMode = (string)$this->getData('homeMode', 'follow');
?>
<?php $this->section('title'); ?><?php echo $this->t('theme.title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<div class="mn-section">
    <div class="mn-section-header">
        <h2><?php echo $this->icon('theme', 16); ?> <?php echo $this->t('theme.title'); ?></h2>
    </div>
    <div class="mn-p-20">
        <?php if ($success): ?><div class="mn-alert mn-alert-success"><?php echo $this->e($success); ?></div><?php endif; ?>
        <p class="mn-fs-13 mn-text-secondary mn-mb-20"><?php echo $this->t('theme.settings_hint', ['count' => count($availableThemes)]); ?></p>
        <form method="POST" id="themeForm">
            <input type="hidden" name="csrf" value="<?php echo $this->e($this->getData('csrfToken')); ?>">

            <!-- 界面偏好：隐藏右栏 / 跟随夜间切换（变更即保存） -->
            <div class="mn-p-16 mn-bg-row-hover mn-rounded-8 mn-mb-20">
                <label class="mn-flex mn-align-center mn-gap-8 mn-cursor-pointer mn-mb-8" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="hide_right_sidebar" value="1" <?php echo $hideRightSidebar ? 'checked' : ''; ?> onchange="document.getElementById('themeForm').submit();">
                    <span class="mn-fs-13 mn-fw-600"><?php echo $this->t('theme.hide_right_sidebar'); ?></span>
                </label>
                <p class="mn-fs-12 mn-text-muted mn-ml-24"><?php echo $this->t('theme.hide_right_sidebar_hint'); ?></p>
                <label class="mn-flex mn-align-center mn-gap-8 mn-cursor-pointer mn-mb-8" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="night_auto" value="1" <?php echo $nightAuto ? 'checked' : ''; ?> onchange="document.getElementById('themeForm').submit();">
                    <span class="mn-fs-13 mn-fw-600"><?php echo $this->t('theme.night_auto'); ?></span>
                </label>
                <p class="mn-fs-12 mn-text-muted mn-ml-24"><?php echo $this->t('theme.night_auto_hint'); ?></p>
                <!-- 列表内容模式：显示部分内容（开关 + 摘要长度 50~80 字） -->
                <label class="mn-flex mn-align-center mn-gap-8 mn-cursor-pointer mn-mb-8" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="list_excerpt" value="1" <?php echo $listExcerpt ? 'checked' : ''; ?> onchange="document.getElementById('themeForm').submit();">
                    <span class="mn-fs-13 mn-fw-600"><?php echo $this->t('theme.list_excerpt'); ?></span>
                </label>
                <p class="mn-fs-12 mn-text-muted mn-ml-24"><?php echo $this->t('theme.list_excerpt_hint'); ?></p>
                <label class="mn-flex mn-align-center mn-gap-8 mn-cursor-pointer mn-mb-8" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-left:24px;">
                    <span class="mn-fs-13 mn-fw-600"><?php echo $this->t('theme.list_excerpt_len'); ?></span>
                    <input type="number" name="list_excerpt_len" min="50" max="200" value="<?php echo $listExcerptLen; ?>" class="mn-input" style="width:80px;" onchange="document.getElementById('themeForm').submit();">
                </label>
                <!-- 首页模式：跟随站点默认 / 官网介绍 / 社区首页 -->
                <label class="mn-flex mn-align-center mn-gap-8 mn-cursor-pointer" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-left:24px;">
                    <span class="mn-fs-13 mn-fw-600"><?php echo $this->t('theme.home_mode'); ?></span>
                    <select name="home_mode" class="mn-input" style="width:140px;" onchange="document.getElementById('themeForm').submit();">
                        <option value="follow"<?php echo $homeMode === 'follow' ? ' selected' : ''; ?>><?php echo $this->t('theme.home_mode_follow'); ?></option>
                        <option value="portal"<?php echo $homeMode === 'portal' ? ' selected' : ''; ?>><?php echo $this->t('theme.home_mode_portal'); ?></option>
                        <option value="community"<?php echo $homeMode === 'community' ? ' selected' : ''; ?>><?php echo $this->t('theme.home_mode_community'); ?></option>
                    </select>
                </label>
            </div>
            <?php
            $groups = ['classic' => $this->t('theme.group_classic'), 'modern' => $this->t('theme.group_modern')];
            $grouped = [];
            foreach ($availableThemes as $themeKey => $themeInfo) {
                $g = $themeInfo['group'] ?? 'templates';
                $grouped[$g][] = ['key' => $themeKey, 'info' => $themeInfo];
            }
            ?>
            <?php foreach ($groups as $groupId => $groupLabel): ?>
                <?php if (empty($grouped[$groupId])) continue; ?>
                <div class="mn-mb-24">
                    <h3 class="mn-fs-14 mn-fw-600 mn-mb-12 mn-pb-8 mn-border-bottom"><?php echo $groupId === 'modern' ? '✦' : '◆'; ?> <?php echo $groupLabel; ?></h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">
                        <?php foreach ($grouped[$groupId] as $item): ?>
                            <?php $themeKey = $item['key']; $themeInfo = $item['info']; ?>
                            <?php $isActive = ($themeKey === $currentTheme); ?>
                            <?php $color = $this->e($themeInfo['color'] ?? '#667eea'); ?>
                            <label style="cursor:pointer;border:2px solid <?php echo $isActive ? $color : 'var(--mn-border)'; ?>;border-radius:8px;overflow:hidden;transition:all 0.15s;background:var(--mn-bg-card);<?php echo $isActive ? "box-shadow:0 0 0 1px {$color};" : ''; ?>" onclick="this.querySelector('input').checked=true;document.getElementById('themeForm').submit();">
                                <input type="radio" name="theme" value="<?php echo $this->e($themeKey); ?>" <?php echo $isActive ? 'checked' : ''; ?> style="position:absolute;opacity:0;">
                                <div style="height:48px;background:<?php echo $color; ?>;display:flex;align-items:center;justify-content:center;">
                                    <?php if ($isActive): ?>
                                    <span class="mn-theme-badge"><?php echo $this->t('theme.current'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mn-p-10-12">
                                    <div class="mn-fs-13 mn-fw-600"><?php echo $this->e($themeInfo['name'] ?? $themeKey); ?></div>
                                    <div class="mn-fs-11 mn-text-muted mn-mt-2"><?php echo $this->e($themeInfo['description'] ?? ''); ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
</div>
<?php $this->endSection(); ?>