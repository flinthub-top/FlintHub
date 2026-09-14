<?php
/**
 * 后台等级设置视图 — 用户等级配置、经验值规则管理
 * @file app/Views/admin/levels.php
 */
?>
<?php $this->section('title'); ?><?php echo $this->t('admin.levels_title'); ?> - <?php echo $this->e($this->getData('siteName')); ?><?php $this->endSection(); ?>
<?php $this->section('content'); ?>
<?php
$levels = $this->getData('levels', []);
$msg = $this->getData('msg');
$error = $this->getData('error');
$csrfToken = $this->e($this->getData('csrfToken'));
?>
<div class="admin-container">
    <?php $this->include('admin/_sidebar', ['__nav_active' => 'levels']); ?>
    <main class="admin-content">
        <div class="admin-header">
            <h1><?php echo $this->icon('levels', 18); ?> <?php echo $this->t('admin.levels_title'); ?></h1>
        </div>
        <div class="admin-section">
            <p class="c-666 mn-fs-13 mn-mb-20">
                <?php echo $this->t('admin.levels_hint'); ?>
                <br><?php echo $this->t('admin.levels_hint2'); ?>
            </p>
            <?php if ($msg): ?><div class="alert alert-success"><?php echo $this->e($msg); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $this->e($error); ?></div><?php endif; ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-w-60"><?php echo $this->t('admin.level'); ?></th>
                        <th><?php echo $this->t('admin.icon'); ?></th>
                        <th><?php echo $this->t('admin.level_name'); ?></th>
                        <th class="admin-w-60 mn-text-center"><?php echo $this->t('admin.required_points'); ?></th>
                        <th class="admin-w-60 mn-text-center"><?php echo $this->t('admin.bg_color'); ?></th>
                        <th class="admin-w-130"><?php echo $this->t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($levels as $lv): ?>
                    <tr class="tr-border-bottom">
                        <td class="mn-fw-bold mn-text-center">Lv.<?php echo (int)$lv['level']; ?></td>
                        <td class="mn-fs-16"><?php echo $this->e($lv['icon']); ?></td>
                        <td>
                            <span class="lv-badge" style="background:<?php echo $this->e($lv['color']); ?>;">
                                <?php echo $this->e($lv['title']); ?>
                            </span>
                        </td>
                        <td class="mn-text-center"><?php echo number_format((int)$lv['required_points']); ?></td>
                        <td class="mn-text-center">
                            <span class="color-swatch" style="background:<?php echo $this->e($lv['color']); ?>;"></span>
                            <code class="mn-fs-11"><?php echo $this->e($lv['color']); ?></code>
                        </td>
                        <td class="mn-text-center">
                            <button type="button" class="btn-sm bg-f0 border-ddd btn-edit-level"
                                    data-level="<?php echo (int)$lv['level']; ?>"
                                    data-title="<?php echo $this->e($lv['title']); ?>"
                                    data-points="<?php echo (int)$lv['required_points']; ?>"
                                    data-icon="<?php echo $this->e($lv['icon']); ?>"
                                    data-color="<?php echo $this->e($lv['color']); ?>"><?php echo $this->t('common.edit'); ?></button>
                            <form method="POST" class="mn-inline-block" x-data x-on:submit.prevent="if(!confirm(<?php echo htmlspecialchars(json_encode(\app\Helpers\I18n::get('admin.delete_level_confirm', ['level' => '__LV__'])), ENT_QUOTES, 'UTF-8'); ?>.replace('__LV__', <?php echo (int)$lv['level']; ?>))) return; $el.submit()">
                                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="level" value="<?php echo (int)$lv['level']; ?>">
                                <button type="submit" class="btn-delete"><?php echo $this->t('common.delete'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($levels)): ?>
                    <tr><td colspan="6" class="empty-row"><?php echo $this->t('admin.no_levels'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <hr class="admin-hr">
            <h3 class="mn-mb-15 mn-fs-16"><?php echo $this->icon('edit', 15); ?> <?php echo $this->t('admin.add_edit_level'); ?></h3>
            <form method="POST" class="level-form-grid">
                <input type="hidden" name="csrf" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save">
                <div>
                    <label class="label-inline"><?php echo $this->t('admin.level'); ?> (Lv.)</label>
                    <input type="number" id="form_level" name="level" min="1" max="99" required class="form-input">
                </div>
                <div>
                    <label class="label-inline"><?php echo $this->t('admin.level_icon'); ?></label>
                    <input type="text" id="form_icon" name="icon" maxlength="10" placeholder="🌟" class="form-input">
                </div>
                <div>
                    <label class="label-inline"><?php echo $this->t('admin.level_name'); ?></label>
                    <input type="text" id="form_title" name="title" required maxlength="50" class="form-input">
                </div>
                <div>
                    <label class="label-inline"><?php echo $this->t('admin.required_points'); ?></label>
                    <input type="number" id="form_points" name="required_points" min="0" value="0" class="form-input">
                </div>
                <div>
                    <label class="label-inline"><?php echo $this->t('admin.bg_color'); ?></label>
                    <div class="mn-flex mn-gap-4">
                        <input type="color" id="form_color_picker" value="#999999" class="input-color">
                        <input type="text" id="form_color" name="color" maxlength="20" placeholder="#999" class="form-input min-w-80">
                    </div>
                </div>
                <div class="align-self-end">
                    <button type="submit" class="btn-save"><?php echo $this->t('common.save'); ?></button>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-edit-level').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('form_level').value = this.dataset.level;
            document.getElementById('form_title').value = this.dataset.title;
            document.getElementById('form_points').value = this.dataset.points;
            document.getElementById('form_icon').value = this.dataset.icon;
            document.getElementById('form_color').value = this.dataset.color;
            document.getElementById('form_color_picker').value = this.dataset.color;
            window.scrollTo({top: document.querySelector('.level-form').offsetTop - 20, behavior: 'smooth'});
        });
    });
    var picker = document.getElementById('form_color_picker');
    var textInput = document.getElementById('form_color');
    picker.addEventListener('input', function() { textInput.value = this.value; });
    textInput.addEventListener('input', function() {
        if (/^#[0-9a-f]{6}$/i.test(this.value)) { picker.value = this.value; }
    });
});
</script>
<?php $this->endSection(); ?>