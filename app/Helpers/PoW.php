<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * PoW 工作量证明 — 自适应验证码防线的第一道防线
 * 前端自动计算 hash(challenge + nonce) 直至结果头部满足难度，后端复算放行
 * @file app/Helpers/PoW.php
 * @package app\Helpers
 */

namespace app\Helpers;

class PoW
{
    /**
     * 获取难度（可从 Settings 调整，预留扩展；失败时回退默认 4）
     */
    public static function getDifficulty(): int
    {
        try {
            $v = (int)Settings::get('pow_difficulty', '4');
        } catch (\Throwable $e) {
            $v = 4;
        }
        return $v >= 1 && $v <= 8 ? $v : 4;
    }

    /**
     * 签发一次性 challenge（Session 存储），并清掉旧的验证码/挑战，防复用
     *
     * @return array{mode:string, challenge:string, difficulty:int}
     *   mode: normal=PoW 即可 / captcha=需走图片验证码
     */
    public static function issue(): array
    {
        $difficulty = self::getDifficulty();

        // 不按 IP 频率决定 mode：PoW 通过即放行，IP 防爆破由后端 check('login', 20, 900) 硬限流兜底，
        // 图片验证码仅在后端校验 poW 失败时兜底
        $mode = 'normal';

        $challenge = \bin2hex(\random_bytes(16));

        $_SESSION['pow_challenge'] = $challenge;
        $_SESSION['pow_difficulty'] = $difficulty;
        $_SESSION['pow_time'] = \time();

        // 不在此清除验证码 Session 标记：验证码 hash 生命周期仅由 /api/captcha 与 Captcha::verify 管理

        return [
            'mode'       => $mode,
            'challenge'  => $challenge,
            'difficulty' => $difficulty,
        ];
    }

    /**
     * 校验前端提交的 nonce
     * @param string|null $nonce 前端计算出的 nonce
     * @return bool
     */
    public static function validate(?string $nonce): bool
    {
        if (empty($_SESSION['pow_challenge']) || empty($_SESSION['pow_difficulty'])) {
            return false;
        }

        // 挑战有效期（5 分钟内），过期即作废并清理
        $now = \time();
        if (($now - (int)($_SESSION['pow_time'] ?? 0)) > 300) {
            unset($_SESSION['pow_challenge'], $_SESSION['pow_difficulty'], $_SESSION['pow_time']);
            return false;
        }

        $challenge  = (string)$_SESSION['pow_challenge'];
        $difficulty = (int)$_SESSION['pow_difficulty'];

        // 一次性：无论成败都清理，防重放
        unset($_SESSION['pow_challenge'], $_SESSION['pow_difficulty'], $_SESSION['pow_time']);

        if (!\preg_match('/^\d{1,16}$/', (string)$nonce)) {
            return false;
        }

        $hash = \hash('sha256', $challenge . (string)$nonce);
        // 校验前导 difficulty 个十六进制字符是否全为 0
        $expected = \str_repeat('0', $difficulty);
        return \substr($hash, 0, $difficulty) === $expected;
    }
}