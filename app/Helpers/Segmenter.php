<?php
/**
 * FlintHub — 中文分词器（二元切词 bigram）+ 英文单词分词
 * 中文按连续 2 个字切词，英文/数字按「单词整体」切分并统一小写，用于倒排索引搜索
 * @file app/Helpers/Segmenter.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Segmenter
{
    /**
     * 分词：中文段按连续 2 个字切词（bigram），英文/数字段按单词整体切分并统一小写
     *
     * 英文/数字段：连续 [a-zA-Z0-9]+ 整体作为单词 token，strtolower 统一小写（大小写不敏感）；
     * 中文段：保持连续 2 汉字 bigram（"中华人民共和国" → ["中华","华人",...]）；
     * 混合文本（如 "PHP教程"）：英文单词 + 中文 bigram 各自切分后合并。
     *
     * "中华人民共和国" → ["中华","华人","人民","民共","共和","和国"]
     * "PHP教程"        → ["php","教程"]
     * "SQLite 数据库"  → ["sqlite","数据","据库"]
     */
    public static function bigram(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];

        $words = [];

        // 按「连续英文字母/数字段」切块（保留捕获分隔符）：
        //   偶数下标 = 非英文段（中文+标点+空格），奇数下标 = 英文/数字单词段
        $parts = preg_split('/([a-zA-Z0-9]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '') continue;

            if ($i % 2 === 1) {
                // ---- 英文/数字段：单词整体 token + 小写归一 ----
                $word = strtolower($part);
                // 纯字母单词长度 < 2（如 'a'/'i'）视为噪音过滤；数字与混合串保留
                if (preg_match('/^[a-z]+$/', $word) && mb_strlen($word) < 2) {
                    continue;
                }
                $words[] = $word;
                continue;
            }

            // ---- 中文段：连续 2 汉字 bigram（跳过含标点/空白的二元） ----
            $len = mb_strlen($part);
            if ($len < 2) continue;
            for ($j = 0; $j < $len - 1; $j++) {
                $two = mb_substr($part, $j, 2);
                if (preg_match('/^[\x{4e00}-\x{9fff}]{2}$/u', $two)) {
                    $words[] = $two;
                }
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * 为倒排索引生成带权重的分词结果
     * @param string $title   标题（权重 3）
     * @param string $content 正文（权重 1）
     * @return array [['token'=>'php','weight'=>3], ...]
     */
    public static function tokenize(string $title, string $content = ''): array
    {
        $tokens = [];

        // 标题分词：权重 3
        foreach (self::bigram($title) as $word) {
            $tokens[$word] = 3; // 用键去重，标题优先保留权重3
        }

        // 正文分词：权重 1（如果标题已有相同词，不降级）
        foreach (self::bigram($content) as $word) {
            if (!isset($tokens[$word])) {
                $tokens[$word] = 1;
            }
        }

        $result = [];
        foreach ($tokens as $token => $weight) {
            $result[] = ['token' => $token, 'weight' => $weight];
        }
        return $result;
    }

    /**
     * 搜索关键词分词（搜索时用，维度更宽）
     * 搜索 "中华人民共和国" 时也拆成二元；英文关键词同样按单词归一化小写
     */
    public static function tokenizeQuery(string $query): array
    {
        return self::bigram($query);
    }
}
