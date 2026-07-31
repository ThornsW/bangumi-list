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
 * 评分档位。返回 key(拼进 CSS class)与 label(前台文案)。
 *
 * 区间「上闭下开」,端点归上一档;评分大量落在 9.5 / 8.5 / 7.5 这几个端点上,
 * 端点归属直接决定显示结果。最低档写作 < 6.7 而非 <= 6.6,以免 6.61–6.69 无档可归。
 *
 * ⚠️「神了」是反讽的贬义,和最高档「神作」只差一字、方向相反,
 *   故两档配色必须对立(绿 vs 红),不能靠文案区分。
 */
function bgm_rating_tiers() {
    // 阈值降序,min 为 null 是兜底档。后台的档位预览经 json_encode 读同一张表,
    // 阈值只此一份,勿在 JS 或 CSS 中重复。
    return [
        ['min' => 9.5,  'key' => 'god',  'label' => '神作'],
        ['min' => 8.5,  'key' => 'good', 'label' => '还不错'],
        ['min' => 7.5,  'key' => 'npc',  'label' => 'NPC'],
        ['min' => 6.7,  'key' => 'meh',  'label' => '拉完了'],
        ['min' => null, 'key' => 'bad',  'label' => '神了'],
    ];
}

function bgm_rating_tier($score) {
    if ($score === null || $score === '') return ['key' => 'none', 'label' => '—'];
    $s = (float) $score;
    foreach (bgm_rating_tiers() as $t) {
        if ($t['min'] === null || $s >= $t['min']) {
            return ['key' => $t['key'], 'label' => $t['label']];
        }
    }
    return ['key' => 'bad', 'label' => '神了'];
}

/** 评分数值统一成 x.x(7 显示为 7.0);无评分返回全角破折号 */
function bgm_rating_number($score) {
    if ($score === null || $score === '') return '—';
    return number_format((float) $score, 1);
}

/**
 * 观看方式的行内简写,只归两态。
 *
 * 数据里存在「常规+二刷+补了漫画」这类长文本,与进度条、评分同处一行放不下,
 * 故行内只显示「速看 / 常规」,完整原文由悬浮层展示。
 */
function bgm_watch_mode_short($mode) {
    $m = trim((string) $mode);
    if ($m !== '' && bgm_strpos($m, '速看') !== false) {
        return ['key' => 'quick', 'label' => '速看'];
    }
    return ['key' => 'normal', 'label' => '常规'];
}

/** strpos 的多字节安全封装,理由同 bgm_strtolower(宿主可能缺 mbstring) */
function bgm_strpos($haystack, $needle) {
    return function_exists('mb_strpos')
        ? mb_strpos((string) $haystack, (string) $needle)
        : strpos((string) $haystack, (string) $needle);
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
