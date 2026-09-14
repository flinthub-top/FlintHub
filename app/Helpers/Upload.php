<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 文件上传 — 类型/大小/MIME 校验、缩略图生成
 * @file app/Helpers/Upload.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Upload
{
    /**
     * 处理上传文件：类型/大小/MIME 校验、缩略图生成
     *
     * 大小上限与扩展名白名单默认读后台「系统设置→附件设置」（attachment_max_size / attachment_allowed_ext），
     * 未配置时回退 config.php 常量（MAX_FILE_SIZE / ALLOWED_EXTENSIONS），与改造前行为完全一致。
     * 注意：扩展名白名单可配，但实际可上传类型仍受内置 MIME 映射表约束（安全边界）。
     *
     * @param array $file         $_FILES 单个文件数组
     * @param array|null $allowedTypes 允许的扩展名列表；null 时从 Settings 读取
     */
    public static function file(array $file, ?array $allowedTypes = null): array
    {
        if ($allowedTypes === null) {
            $extList = Settings::get('attachment_allowed_ext', \implode(',', ALLOWED_EXTENSIONS));
            $allowedTypes = \array_filter(\array_map('trim', \explode(',', $extList)));
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => '文件上传失败'];
        }

        $maxSize = (int)Settings::get('attachment_max_size', (string)MAX_FILE_SIZE);
        if (!isset($file['size']) || $file['size'] > $maxSize) {
            return ['success' => false, 'error' => '文件大小超出限制'];
        }

        if (\strpos($file['name'] ?? '', "\0") !== false) {
            return ['success' => false, 'error' => '非法的文件名'];
        }

        $ext = \strtolower(\pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        // 白名单校验：不在允许列表内的后缀一律拒绝
        if (!\in_array($ext, $allowedTypes, true)) {
            return ['success' => false, 'error' => '不允许的文件类型'];
        }

        if (!isset($file['tmp_name']) || !\is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => '无效的上传文件'];
        }

        // MIME 校验（无条件执行，不在白名单 MIME 内一律拒绝）
        $finfo = \finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = \finfo_file($finfo, $file['tmp_name']);
            \finfo_close($finfo);
            if ($mime === false) return ['success' => false, 'error' => '无法识别文件类型'];

            $allowedMimes = [
                'jpg' => ['image/jpeg', 'image/pjpeg'], 'jpeg' => ['image/jpeg', 'image/pjpeg'],
                'png' => ['image/png'], 'gif' => ['image/gif'],
                'webp' => ['image/webp'],
                'pdf' => ['application/pdf'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
                'doc' => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ];
            // 严格 MIME 校验：后缀必须在映射表中，且 MIME 必须匹配
            if (!isset($allowedMimes[$ext]) || !\in_array($mime, $allowedMimes[$ext], true)) {
                return ['success' => false, 'error' => '文件类型与扩展名不匹配'];
            }
        } else {
            // finfo 不可用（fileinfo 扩展未安装）→ 无法验证真实 MIME，一律拒绝上传：
            // polyglot 文件可伪造扩展名绕过纯扩展名白名单（图片与非图片皆然），
            // 不再保留"非图片类仅扩展名白名单"的降级放行分支。
            \error_log('Upload: finfo_open failed（fileinfo 扩展不可用），上传被拒绝: ' . ($file['name'] ?? 'unknown'));
            return ['success' => false, 'error' => '无法验证文件类型，上传已禁用，请联系管理员'];
        }

        $filename = \bin2hex(\random_bytes(16)) . '.' . $ext;
        $destination = \rtrim(UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . $filename;

        if (!\is_dir(UPLOAD_PATH)) {
            @\mkdir(UPLOAD_PATH, 0755, true);
        }

        if (\move_uploaded_file($file['tmp_name'], $destination)) {
            @\chmod($destination, 0644);

            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (\in_array($ext, $imageExts, true)) {
                $thumbStatus = self::makeThumbnail($destination, $filename, $ext);
                // 像素超限拒绝：删除已落盘原图并整体回滚上传，避免"文件已删但前端仍报上传成功"的脏状态
                if ($thumbStatus === 'rejected') {
                    @\unlink($destination);
                    return ['success' => false, 'error' => '图片尺寸超出限制'];
                }
            }

            return [
                'success' => true,
                'filename' => $filename,
                'original_name' => $file['name'] ?? $filename,
                'size' => (int)$file['size'],
                'url' => UPLOAD_URL . $filename,
                'type' => $mime ?? '',
            ];
        }

        return ['success' => false, 'error' => '文件保存失败'];
    }

    /**
     * 生成缩略图（优先 Imagick，降级 GD）
     *
     * @param string $sourcePath 原图完整路径
     * @param string $filename   原图文件名
     * @param string $ext        扩展名
     * @param int    $maxW       缩略图最大宽度
     * @param int    $maxH       缩略图最大高度
     * @return string 'ok'=已生成缩略图 | 'skip'=无需/无法生成（原图保留） | 'rejected'=像素超限拒绝（调用方需回滚删除原图）
     */
    public static function makeThumbnail(string $sourcePath, string $filename, string $ext, int $maxW = 400, int $maxH = 300): string
    {
        $thumbName = 'thumb_' . $filename;
        $thumbPath = \rtrim(UPLOAD_PATH, '/\\') . \DIRECTORY_SEPARATOR . $thumbName;

        // 原图小于缩略图尺寸则没必要生成
        $info = @\getimagesize($sourcePath);
        if ($info === false) return 'skip';
        // 防 GD 像素炸弹：单边超过 5000 像素直接拒绝（rejected 由调用方回滚删除原图）
        if ($info[0] > 5000 || $info[1] > 5000) {
            return 'rejected';
        }
        if ($info[0] <= $maxW && $info[1] <= $maxH) return 'skip';

        // 防 OOM：解码前先估算位图内存（RGBA 4 字节/像素），超过 32MB 放弃生成缩略图（保留原图）
        $estBytes = $info[0] * $info[1] * 4;
        if ($estBytes > 32 * 1024 * 1024) {
            \error_log('[Upload] thumbnail skipped (memory estimate ' . $estBytes . 'B > 32MB): ' . $filename);
            return 'skip';
        }

        // 策略：先尝试 Imagick，没有则降级 GD
        if (\extension_loaded('imagick')) {
            self::makeThumbnailImagick($sourcePath, $thumbPath, $ext, $maxW, $maxH);
        } elseif (\extension_loaded('gd')) {
            self::makeThumbnailGd($sourcePath, $thumbPath, $ext, $maxW, $maxH);
        }
        return 'ok';
    }

    /**
     * Imagick 生成缩略图
     */
    private static function makeThumbnailImagick(string $sourcePath, string $thumbPath, string $ext, int $maxW, int $maxH): void
    {
        try {
            $img = new \Imagick();
            // 限制 Imagick 允许的格式，防 SVG/MSL/MVG 等格式触发 RCE
            $img->setOption('svg:auto-graphic', 'false');
            $img->readImageBlob(\file_get_contents($sourcePath));
            $img->setImageCompressionQuality(80);
            $img->thumbnailImage($maxW, $maxH, true, true);
            // 输出格式保持原图格式
            $img->writeImage($thumbPath);
            $img->clear();
            @\chmod($thumbPath, 0644);
        } catch (\Throwable $e) {
            \error_log('[Upload] Imagick thumbnail failed: ' . $e->getMessage());
        }
    }

    /**
     * GD 生成缩略图
     */
    private static function makeThumbnailGd(string $sourcePath, string $thumbPath, string $ext, int $maxW, int $maxH): void
    {
        try {
            // 根据扩展名选择创建函数
            $createFn = null;
            $outputFn = null;
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    $createFn = 'imagecreatefromjpeg';
                    $outputFn = 'imagejpeg';
                    break;
                case 'png':
                    $createFn = 'imagecreatefrompng';
                    $outputFn = 'imagepng';
                    break;
                case 'gif':
                    $createFn = 'imagecreatefromgif';
                    $outputFn = 'imagegif';
                    break;
                case 'webp':
                    if (\function_exists('imagecreatefromwebp')) {
                        $createFn = 'imagecreatefromwebp';
                        $outputFn = 'imagewebp';
                    }
                    break;
            }
            if ($createFn === null || $outputFn === null) return;

            $src = @$createFn($sourcePath);
            if ($src === false) return;

            $srcW = \imagesx($src);
            $srcH = \imagesy($src);

            // 计算缩放尺寸（cover 模式：按比例缩放，取较小值）
            $ratio = min($maxW / $srcW, $maxH / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);

            $thumb = \imagecreatetruecolor($newW, $newH);
            if ($thumb === false) { \imagedestroy($src); return; }

            // 保持 PNG 透明
            if ($ext === 'png') {
                \imagealphablending($thumb, false);
                \imagesavealpha($thumb, true);
            }

            \imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
            \imagedestroy($src);

            if ($ext === 'jpg' || $ext === 'jpeg') {
                $outputFn($thumb, $thumbPath, 80);
            } elseif ($ext === 'png') {
                $outputFn($thumb, $thumbPath, 8);
            } elseif ($ext === 'webp') {
                $outputFn($thumb, $thumbPath, 80);
            } else {
                $outputFn($thumb, $thumbPath);
            }
            \imagedestroy($thumb);
            @\chmod($thumbPath, 0644);
        } catch (\Throwable $e) {
            \error_log('[Upload] GD thumbnail failed: ' . $e->getMessage());
        }
    }
}
