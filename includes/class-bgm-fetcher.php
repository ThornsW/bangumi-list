<?php
if (!defined('ABSPATH')) exit;

/**
 * 从 Bangumi (bgm.tv) 搜索并下载封面到媒体库。
 * 需要服务器能访问 api.bgm.tv;若访问不到,请在后台手动上传特色图。
 */
class BGM_Fetcher {
    public static $last_id = 0;

    /** 请求用 User-Agent(动态取站点地址,符合 bgm API 使用规范) */
    public static function user_agent() {
        return apply_filters('bangumi_list_user_agent', 'bangumi-list/' . BGM_VERSION . ' (' . home_url('/') . ')');
    }

    /** 用番名搜 Bangumi 动画,返回封面 URL 或 null */
    public static function search_cover($title) {
        $kw = bgm_normalize_title($title);
        if ($kw === '') return null;
        $url = 'https://api.bgm.tv/search/subject/' . rawurlencode($kw) . '?type=2&responseGroup=small&max_results=1';
        $res = wp_remote_get($url, [
            'headers' => ['User-Agent' => self::user_agent()],
            'timeout' => 15,
        ]);
        if (is_wp_error($res)) return null;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($body['list'][0])) return null;
        $first = $body['list'][0];
        self::$last_id = isset($first['id']) ? (int) $first['id'] : 0;
        $img = $first['images']['large'] ?? ($first['images']['common'] ?? '');
        return $img ?: null;
    }

    /** 下载封面进媒体库并设为特色图,返回 attachment id 或 WP_Error */
    public static function sideload($img_url, $post_id) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($img_url, 20);
        if (is_wp_error($tmp)) return $tmp;
        $file = ['name' => 'bgm-' . $post_id . '.jpg', 'tmp_name' => $tmp];
        $att = media_handle_sideload($file, $post_id);
        if (is_wp_error($att)) { @unlink($tmp); return $att; }
        set_post_thumbnail($post_id, $att);
        return $att;
    }
}
