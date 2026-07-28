<?php
// 非 WordPress 环境直接退出;定义 BGM_TEST 可绕过,便于单测直接 require 本文件。
if (!defined('ABSPATH') && !defined('BGM_TEST')) exit;

/** 去除首尾书名号/方括号与空白,得到搜索用主标题 */
function bgm_normalize_title($title) {
    $t = trim((string) $title);
    $t = preg_replace('/^[《「『【\s]+/u', '', $t);
    $t = preg_replace('/[》」』】\s]+$/u', '', $t);
    return trim($t);
}

/** 从自由文本解析 0–10 评分数值;解析不出返回 null */
function bgm_parse_rating($text) {
    $s = trim((string) $text);
    if ($s === '') return null;
    if (preg_match('#(\d+(?:\.\d+)?)\s*/\s*10#u', $s, $m)) {
        $v = (float) $m[1];
        return ($v >= 0 && $v <= 10) ? $v : null;
    }
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*$/u', $s, $m)) {
        $v = (float) $m[1];
        return ($v >= 0 && $v <= 10) ? $v : null;
    }
    return null;
}

/**
 * 评分的前台展示形态:数值与「/10」后缀分开返回,便于把后缀渲染成弱化样式。
 *
 * 后台只需填数字(如 9.7),后缀由前台补;旧数据里手写成 9.7/10 的也归一到同一形态,
 * 不会出现 9.7/10/10。「神作」「不做评价」这类自由文本原样返回,不补后缀。
 *
 * @return array{num:string,unit:string} num 为显示主体(保留用户写的字面量,7 不会变成 7.0),
 *                                       unit 为 '/10' 或 ''(自由文本时)
 */
function bgm_rating_display($text) {
    $s = trim((string) $text);
    if ($s === '') return ['num' => '', 'unit' => ''];
    if (preg_match('#^(\d+(?:\.\d+)?)\s*(?:/\s*10)?$#u', $s, $m) && (float) $m[1] <= 10) {
        return ['num' => $m[1], 'unit' => '/10'];
    }
    return ['num' => $s, 'unit' => ''];
}

/**
 * mb_strtolower 的安全封装。
 * WordPress 只 polyfill 了 mb_substr,没有 mb_strtolower;宿主机缺 mbstring 扩展时
 * 直接调用会 fatal error 白屏,故在此退回 strtolower(中文无大小写,不影响搜索)。
 */
function bgm_strtolower($text) {
    $s = (string) $text;
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** 未看成条目判定:评分与评语皆空,或命中未看关键词 */
function bgm_should_skip($rating, $review) {
    $r = trim((string) $rating);
    $c = trim((string) $review);
    if ($r === '' && $c === '') return true;
    if (preg_match('/看不了|G了/u', $r . ' ' . $c)) return true;
    return false;
}
