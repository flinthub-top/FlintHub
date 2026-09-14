<?php
// 社区治理插件 前台反馈片段（fetch 拉取，返回纯提示文本）
$notify = $notify ?? '';
$fail   = $fail ?? '';
if ($notify !== '') {
    echo $notify;
} elseif ($fail !== '') {
    echo '[' . $fail . ']'; // 以 [ ] 包裹失败信息，便于前端区分成功/失败
}