<?php
if (!defined('ABSPATH')) exit;

/**
 * 从 Bangumi (bgm.tv) 搜索并下载封面到媒体库。
 * 需要服务器能访问 api.bgm.tv;若访问不到,请在后台手动上传特色图。
 *
 * 关于比例闸:bgm 的 type=2 是全库搜索,除了番剧本体还混着「特别纪念动画」
 * 「联动 PV」这类条目,它们的封面是横版主视觉而非海报(实测宽/高 1.41、1.59)。
 * 这种图落进前台 3:4 的海报框后会被 object-fit:cover 裁掉一半宽度,看着就是
 * 「比例失调」。正规竖版海报实测集中在 0.707(√2/2),区间 0.659–0.756。
 *
 * 上限取 0.8 而非更宽,是因为另一类坏图更常见也更隐蔽:**蓝光碟/DVD 盒封扫描图**
 * 接近正方形(实测 328x384 = 0.854)。它不是横版,却和一屏 0.707 的海报排在一起
 * 明显违和。0.8 既留足了正常海报的余量(实测最大 0.756),又挡得住这类盒封。
 */
class BGM_Fetcher {
    public static $last_id = 0;

    /** 可接受的封面宽高比区间(宽/高),主题可用过滤器调整 */
    public static function ratio_range() {
        $r = apply_filters('bangumi_list_cover_ratio_range', [0.5, 0.8]);
        if (!is_array($r) || count($r) < 2) return [0.5, 0.8];
        $min = (float) $r[0];
        $max = (float) $r[1];
        if ($min <= 0 || $max <= 0 || $min >= $max) return [0.5, 0.8];
        return [$min, $max];
    }

    /** 封面最小宽度像素(挡掉 bgm 的 150px common 兜底图) */
    public static function min_width() {
        return (int) apply_filters('bangumi_list_cover_min_width', 200);
    }

    /**
     * 载入 download_url() / wp_tempnam() / media_handle_sideload() 所在的后台文件。
     * 前台与 WP-CLI 上下文默认不加载 wp-admin/includes/,不先 require 会直接 fatal。
     */
    protected static function load_wp_admin_includes() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /** 请求用 User-Agent(动态取站点地址,符合 bgm API 使用规范) */
    public static function user_agent() {
        return apply_filters('bangumi_list_user_agent', 'bangumi-list/' . BGM_VERSION . ' (' . home_url('/') . ')');
    }

    /**
     * 用番名搜 Bangumi 动画,返回候选数组
     * [['id' => int, 'name' => string, 'img' => string], ...]
     */
    public static function search_covers($title, $limit = 5) {
        $kw = bgm_normalize_title($title);
        if ($kw === '') return [];
        $limit = max(1, (int) $limit);

        $url = 'https://api.bgm.tv/search/subject/' . rawurlencode($kw)
             . '?type=2&responseGroup=small&max_results=' . $limit;
        $res = wp_remote_get($url, [
            'headers' => ['User-Agent' => self::user_agent()],
            'timeout' => 15,
        ]);
        if (is_wp_error($res)) return [];

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($body['list']) || !is_array($body['list'])) return [];

        $out = [];
        foreach ($body['list'] as $item) {
            $img = $item['images']['large'] ?? ($item['images']['common'] ?? '');
            if (!$img) continue;
            // API 回的是 http://,换 https 免得被中间层拦或触发混合内容
            if (strpos($img, 'http://') === 0) $img = 'https://' . substr($img, 7);
            $out[] = [
                'id'   => isset($item['id']) ? (int) $item['id'] : 0,
                'name' => (string) ($item['name_cn'] ?? ($item['name'] ?? '')),
                'img'  => $img,
            ];
        }
        return $out;
    }

    /** 兼容旧签名:只要第一个候选的封面 URL,取不到返回 null */
    public static function search_cover($title) {
        $list = self::search_covers($title, 1);
        if (empty($list)) return null;
        self::$last_id = $list[0]['id'];
        return $list[0]['img'] ?: null;
    }

    /**
     * 校验本地图片文件是不是一张像样的竖版海报。
     * 合格返回 ['width','height','ratio','ext'],不合格返回 WP_Error。
     */
    public static function validate_image($path) {
        $info = @getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return new WP_Error('bgm_not_image', '不是可识别的图片');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $ratio = $w / $h;
        list($min, $max) = self::ratio_range();

        if ($ratio < $min || $ratio > $max) {
            return new WP_Error('bgm_bad_ratio', sprintf(
                '比例 %.3f 超出 %.2f–%.2f(%dx%d),疑似横版主视觉而非海报', $ratio, $min, $max, $w, $h));
        }
        if ($w < self::min_width()) {
            return new WP_Error('bgm_too_small', sprintf(
                '宽度 %dpx 不足 %dpx(%dx%d)', $w, self::min_width(), $w, $h));
        }

        $ext_map = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
        ];
        if (defined('IMAGETYPE_WEBP')) $ext_map[IMAGETYPE_WEBP] = 'webp';
        $ext = $ext_map[$info[2]] ?? 'jpg';

        return ['width' => $w, 'height' => $h, 'ratio' => $ratio, 'ext' => $ext];
    }

    /**
     * 下载封面进媒体库并设为特色图,返回 attachment id 或 WP_Error。
     * 会先过一遍 validate_image(),横版图与过小的图直接拒收。
     */
    public static function sideload($img_url, $post_id) {
        self::load_wp_admin_includes();   // download_url() 就在这些文件里
        $tmp = download_url($img_url, 20);
        if (is_wp_error($tmp)) return $tmp;

        $meta = self::validate_image($tmp);
        if (is_wp_error($meta)) { @unlink($tmp); return $meta; }

        // 扩展名按实际图片类型给,不再一律 .jpg
        return self::attach_tmp($tmp, $post_id, 'bgm-' . $post_id . '.' . $meta['ext']);
    }

    /**
     * 把一个本地文件收进媒体库并设为特色图(不走网络)。
     * 用于 `wp bangumi import-covers`:源文件会被复制,原件保留。
     */
    public static function sideload_path($src, $post_id, $name = '') {
        if (!is_readable($src)) {
            return new WP_Error('bgm_unreadable', '文件不可读: ' . $src);
        }
        $meta = self::validate_image($src);
        if (is_wp_error($meta)) return $meta;

        self::load_wp_admin_includes();   // wp_tempnam() 也在这些文件里

        // media_handle_sideload 会「搬走」传给它的文件,所以先复制一份临时件,
        // 免得把用户 covers/ 目录里的原图吃掉。
        $tmp = wp_tempnam(basename($src));
        if (!$tmp || !@copy($src, $tmp)) {
            @unlink($tmp);
            return new WP_Error('bgm_copy_failed', '复制到临时文件失败: ' . $src);
        }

        if ($name === '') $name = 'bgm-' . $post_id . '.' . $meta['ext'];
        return self::attach_tmp($tmp, $post_id, $name);
    }

    /** 共用尾段:把临时文件挂进媒体库并设为特色图 */
    protected static function attach_tmp($tmp, $post_id, $name) {
        self::load_wp_admin_includes();

        $att = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $post_id);
        if (is_wp_error($att)) { @unlink($tmp); return $att; }
        set_post_thumbnail($post_id, $att);
        return $att;
    }
}
