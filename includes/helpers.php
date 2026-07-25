<?php
if (!defined('ABSPATH') && !defined('BGM_TEST')) { /* 允许被测试直接 require */ }

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
