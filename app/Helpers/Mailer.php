<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * 邮件发送工具 — SMTP 驱动、密码加密/解密、模板渲染
 * @file app/Helpers/Mailer.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Mailer
{
    /** 最后一次 SMTP 服务器响应（用于错误诊断） */
    private static string $lastResponse = '';

    /**
     * 从数据库 settings 读取 SMTP 配置，并回退到 config.php 的 define()
     */
    private static function cfg(string $key, string $default = ''): string
    {
        try {
            $val = \app\Helpers\Settings::get('mail_' . $key, '');
            if ($val !== '') {
                // SMTP 密码自动解密
                if ($key === 'pass') {
                    return self::decryptSmtpPass($val);
                }
                return $val;
            }
        } catch (\Throwable $e) {
            // Settings 未初始化时忽略
        }
        $const = 'MAIL_' . strtoupper($key);
        return defined($const) ? constant($const) : $default;
    }

    /**
     * 新格式魔数（AES-256-GCM）：6 字节固定前缀 + 版本字节。
     * 用于与旧版 AES-256-CBC 密文（无前缀）区分，旧密文可继续解密（向后兼容）。
     */
    private const GCM_MAGIC = "FHGCM\x01";

    /**
     * 加密 SMTP 密码（AES-256-GCM，带认证标签；旧数据格式见 decryptSmtpPass）
     * @param string $plain 明文密码
     * @return string base64 编码的密文
     */
    public static function encryptSmtpPass(string $plain): string
    {
        // 必须通过 config.php 定义 MAIL_PASS_KEY，禁止源码硬编码
        $key = MAIL_PASS_KEY;
        $iv  = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            // GCM 不可用兜底：退回旧版 AES-256-CBC（保持原有可用性）
            \error_log('Mailer::encryptSmtpPass aes-256-gcm 加密失败，回退 aes-256-cbc: ' . openssl_error_string());
            $ivCbc = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
            $encCbc = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $ivCbc);
            if ($encCbc === false) {
                throw new \RuntimeException('SMTP 密码加密失败: ' . openssl_error_string());
            }
            return base64_encode($ivCbc . $encCbc);
        }
        // 新格式：魔数 + IV(12) + 认证标签(16) + 密文
        return base64_encode(self::GCM_MAGIC . $iv . $tag . $encrypted);
    }

    /**
     * 解密 SMTP 密码
     * 格式检测：FHGCM\x01 前缀 → AES-256-GCM（认证失败即拒绝，防篡改/降级）；
     * 否则按旧版 AES-256-CBC 兼容解密；非密文格式返回原值（兼容已有明文数据）。
     * @param string $cipher base64 编码的密文
     * @return string 明文密码
     */
    public static function decryptSmtpPass(string $cipher): string
    {
        $key = MAIL_PASS_KEY;
        $data = base64_decode($cipher, true);
        if ($data === false) return $cipher; // 非密文格式，返回原值（兼容已有明文数据）

        // 新版 AES-256-GCM（带认证）
        if (strlen($data) > strlen(self::GCM_MAGIC) && substr($data, 0, strlen(self::GCM_MAGIC)) === self::GCM_MAGIC) {
            $body = substr($data, strlen(self::GCM_MAGIC));
            $ivLen = openssl_cipher_iv_length('aes-256-gcm');
            if (strlen($body) >= $ivLen + 16) {
                $iv  = substr($body, 0, $ivLen);
                $tag = substr($body, $ivLen, 16);
                $encrypted = substr($body, $ivLen + 16);
                $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($decrypted !== false) return $decrypted;
                // GCM 认证失败 = 密文被篡改；记录日志并拒绝，不回退旧格式（防降级攻击）
                \error_log('Mailer::decryptSmtpPass aes-256-gcm 认证失败（SMTP 密码密文可能被篡改）');
                return '';
            }
            \error_log('Mailer::decryptSmtpPass GCM 密文长度异常，长度不足');
            return '';
        }

        // 旧版 AES-256-CBC（无认证，仅兼容存量密文）
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        if (strlen($iv) !== $ivLen || $encrypted === false) return $cipher;
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : $cipher;
    }

    /**
     * 发送邮件
     * @param string $to       收件人地址
     * @param string $subject  主题
     * @param string $body     正文
     * @param bool   $isHtml   是否为 HTML 邮件
     * @return array ['success' => bool, 'message' => string]
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = false): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return self::err('收件人邮箱格式不正确');
        }

        $driver = strtolower(self::cfg('driver', 'smtp'));

        if ($driver === 'smtp') {
            return self::sendSmtp($to, $subject, $body, $isHtml);
        }

        return self::sendMail($to, $subject, $body, $isHtml);
    }

    // ========================================================================
    //  SMTP 驱动
    // ========================================================================

    private static function sendSmtp(string $to, string $subject, string $body, bool $isHtml): array
    {
        self::$lastResponse = '';

        $host     = self::cfg('host', 'smtp.qq.com');
        $port     = (int)self::cfg('port', '465');
        $user     = self::cfg('user');
        $pass     = self::cfg('pass');
        $fromAddr = self::cfg('from_addr') ?: $user;
        $fromName = self::cfg('from_name', \app\Helpers\I18n::get('mail.site_name'));
        $enc      = strtolower(self::cfg('encryption', 'ssl'));

        if (empty($host) || empty($user) || empty($pass)) {
            return self::err('SMTP 配置不完整');
        }

        $tag = "host={$host} port={$port} enc={$enc}";

        // ---- 输入清理 ----
        $subject  = str_replace(["\r", "\n"], '', $subject);
        $fromName = str_replace(["\r", "\n", '<', '>'], '', $fromName);

        // ---- 校验邮箱 ----
        if (!filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
            return self::err('发件人邮箱地址无效');
        }
        if (strcasecmp($fromAddr, $user) !== 0) {
            \error_log("Mailer [{$tag}]: MAIL_FROM_ADDR ({$fromAddr}) 与 MAIL_USER ({$user}) 不一致");
        }

        // ---- MIME 编码 ----
        $subjectEnc  = self::mimeEncode($subject);
        $fromNameEnc = self::mimeEncode($fromName);

        // ---- 正文编码 ----
        $bodyEnc = chunk_split(base64_encode($body), 76, "\r\n");
        $bodyEnc = preg_replace('/^\./m', '..', $bodyEnc); // Dot-Stuffing 保险

        $bodyType = $isHtml ? 'text/html' : 'text/plain';
        $headers  = "From: {$fromNameEnc} <{$fromAddr}>\r\n"
                  . "To: <{$to}>\r\n"
                  . "Subject: {$subjectEnc}\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: {$bodyType}; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: base64\r\n"
                  . "X-Mailer: FlintHub Mailer\r\n";

        // ---- SSL 上下文 ----
        $sslContext = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $tlsCrypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

        $socket = null;

        try {
            // ---- 建立连接 ----
            if ($enc === 'ssl') {
                $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $sslContext);
            } else {
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $sslContext);
            }

            if (!$socket) {
                return self::err("无法连接 SMTP 服务器 [{$tag}] — {$errstr}");
            }
            stream_set_timeout($socket, 20);

            // ---- 1. Greeting ----
            if (!self::expectCode($socket, [220])) {
                return self::smtpErr('SMTP 服务器无响应', $tag, $socket);
            }

            // ---- 2. EHLO ----
            if (!self::sendAndExpect($socket, "EHLO FlintHubMailer", [250])) {
                return self::smtpErr('EHLO 失败', $tag, $socket);
            }

            // ---- 3. STARTTLS（仅 tls 模式） ----
            if ($enc === 'tls') {
                if (!self::sendAndExpect($socket, "STARTTLS", [220])) {
                    return self::smtpErr('STARTTLS 失败', $tag, $socket);
                }
                if (!stream_socket_enable_crypto($socket, true, $tlsCrypto)) {
                    return self::err('TLS 加密协商失败');
                }
                if (!self::sendAndExpect($socket, "EHLO FlintHubMailer", [250])) {
                    return self::smtpErr('EHLO（加密后）失败', $tag, $socket);
                }
            }

            // ---- 4. AUTH LOGIN ----
            if (!self::sendAndExpect($socket, "AUTH LOGIN", [334])) {
                return self::smtpErr('AUTH LOGIN 不被服务器支持', $tag, $socket);
            }
            if (!self::sendAndExpect($socket, base64_encode($user), [334])) {
                return self::smtpErr('SMTP 用户名认证失败', $tag, $socket);
            }
            if (!self::sendAndExpect($socket, base64_encode($pass), [235], [535])) {
                return self::smtpErr('SMTP 密码认证失败', $tag, $socket);
            }

            // ---- 5. MAIL FROM ----
            if (!self::sendAndExpect($socket, "MAIL FROM:<{$fromAddr}>", [250, 251])) {
                return self::smtpErr('发件人地址被拒绝', $tag, $socket);
            }

            // ---- 6. RCPT TO ----
            if (!self::sendAndExpect($socket, "RCPT TO:<{$to}>", [250, 251, 252])) {
                return self::smtpErr('收件人地址被拒绝', $tag, $socket);
            }

            // ---- 7. DATA ----
            if (!self::sendAndExpect($socket, "DATA", [354])) {
                return self::smtpErr('DATA 命令失败', $tag, $socket);
            }

            // ---- 8. 发送正文 ----
            self::writeAll($socket, $headers . "\r\n" . $bodyEnc . "\r\n");
            self::writeAll($socket, ".\r\n");
            fflush($socket);

            $dataCode = self::readResponse($socket);
            $dataNum  = (int)$dataCode;
            if ($dataNum < 200 || $dataNum >= 300) {
                return self::smtpErr('发送正文失败', $tag, $socket);
            }

            // ---- 9. QUIT ----
            self::sendAndExpect($socket, "QUIT", [221], [], false);

            return ['success' => true, 'message' => '邮件已发送'];

        } catch (\Throwable $e) {
            \error_log("Mailer [{$tag}] 异常: " . $e->getMessage());
            return self::err('发送邮件失败，请联系管理员');
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    // ========================================================================
    //  PHP mail() 驱动
    // ========================================================================

    private static function sendMail(string $to, string $subject, string $body, bool $isHtml): array
    {
        $fromAddr = self::cfg('from_addr') ?: 'noreply@localhost';
        $fromName = str_replace(["\r", "\n", '<', '>'], '', self::cfg('from_name', 'FlintHub 论坛'));

        if (!filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
            return self::err('发件人邮箱地址无效（MAIL_FROM_ADDR 配置错误）');
        }

        $subject  = str_replace(["\r", "\n"], '', $subject);
        $subjectEnc = self::mimeEncode($subject);
        $fromNameEnc = self::mimeEncode($fromName);
        $bodyType = $isHtml ? 'text/html' : 'text/plain';
        $bodyEnc  = chunk_split(base64_encode($body), 76, "\r\n");

        $headers = "From: {$fromNameEnc} <{$fromAddr}>\r\n"
                 . "Reply-To: {$fromAddr}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: {$bodyType}; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "X-Mailer: FlintHub Mailer\r\n";

        $result = @mail($to, $subjectEnc, $bodyEnc, $headers);
        return $result
            ? ['success' => true, 'message' => '邮件已发送']
            : ['success' => false, 'message' => 'mail() 发送失败，请检查服务器邮件配置'];
    }

    // ========================================================================
    //  SMTP 协议底层
    // ========================================================================

    /**
     * 发送一行命令并检查响应码
     */
    private static function sendAndExpect($socket, string $cmd, array $expectCodes, array $forbidCodes = [], bool $throwOnFail = true): bool
    {
        if (!self::writeAll($socket, $cmd . "\r\n")) {
            if ($throwOnFail) {
                \error_log("Mailer: fwrite 失败: {$cmd}");
            }
            return false;
        }
        fflush($socket);
        return self::expectCode($socket, $expectCodes, $forbidCodes, $throwOnFail);
    }

    /**
     * 完整写入所有数据，避免 TCP Socket 部分写入（partial write）
     */
    private static function writeAll($socket, string $data): bool
    {
        $len  = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($socket, substr($data, $sent));
            if ($n === false || $n === 0) {
                return false;
            }
            $sent += $n;
        }
        return true;
    }

    /**
     * 从 socket 读取 SMTP 响应（支持多行）
     *
     * 按 RFC 5321 §4.2：多行响应每行以 3 位数字 + 连字符开头，
     * 末行以 3 位数字 + 空格开头，且数字与首行一致。
     */
    private static function readResponse($socket): string
    {
        $response    = '';
        $firstPrefix = '';

        while (true) {
            $line = @fgets($socket, 512);
            if ($line === false || $line === '') break;

            $response .= $line;

            // 记录首行 3 位前缀
            if ($firstPrefix === '' && strlen($line) >= 3) {
                $firstPrefix = substr($line, 0, 3);
            }

            // 末行判别：前缀一致且第 4 位为空格
            if ($firstPrefix !== '' && strlen($line) >= 4) {
                $linePrefix = substr($line, 0, 3);
                if ($linePrefix === $firstPrefix && $line[3] === ' ') break;
            }

            // 超时检测
            $meta = @stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                $response .= '[timed_out]';
                break;
            }

            // 防无限输出
            if (strlen($response) > 65536) {
                $response .= '[truncated]';
                break;
            }
        }

        self::$lastResponse = trim($response);
        return self::$lastResponse;
    }

    /**
     * 读取并检查响应码
     */
    private static function expectCode($socket, array $expected, array $forbidden = [], bool $throwOnFail = true): bool
    {
        $response = self::readResponse($socket);
        $code     = (int)$response;

        foreach ($forbidden as $fc) {
            if ((int)$fc === $code) {
                if ($throwOnFail) {
                    \error_log("Mailer: 命中禁止码 {$fc}: {$response}");
                }
                return false;
            }
        }

        if (in_array($code, $expected, true)) {
            return true;
        }

        if ($throwOnFail) {
            \error_log("Mailer: 预期 [" . implode(',', $expected) . "]，收到 {$code}: {$response}");
        }
        return false;
    }

    // ========================================================================
    //  工具方法
    // ========================================================================

    private static function mimeEncode(string $str): string
    {
        if (preg_match('/[^\x20-\x7e]/', $str)) {
            return mb_encode_mimeheader($str, 'UTF-8', 'B', "\r\n");
        }
        return $str;
    }

    /**
     * 构造 SMTP 错误消息并记录日志（不在此处调用 readResponse()，以免覆盖 lastResponse）
     */
    private static function smtpErr(string $msg, string $tag, $socket = null): array
    {
        $detail = self::$lastResponse;
        \error_log("Mailer [{$tag}]: {$msg} — {$detail}");
        return self::err('发送邮件失败，请联系管理员');
    }

    private static function err(string $msg): array
    {
        return ['success' => false, 'message' => $msg];
    }
}
