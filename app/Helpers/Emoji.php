<?php
/**
 * FlintHub — 轻量级 PHP 社区系统
 * Emoji 解析 — 站内表情转换、HTML 渲染
 * 表情资源目录：assets/img/emoji/default/（拼音命名 png），新增表情时在此映射表加一行即可扩展
 * @file app/Helpers/Emoji.php
 * @package app\Helpers
 */

namespace app\Helpers;

class Emoji
{
    /** 表情资源子目录（相对 /assets/img/emoji/） */
    const DIR = 'default';

    /** 图片扩展名 */
    const EXT = 'png';

    /**
     * 表情映射：code → 文件名
     * code 与文件名（不含扩展名）通常同名；兼容别名（历史遗留 :xiaoku: 等）单独指向新图。
     */
    const EMOJI_MAP = [
        'guzhang'    => 'guzhang.png',    // 鼓掌
        'nanguo'     => 'nanguo.png',     // 难过
        'suanle'     => 'suanle.png',     // 酸了
        'guile'      => 'guile.png',      // 跪了
        'tiaopi'     => 'tiaopi.png',     // 调皮
        'lianhong'   => 'lianhong.png',   // 脸红
        'tuodandoge' => 'tuodandoge.png', // 脱单doge
        'shengli'    => 'shengli.png',    // 胜利
        'fanbaiyan'  => 'fanbaiyan.png',  // 翻白眼
        'geixinxin'  => 'geixinxin.png',  // 给心心
        'teng'       => 'teng.png',       // 疼
        'yihuo'      => 'yihuo.png',      // 疑惑
        'shengbing'  => 'shengbing.png',  // 生病
        'shengqi'    => 'shengqi.png',    // 生气
        'dianzan'    => 'dianzan.png',    // 点赞
        'huaji'      => 'huaji.png',      // 滑稽
        'waizui'     => 'waizui.png',     // 歪嘴
        'xingxingyan'=> 'xingxingyan.png',// 星星眼
        'wuyu'       => 'wuyu.png',       // 无语
        'zhichi'     => 'zhichi.png',     // 支持
        'piezui'     => 'piezui.png',     // 撇嘴
        'wulian'     => 'wulian.png',     // 捂脸
        'wuyan'      => 'wuyan.png',      // 捂眼
        'yongbao'    => 'yongbao.png',    // 拥抱
        'baoquan'    => 'baoquan.png',    // 抱拳
        'koubi'      => 'koubi.png',      // 抠鼻
        'zhuakuang'  => 'zhuakuang.png',  // 抓狂
        'dacall'     => 'dacall.png',     // 打call
        'jingya'     => 'jingya.png',     // 惊讶
        'jingxi'     => 'jingxi.png',     // 惊喜
        'sikao'      => 'sikao.png',      // 思考
        'weixiao'    => 'weixiao.png',    // 微笑
        'ganbei'     => 'ganbei.png',     // 干杯
        'ganga'      => 'ganga.png',      // 尴尬
        'haixiu'     => 'haixiu.png',     // 害羞
        'xianqi'     => 'xianqi.png',     // 嫌弃
        'weiqu'      => 'weiqu.png',      // 委屈
        'miaoah'     => 'miaoah.png',     // 妙啊
        'fendou'     => 'fendou.png',     // 奋斗
        'daxiao'     => 'daxiao.png',     // 大笑
        'daku'       => 'daku.png',       // 大哭
        'mojing'     => 'mojing.png',     // 墨镜
        'dudu'       => 'dudu.png',       // 嘟嘟
        'xusheng'    => 'xusheng.png',    // 嘘声
        'keguazi'    => 'keguazi.png',    // 嗑瓜子
        'xihuan'     => 'xihuan.png',     // 喜欢
        'xijierqi'   => 'xijierqi.png',   // 喜极而泣
        'ohu'        => 'ohu.png',        // 哦呼
        'xiangzhi'   => 'xiangzhi.png',   // 响指
        'haqian'     => 'haqian.png',     // 哈欠
        'ciya'       => 'ciya.png',       // 呲牙
        'dai'        => 'dai.png',        // 呆
        'chigua'     => 'chigua.png',     // 吃瓜
        'jiayou'     => 'jiayou.png',     // 加油
        'zaijian'    => 'zaijian.png',    // 再见
        'aojiao'     => 'aojiao.png',     // 傲娇
        'touxiao'    => 'touxiao.png',    // 偷笑
        'baoyou'     => 'baoyou.png',     // 保佑
        'ok'         => 'OK.png',         // OK
        'doge'       => 'doge.png',       // doge

        // ---- 历史兼容别名：老数据中出现的 QQ 表情 code，语义顶替到新表情 ----
        'xiaoku'     => 'daku.png',       // 小哭 → 大哭
    ];

    // 前端用简化数组（code → 中文名，用于编辑器面板；不含历史兼容别名）
    const EMOJI_LIST = [
        ['guzhang','鼓掌'],['nanguo','难过'],['suanle','酸了'],['guile','跪了'],
        ['tiaopi','调皮'],['lianhong','脸红'],['tuodandoge','脱单doge'],['shengli','胜利'],
        ['fanbaiyan','翻白眼'],['geixinxin','给心心'],['teng','疼'],['yihuo','疑惑'],
        ['shengbing','生病'],['shengqi','生气'],['dianzan','点赞'],['huaji','滑稽'],
        ['waizui','歪嘴'],['xingxingyan','星星眼'],['wuyu','无语'],['zhichi','支持'],
        ['piezui','撇嘴'],['wulian','捂脸'],['wuyan','捂眼'],['yongbao','拥抱'],
        ['baoquan','抱拳'],['koubi','抠鼻'],['zhuakuang','抓狂'],['dacall','打call'],
        ['jingya','惊讶'],['jingxi','惊喜'],['sikao','思考'],['weixiao','微笑'],
        ['ganbei','干杯'],['ganga','尴尬'],['haixiu','害羞'],['xianqi','嫌弃'],
        ['weiqu','委屈'],['miaoah','妙啊'],['fendou','奋斗'],['daxiao','大笑'],
        ['daku','大哭'],['mojing','墨镜'],['dudu','嘟嘟'],['xusheng','嘘声'],
        ['keguazi','嗑瓜子'],['xihuan','喜欢'],['xijierqi','喜极而泣'],['ohu','哦呼'],
        ['xiangzhi','响指'],['haqian','哈欠'],['ciya','呲牙'],['dai','呆'],
        ['chigua','吃瓜'],['jiayou','加油'],['zaijian','再见'],['aojiao','傲娇'],
        ['touxiao','偷笑'],['baoyou','保佑'],['ok','OK'],['doge','doge'],
    ];

    public static function url(string $filename): string
    {
        return '/assets/img/emoji/' . self::DIR . '/' . $filename;
    }

    public static function parse(string $html): string
    {
        $map = self::EMOJI_MAP;
        return preg_replace_callback('/:([a-zA-Z0-9_]+):/', function($m) use ($map) {
            $code = strtolower($m[1]);
            if (isset($map[$code])) {
                $url = self::url($map[$code]);
                return '<img src="' . $url . '" alt="' . $code . '" class="emoji-emotion" loading="lazy">';
            }
            return $m[0];
        }, $html);
    }
}
